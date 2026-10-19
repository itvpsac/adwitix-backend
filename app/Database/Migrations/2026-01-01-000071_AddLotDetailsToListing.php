<?php

namespace App\Database\Migrations;

use App\Libraries\MultiStatementMigrationTrait;
use CodeIgniter\Database\Migration;

// Multi-step "Create a Lot" form (Phase X): adds the Basic Details fields
// collected in step 1 that don't already exist on `listing` — micro
// category, city, state, and a free-text description. pincode/full
// address already exist as yard_location_pin / yard_location_address.
class AddLotDetailsToListing extends Migration
{
    use MultiStatementMigrationTrait;

    public function up()
    {
        // Each column is added only if missing: on some databases these
        // were already added by hand, which made the one-statement ALTER fail.
        $columns = [
            'micro_category' => 'TEXT AFTER subcategory',
            'city' => 'VARCHAR(255) AFTER yard_location_pin',
            'state' => 'VARCHAR(255) AFTER city',
            'description' => 'TEXT AFTER title',
        ];
        foreach ($columns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'listing')) {
                $this->db->query("ALTER TABLE listing ADD COLUMN {$name} {$definition}");
            }
        }
    }

    public function down()
    {
        $this->execMulti(<<<SQL
            ALTER TABLE listing
                DROP COLUMN micro_category,
                DROP COLUMN city,
                DROP COLUMN state,
                DROP COLUMN description;
        SQL);
    }
}
