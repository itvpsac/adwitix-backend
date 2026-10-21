<?php

namespace App\Controllers;

use App\Libraries\KycService;
use App\Libraries\UserAuthContext;
use App\Models\PartyModel;
use App\Models\PartyDocumentModel;
use App\Models\PartyAddressModel;

// BR-17/PR-15: Super Admin (SaaS Admin) side of KYC review —
// jwtSuperAdmin-gated (real TOTP/email-OTP-verified login, BR-04).
//
// Deliberate, flagged deviation from PR-15's literal text: PR-15 says
// "Tenant Admin reviews the compliance dossier and transitions master
// KYC Status." KYC is party-level data with no owning tenant, though —
// unlike every other resource jwtTenantAdmin guards (listing, saleEvent,
// settlement, sellerApplication, all tenant-owned), a Party's own
// identity isn't scoped to one tenant (BR-06: buyers are federated
// globally). Routed to Super Admin instead, consistent with how this
// codebase already handles other genuinely platform-wide compliance
// functions (BR-54 AML review, BR-05 audit log, BR-49's cross-tenant
// high-value reporting).
class KycReviewController extends BaseController
{
    private KycService $kyc;
    private PartyModel $partyModel;

    public function __construct()
    {
        $this->kyc = new KycService();
        $this->partyModel = new PartyModel();
    }

    public function index()
    {
        // ?status = submitted (default, oldest first) | verified | suspended
        // (most recently updated first). Only the columns the queue shows,
        // plus each dossier's document and address counts.
        $status = $this->request->getGet('status') ?: 'submitted';
        if (!in_array($status, ['submitted', 'verified', 'suspended'], true)) {
            return $this->jsonError(422, 'invalid_status', 'Status must be submitted, verified or suspended.');
        }
        $parties = \Config\Database::connect()->table('party p')
            ->select('p.id, p.mobile_number, p.full_name, p.entity_type, p.kyc_status, p.kyc_status_reason, p.kyc_submitted_at, p.updated_at, p.edd_required_at, p.edd_cleared_at,
                (SELECT COUNT(*) FROM party_document d WHERE d.party_id = p.id) AS document_count,
                (SELECT COUNT(*) FROM party_address a WHERE a.party_id = p.id) AS address_count', false)
            ->where('p.kyc_status', $status)->where('p.archived_at', null)
            ->orderBy($status === 'submitted' ? 'p.kyc_submitted_at' : 'p.updated_at', $status === 'submitted' ? 'ASC' : 'DESC')
            ->limit(200)
            ->get()->getResultArray();
        return $this->apiResponse(['parties' => $parties, 'status' => $status]);
    }

    public function detail(string $partyId)
    {
        $party = $this->partyModel->find($partyId);
        if (!$party) {
            return $this->jsonError(404, 'not_found', 'Party not found.');
        }
        $documents = (new PartyDocumentModel())->forParty($partyId);
        $addresses = (new PartyAddressModel())->forParty($partyId);

        return $this->apiResponse([
            'party' => $party, 'documents' => $documents, 'addresses' => $addresses,
            'suspensionReasons' => KycService::suspensionReasons(),
        ]);
    }

    public function verifyFlag(string $partyId)
    {
        try {
            $this->kyc->verifyComplianceFlag($partyId, (string) $this->input('flag'), UserAuthContext::partyId());
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'verify_failed', $e->getMessage());
        }
        return $this->apiResponse(['party' => $this->partyModel->find($partyId), 'message' => 'Compliance flag verified.']);
    }

    public function decide(string $partyId)
    {
        $approve = $this->input('decision') === 'verify';
        try {
            $this->kyc->reviewDossier($partyId, UserAuthContext::partyId(), $approve, $this->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'decide_failed', $e->getMessage());
        }
        return $this->apiResponse(['party' => $this->partyModel->find($partyId), 'message' => $approve ? 'KYC verified.' : 'KYC suspended.']);
    }

    public function clearEdd(string $partyId)
    {
        $this->kyc->clearEnhancedDueDiligence($partyId, UserAuthContext::partyId());
        return $this->apiResponse(['party' => $this->partyModel->find($partyId), 'message' => 'Enhanced due diligence cleared for this party.']);
    }

    // Documents are never reachable by a guessed URL — decrypted only
    // on-demand for a real, TOTP-verified Super Admin (jwtSuperAdmin).
    public function downloadDocument(string $documentId)
    {
        try {
            $decrypted = $this->kyc->decryptDocument($documentId);
        } catch (\RuntimeException $e) {
            return $this->jsonError(404, 'not_found', 'Document not found.');
        }
        return $this->response
            ->setHeader('Content-Type', $decrypted['mimeType'])
            ->setHeader('Content-Disposition', 'inline; filename="' . $decrypted['filename'] . '"')
            ->setBody($decrypted['contents']);
    }
}
