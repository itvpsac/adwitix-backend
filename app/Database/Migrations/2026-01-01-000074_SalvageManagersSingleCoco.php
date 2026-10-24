<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// D-130: Salvage Managers is the one CoCo TradeSphereX and the one Company
// Shop; the General Marketplace is the combined view of every TSX, not a
// tenant. Production had a "General Marketplace" tenant row anyway: Create
// Lot filed every new Lot under it, and `subscription_tier` defaulted to
// coco_starter. This:
//   1. makes tsx_launch the column default;
//   2. makes the TSX named "Salvage Managers" CoCo + Company Shop, at the
//      subdomain `salvage-managers` (when free);
//   3. moves everything filed under a tenant named "General Marketplace"
//      (Lots, Trading Sessions, roles, applications, ledgers — every table
//      with a tenant_id foreign key) to Salvage Managers, logs the move in
//      the audit chain, and deletes the now-empty row;
//   4. takes CoCo and the Company Shop class off every other TSX.
// Nothing is moved unless a "Salvage Managers" TSX exists. The data move is
// one-way; the pre-deploy backup is the way back.
class SalvageManagersSingleCoco extends Migration
{
    public function up()
    {
        $db = $this->db;
        $db->query("ALTER TABLE tenant MODIFY COLUMN subscription_tier ENUM('coco_starter', 'tsx_launch', 'tsx_growth', 'tsx_enterprise') NOT NULL DEFAULT 'tsx_launch'");

        $sm = $db->table('tenant')->where('name', 'Salvage Managers')->get()->getRowArray();
        if (!$sm) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $smUpdate = ['subscription_tier' => 'coco_starter', 'tenant_class' => 'company_shop', 'updated_at' => $now];
        if ($sm['subdomain'] !== 'salvage-managers'
            && !$db->table('tenant')->where('subdomain', 'salvage-managers')->countAllResults()) {
            $smUpdate['subdomain'] = 'salvage-managers';
        }
        $db->table('tenant')->where('id', $sm['id'])->update($smUpdate);

        $gm = $db->table('tenant')->where('name', 'General Marketplace')->where('id !=', $sm['id'])->get()->getRowArray();
        if ($gm) {
            $moved = [];
            $db->transStart();
            foreach (self::tenantReferencingTables($db) as $table) {
                $db->table($table)->where('tenant_id', $gm['id'])->update(['tenant_id' => $sm['id']]);
                if (($n = $db->affectedRows()) > 0) {
                    $moved[$table] = $n;
                }
            }
            $db->table('tenant')->where('id', $gm['id'])->delete();
            $db->transComplete();
            if ($db->transStatus()) {
                (new \App\Libraries\AuditLogService())->log('tenant.merged_into_salvage_managers', null, [
                    'fromTenantId' => $gm['id'], 'fromTenantName' => $gm['name'],
                    'toTenantId' => $sm['id'], 'moved' => $moved, 'decision' => 'D-130',
                ]);
            }
        }

        $db->table('tenant')->where('id !=', $sm['id'])->where('subscription_tier', 'coco_starter')->update(['subscription_tier' => 'tsx_launch', 'updated_at' => $now]);
        $db->table('tenant')->where('id !=', $sm['id'])->where('tenant_class', 'company_shop')->update(['tenant_class' => 'general', 'updated_at' => $now]);
    }

    public function down()
    {
        $this->db->query("ALTER TABLE tenant MODIFY COLUMN subscription_tier ENUM('coco_starter', 'tsx_launch', 'tsx_growth', 'tsx_enterprise') NOT NULL DEFAULT 'coco_starter'");
    }

    /** Every table with a `tenant_id` foreign key to tenant.id. */
    public static function tenantReferencingTables($db): array
    {
        $rows = $db->query(
            "SELECT DISTINCT k.TABLE_NAME AS t FROM information_schema.KEY_COLUMN_USAGE k
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'tenant'
               AND k.REFERENCED_COLUMN_NAME = 'id' AND k.COLUMN_NAME = 'tenant_id'"
        )->getResultArray();
        return array_column($rows, 't');
    }
}
