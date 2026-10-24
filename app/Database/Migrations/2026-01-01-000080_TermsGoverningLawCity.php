<?php

namespace App\Database\Migrations;

use App\Libraries\LegalDocumentService;
use CodeIgniter\Database\Migration;

// D-142 (project owner): Terms of Usage §8.1 named the courts as
// "[city — TODO]"; the chosen city is New Delhi, India. Publishes a new,
// non-material version (filling a placeholder changes nothing users agreed
// to) through the Documents service and its audit trail, acting as the
// Custodian. Left alone if a draft is in progress or the placeholder is gone.
class TermsGoverningLawCity extends Migration
{
    public function up()
    {
        $custodian = $this->db->table('party_role')->select('party_id')->where('role', 'super_admin')->where('revoked_at', null)->get()->getRowArray();
        $doc = $this->db->table('legal_document')->where('slug', 'terms-of-usage')->get()->getRowArray();
        if (!$custodian || !$doc) {
            return;
        }
        $hasDraft = $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('status', 'draft')->countAllResults() > 0;
        $live = $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('status', 'published')->orderBy('version', 'DESC')->get()->getRowArray();
        $content = $live ? json_decode($live['content'], true) : null;
        if ($hasDraft || !is_array($content)) {
            return;
        }
        $found = false;
        array_walk_recursive($content, function (&$v) use (&$found) {
            if (is_string($v) && str_contains($v, '[city — TODO]')) {
                $v = str_replace('[city — TODO]', 'New Delhi, India', $v);
                $found = true;
            }
        });
        if (!$found) {
            return;
        }
        $docs = new LegalDocumentService();
        $docs->saveDraft('terms-of-usage', $content, $live['effective_date'], false, '§8.1: courts at New Delhi, India (the placeholder city filled in).', $custodian['party_id']);
        $docs->publish('terms-of-usage', $custodian['party_id']);
    }

    public function down()
    {
        // A published version is history; restore an earlier one from the Documents page if needed.
    }
}
