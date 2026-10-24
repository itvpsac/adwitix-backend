<?php

namespace App\Libraries;

use App\Models\SellerApplicationModel;
use App\Models\PartyRoleModel;
use App\Models\ListingModel;

class SellerApplicationService
{
    private SellerApplicationModel $applicationModel;
    private PartyRoleModel $roleModel;
    private ListingModel $listingModel;

    public function __construct()
    {
        $this->applicationModel = new SellerApplicationModel();
        $this->roleModel = new PartyRoleModel();
        $this->listingModel = new ListingModel();
    }

    public function apply(string $partyId, string $tenantId): array
    {
        if (self::isOpenSelling($tenantId)) {
            throw new \RuntimeException('No application is needed on Salvage Managers — any verified user can list there. Each Lot is still approved by its TSX Master.');
        }
        $existing = $this->applicationModel->findForPartyAndTenant($partyId, $tenantId);
        if ($existing) {
            throw new \RuntimeException("An application for this tenant already exists (status: {$existing['status']}).");
        }
        return $this->applicationModel->createApplication($partyId, $tenantId);
    }

    public function approve(string $applicationId, string $tenantAdminId): array
    {
        $app = $this->requireApplication($applicationId);
        $this->requireTenantAdmin($tenantAdminId, $app['tenant_id']);
        if ($app['status'] !== 'pending') {
            throw new \RuntimeException('This application has already been decided.');
        }

        $this->applicationModel->update($applicationId, [
            'status' => 'approved', 'decided_by_party_id' => $tenantAdminId, 'decided_at' => date('Y-m-d H:i:s'),
        ]);
        $this->roleModel->grantRole($app['party_id'], 'seller', $app['tenant_id']);
        (new \App\Libraries\AuditLogService())->log('seller_application.approved', $tenantAdminId, [
            'applicationId' => $applicationId, 'approvedPartyId' => $app['party_id'], 'tenantId' => $app['tenant_id'],
        ]);
        return $this->applicationModel->find($applicationId);
    }

    public function reject(string $applicationId, string $tenantAdminId, string $reason): array
    {
        $app = $this->requireApplication($applicationId);
        $this->requireTenantAdmin($tenantAdminId, $app['tenant_id']);
        if ($app['status'] !== 'pending') {
            throw new \RuntimeException('This application has already been decided.');
        }

        $this->applicationModel->update($applicationId, [
            'status' => 'rejected', 'rejection_reason' => $reason,
            'decided_by_party_id' => $tenantAdminId, 'decided_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->applicationModel->find($applicationId);
    }

    // BR-09 (D-131): on Salvage Managers selling is open — no seller
    // approval — unless its TSX Master has suspended this seller there. Every
    // other TSX needs an approved seller role. Lots are approved either way.
    public function isApprovedSeller(string $partyId, string $tenantId): bool
    {
        if (self::isOpenSelling($tenantId)) {
            return !$this->isSuspendedSeller($partyId, $tenantId);
        }
        return $this->roleModel->hasActiveRole($partyId, 'seller', $tenantId);
    }

    public static function isOpenSelling(string $tenantId): bool
    {
        return \App\Models\TenantModel::isSalvageManagers((new \App\Models\TenantModel())->find($tenantId));
    }

    // A revoked seller role and no active one: the TSX Master suspended them.
    private function isSuspendedSeller(string $partyId, string $tenantId): bool
    {
        $rows = $this->roleModel->where('party_id', $partyId)->where('role', 'seller')->where('tenant_id', $tenantId)->findAll();
        return $rows && !array_filter($rows, fn($r) => $r['revoked_at'] === null);
    }

    // BR-09: suspension cascade — revoking seller status instantly
    // suspends every active listing that seller has on THIS tenant only.
    public function suspendSeller(string $partyId, string $tenantId, string $tenantAdminId, string $reason): array
    {
        $this->requireTenantAdmin($tenantAdminId, $tenantId);

        if (self::isOpenSelling($tenantId)) {
            // No application or role exists on Salvage Managers; record the
            // suspension as a revoked seller role so isApprovedSeller() sees it.
            if ($this->isSuspendedSeller($partyId, $tenantId)) {
                throw new \RuntimeException('This seller is already suspended on Salvage Managers.');
            }
            if (!$this->roleModel->hasActiveRole($partyId, 'seller', $tenantId)) {
                $this->roleModel->grantRole($partyId, 'seller', $tenantId);
            }
        } else {
            $activeRole = $this->applicationModel->where('party_id', $partyId)->where('tenant_id', $tenantId)->where('status', 'approved')->first();
            if (!$activeRole) {
                throw new \RuntimeException('This party does not hold an approved seller status on this tenant.');
            }
        }

        $db = \Config\Database::connect();
        $db->table('party_role')
            ->where('party_id', $partyId)->where('tenant_id', $tenantId)->where('role', 'seller')->where('revoked_at', null)
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $suspended = $db->table('listing')
            ->where('seller_party_id', $partyId)->where('tenant_id', $tenantId)
            ->whereIn('status', ['inventory', 'pending_approval', 'upcoming', 'active'])
            ->get()->getResultArray();

        foreach ($suspended as $listing) {
            $this->listingModel->update($listing['id'], [
                'status' => 'suspended', 'rejection_reason' => $reason, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new \App\Libraries\AuditLogService())->log('seller.suspended', $tenantAdminId, [
            'suspendedPartyId' => $partyId, 'tenantId' => $tenantId, 'reason' => $reason, 'listingsSuspended' => count($suspended),
        ]);

        return ['revokedFrom' => $partyId, 'listingsSuspended' => count($suspended)];
    }

    private function requireApplication(string $applicationId): array
    {
        $app = $this->applicationModel->find($applicationId);
        if (!$app) {
            throw new \RuntimeException('Application not found');
        }
        return $app;
    }

    private function requireTenantAdmin(string $partyId, string $tenantId): void
    {
        $auth = new AuthorizationService();
        if (!$auth->isTenantAdminFor($partyId, $tenantId)) {
            throw new \RuntimeException('BR-09: only the Tenant Admin for this tenant may act on seller applications.');
        }
    }
}
