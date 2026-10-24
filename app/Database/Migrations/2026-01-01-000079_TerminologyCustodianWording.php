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
//
// First (D-143 deploy fix): tables created with plain CREATE TABLE took the
// database's default collation, which on the live server differs from the
// app's own tables, and a join across the two failed ("Illegal mix of
// collations") — publishing a document re-reads its history joined to
// party. Every table is brought to party's collation; foreign keys that
// would block the change are dropped and put back exactly as they were.
class TerminologyCustodianWording extends Migration
{
    public function up()
    {
        $this->alignCollations();
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

    private function alignCollations(): void
    {
        $target = $this->db->query("SELECT TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'party'")->getRowArray()['c'] ?? null;
        if (!$target) {
            return;
        }
        $charset = explode('_', $target)[0];
        $tables = array_column($this->db->query(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_COLLATION <> ?
             UNION SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?",
            [$target, $target]
        )->getResultArray(), 't');
        if (!$tables) {
            return;
        }
        // Foreign keys touching those tables, child or parent side.
        $in = implode(',', array_fill(0, count($tables), '?'));
        $fks = $this->db->query(
            "SELECT rc.CONSTRAINT_NAME AS name, rc.TABLE_NAME AS child, rc.REFERENCED_TABLE_NAME AS parent, rc.UPDATE_RULE AS upd, rc.DELETE_RULE AS del,
                    GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS cols, GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS refcols
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND (rc.TABLE_NAME IN ($in) OR rc.REFERENCED_TABLE_NAME IN ($in))
             GROUP BY rc.CONSTRAINT_NAME, rc.TABLE_NAME, rc.REFERENCED_TABLE_NAME, rc.UPDATE_RULE, rc.DELETE_RULE",
            array_merge($tables, $tables)
        )->getResultArray();
        $q = fn($n) => '`' . str_replace('`', '``', $n) . '`';
        $cols = fn($list) => implode(', ', array_map($q, explode(',', $list)));
        foreach ($fks as $fk) {
            $this->db->query('ALTER TABLE ' . $q($fk['child']) . ' DROP FOREIGN KEY ' . $q($fk['name']));
        }
        foreach ($tables as $t) {
            $this->db->query('ALTER TABLE ' . $q($t) . " CONVERT TO CHARACTER SET {$charset} COLLATE {$target}");
        }
        foreach ($fks as $fk) {
            $this->db->query('ALTER TABLE ' . $q($fk['child']) . ' ADD CONSTRAINT ' . $q($fk['name']) . ' FOREIGN KEY (' . $cols($fk['cols']) . ') REFERENCES '
                . $q($fk['parent']) . ' (' . $cols($fk['refcols']) . ") ON UPDATE {$fk['upd']} ON DELETE {$fk['del']}");
        }
    }
}
