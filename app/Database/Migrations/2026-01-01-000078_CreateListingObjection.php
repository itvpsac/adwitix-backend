<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// D-140: the Custodian's objection to a Lot. Under BR-15 the Custodian
// never edits a seller's Lot; it objects, the Lot is put on hold (no
// deadline — it stays paused until put right), the seller fixes it and the
// Custodian lifts the hold, after which the TSX Master approves it again
// (BR-13). A stock-photo objection is a CBS violation unless a media
// waiver covers it (BR-59/60/61).
class CreateListingObjection extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE listing MODIFY status ENUM('inventory','pending_approval','upcoming','active','sold','cycle_ended_unsold','suspended','on_hold') NOT NULL DEFAULT 'inventory'");
        $this->db->query("CREATE TABLE listing_objection (
            id CHAR(36) NOT NULL PRIMARY KEY,
            listing_id CHAR(36) NOT NULL,
            tenant_id CHAR(36) NOT NULL,
            seller_party_id CHAR(36) NOT NULL,
            reason_key VARCHAR(40) NOT NULL,
            note TEXT NOT NULL,
            status ENUM('open','fix_submitted','resolved','withdrawn') NOT NULL DEFAULT 'open',
            listing_status_before VARCHAR(30) NOT NULL,
            sessions_stopped INT NOT NULL DEFAULT 0,
            cbs_offense_number INT NULL DEFAULT NULL,
            waiver_id CHAR(36) NULL DEFAULT NULL,
            raised_by_party_id CHAR(36) NOT NULL,
            raised_at DATETIME(6) NOT NULL,
            acknowledged_at DATETIME(6) NULL DEFAULT NULL,
            fix_note TEXT NULL DEFAULT NULL,
            fix_submitted_at DATETIME(6) NULL DEFAULT NULL,
            review_note TEXT NULL DEFAULT NULL,
            returned_count INT NOT NULL DEFAULT 0,
            closed_by_party_id CHAR(36) NULL DEFAULT NULL,
            closed_at DATETIME(6) NULL DEFAULT NULL,
            KEY idx_objection_listing (listing_id, status),
            KEY idx_objection_seller (seller_party_id, status),
            KEY idx_objection_status (status)
        )");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS listing_objection');
        $this->db->query("UPDATE listing SET status = 'suspended' WHERE status = 'on_hold'");
        $this->db->query("ALTER TABLE listing MODIFY status ENUM('inventory','pending_approval','upcoming','active','sold','cycle_ended_unsold','suspended') NOT NULL DEFAULT 'inventory'");
    }
}
