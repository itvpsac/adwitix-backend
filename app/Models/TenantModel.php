<?php

namespace App\Models;

use CodeIgniter\Model;

class TenantModel extends Model
{
    protected $table            = 'tenant';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    // BR-08/09 (D-87/D-88): subscription_tier replaces buyer_fee_percent
    // — the Success Fee is now a fixed, platform-wide schedule
    // (EmdService::calculateSuccessFee) the Tenant Admin cannot set. The
    // tier instead drives Section 5's subscription model and gates
    // Seller-Pays eligibility (TenantBillingService/SaleEventController).
    public const SUBSCRIPTION_TIERS = ['coco_starter', 'tsx_launch', 'tsx_growth', 'tsx_enterprise'];

    protected $allowedFields = [
        'id', 'name', 'tenant_class', 'subdomain', 'custom_domain',
        'subscription_tier', 'branding_logo_url', 'branding_primary_color',
        'terms_url', 'suspended_at', 'updated_at', 'low_bracket_max', 'medium_bracket_max',
        'webhook_url', 'webhook_signing_secret',
    ];

    public function createTenant(array $data): array
    {
        $id = \App\Libraries\Uuid::v4();
        $data['id'] = $id;
        // Salvage Managers is both the CoCo plan and the Company Shop class
        // (BR-12, D-130): either one implies the other.
        if (($data['tenant_class'] ?? null) === 'company_shop' && empty($data['subscription_tier'])) {
            $data['subscription_tier'] = self::SALVAGE_MANAGERS_TIER;
        }
        $data['subscription_tier'] = $data['subscription_tier'] ?? 'tsx_launch';
        $data['tenant_class'] = self::classForTier($data['subscription_tier'], $data['tenant_class'] ?? 'general');
        $this->insert($data);
        return $this->find($id);
    }

    // The CoCo tier is "Salvage Managers": the one company-owned
    // TradeSphereX (BR-12's Company Shop, D-130). There is only ever one,
    // it alone runs Tender Auctions, and it leads every list.
    public const SALVAGE_MANAGERS_TIER = 'coco_starter';

    public static function isSalvageManagers(?array $tenant): bool
    {
        return $tenant !== null && ($tenant['subscription_tier'] ?? null) === self::SALVAGE_MANAGERS_TIER;
    }

    // The tenant_class that goes with a plan: Company Shop for Salvage
    // Managers only; any other plan can't keep the Company Shop class.
    public static function classForTier(string $tier, string $class): string
    {
        if ($tier === self::SALVAGE_MANAGERS_TIER) {
            return 'company_shop';
        }
        return $class === 'company_shop' ? 'general' : $class;
    }

    // Every TradeSphereX, Salvage Managers first, then by name.
    public function salvageManagersFirst(): self
    {
        return $this->orderBy("subscription_tier = '" . self::SALVAGE_MANAGERS_TIER . "'", 'DESC', false)
            ->orderBy('name', 'ASC');
    }

    // The Salvage Managers TradeSphereX other than $exceptId, if any.
    public function salvageManagers(?string $exceptId = null): ?array
    {
        $builder = $this->where('subscription_tier', self::SALVAGE_MANAGERS_TIER);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }
        return $builder->first();
    }

    // Rows in every table that points at this tenant (tenant_id foreign
    // keys), by table — only counts above zero. A TSX can be deleted only
    // when this is empty.
    public function referenceCounts(string $tenantId): array
    {
        $db = $this->db;
        $tables = array_column($db->query(
            "SELECT DISTINCT TABLE_NAME AS t FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tenant'
               AND REFERENCED_COLUMN_NAME = 'id' AND COLUMN_NAME = 'tenant_id'"
        )->getResultArray(), 't');
        $counts = [];
        foreach ($tables as $table) {
            $n = $db->table($table)->where('tenant_id', $tenantId)->countAllResults();
            if ($n > 0) {
                $counts[$table] = $n;
            }
        }
        return $counts;
    }

    // BR-66: API availability is a package feature that varies by tier —
    // CoCo Starter has none, TSX Launch is read-only, TSX Growth adds
    // listing push, TSX Enterprise adds Sale System push too.
    public static function hasApiAccess(string $subscriptionTier): bool
    {
        return $subscriptionTier !== 'coco_starter';
    }

    public static function canPushListings(string $subscriptionTier): bool
    {
        return in_array($subscriptionTier, ['tsx_growth', 'tsx_enterprise'], true);
    }

    public static function canPushSaleEvents(string $subscriptionTier): bool
    {
        return $subscriptionTier === 'tsx_enterprise';
    }
}
