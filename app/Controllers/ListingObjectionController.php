<?php

namespace App\Controllers;

use App\Libraries\ListingObjectionService;
use App\Libraries\MediaService;
use App\Libraries\UserAuthContext;

// D-140: the Custodian's objection to a Lot (jwtSuperAdmin), and the
// seller's side of it — acknowledge, correct, submit the fix (jwtAuth).
class ListingObjectionController extends BaseController
{
    private ListingObjectionService $objections;

    public function __construct()
    {
        $this->objections = new ListingObjectionService();
    }

    // POST /admin/listings/:id/objections {reason, note}
    public function raise(string $listingId)
    {
        return $this->attempt(fn() => ['objection' => $o = $this->objections->raise($listingId, (string) $this->input('reason'), (string) $this->input('note'), UserAuthContext::partyId()),
            'message' => 'Lot put on hold. The seller and the TSX Master have been told.'
                . ($o['cbs_offense_number'] ? " Recorded as CBS violation #{$o['cbs_offense_number']} for this seller." : ($o['waiver_id'] ? ' A media waiver covers this category, so no CBS violation was recorded.' : ''))]);
    }

    // POST /admin/objections/:id/review {decision: lift|return|withdraw, note}
    public function review(string $objectionId)
    {
        $decision = (string) $this->input('decision');
        return $this->attempt(fn() => ['objection' => $this->objections->review($objectionId, $decision, (string) $this->input('note'), UserAuthContext::partyId()),
            'message' => ['lift' => 'Hold lifted — the Lot goes back for its TSX Master\'s approval.', 'return' => 'Sent back to the seller.', 'withdraw' => 'Objection withdrawn and the hold lifted.'][$decision] ?? 'Done.']);
    }

    // GET /me/lots-on-hold
    public function mine()
    {
        return $this->apiResponse(['objections' => $this->objections->openForSeller(UserAuthContext::partyId()), 'fixable' => ListingObjectionService::FIXABLE]);
    }

    // POST /objections/:id/acknowledge
    public function acknowledge(string $objectionId)
    {
        return $this->attempt(fn() => ['objection' => $this->objections->acknowledge($objectionId, UserAuthContext::partyId())]);
    }

    // POST /listings/:id/correct {field: value, …}
    public function correct(string $listingId)
    {
        $input = (array) ($this->request->getJSON(true) ?? $this->request->getPost());
        return $this->attempt(fn() => ['listing' => $this->objections->fixDetails($listingId, $input, UserAuthContext::partyId()), 'message' => 'Changes saved.']);
    }

    // POST /objections/:id/submit-fix {note}
    public function submitFix(string $objectionId)
    {
        return $this->attempt(fn() => ['objection' => $this->objections->submitFix($objectionId, (string) $this->input('note'), UserAuthContext::partyId()),
            'message' => 'Sent to the Custodian. The Lot stays on hold until they lift it.']);
    }

    // POST /listings/:id/media/:mediaId/remove
    public function removeMedia(string $listingId, string $mediaId)
    {
        return $this->attempt(function () use ($listingId, $mediaId) {
            (new MediaService())->removeMedia($mediaId, $listingId, UserAuthContext::partyId());
            return ['message' => 'Removed.'];
        });
    }

    private function attempt(callable $fn)
    {
        try {
            return $this->apiResponse($fn());
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'objection', $e->getMessage());
        }
    }
}
