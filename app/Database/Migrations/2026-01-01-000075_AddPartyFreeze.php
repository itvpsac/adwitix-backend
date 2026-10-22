<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// D-134: the Custodian can freeze an account — it keeps its record and can
// still sign in, finish settlements and see its history, but can't bid,
// offer, pledge, list, open a Trading Session or accept an offer until
// unfrozen. Both directions are logged in the audit chain with a reason.
class AddPartyFreeze extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE party
            ADD COLUMN frozen_at DATETIME(6) NULL DEFAULT NULL,
            ADD COLUMN frozen_reason TEXT NULL DEFAULT NULL,
            ADD COLUMN frozen_by_party_id CHAR(36) NULL DEFAULT NULL');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE party DROP COLUMN frozen_at, DROP COLUMN frozen_reason, DROP COLUMN frozen_by_party_id');
    }
}
