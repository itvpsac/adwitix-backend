<?php

namespace App\Libraries;

use App\Models\ListingMediaModel;
use App\Models\ListingModel;
use App\Models\MediaUploadJobModel;

// BR-11: 5-50 photos per listing, exactly one primary.
// BR-45: GPS + timestamp captured "at the moment of capture" — on a
// native mobile app this is automatic EXIF/sensor data. This is a WEB
// application, so true automatic capture isn't achievable the same way;
// this service accepts GPS/timestamp as optional client-supplied fields
// (populated via the browser's Geolocation API where the user grants
// permission) rather than guaranteeing it the way a native app could.
// Flagged explicitly, not silently treated as equivalent — see D-24.
//
// BR-59's "genuine photo of the actual item, not stock photography"
// requirement is NOT enforced here or anywhere in this codebase — that
// would require computer-vision fraud detection, out of scope. This is a
// trust/audit-time concern, not a code-enforced one.
//
// PR-09: uploads are queued, not processed inline within the HTTP
// request — enqueueUploads() only validates and stages the raw files
// (fast); the actual WebP/ffmpeg compression happens later, off the
// request thread, in processJob() (called by MediaQueueService's
// worker). This is what makes "the interface never freezes while the
// seller continues filling in details" genuinely true rather than just
// asserted.
class MediaService
{
    private const MIN_PHOTOS = 5;
    private const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const ALLOWED_VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];
    private const ALLOWED_DOCUMENT_MIME_TYPES = ['application/pdf'];
    private const MAX_FILE_SIZE_KB = 8192; // 8MB per photo, pre-compression
    private const MAX_VIDEO_SIZE_KB = 512000; // 500MB per video, pre-transcode — a raw phone video can be large before compression brings it down
    private const MAX_DOCUMENT_SIZE_KB = 20480; // 20MB per document

    private ListingMediaModel $mediaModel;
    private ListingModel $listingModel;
    private MediaUploadJobModel $jobModel;

    public function __construct()
    {
        $this->mediaModel = new ListingMediaModel();
        $this->listingModel = new ListingModel();
        $this->jobModel = new MediaUploadJobModel();
    }

    // Validates a batch of uploaded files and stages them for background
    // processing — returns immediately once files are moved to the
    // staging directory and job rows are created, without waiting for
    // any compression/transcoding to happen.
    public function enqueueUploads(string $listingId, string $uploaderPartyId, array $files, ?float $gpsLat, ?float $gpsLng): array
    {
        $listing = $this->listingModel->findActiveById($listingId);
        if (!$listing) {
            throw new \RuntimeException('Listing not found');
        }
        // on_hold (D-140): the seller is correcting the Lot after a Custodian objection.
        if (!in_array($listing['status'], ['inventory', 'pending_approval', 'on_hold'], true)) {
            throw new \RuntimeException('Media can only be added while a listing is in inventory or pending_approval, or on hold for a correction');
        }

        $validFiles = array_filter($files, fn($f) => $f->isValid() && !$f->hasMoved());

        // Per-Lot counts and storage come from the TSX's plan (D-138,
        // §6.5; BR-11's 5-photo minimum still applies at submit). Counts
        // include still-queued jobs, so a seller can't race past a cap by
        // uploading faster than the queue drains. Every file is checked
        // before any is staged, so a refused batch leaves nothing behind.
        $kindOf = function ($f): ?string {
            $m = $f->getMimeType();
            return in_array($m, self::ALLOWED_IMAGE_MIME_TYPES, true) ? 'photo'
                : (in_array($m, self::ALLOWED_VIDEO_MIME_TYPES, true) ? 'video'
                : (in_array($m, self::ALLOWED_DOCUMENT_MIME_TYPES, true) ? 'document' : null));
        };
        $mimes = ['photo' => self::ALLOWED_IMAGE_MIME_TYPES, 'video' => self::ALLOWED_VIDEO_MIME_TYPES, 'document' => self::ALLOWED_DOCUMENT_MIME_TYPES];
        $already = $incoming = ['photo' => 0, 'video' => 0, 'document' => 0];
        $sizes = [];
        foreach ($validFiles as $file) {
            $kind = $kindOf($file);
            if ($kind === null) {
                throw new \RuntimeException("Unsupported file type: {$file->getMimeType()} (JPEG/PNG/WebP for photos, MP4/MOV/WebM for video, PDF for documents)");
            }
            $incoming[$kind]++;
            $sizes[] = [$file->getClientName(), (int) $file->getSize(), $kind];
        }
        foreach ($mimes as $kind => $types) {
            $already[$kind] = $this->mediaModel->countForListing($listingId, $kind)
                + $this->jobModel->countPendingOrProcessingForListing($listingId, $types);
        }
        $tenant = (new \App\Models\TenantModel())->find($listing['tenant_id']);
        if ($tenant) {
            PlanService::assertUploadAllowed($tenant, $already, $incoming, $sizes);
        }

        // Staged OUTSIDE the public webroot — these are raw, unprocessed
        // uploads (not yet compressed/scanned), never meant to be
        // directly served until processJob() produces the real,
        // compressed file under the public uploads storage (StoragePaths).
        $stagingDir = StoragePaths::stagingDir() . '/' . $listingId;
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $jobs = [];
        foreach ($validFiles as $file) {
            $mimeType = $file->getMimeType();
            $isVideo = in_array($mimeType, self::ALLOWED_VIDEO_MIME_TYPES, true);
            $isDocument = in_array($mimeType, self::ALLOWED_DOCUMENT_MIME_TYPES, true);

            // Platform caps per file type; the plan's own per-file limit
            // (checked above) can be lower, never higher.
            $sizeCapKb = $isVideo ? self::MAX_VIDEO_SIZE_KB : ($isDocument ? self::MAX_DOCUMENT_SIZE_KB : self::MAX_FILE_SIZE_KB);
            if ($file->getSize() / 1024 > $sizeCapKb) {
                throw new \RuntimeException("File too large: {$file->getName()} (max {$sizeCapKb}KB before compression)");
            }

            $stagedName = Uuid::v4() . '_raw';
            $stagedPath = $stagingDir . '/' . $stagedName;
            $file->move($stagingDir, $stagedName);

            $job = $this->jobModel->createJob([
                'listing_id' => $listingId,
                'uploaded_by_party_id' => $uploaderPartyId,
                'staged_file_path' => $stagedPath,
                'original_filename' => $file->getClientName(),
                'mime_type' => $mimeType,
                'gps_lat' => $gpsLat,
                'gps_lng' => $gpsLng,
                'status' => 'pending',
            ]);
            $jobs[] = $job;
        }

        return $jobs;
    }

    // Processes exactly one staged job into a real listing_media row —
    // called by MediaQueueService's sequential worker, never directly
    // from an HTTP request. Throws on failure; the caller is responsible
    // for catching and marking the job failed (so one bad file doesn't
    // stop the rest of the queue from draining).
    public function processJob(array $job): array
    {
        $listingId = $job['listing_id'];
        $mimeType = $job['mime_type'];
        $stagedPath = $job['staged_file_path'];

        if (!file_exists($stagedPath)) {
            throw new \RuntimeException('Staged upload file is missing — cannot process');
        }

        $isImage = in_array($mimeType, self::ALLOWED_IMAGE_MIME_TYPES, true);
        $isVideo = in_array($mimeType, self::ALLOWED_VIDEO_MIME_TYPES, true);

        $uploadDir = dirname(StoragePaths::publicPathForWrite("uploads/listings/{$listingId}/.keep"));

        $mediaData = [
            'listing_id' => $listingId,
            'uploaded_by_party_id' => $job['uploaded_by_party_id'],
            'original_filename' => $job['original_filename'],
            'gps_lat' => $job['gps_lat'],
            'gps_lng' => $job['gps_lng'],
            'captured_at' => ($job['gps_lat'] !== null) ? date('Y-m-d H:i:s') : null,
        ];

        $compression = new MediaCompressionService();

        if ($isImage) {
            // The first photo to finish processing becomes primary — and
            // so does a new photo when the current primary's file is
            // missing, so the listing's card image recovers when photos
            // are re-added. Checked at PROCESS time (not enqueue time),
            // since jobs may finish out of upload order.
            $isFirstPhoto = !$this->mediaModel->hasAvailablePrimaryPhoto($listingId);

            $finalName = Uuid::v4() . '.webp';
            $finalPath = $uploadDir . '/' . $finalName;
            $result = $compression->compressImage($stagedPath, $finalPath, $mimeType);
            unlink($stagedPath);

            $mediaData['media_type'] = 'photo';
            $mediaData['is_primary'] = $isFirstPhoto;
            $mediaData['file_path'] = "uploads/listings/{$listingId}/{$finalName}";
            $mediaData['original_size_bytes'] = $result['originalSizeBytes'];
            $mediaData['compressed_size_bytes'] = $result['compressedSizeBytes'];
        } elseif ($isVideo) {
            $finalName = Uuid::v4() . '.mp4';
            $finalPath = $uploadDir . '/' . $finalName;
            $result = $compression->transcodeVideo($stagedPath, $finalPath);
            unlink($stagedPath);

            $mediaData['media_type'] = 'video';
            $mediaData['is_primary'] = false;
            $mediaData['file_path'] = "uploads/listings/{$listingId}/{$finalName}";
            $mediaData['original_size_bytes'] = $result['originalSizeBytes'];
            $mediaData['compressed_size_bytes'] = $result['compressedSizeBytes'];
            $mediaData['duration_seconds'] = $result['durationSeconds'];
        } else {
            // Documents are stored as-is — no compression pipeline
            // applies to a PDF the way it does to images/video.
            $finalName = Uuid::v4() . '.pdf';
            $finalPath = $uploadDir . '/' . $finalName;
            $originalSize = filesize($stagedPath);
            rename($stagedPath, $finalPath);

            $mediaData['media_type'] = 'document';
            $mediaData['is_primary'] = false;
            $mediaData['file_path'] = "uploads/listings/{$listingId}/{$finalName}";
            $mediaData['original_size_bytes'] = $originalSize;
            $mediaData['compressed_size_bytes'] = $originalSize;
        }

        if ($mediaData['is_primary']) {
            // Clears a missing-file primary before this one takes over.
            $this->mediaModel->clearPrimaryForListing($listingId);
        }
        $media = $this->mediaModel->createMedia($mediaData);

        // media_count feeds directly into BR-11's "5 photos required"
        // check (ListingLifecycleService::submitForApproval) — must be
        // the PHOTO count specifically, not photos+video+documents
        // combined, or a seller could pass that gate with fewer real
        // photos than required.
        $newPhotoCountTotal = $this->mediaModel->countForListing($listingId, 'photo');
        $this->listingModel->setMediaCount($listingId, $newPhotoCountTotal);

        return $media;
    }

    // D-140: the seller removes a photo, video or document — while the Lot
    // is not yet approved, or is on hold for a correction (e.g. a stock
    // photo the Custodian objected to). The stored file is kept on disk as
    // evidence; only the Lot stops showing it. If the main photo goes, the
    // earliest remaining photo becomes the main one.
    public function removeMedia(string $mediaId, string $listingId, string $partyId): void
    {
        $listing = $this->listingModel->find($listingId);
        if (!$listing || $listing['seller_party_id'] !== $partyId) {
            throw new \RuntimeException('Only the Lot\'s seller can remove its media.');
        }
        if (!in_array($listing['status'], ['inventory', 'pending_approval', 'on_hold'], true)) {
            throw new \RuntimeException('Media can only be removed before the Lot is approved, or while it is on hold for a correction.');
        }
        $media = $this->mediaModel->where('id', $mediaId)->where('listing_id', $listingId)->first();
        if (!$media) {
            throw new \RuntimeException('Media not found on this Lot.');
        }
        $this->mediaModel->delete($mediaId);
        if ($media['is_primary'] && $media['media_type'] === 'photo') {
            $next = $this->mediaModel->where('listing_id', $listingId)->where('media_type', 'photo')->orderBy('created_at', 'ASC')->first();
            if ($next) {
                $this->mediaModel->setPrimary($next['id'], $listingId);
            }
        }
        $this->listingModel->setMediaCount($listingId, $this->mediaModel->countForListing($listingId, 'photo'));
        (new AuditLogService())->log('listing.media_removed', $partyId, [
            'listingId' => $listingId, 'mediaId' => $mediaId, 'type' => $media['media_type'], 'file' => $media['file_path'], 'original' => $media['original_filename'],
        ]);
    }

    public function setPrimary(string $mediaId, string $listingId): void
    {
        $this->mediaModel->setPrimary($mediaId, $listingId);
    }

    public function getMediaForListing(string $listingId): array
    {
        return $this->mediaModel->findForListing($listingId);
    }

    public function getQueuedJobsForListing(string $listingId): array
    {
        return $this->jobModel->findForListing($listingId);
    }

    public static function minPhotos(): int
    {
        return self::MIN_PHOTOS;
    }
}
