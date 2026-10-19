<?php

namespace App\Database\Migrations;

use App\Libraries\MultiStatementMigrationTrait;
use CodeIgniter\Database\Migration;

// Multi-step "Create a Lot" form: Easy Auction lets the seller declare a
// window during which a buyer may physically inspect the lot before
// bidding closes. Only meaningful when sale_format = 'easy'.
class AddInspectionWindowToSaleEvent extends Migration
{
    use MultiStatementMigrationTrait;

    public function up()
    {
        // Added only if missing, for databases where they were added by hand.
        $columns = [
            'inspection_window_start' => 'DATETIME(6) AFTER scheduled_end_at',
            'inspection_window_end' => 'DATETIME(6) AFTER inspection_window_start',
        ];
        foreach ($columns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'sale_event')) {
                $this->db->query("ALTER TABLE sale_event ADD COLUMN {$name} {$definition}");
            }
        }
    }

    public function down()
    {
        $this->execMulti(<<<SQL
            ALTER TABLE sale_event
                DROP COLUMN inspection_window_start,
                DROP COLUMN inspection_window_end;
        SQL);
    }
}
