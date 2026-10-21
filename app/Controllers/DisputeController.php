<?php

namespace App\Controllers;

use App\Libraries\DisputeService;
use App\Libraries\UserAuthContext;
use App\Models\DisputeModel;
use App\Models\SaleEventModel;

class DisputeController extends BaseController
{
    private DisputeService $dispute;
    private DisputeModel $disputeModel;
    private SaleEventModel $saleEventModel;

    public function __construct()
    {
        $this->dispute = new DisputeService();
        $this->disputeModel = new DisputeModel();
        $this->saleEventModel = new SaleEventModel();
    }

    public function fileSubmit(string $saleEventId)
    {
        $partyId = UserAuthContext::partyId();
        $category = $this->input('category');
        $description = $this->input('description');

        try {
            $d = $this->dispute->fileDispute($saleEventId, $partyId, $category, $description);
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'file_failed', $e->getMessage());
        }

        return $this->apiResponse(['dispute' => $d], null, 201);
    }

    // A dispute (and its evidence) is visible only to its two parties, the
    // Tenant Admin of the sale's tenant, and the Super Admin.
    private function canView(array $dispute, ?string $partyId): bool
    {
        if (!$partyId) {
            return false;
        }
        if (in_array($partyId, [$dispute['filed_by_party_id'], $dispute['respondent_party_id']], true)) {
            return true;
        }
        $authz = new \App\Libraries\AuthorizationService();
        return $authz->isSuperAdmin($partyId)
            || ($dispute['sale_event_id'] && $authz->isTenantAdminForSaleEvent($partyId, $dispute['sale_event_id']));
    }

    // GET /disputes: the caller's own disputes (filed or responding), those
    // on sales of tenants they administer, or all of them for the Super Admin.
    public function mine()
    {
        $partyId = UserAuthContext::partyId();
        $db = \Config\Database::connect();
        $builder = $db->table('dispute d')
            ->select('d.id, d.sale_event_id, d.filed_by_party_id, d.respondent_party_id, d.category, d.description, d.status, d.evidence_deadline_at, d.ruling_outcome, d.ruled_at, d.appealed_at, d.created_at, se.ern, l.title AS listing_title, t.name AS tenant_name')
            ->join('sale_event se', 'se.id = d.sale_event_id', 'left')
            ->join('listing l', 'l.id = se.listing_id', 'left')
            ->join('tenant t', 't.id = se.tenant_id', 'left')
            ->orderBy('d.created_at', 'DESC')
            ->limit(200);

        if (!(new \App\Libraries\AuthorizationService())->isSuperAdmin($partyId)) {
            $adminTenantIds = array_column($db->table('party_role')->select('tenant_id')
                ->where('party_id', $partyId)->where('role', 'tenant_admin')->where('revoked_at', null)
                ->get()->getResultArray(), 'tenant_id');
            $builder->groupStart()
                ->where('d.filed_by_party_id', $partyId)
                ->orWhere('d.respondent_party_id', $partyId);
            if ($adminTenantIds) {
                $builder->orWhereIn('se.tenant_id', $adminTenantIds);
            }
            $builder->groupEnd();
        }

        return $this->apiResponse(['disputes' => $builder->get()->getResultArray(), 'callerId' => $partyId]);
    }

    public function show(string $disputeId)
    {
        $d = $this->disputeModel->find($disputeId);
        if (!$d || !$this->canView($d, UserAuthContext::partyId())) {
            // Same answer for "missing" and "not yours", so ids can't be probed.
            return $this->jsonError(404, 'not_found', 'Dispute not found.');
        }

        return $this->apiResponse([
            'dispute' => $d,
            'evidence' => $this->dispute->getEvidence($disputeId),
            'callerId' => UserAuthContext::partyId(),
        ]);
    }

    public function submitEvidence(string $disputeId)
    {
        try {
            $this->dispute->submitEvidence($disputeId, UserAuthContext::partyId(), $this->input('content'));
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'evidence_failed', $e->getMessage());
        }
        return $this->apiResponse(['dispute' => $this->disputeModel->find($disputeId)]);
    }

    // Authorization is checked inside DisputeService itself (category-aware
    // — Tenant Admin vs Super Admin), not by a route filter, since a single
    // route filter can't branch by the dispute's own category. Runs behind
    // jwtAuth (any authenticated party) for that reason.
    public function rule(string $disputeId)
    {
        try {
            $this->dispute->ruleOnDispute(
                $disputeId, UserAuthContext::partyId(), $this->input('outcome'),
                $this->input('rationale'), $this->input('at_fault_party_id') ?: null
            );
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'rule_failed', $e->getMessage());
        }
        return $this->apiResponse(['dispute' => $this->disputeModel->find($disputeId)]);
    }

    public function appeal(string $disputeId)
    {
        try {
            $this->dispute->fileAppeal($disputeId, UserAuthContext::partyId());
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'appeal_failed', $e->getMessage());
        }
        return $this->apiResponse(['dispute' => $this->disputeModel->find($disputeId)]);
    }

    // Access enforced by the jwtSuperAdmin route filter.
    public function ruleOnAppeal(string $disputeId)
    {
        try {
            $this->dispute->ruleOnAppeal($disputeId, UserAuthContext::partyId(), $this->input('rationale'));
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'rule_appeal_failed', $e->getMessage());
        }
        return $this->apiResponse(['dispute' => $this->disputeModel->find($disputeId)]);
    }
}
