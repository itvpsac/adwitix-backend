<?php

namespace App\Database\Migrations;

use App\Libraries\LegalDocumentService;
use CodeIgniter\Database\Migration;

// D-140 (project owner's request): the published Terminology page still
// called the Custodian "Custodian (SaaS Admin)" — the retired term the
// Documents checks flag. Publishes a new, non-material version with the
// wording fixed, through the same service (and audit trail) as the
// Custodian's Documents page, acting as the Custodian. Left alone if a
// draft is in progress (the Custodian is editing it) or nothing matches.
class TerminologyCustodianWording extends Migration
{
    public function up()
    {
        $custodian = $this->db->table('party_role')->select('party_id')->where('role', 'super_admin')->where('revoked_at', null)->get()->getRowArray();
        $doc = $this->db->table('legal_document')->where('slug', 'terminology')->get()->getRowArray();
        if (!$custodian || !$doc) {
            return;
        }
        $hasDraft = $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('status', 'draft')->countAllResults() > 0;
        $live = $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('status', 'published')->orderBy('version', 'DESC')->get()->getRowArray();
        if ($hasDraft || !$live || !str_contains($live['content'], 'SaaS Admin')) {
            return;
        }
        $content = json_decode(str_replace('Custodian (SaaS Admin)', 'Custodian', $live['content']), true);
        $docs = new LegalDocumentService();
        $docs->saveDraft('terminology', $content, $live['effective_date'], false, 'Wording: "Custodian (SaaS Admin)" is now "Custodian" — the retired term removed.', $custodian['party_id']);
        $docs->publish('terminology', $custodian['party_id']);
    }

    public function down()
    {
        // A published version is history; restore an earlier one from the Documents page if needed.
    }
}
