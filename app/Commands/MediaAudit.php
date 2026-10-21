<?php

namespace App\Commands;

use App\Libraries\StoragePaths;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// Checks every user file the database points at (listing media, tenant
// logos) against storage and, for files not there, searches the given
// directories for a file with the same name (upload names are UUIDs, so a
// name match is the same file). With --restore, copies each file found into
// storage. Never moves, overwrites or deletes anything.
class MediaAudit extends BaseCommand
{
    protected $group       = 'Media';
    protected $name        = 'media:audit';
    protected $description = 'Finds database-referenced user files missing from storage, and where else they exist.';
    protected $usage       = 'media:audit [--search <dir>[,<dir>...]] [--restore]';
    protected $options     = [
        '--search'  => 'Comma-separated directories to search (default: the home directory).',
        '--restore' => 'Copy files found elsewhere into storage.',
    ];

    // Directories never worth descending into.
    private const SKIP = ['node_modules', 'vendor', '.git', '.npm', '.nvm', '.cache', '.cagefs', '.composer', '.dotnet', '.local', '.subversion'];

    public function run(array $params)
    {
        $roots = array_filter(array_map('trim', explode(',', (string) (CLI::getOption('search') ?: getenv('HOME')))));
        $restore = CLI::getOption('restore') !== null;
        $db = \Config\Database::connect();

        $wanted = []; // basename => url path ("uploads/...")
        foreach ($db->table('listing_media')->select('file_path')->get()->getResultArray() as $row) {
            $wanted[basename($row['file_path'])] = ltrim($row['file_path'], '/');
        }
        foreach ($db->table('tenant')->select('branding_logo_url')->where('branding_logo_url IS NOT NULL')->get()->getResultArray() as $row) {
            $wanted[basename($row['branding_logo_url'])] = ltrim($row['branding_logo_url'], '/');
        }

        $missing = [];
        foreach ($wanted as $name => $urlPath) {
            if (StoragePaths::findPublicFile($urlPath) === null) {
                $missing[$name] = $urlPath;
            }
        }
        CLI::write(sprintf('Files in the database: %d, in storage: %d, missing: %d', count($wanted), count($wanted) - count($missing), count($missing)));
        if (!$missing) {
            return EXIT_SUCCESS;
        }

        $storage = realpath(StoragePaths::publicDir()) ?: StoragePaths::publicDir();
        $found = []; // basename => first path found
        foreach ($roots as $root) {
            CLI::write("Searching {$root} …");
            $this->scan($root, $missing, $found, $storage);
        }

        $byDir = [];
        foreach ($found as $path) {
            $byDir[dirname(dirname($path))] = ($byDir[dirname(dirname($path))] ?? 0) + 1;
        }
        arsort($byDir);
        CLI::write(sprintf('Found elsewhere: %d of %d missing files', count($found), count($missing)), count($found) ? 'green' : 'yellow');
        foreach ($byDir as $dir => $n) {
            CLI::write("  {$n}\t{$dir}");
        }

        // Missing files per listing, to tell sellers which lots need photos.
        $stillMissing = array_diff_key($missing, $found);
        $perListing = [];
        foreach ($stillMissing as $urlPath) {
            if (preg_match('#^uploads/listings/([^/]+)/#', $urlPath, $m)) {
                $perListing[$m[1]] = ($perListing[$m[1]] ?? 0) + 1;
            }
        }
        CLI::write(sprintf('Not found anywhere: %d file(s) across %d listing(s)', count($stillMissing), count($perListing)));
        arsort($perListing);
        foreach ($perListing as $listingId => $n) {
            CLI::write("  {$n}\t{$listingId}");
        }

        if ($restore && $found) {
            $copied = 0;
            foreach ($found as $name => $src) {
                $dst = StoragePaths::publicPathForWrite($missing[$name]);
                if (!is_file($dst) && copy($src, $dst)) {
                    $copied++;
                }
            }
            CLI::write("Copied {$copied} file(s) into storage.", 'green');
        }
        return EXIT_SUCCESS;
    }

    private function scan(string $dir, array $missing, array &$found, string $storage): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP, true)) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                continue; // reached through its real location, if at all
            }
            if (is_dir($path)) {
                if ($path !== $storage) {
                    $this->scan($path, $missing, $found, $storage);
                }
            } elseif (isset($missing[$entry]) && !isset($found[$entry]) && filesize($path) > 0) {
                $found[$entry] = $path;
            }
        }
    }
}
