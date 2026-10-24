<?php

namespace App\Controllers;

use App\Models\PartyModel;
use App\Models\PartyRoleModel;
use App\Models\TenantModel;

// Phase 3D: platform-wide Super Admin user directory. All routes
// jwtSuperAdmin-gated.
class UserController extends BaseController
{
    public function index()
    {
        $partyModel = new PartyModel();
        $q = trim((string) $this->request->getGet('q'));

        // The Custodian's own account isn't a platform user — it never trades,
        // so it stays out of the directory.
        $builder = $partyModel->where('archived_at', null)
            ->where("id NOT IN (SELECT party_id FROM party_role WHERE role = 'super_admin' AND revoked_at IS NULL)", null, false)
            ->orderBy('mobile_number', 'ASC');
        // Filters run here, across every page: ?status (active, frozen,
        // delisted, shadow_banned), ?type (individual, organization), ?kyc.
        match ((string) $this->request->getGet('status')) {
            'frozen' => $builder->where('frozen_at IS NOT NULL', null, false),
            'delisted' => $builder->where('frozen_at', null)->where('seller_delisted_at IS NOT NULL', null, false),
            'shadow_banned' => $builder->where('frozen_at', null)->where('seller_delisted_at', null)
                ->groupStart()->where('shadow_banned_at_buyer IS NOT NULL', null, false)->orWhere('shadow_banned_at_seller IS NOT NULL', null, false)->groupEnd(),
            'active' => $builder->where('frozen_at', null)->where('seller_delisted_at', null)->where('shadow_banned_at_buyer', null)->where('shadow_banned_at_seller', null),
            default => null,
        };
        $type = (string) $this->request->getGet('type');
        if (in_array($type, ['individual', 'organization'], true)) {
            $builder->where('entity_type', $type);
        }
        $kyc = (string) $this->request->getGet('kyc');
        if ($kyc !== '' && $kyc !== 'all') {
            $kyc === 'pending' ? $builder->groupStart()->where('kyc_status', 'pending')->orWhere('kyc_status', null)->groupEnd() : $builder->where('kyc_status', $kyc);
        }
        if ($q !== '') {
            $builder = $builder->groupStart()
                ->like('mobile_number', $q)
                ->orLike('full_name', $q)
                ->orLike('recovery_email', $q)
                ->orLike('org_gstin', $q)
                ->groupEnd();
        }
        // ?page (1-based) and ?per_page (≤ 100).
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?: 100)));
        $page = max(1, (int) ($this->request->getGet('page') ?: 1));
        $total = $builder->countAllResults(false);
        $users = array_map(fn($u) => $u + ['status' => PartyModel::standing($u)], $builder->findAll($perPage, ($page - 1) * $perPage));

        return $this->apiResponse([
            'users' => $users, 'q' => $q,
            'total' => $total, 'page' => $page, 'perPage' => $perPage, 'totalPages' => (int) ceil($total / $perPage),
        ]);
    }

    public function detail(string $partyId)
    {
        $party = (new PartyModel())->find($partyId);
        if (!$party) {
            return $this->jsonError(404, 'not_found', 'Party not found.');
        }

        $roles = (new PartyRoleModel())->findActiveRolesForParty($partyId);

        $db = \Config\Database::connect();
        $tenantIds = array_filter(array_column($roles, 'tenant_id'));
        $tenantNames = [];
        if (!empty($tenantIds)) {
            $rows = $db->table('tenant')->select('id, name')->whereIn('id', $tenantIds)->get()->getResultArray();
            foreach ($rows as $r) {
                $tenantNames[$r['id']] = $r['name'];
            }
        }

        $purchases = $db->table('settlement s')
            ->select('s.id, s.status, s.final_price, s.created_at')
            ->where('s.buyer_party_id', $partyId)
            ->orderBy('s.created_at', 'DESC')
            ->limit(20)->get()->getResultArray();

        $sales = $db->table('settlement s')
            ->select('s.id, s.status, s.final_price, s.created_at')
            ->where('s.seller_party_id', $partyId)
            ->orderBy('s.created_at', 'DESC')
            ->limit(20)->get()->getResultArray();

        $disputes = $db->table('dispute')
            ->where('filed_by_party_id', $partyId)
            ->orWhere('respondent_party_id', $partyId)
            ->orderBy('created_at', 'DESC')
            ->limit(20)->get()->getResultArray();

        $ratingEvents = $db->table('rating_event')
            ->where('party_id', $partyId)
            ->orderBy('created_at', 'DESC')
            ->limit(20)->get()->getResultArray();

        return $this->apiResponse([
            'party' => $party, 'roles' => $roles, 'tenantNames' => $tenantNames,
            'purchases' => $purchases, 'sales' => $sales, 'disputes' => $disputes,
            'ratingEvents' => $ratingEvents, 'tenants' => (new TenantModel())->salvageManagersFirst()->findAll(),
        ]);
    }

    // PR-08: Super Admin web UI to promote a Tenant Admin. Wraps the same
    // PartyRoleModel::promoteTenantAdmin() logic (BR-44 auto-demotion of
    // whoever previously held the role for that tenant included).
    public function promoteTenantAdmin(string $partyId)
    {
        $partyModel = new PartyModel();
        $party = $partyModel->find($partyId);
        if (!$party) {
            return $this->jsonError(404, 'not_found', 'Party not found.');
        }

        $tenantId = (string) $this->input('tenant_id');
        $tenant = (new TenantModel())->find($tenantId);
        if (!$tenant) {
            return $this->jsonError(422, 'invalid_tenant', 'A valid tenant must be selected.');
        }

        $roleModel = new PartyRoleModel();
        $existing = $roleModel->findActiveTenantAdmin($tenantId);

        $roleModel->promoteTenantAdmin($partyId, $tenantId);
        (new \App\Libraries\AuditLogService())->log('admin.tenant_admin_granted', $partyId, [
            'tenantId' => $tenantId, 'tenantName' => $tenant['name'],
            'demotedPreviousAdminId' => $existing['party_id'] ?? null, 'grantedViaCli' => false,
        ], $this->request->getIPAddress(), (string) $this->request->getUserAgent());

        return $this->apiResponse(['message' => "Granted Tenant Admin for \"{$tenant['name']}\"."]);
    }
}
