<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// D-137: the Custodian's Documents page. Legal and help pages become
// versioned, published content: drafts are edited and previewed, then
// published; every published version is kept (BR-05 — nothing deleted).
// A "material" publish of a document that needs acceptance (Terms of
// Usage, Privacy Policy) raises consent_version, and every user must accept
// it again before further use (BR-51(d)).
// Version 1 of each document is today's built-in page text, from
// app/Database/Seeds/legal_documents_v1.json.
class CreateLegalDocuments extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE legal_document (
            id CHAR(36) NOT NULL PRIMARY KEY,
            slug VARCHAR(80) NOT NULL,
            title VARCHAR(200) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            category VARCHAR(10) NOT NULL,
            path VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            requires_consent TINYINT(1) NOT NULL DEFAULT 0,
            consent_version INT NULL DEFAULT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            UNIQUE KEY idx_legal_document_slug (slug)
        )");

        $this->db->query("CREATE TABLE legal_document_version (
            id CHAR(36) NOT NULL PRIMARY KEY,
            document_id CHAR(36) NOT NULL,
            version INT NOT NULL,
            status VARCHAR(12) NOT NULL,
            content LONGTEXT NOT NULL,
            effective_date DATE NULL DEFAULT NULL,
            material TINYINT(1) NOT NULL DEFAULT 0,
            change_note TEXT NULL,
            created_by_party_id CHAR(36) NULL DEFAULT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NULL DEFAULT NULL,
            published_by_party_id CHAR(36) NULL DEFAULT NULL,
            published_at DATETIME(6) NULL DEFAULT NULL,
            UNIQUE KEY idx_legal_version (document_id, version),
            KEY idx_legal_version_status (document_id, status),
            CONSTRAINT fk_legal_version_document FOREIGN KEY (document_id) REFERENCES legal_document (id)
        )");

        $seed = json_decode((string) file_get_contents(APPPATH . 'Database/Seeds/legal_documents_v1.json'), true);
        $now = date('Y-m-d H:i:s');
        foreach ($seed['documents'] ?? [] as $d) {
            $docId = \App\Libraries\Uuid::v4();
            $this->db->table('legal_document')->insert([
                'id' => $docId, 'slug' => $d['slug'], 'title' => $d['title'], 'kind' => $d['kind'],
                'category' => $d['category'], 'path' => $d['path'], 'sort_order' => $d['sort'],
                'requires_consent' => $d['requires_consent'] ? 1 : 0,
                'consent_version' => $d['requires_consent'] ? 1 : null,
            ]);
            $this->db->table('legal_document_version')->insert([
                'id' => \App\Libraries\Uuid::v4(), 'document_id' => $docId, 'version' => 1, 'status' => 'published',
                'content' => json_encode($d['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'effective_date' => $d['effective_date'], 'material' => 0,
                'change_note' => 'Version 1: the text the site already showed.', 'published_at' => $now,
            ]);
        }
    }

    public function down()
    {
        $this->db->query('DROP TABLE legal_document_version');
        $this->db->query('DROP TABLE legal_document');
    }
}
