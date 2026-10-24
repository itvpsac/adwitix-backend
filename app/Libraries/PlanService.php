<?php

namespace App\Libraries;

// D-138: TSX subscription plans as data (table subscription_plan). Every
// plan-dependent rule reads from here — API access (BR-66), Seller-Pays
// eligibility (BR-32), and the media/storage limits enforced on upload
// (§6.5) — so changing a plan on the Custodian's Plans page changes the
// platform's behaviour, with no code change. NULL limits mean unlimited.
class PlanService
{
    public const API_LEVELS = ['none', 'read', 'read_push_lots', 'full'];

    // Fields the Custodian may edit, with their type.
    public const EDITABLE = [
        'name' => 'text', 'tagline' => 'text', 'monthly_price_inr' => 'int',
        'api_access' => 'api', 'seller_pays_allowed' => 'bool', 'tsx_masters_included' => 'int', 'staff_roles' => 'text', 'sso' => 'bool',
        'storage_gb' => 'limit', 'max_file_mb' => 'limit', 'images_per_lot' => 'limit', 'videos_per_lot' => 'limit', 'documents_per_lot' => 'limit',
        'data_retention_years' => 'limit', 'draft_retention_days' => 'limit', 'deleted_recovery_days' => 'limit',
        'audit_retention_years' => 'limit', 'activity_history_years' => 'limit', 'archive_access' => 'bool',
        'purge_policy' => 'text', 'backup_frequency' => 'text', 'restore_requests' => 'text',
    ];

    // Fields the platform enforces in code today; the rest are stated
    // policy (shown on the sheet, honoured by operations).
    public const ENFORCED = ['api_access', 'seller_pays_allowed', 'storage_gb', 'max_file_mb', 'images_per_lot', 'videos_per_lot', 'documents_per_lot'];

    private static array $cache = [];

    public static function all(): array
    {
        if (!isset(self::$cache['__all'])) {
            self::$cache['__all'] = \Config\Database::connect()->table('subscription_plan')->orderBy('sort_order', 'ASC')->get()->getResultArray();
        }
        return self::$cache['__all'];
    }

    public static function get(?string $key): ?array
    {
        foreach (self::all() as $p) {
            if ($p['plan_key'] === $key) {
                return $p;
            }
        }
        return null;
    }

    // ── API and fee rules (BR-66, BR-32) ─────────────────────────────

    public static function apiLevel(?string $tier): int
    {
        $p = self::get($tier);
        return (int) array_search($p['api_access'] ?? 'none', self::API_LEVELS, true);
    }

    public static function hasApiAccess(?string $tier): bool { return self::apiLevel($tier) >= 1; }
    public static function canPushListings(?string $tier): bool { return self::apiLevel($tier) >= 2; }
    public static function canPushSaleEvents(?string $tier): bool { return self::apiLevel($tier) >= 3; }

    public static function sellerPaysAllowed(?string $tier): bool
    {
        return (bool) (self::get($tier)['seller_pays_allowed'] ?? false);
    }

    // ── Storage (§6.5) ───────────────────────────────────────────────

    // Plan storage plus storage the TSX has bought; null = unlimited.
    public static function storageLimitBytes(array $tenant): ?int
    {
        $p = self::get($tenant['subscription_tier'] ?? null);
        if (!$p || $p['storage_gb'] === null) {
            return null;
        }
        return ((int) $p['storage_gb'] + (int) ($tenant['extra_storage_gb'] ?? 0)) * 1024 ** 3;
    }

    // Bytes stored for a TSX's Lots (as kept on disk, after compression).
    public static function storageUsedBytes(string $tenantId): int
    {
        $row = \Config\Database::connect()->table('listing_media m')
            ->select('COALESCE(SUM(COALESCE(m.compressed_size_bytes, m.original_size_bytes, 0)), 0) AS bytes', false)
            ->join('listing l', 'l.id = m.listing_id')->where('l.tenant_id', $tenantId)->get()->getRowArray();
        return (int) ($row['bytes'] ?? 0);
    }

    public static function usage(array $tenant): array
    {
        $plan = self::get($tenant['subscription_tier'] ?? null);
        $db = \Config\Database::connect();
        $perLot = $db->table('listing_media m')->select('m.listing_id, m.media_type, COUNT(*) AS n')
            ->join('listing l', 'l.id = m.listing_id')->where('l.tenant_id', $tenant['id'])
            ->groupBy(['m.listing_id', 'm.media_type'])->get()->getResultArray();
        $max = ['photo' => 0, 'video' => 0, 'document' => 0];
        foreach ($perLot as $r) {
            $max[$r['media_type']] = max($max[$r['media_type']] ?? 0, (int) $r['n']);
        }
        $limit = self::storageLimitBytes($tenant);
        $used = self::storageUsedBytes($tenant['id']);
        return [
            'plan' => $plan,
            'storage' => ['used_bytes' => $used, 'limit_bytes' => $limit, 'extra_gb' => (int) ($tenant['extra_storage_gb'] ?? 0),
                'percent' => $limit ? round(100 * $used / $limit, 1) : null],
            'largest_lot' => ['photos' => $max['photo'], 'videos' => $max['video'], 'documents' => $max['document']],
        ];
    }

    // Per-Lot limits shown to sellers before they upload.
    public static function lotLimits(?array $tenant): array
    {
        $p = self::get($tenant['subscription_tier'] ?? null);
        $n = fn($v) => $v === null ? null : (int) $v;
        return ['plan' => $p['name'] ?? null, 'photos' => $n($p['images_per_lot'] ?? null), 'videos' => $n($p['videos_per_lot'] ?? null),
            'documents' => $n($p['documents_per_lot'] ?? null), 'maxFileMb' => $n($p['max_file_mb'] ?? null)];
    }

    // ── Upload checks (called by MediaService before staging) ────────

    // $incoming: ['photo' => n, 'video' => n, 'document' => n]; $already:
    // the same counts for what the Lot already has or has queued;
    // $files: [[name, bytes, kind], …]. Throws with a clear reason.
    public static function assertUploadAllowed(array $tenant, array $already, array $incoming, array $files): void
    {
        $p = self::get($tenant['subscription_tier'] ?? null);
        if (!$p) {
            return;
        }
        $name = $p['name'];
        foreach (['photo' => 'images_per_lot', 'video' => 'videos_per_lot', 'document' => 'documents_per_lot'] as $kind => $col) {
            if ($p[$col] !== null && ($incoming[$kind] ?? 0) > 0 && ($already[$kind] ?? 0) + $incoming[$kind] > (int) $p[$col]) {
                $cap = (int) $p[$col];
                throw new \RuntimeException("The {$name} plan allows {$cap} {$kind}" . ($cap === 1 ? '' : 's') . " per Lot. This Lot already has {$already[$kind]}, so {$incoming[$kind]} more won't fit.");
            }
        }
        if ($p['max_file_mb'] !== null) {
            foreach ($files as [$fname, $bytes]) {
                if ($bytes > (int) $p['max_file_mb'] * 1024 * 1024) {
                    throw new \RuntimeException("{$fname} is larger than the {$name} plan's {$p['max_file_mb']} MB limit per file.");
                }
            }
        }
        $limit = self::storageLimitBytes($tenant);
        if ($limit !== null) {
            $incomingBytes = array_sum(array_column($files, 1));
            $used = self::storageUsedBytes($tenant['id']);
            if ($used + $incomingBytes > $limit) {
                throw new \RuntimeException(sprintf(
                    "This TradeSphereX has used %s of its %s storage, so these files (%s) won't fit. Its TSX Master can buy more storage or move to a larger plan.",
                    self::human($used), self::human($limit), self::human($incomingBytes)
                ));
            }
        }
    }

    public static function human(int $bytes): string
    {
        foreach (['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $u => $n) {
            if ($bytes >= $n) {
                return rtrim(rtrim(number_format($bytes / $n, 1), '0'), '.') . " {$u}";
            }
        }
        return "{$bytes} B";
    }

    // ── Editing (Custodian) ──────────────────────────────────────────

    public static function update(string $key, array $input, string $actorId): array
    {
        $plan = self::get($key);
        if (!$plan) {
            throw new \RuntimeException('Plan not found.');
        }
        $set = [];
        foreach (self::EDITABLE as $field => $type) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $v = $input[$field];
            $set[$field] = match ($type) {
                'int' => max(0, (int) $v),
                'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'limit' => ($v === null || $v === '' || strtolower((string) $v) === 'unlimited') ? null : max(0, (int) $v),
                'api' => in_array($v, self::API_LEVELS, true) ? $v : throw new \RuntimeException('Unknown API access level.'),
                default => mb_substr(trim(strip_tags((string) $v)), 0, 200),
            };
        }
        if (isset($set['name']) && $set['name'] === '') {
            throw new \RuntimeException('A plan needs a name.');
        }
        $changes = [];
        foreach ($set as $f => $v) {
            if ((string) ($plan[$f] ?? '') !== (string) ($v ?? '')) {
                $changes[$f] = ['from' => $plan[$f], 'to' => $v];
            }
        }
        if (!$changes) {
            return $plan;
        }
        \Config\Database::connect()->table('subscription_plan')->where('plan_key', $key)
            ->update(array_intersect_key($set, $changes) + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by_party_id' => $actorId]);
        self::$cache = [];
        (new AuditLogService())->log('subscription_plan.updated', $actorId, ['plan' => $key, 'changes' => $changes]);
        return self::get($key);
    }
}
