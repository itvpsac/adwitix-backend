<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// Writes the app's own database connection (as the site resolves it, from
// app/Config/Database.php plus .env) to an owner-only MySQL option file for
// mysqldump, and prints only the database name. Lets scripts/backup.sh
// connect exactly as the app does without parsing .env or putting the
// password on a command line.
class BackupMysqlConfig extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'backup:mysql-config';
    protected $description = 'Writes the app DB connection to a MySQL option file; prints the database name.';
    protected $usage       = 'backup:mysql-config <file>';

    public function run(array $params)
    {
        $file = $params[0] ?? null;
        if (!$file) {
            CLI::error('Usage: php spark ' . $this->usage);
            return EXIT_ERROR;
        }
        $db = config('Database')->{config('Database')->defaultGroup};
        $quote = fn($v) => '"' . addcslashes((string) $v, "\\\"") . '"';

        $old = umask(077);
        $ok = file_put_contents($file, implode("\n", [
            '[client]',
            'host=' . $quote($db['hostname']),
            'port=' . (int) ($db['port'] ?: 3306),
            'user=' . $quote($db['username']),
            'password=' . $quote($db['password']),
            '',
        ]));
        umask($old);
        if ($ok === false) {
            CLI::error("Could not write {$file}");
            return EXIT_ERROR;
        }
        chmod($file, 0600);
        echo $db['database'], "\n";
        return EXIT_SUCCESS;
    }
}
