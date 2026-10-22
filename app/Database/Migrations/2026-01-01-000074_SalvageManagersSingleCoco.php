<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// D-130: Salvage Managers is the only CoCo TradeSphereX and the only Company
// Shop. Until now `tenant.subscription_tier` defaulted to coco_starter, so
// every TSX created without an explicit plan (e.g. "General Marketplace")
// silently became CoCo too. This:
//   1. makes tsx_launch the column default;
//   2. if a TSX named "Salvage Managers" holds CoCo, moves every other CoCo
//      TSX to tsx_launch (the Custodian can pick another plan afterwards);
//      with no such TSX nothing is moved — the Custodian decides;
//   3. gives the CoCo TSX the Company Shop class and every other TSX a
//      non-Company-Shop class, so Tender rights follow the plan.
class SalvageManagersSingleCoco extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE tenant MODIFY COLUMN subscription_tier ENUM('coco_starter', 'tsx_launch', 'tsx_growth', 'tsx_enterprise') NOT NULL DEFAULT 'tsx_launch'");

        $coco = $this->db->table('tenant')->select('id, name')->where('subscription_tier', 'coco_starter')->get()->getResultArray();
        $salvage = array_values(array_filter($coco, fn($t) => strcasecmp(trim($t['name']), 'Salvage Managers') === 0));
        if (count($coco) > 1 && count($salvage) === 1) {
            $this->db->table('tenant')
                ->where('subscription_tier', 'coco_starter')->where('id !=', $salvage[0]['id'])
                ->update(['subscription_tier' => 'tsx_launch', 'updated_at' => date('Y-m-d H:i:s')]);
        }

        if (count($this->db->table('tenant')->where('subscription_tier', 'coco_starter')->get()->getResultArray()) === 1) {
            $this->db->table('tenant')->where('subscription_tier', 'coco_starter')->update(['tenant_class' => 'company_shop']);
            $this->db->table('tenant')->where('subscription_tier !=', 'coco_starter')->where('tenant_class', 'company_shop')->update(['tenant_class' => 'general']);
        }
    }

    public function down()
    {
        $this->db->query("ALTER TABLE tenant MODIFY COLUMN subscription_tier ENUM('coco_starter', 'tsx_launch', 'tsx_growth', 'tsx_enterprise') NOT NULL DEFAULT 'coco_starter'");
    }
}
