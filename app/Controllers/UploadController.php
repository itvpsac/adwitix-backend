<?php

namespace App\Controllers;

use App\Libraries\StoragePaths;

// Serves public user files (listing media, tenant logos) from persistent
// storage at the same URLs they always had: /uploads/<path>. Apache serves
// files still present in public/uploads/ directly; everything else falls
// through public/.htaccess to this route.
class UploadController extends BaseController
{
    private const MIME_TYPES = [
        'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'mp4' => 'video/mp4', 'pdf' => 'application/pdf',
    ];

    public function serve(string ...$segments)
    {
        $path = StoragePaths::findPublicFile(implode('/', $segments));
        $ext = $path !== null ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        if ($path === null || !isset(self::MIME_TYPES[$ext])) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        // File names are unique per upload, so the content never changes.
        return $this->response
            ->setHeader('Content-Type', self::MIME_TYPES[$ext])
            ->setHeader('Content-Length', (string) filesize($path))
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(file_get_contents($path));
    }
}
