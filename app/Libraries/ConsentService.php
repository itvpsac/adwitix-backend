<?php

namespace App\Libraries;

class ConsentService
{
    private const TERMS_VERSION = '2026-07-v1';

    public function recordEmdPledgeConsent(string $partyId, string $relatedReferenceId, float $amount, string $forfeitureConsequenceText, ?string $ipAddress = null): array
    {
        $consentText = "I understand that pledging ₹" . number_format($amount, 2)
            . " as Earnest Money Deposit for this bid means: {$forfeitureConsequenceText}";

        return $this->record($partyId, 'emd_pledge', $relatedReferenceId, $consentText, $ipAddress);
    }

    public function recordRegistrationConsent(string $partyId, ?string $ipAddress = null): array
    {
        return $this->record($partyId, 'registration_terms', null,
            'I accept the Terms of Usage and Privacy Policy.', $ipAddress);
    }

    // BR-51(a)/(d), D-137: acceptance of one version of a published document
    // (Terms of Usage, Privacy Policy). terms_version is "<slug>@v<n>".
    public function recordDocumentAcceptance(string $partyId, string $slug, int $version, string $consentText, ?string $ipAddress = null): array
    {
        return $this->record($partyId, 'document_acceptance', $slug, $consentText, $ipAddress, "{$slug}@v{$version}");
    }

    private function record(string $partyId, string $consentType, ?string $relatedReferenceId, string $consentText, ?string $ipAddress, ?string $termsVersion = null): array
    {
        $termsVersion ??= self::TERMS_VERSION;
        $db = \Config\Database::connect();
        $id = Uuid::v4();
        $db->table('consent_event')->insert([
            'id' => $id,
            'party_id' => $partyId,
            'consent_type' => $consentType,
            'terms_version' => $termsVersion,
            'related_reference_id' => $relatedReferenceId,
            'consent_text_shown' => $consentText,
            'ip_address' => $ipAddress,
        ]);

        (new AuditLogService())->log('consent.recorded', $partyId, [
            'consentType' => $consentType, 'termsVersion' => $termsVersion, 'relatedReferenceId' => $relatedReferenceId,
        ]);

        return $db->table('consent_event')->where('id', $id)->get()->getRowArray();
    }

    public function hasEmdPledgeConsent(string $partyId, string $relatedReferenceId): bool
    {
        $db = \Config\Database::connect();
        return $db->table('consent_event')
            ->where('party_id', $partyId)
            ->where('consent_type', 'emd_pledge')
            ->where('related_reference_id', $relatedReferenceId)
            ->countAllResults() > 0;
    }
}
