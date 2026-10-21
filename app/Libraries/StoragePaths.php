<?php

namespace App\Libraries;

// Single place that maps user files to disk. Public files are addressed by
// their URL path ("uploads/listings/<id>/<file>"), which is what the
// database stores; KYC documents by their stored path.
class StoragePaths
{
    public static function root(): ?string
    {
        $root = rtrim((string) config('Storage')->root, '/');
        return $root !== '' ? $root : null;
    }

    // Directory holding public files; "uploads/x" lives at publicDir()/x.
    public static function publicDir(): string
    {
        $root = self::root();
        return $root !== null ? $root . '/uploads' : rtrim(FCPATH, '/') . '/uploads';
    }

    public static function kycVaultDir(): string
    {
        $root = self::root();
        return $root !== null ? $root . '/kyc_vault' : WRITEPATH . 'kyc_vault';
    }

    public static function stagingDir(): string
    {
        $root = self::root();
        return $root !== null ? $root . '/uploads_staging' : WRITEPATH . 'uploads_staging';
    }

    // Absolute path for a public URL path ("uploads/..."), ensuring its
    // directory exists — for writing.
    public static function publicPathForWrite(string $urlPath): string
    {
        $path = self::publicDir() . '/' . self::stripUploadsPrefix($urlPath);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $path;
    }

    // Absolute path of an existing public file, or null. Checks the
    // configured storage first, then the legacy in-tree public/uploads/.
    public static function findPublicFile(string $urlPath): ?string
    {
        $relative = self::stripUploadsPrefix($urlPath);
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }
        foreach (array_unique([self::publicDir(), rtrim(FCPATH, '/') . '/uploads']) as $dir) {
            $path = $dir . '/' . $relative;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    // Resolves a stored KYC document path. Rows written before storage
    // moved hold the old absolute path; fall back to the same file under
    // the current vault.
    public static function findKycFile(string $storedPath): ?string
    {
        if (is_file($storedPath)) {
            return $storedPath;
        }
        $pos = strpos($storedPath, 'kyc_vault/');
        if ($pos !== false) {
            $candidate = self::kycVaultDir() . '/' . substr($storedPath, $pos + strlen('kyc_vault/'));
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function stripUploadsPrefix(string $urlPath): string
    {
        $path = ltrim($urlPath, '/');
        return str_starts_with($path, 'uploads/') ? substr($path, strlen('uploads/')) : $path;
    }
}
