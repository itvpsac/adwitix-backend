<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// Records an App migration as already run, for a database where its table
// was created outside the migrator (so `migrate` keeps failing on "table
// already exists" and never reaches later migrations). Does nothing unless
// the migration is unrecorded AND the table it creates exists.
class MarkMigrationApplied extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'migrate:mark-applied';
    protected $description = 'Records a migration as run when its table already exists.';
    protected $usage       = 'migrate:mark-applied <version> <ClassName> <table>';

    public function run(array $params)
    {
        [$version, $className, $table] = $params + [null, null, null];
        if (!$version || !$className || !$table) {
            CLI::error('Usage: php spark ' . $this->usage);
            return EXIT_ERROR;
        }

        $db = \Config\Database::connect();
        $migrations = $db->table('migrations');

        if ($migrations->where('version', $version)->where('namespace', 'App')->countAllResults() > 0) {
            CLI::write("{$version} is already recorded — nothing to do.");
            return EXIT_SUCCESS;
        }
        if (!$db->tableExists($table)) {
            CLI::write("Table {$table} does not exist — leaving {$version} for migrate to run.");
            return EXIT_SUCCESS;
        }

        $batch = (int) ($db->table('migrations')->selectMax('batch')->get()->getRow()->batch ?? 0) + 1;
        $migrations->insert([
            'version'   => $version,
            'class'     => 'App\\Database\\Migrations\\' . $className,
            'group'     => 'default',
            'namespace' => 'App',
            'time'      => time(),
            'batch'     => $batch,
        ]);
        CLI::write("Recorded {$version} {$className} as applied (table {$table} already existed).", 'green');
        return EXIT_SUCCESS;
    }
}
