<?php

namespace App\Controllers;

use App\Models\TenantModel;

class TenantController extends BaseController
{
    private TenantModel $tenantModel;

    public function __construct()
    {
        $this->tenantModel = new TenantModel();
    }

    // BR-06: tenant creation IS the whitelisting act — a tenant only
    // exists once a Super Admin has whitelisted it. jwtSuperAdmin-gated.
    public function createSubmit()
    {
        $name = $this->input('name');
        $tenantClass = $this->input('tenant_class') ?: 'general';
        $subdomain = $this->input('subdomain');
        $subscriptionTier = $this->input('subscription_tier') ?: 'tsx_launch';
        if (!in_array($subscriptionTier, TenantModel::SUBSCRIPTION_TIERS, true)) {
            return $this->jsonError(422, 'invalid_tier', 'Invalid subscription tier.');
        }
        if ($subscriptionTier === TenantModel::SALVAGE_MANAGERS_TIER && ($existing = $this->tenantModel->salvageManagers())) {
            return $this->jsonError(409, 'salvage_managers_exists', "There is only one Salvage Managers — it is already \"{$existing['name']}\".");
        }
        // BR-06: "a dedicated subdomain ... or custom domain" — optional,
        // set once at whitelisting time like subdomain itself.
        $customDomain = trim((string) $this->input('custom_domain')) ?: null;

        if (!$name || !$subdomain) {
            return $this->jsonError(422, 'missing_fields', 'Name and subdomain are required.');
        }

        try {
            $tenant = $this->tenantModel->createTenant([
                'name' => $name, 'tenant_class' => $tenantClass,
                'subdomain' => $subdomain, 'custom_domain' => $customDomain, 'subscription_tier' => $subscriptionTier,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError(422, 'create_failed', 'Could not create tenant — subdomain or custom domain may already be in use.');
        }

        return $this->apiResponse(['tenant' => $tenant, 'message' => "Tenant \"{$tenant['name']}\" whitelisted successfully."], null, 201);
    }

    // jwtSuperAdmin-gated.
    public function list()
    {
        $q = trim((string) $this->request->getGet('q'));
        $builder = $this->tenantModel->salvageManagersFirst();
        if ($q !== '') {
            $builder = $builder->groupStart()->like('name', $q)->orLike('subdomain', $q)->groupEnd();
        }
        return $this->apiResponse(['tenants' => $builder->findAll(), 'q' => $q]);
    }

    // jwtSuperAdmin-gated.
    public function view(string $tenantId)
    {
        $tenant = $this->tenantModel->find($tenantId);
        if (!$tenant) {
            return $this->jsonError(404, 'not_found', 'Tenant not found.');
        }
        $db = \Config\Database::connect();
        $lotCount = fn(string $status) => $db->table('listing')->where('tenant_id', $tenantId)->where('status', $status)->where('archived_at', null)->countAllResults();
        $viaSaleEvent = fn(string $table, string $alias) => $db->table("{$table} {$alias}")
            ->join('sale_event se', "se.id = {$alias}.sale_event_id")->join('listing l', 'l.id = se.listing_id')->where('l.tenant_id', $tenantId);
        $settled = $viaSaleEvent('settlement', 's')->selectSum('s.final_price', 'total')
            ->where('s.status', 'completed')->where('s.completed_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))->get()->getRow();

        // Custodian Console › TradeSphereX: headline numbers and admins.
        $stats = [
            'liveLots' => $lotCount('active'),
            'upcomingLots' => $lotCount('upcoming'),
            'pendingLots' => $lotCount('pending_approval'),
            'approvedSellers' => $db->table('seller_application')->where('tenant_id', $tenantId)->where('status', 'approved')->countAllResults(),
            'pendingSellers' => $db->table('seller_application')->where('tenant_id', $tenantId)->where('status', 'pending')->countAllResults(),
            'openDisputes' => $viaSaleEvent('dispute', 'd')->whereIn('d.status', ['filed', 'evidence_window', 'appealed'])->countAllResults(),
            'stalledSettlements' => $viaSaleEvent('settlement', 's')->where('s.status', 'stalled')->countAllResults(),
            'settled30d' => (float) ($settled->total ?? 0),
        ];
        $admins = $db->table('party_role r')->select('p.id, p.full_name, p.mobile_number, r.granted_at')
            ->join('party p', 'p.id = r.party_id')
            ->where('r.tenant_id', $tenantId)->where('r.role', 'tenant_admin')->where('r.revoked_at', null)
            ->orderBy('r.granted_at', 'ASC')->get()->getResultArray();

        return $this->apiResponse(['tenant' => $tenant, 'stats' => $stats, 'admins' => $admins]);
    }

    // BR-06: tenant branding (logo + primary color). jwtSuperAdmin-gated.
    // Multipart/form-data (branding_logo upload), same reasoning as
    // MediaController::upload.
    public function editSubmit(string $tenantId)
    {
        $tenant = $this->tenantModel->find($tenantId);
        if (!$tenant) {
            return $this->jsonError(404, 'not_found', 'Tenant not found.');
        }

        $postedColor = $this->request->getPost('branding_primary_color');
        if ($postedColor && !preg_match('/^#[0-9a-fA-F]{6}$/', $postedColor)) {
            return $this->jsonError(422, 'invalid_color', 'Brand color must be a 6-digit hex code, e.g. #0F6E4E.');
        }

        $subscriptionTier = $this->request->getPost('subscription_tier') ?: $tenant['subscription_tier'];
        if (!in_array($subscriptionTier, TenantModel::SUBSCRIPTION_TIERS, true)) {
            return $this->jsonError(422, 'invalid_tier', 'Invalid subscription tier.');
        }
        if ($subscriptionTier === TenantModel::SALVAGE_MANAGERS_TIER && ($existing = (new TenantModel())->salvageManagers($tenantId))) {
            return $this->jsonError(409, 'salvage_managers_exists', "There is only one Salvage Managers — it is already \"{$existing['name']}\".");
        }

        $update = [
            'name' => $this->request->getPost('name') ?: $tenant['name'],
            'subscription_tier' => $subscriptionTier,
            'branding_primary_color' => $postedColor ?: $tenant['branding_primary_color'],
            'terms_url' => $this->request->getPost('terms_url') ?: $tenant['terms_url'],
        ];

        $logo = $this->request->getFile('branding_logo');
        if ($logo && $logo->isValid() && !$logo->hasMoved()) {
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
            if (!in_array($logo->getMimeType(), $allowed, true)) {
                return $this->jsonError(422, 'invalid_logo_type', 'Logo must be JPEG, PNG, WebP, or SVG.');
            }
            $uploadDir = dirname(\App\Libraries\StoragePaths::publicPathForWrite("uploads/tenants/{$tenantId}/.keep"));
            $filename = 'logo_' . time() . '.' . $logo->getExtension();
            $logo->move($uploadDir, $filename);
            $update['branding_logo_url'] = '/uploads/tenants/' . $tenantId . '/' . $filename;
        }

        $this->tenantModel->update($tenantId, $update);
        return $this->apiResponse(['tenant' => $this->tenantModel->find($tenantId), 'message' => 'Tenant updated.']);
    }

    // Public — a seller browsing which tenants exist without already
    // knowing a tenant ID.
    public function directory()
    {
        // Public: only what an applicant needs to pick a tenant, never
        // webhook secrets or plan internals, and no suspended tenants.
        $tenants = $this->tenantModel
            ->select('id, name, tenant_class, subdomain, custom_domain, subscription_tier, branding_primary_color')
            ->where('suspended_at', null)
            ->salvageManagersFirst()->findAll();
        return $this->apiResponse(['tenants' => $tenants]);
    }
}
