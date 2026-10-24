<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// D-138: TSX plans as data, not code. One row per subscription tier holds
// its price, API access, Seller-Pays eligibility, media and storage limits
// and retention terms (Master Business Rules §6.2–6.5; Salvage Managers per
// D-130 at Enterprise-level allowances). The upload pipeline, API gating and
// Fee Payer Election read these rows; the Custodian edits them on the Plans
// page (every change audited). NULL in a limit means unlimited.
// tenant.extra_storage_gb records storage a TSX has bought on top of its
// plan (₹2,000 per 100 GB/year, §6.6).
class CreateSubscriptionPlan extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE subscription_plan (
            plan_key VARCHAR(30) NOT NULL PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            monthly_price_inr INT NOT NULL DEFAULT 0,
            tagline VARCHAR(200) NULL,
            api_access VARCHAR(20) NOT NULL DEFAULT 'none',
            seller_pays_allowed TINYINT(1) NOT NULL DEFAULT 0,
            tsx_masters_included INT NOT NULL DEFAULT 1,
            staff_roles VARCHAR(100) NULL,
            sso TINYINT(1) NOT NULL DEFAULT 0,
            storage_gb INT NULL,
            max_file_mb INT NULL,
            images_per_lot INT NULL,
            videos_per_lot INT NULL,
            documents_per_lot INT NULL,
            data_retention_years INT NULL,
            draft_retention_days INT NULL,
            deleted_recovery_days INT NULL,
            audit_retention_years INT NULL,
            activity_history_years INT NULL,
            archive_access TINYINT(1) NOT NULL DEFAULT 1,
            purge_policy VARCHAR(40) NULL,
            backup_frequency VARCHAR(40) NULL,
            restore_requests VARCHAR(40) NULL,
            updated_at DATETIME(6) NULL DEFAULT NULL,
            updated_by_party_id CHAR(36) NULL DEFAULT NULL
        )");
        $rows = [
            ['coco_starter', 'Salvage Managers', 1, 0, "The platform's own TradeSphereX — individual and one-time sellers list free", 'none', 0, 1, null, 0,
                1024, 500, null, null, null, 10, 365, 180, null, null, 1, 'Custodian-defined', 'Daily', 'Included'],
            ['tsx_launch', 'TSX Launch', 2, 10000, 'Branded marketplace, read-only API', 'read', 1, 1, null, 0,
                100, 100, 100, 5, 50, 5, 180, 30, 2, 5, 1, 'Configurable', 'Daily', 'Included'],
            ['tsx_growth', 'TSX Growth', 3, 25000, 'Basic roles, API read + push Lots', 'read_push_lots', 1, 1, 'Basic (view / approve)', 0,
                400, 250, 300, 10, 150, 7, 365, 90, 5, 7, 1, 'Configurable', 'Daily', 'Included'],
            ['tsx_enterprise', 'TSX Enterprise', 4, 60000, 'Full roles, full API, SSO, audit logs', 'full', 1, 1, 'Full role-based access', 1,
                1024, 500, null, null, null, 10, null, 180, null, null, 1, 'Customer-defined', 'Real-time + daily', 'Priority'],
        ];
        $cols = ['plan_key', 'name', 'sort_order', 'monthly_price_inr', 'tagline', 'api_access', 'seller_pays_allowed', 'tsx_masters_included', 'staff_roles', 'sso',
            'storage_gb', 'max_file_mb', 'images_per_lot', 'videos_per_lot', 'documents_per_lot', 'data_retention_years', 'draft_retention_days', 'deleted_recovery_days',
            'audit_retention_years', 'activity_history_years', 'archive_access', 'purge_policy', 'backup_frequency', 'restore_requests'];
        foreach ($rows as $r) {
            $this->db->table('subscription_plan')->insert(array_combine($cols, $r));
        }
        $this->db->query('ALTER TABLE tenant ADD COLUMN extra_storage_gb INT NOT NULL DEFAULT 0');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE tenant DROP COLUMN extra_storage_gb');
        $this->db->query('DROP TABLE subscription_plan');
    }
}
