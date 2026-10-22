<?php

namespace App\Libraries;

// D-137: versioned legal and help documents, managed from the Custodian's
// Documents page. A document has at most one draft and one published
// version at a time; every earlier published version is kept as
// "superseded" (BR-05: nothing is deleted). Content is stored as JSON in the
// shape its public page renders, described by SCHEMAS — the same schema
// drives the server-side sanitiser and the console's editor.
//
// BR-51(d): publishing a *material* change to a document that needs
// acceptance (Terms of Usage, Privacy Policy) raises its consent_version;
// every user must then accept it again before bidding, offering, pledging
// or selling.
class LegalDocumentService
{
    // Field types: text (plain), html (limited formatting), bool, int,
    // path (site-relative link), slug, list (of objects), listOf (of text).
    public const SCHEMAS = [
        'sections' => [
            'eyebrow' => 'text', 'title' => 'text', 'meta' => 'html', 'intro' => 'html',
            'sections' => ['list' => ['id' => 'slug', 'title' => 'text', 'body' => 'html']],
            'footer' => 'html', 'toc' => 'bool', 'maxWidth' => 'int', 'backTo' => 'path',
        ],
        'faq' => [
            'desc' => 'text',
            'categories' => ['list' => ['id' => 'slug', 'label' => 'text',
                'items' => ['list' => ['code' => 'text', 'q' => 'text', 'buyer' => 'text', 'seller' => 'text']]]],
        ],
        'terminology' => [
            'desc' => 'text',
            'terms' => ['list' => ['term' => 'text', 'def' => 'text', 'related' => ['listOf' => 'text']]],
        ],
        'dosdonts' => [
            'desc' => 'text',
            'sections' => ['list' => ['id' => 'slug', 'chip' => 'text', 'title' => 'text',
                'rows' => ['list' => ['do' => 'text', 'dont' => 'text']]]],
        ],
        'pillars' => [
            'desc' => 'text',
            'badges' => ['listOf' => 'text'],
            'pillars' => ['list' => ['emoji' => 'text', 'title' => 'text', 'intro' => 'text', 'points' => ['listOf' => 'text']]],
        ],
    ];

    // Fields that must not be empty (dot paths; * = every list item).
    private const REQUIRED = [
        'sections' => ['title', 'sections.*.title', 'sections.*.body'],
        'faq' => ['categories.*.label', 'categories.*.items.*.q', 'categories.*.items.*.buyer', 'categories.*.items.*.seller'],
        'terminology' => ['terms.*.term', 'terms.*.def'],
        'dosdonts' => ['sections.*.title', 'sections.*.rows.*.do', 'sections.*.rows.*.dont'],
        'pillars' => ['pillars.*.title', 'pillars.*.intro'],
    ];

    // Terms the platform has retired (D-130 and earlier), and leftovers.
    private const RETIRED = [
        '/\bCoCo Starter\b/i' => '"CoCo Starter" is now Salvage Managers (D-130).',
        '/\bSaaS Admin\b/i' => '"SaaS Admin" is now the Custodian.',
        '/\beBid\b|\bebid[\w.-]*/i' => '"eBid" is the old platform name — it is AdwitiX now.',
        '/General Marketplace (TSX|tenant|TradeSphereX)/i' => 'The General Marketplace is the combined view of every TSX, not a TSX (D-130).',
        '/\b(TODO|TBD|lorem ipsum|XXX)\b|\[insert|\{\{/i' => 'Looks like a placeholder that was never filled in.',
    ];

    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h3', 'h4', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'template', 'noscript'];

    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // ── Reading ──────────────────────────────────────────────────────

    public function document(string $slug): ?array
    {
        return $this->db->table('legal_document')->where('slug', $slug)->get()->getRowArray();
    }

    public function published(string $slug): ?array
    {
        $doc = $this->document($slug);
        return $doc ? $this->versionRow($doc['id'], 'published') : null;
    }

    // Public payload for a page.
    public function publicDocument(string $slug): ?array
    {
        $doc = $this->document($slug);
        $v = $doc ? $this->versionRow($doc['id'], 'published') : null;
        if (!$v) {
            return null;
        }
        return ['slug' => $doc['slug'], 'title' => $doc['title'], 'kind' => $doc['kind'], 'path' => $doc['path'],
            'version' => (int) $v['version'], 'effective_date' => $v['effective_date'], 'published_at' => $v['published_at'],
            'content' => json_decode($v['content'], true)];
    }

    public function listForConsole(): array
    {
        $docs = $this->db->table('legal_document')->orderBy('sort_order', 'ASC')->get()->getResultArray();
        $out = [];
        foreach ($docs as $doc) {
            $pub = $this->versionRow($doc['id'], 'published');
            $draft = $this->versionRow($doc['id'], 'draft');
            $out[] = $doc + [
                'published' => $pub ? $this->summary($pub) : null,
                'draft' => $draft ? $this->summary($draft) : null,
                'versions' => $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('status !=', 'draft')->countAllResults(),
                'checks' => $pub ? $this->check($doc['kind'], json_decode($pub['content'], true) ?? []) : [],
                'acceptances' => $doc['requires_consent'] ? $this->acceptanceCount($doc) : null,
            ];
        }
        return $out;
    }

    public function forConsole(string $slug): ?array
    {
        $doc = $this->document($slug);
        if (!$doc) {
            return null;
        }
        $versions = $this->db->table('legal_document_version v')
            ->select('v.id, v.version, v.status, v.effective_date, v.material, v.change_note, v.created_at, v.updated_at, v.published_at, cp.full_name AS created_by, pp.full_name AS published_by')
            ->join('party cp', 'cp.id = v.created_by_party_id', 'left')->join('party pp', 'pp.id = v.published_by_party_id', 'left')
            ->where('v.document_id', $doc['id'])->orderBy('v.version', 'DESC')->get()->getResultArray();
        $pub = $this->versionRow($doc['id'], 'published');
        $draft = $this->versionRow($doc['id'], 'draft');
        return [
            'document' => $doc,
            'schema' => self::SCHEMAS[$doc['kind']] ?? [],
            'published' => $pub ? $this->full($pub, $doc['kind']) : null,
            'draft' => $draft ? $this->full($draft, $doc['kind']) : null,
            'versions' => $versions,
            'acceptances' => $doc['requires_consent'] ? $this->acceptanceCount($doc) : null,
        ];
    }

    public function versionForConsole(string $slug, int $version): ?array
    {
        $doc = $this->document($slug);
        $v = $doc ? $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('version', $version)->get()->getRowArray() : null;
        return $v ? $this->full($v, $doc['kind']) : null;
    }

    // ── Editing ──────────────────────────────────────────────────────

    // Create the draft, or replace the open one. Content is sanitised to the
    // document's schema; what comes back is what will be published.
    public function saveDraft(string $slug, $content, ?string $effectiveDate, bool $material, ?string $note, string $actorId): array
    {
        $doc = $this->requireDocument($slug);
        if (!is_array($content)) {
            throw new \RuntimeException('The document content is missing or not in the expected shape.');
        }
        if ($effectiveDate !== null && $effectiveDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            throw new \RuntimeException('Effective date must be a date (YYYY-MM-DD).');
        }
        $clean = $this->sanitize(self::SCHEMAS[$doc['kind']], $content);
        $row = [
            'content' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'effective_date' => $effectiveDate ?: null,
            'material' => ($material && $doc['requires_consent']) ? 1 : 0,
            'change_note' => $note !== null ? mb_substr(trim(strip_tags($note)), 0, 2000) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $draft = $this->versionRow($doc['id'], 'draft');
        if ($draft) {
            $this->db->table('legal_document_version')->where('id', $draft['id'])->update($row);
            $id = $draft['id'];
        } else {
            $id = Uuid::v4();
            $next = (int) ($this->db->table('legal_document_version')->selectMax('version', 'v')->where('document_id', $doc['id'])->get()->getRowArray()['v'] ?? 0) + 1;
            $this->db->table('legal_document_version')->insert($row + [
                'id' => $id, 'document_id' => $doc['id'], 'version' => $next, 'status' => 'draft', 'created_by_party_id' => $actorId,
            ]);
            (new AuditLogService())->log('legal_document.draft_created', $actorId, ['slug' => $slug, 'version' => $next]);
        }
        return $this->full($this->db->table('legal_document_version')->where('id', $id)->get()->getRowArray(), $doc['kind']);
    }

    public function publish(string $slug, string $actorId): array
    {
        $doc = $this->requireDocument($slug);
        $draft = $this->versionRow($doc['id'], 'draft');
        if (!$draft) {
            throw new \RuntimeException('There is no draft to publish.');
        }
        $errors = array_filter($this->check($doc['kind'], json_decode($draft['content'], true) ?? []), fn($c) => $c['level'] === 'error');
        if ($errors) {
            throw new \RuntimeException('Fix these before publishing: ' . implode('; ', array_map(fn($e) => $e['where'] . ' — ' . $e['message'], array_slice($errors, 0, 5))));
        }
        $now = date('Y-m-d H:i:s');
        $this->db->transStart();
        $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('status', 'published')->update(['status' => 'superseded']);
        $this->db->table('legal_document_version')->where('id', $draft['id'])->update([
            'status' => 'published', 'published_by_party_id' => $actorId, 'published_at' => $now, 'updated_at' => $now,
            'effective_date' => $draft['effective_date'] ?: date('Y-m-d'),
        ]);
        if ($doc['requires_consent'] && $draft['material']) {
            $this->db->table('legal_document')->where('id', $doc['id'])->update(['consent_version' => (int) $draft['version']]);
        }
        $this->db->transComplete();
        (new AuditLogService())->log('legal_document.published', $actorId, [
            'slug' => $slug, 'version' => (int) $draft['version'], 'material' => (bool) $draft['material'],
            'reacceptanceRequired' => (bool) ($doc['requires_consent'] && $draft['material']),
        ]);
        return $this->forConsole($slug);
    }

    public function discardDraft(string $slug, string $actorId): void
    {
        $doc = $this->requireDocument($slug);
        $draft = $this->versionRow($doc['id'], 'draft');
        if (!$draft) {
            throw new \RuntimeException('There is no draft to discard.');
        }
        // A draft was never public, so removing it erases no record.
        $this->db->table('legal_document_version')->where('id', $draft['id'])->delete();
        (new AuditLogService())->log('legal_document.draft_discarded', $actorId, ['slug' => $slug, 'version' => (int) $draft['version']]);
    }

    // Start a draft from an earlier version's content.
    public function restore(string $slug, int $version, string $actorId): array
    {
        $doc = $this->requireDocument($slug);
        $old = $this->db->table('legal_document_version')->where('document_id', $doc['id'])->where('version', $version)->get()->getRowArray();
        if (!$old || $old['status'] === 'draft') {
            throw new \RuntimeException("Version {$version} not found.");
        }
        $draft = $this->saveDraft($slug, json_decode($old['content'], true), null, false, "Restored from version {$version}.", $actorId);
        (new AuditLogService())->log('legal_document.restored_to_draft', $actorId, ['slug' => $slug, 'fromVersion' => $version]);
        return $draft;
    }

    // ── Acceptance (BR-51) ───────────────────────────────────────────

    // Documents this person still has to accept: those needing acceptance
    // whose consent_version is newer than anything they have accepted.
    public function pendingAcceptances(string $partyId): array
    {
        if ((new AuthorizationService())->isSuperAdmin($partyId)) {
            return [];
        }
        $pending = [];
        foreach ($this->db->table('legal_document')->where('requires_consent', 1)->orderBy('sort_order', 'ASC')->get()->getResultArray() as $doc) {
            $need = (int) ($doc['consent_version'] ?? 0);
            if ($need > 0 && $this->acceptedVersion($partyId, $doc['slug']) < $need) {
                $pending[] = ['slug' => $doc['slug'], 'title' => $doc['title'], 'path' => $doc['path'], 'version' => $need];
            }
        }
        return $pending;
    }

    public function accept(string $partyId, array $slugs, ?string $ip): array
    {
        $pending = $this->pendingAcceptances($partyId);
        $bySlug = array_column($pending, null, 'slug');
        $accepted = [];
        foreach ($slugs as $slug) {
            if (!isset($bySlug[$slug])) {
                continue;
            }
            $p = $bySlug[$slug];
            (new ConsentService())->recordDocumentAcceptance($partyId, $slug, (int) $p['version'], "I have read and accept the {$p['title']} (version {$p['version']}).", $ip);
            $accepted[] = $slug;
        }
        return ['accepted' => $accepted, 'pending' => $this->pendingAcceptances($partyId)];
    }

    private function acceptedVersion(string $partyId, string $slug): int
    {
        $rows = $this->db->table('consent_event')->select('terms_version')->where('party_id', $partyId)
            ->where('consent_type', 'document_acceptance')->where('related_reference_id', $slug)->get()->getResultArray();
        $max = 0;
        foreach ($rows as $r) {
            if (preg_match('/@v(\d+)$/', (string) $r['terms_version'], $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max;
    }

    private function acceptanceCount(array $doc): array
    {
        $v = (int) ($doc['consent_version'] ?? 0);
        $accepted = $v ? $this->db->table('consent_event')->select('COUNT(DISTINCT party_id) AS n', false)
            ->where('consent_type', 'document_acceptance')->where('terms_version', "{$doc['slug']}@v{$v}")->get()->getRowArray()['n'] ?? 0 : 0;
        return ['version' => $v, 'accepted' => (int) $accepted];
    }

    // ── Checks ───────────────────────────────────────────────────────

    // [{level: error|warning, where, message}] — errors block publishing.
    public function check(string $kind, array $content): array
    {
        $issues = [];
        foreach (self::REQUIRED[$kind] ?? [] as $path) {
            foreach ($this->valuesAt($content, explode('.', $path), '') as [$where, $value]) {
                if (trim(strip_tags((string) $value)) === '') {
                    $issues[] = ['level' => 'error', 'where' => $where, 'message' => 'is empty'];
                }
            }
        }
        $this->scanRetired($content, '', $issues);
        return $issues;
    }

    private function scanRetired($node, string $path, array &$issues): void
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $this->scanRetired($v, is_int($k) ? $path . '[' . ($k + 1) . ']' : ltrim($path . '.' . $k, '.'), $issues);
            }
            return;
        }
        if (!is_string($node)) {
            return;
        }
        $text = html_entity_decode(strip_tags($node));
        foreach (self::RETIRED as $re => $msg) {
            if (preg_match($re, $text, $m)) {
                $issues[] = ['level' => 'warning', 'where' => $path, 'message' => $msg . ' (found "' . $m[0] . '")'];
            }
        }
    }

    private function valuesAt($node, array $parts, string $prefix): array
    {
        if (!$parts) {
            return [[$prefix, $node]];
        }
        $part = array_shift($parts);
        if ($part === '*') {
            $out = [];
            foreach ((array) $node as $i => $child) {
                $out = array_merge($out, $this->valuesAt($child, $parts, $prefix . '[' . ($i + 1) . ']'));
            }
            return $out;
        }
        return $this->valuesAt(is_array($node) ? ($node[$part] ?? '') : '', $parts, ltrim($prefix . '.' . $part, '.'));
    }

    // ── Sanitising ───────────────────────────────────────────────────

    private function sanitize(array $schema, array $data): array
    {
        $out = [];
        foreach ($schema as $key => $type) {
            $v = $data[$key] ?? null;
            if (is_array($type) && isset($type['list'])) {
                $out[$key] = array_values(array_map(fn($item) => $this->sanitize($type['list'], is_array($item) ? $item : []), is_array($v) ? $v : []));
            } elseif (is_array($type) && isset($type['listOf'])) {
                $out[$key] = array_values(array_filter(array_map(fn($x) => $this->text($x), is_array($v) ? $v : []), fn($x) => $x !== ''));
            } else {
                $out[$key] = match ($type) {
                    'html' => $this->html((string) ($v ?? '')),
                    'bool' => (bool) $v,
                    'int' => max(480, min(1400, (int) ($v ?: 760))),
                    'path' => preg_match('#^/[A-Za-z0-9/_-]*$#', (string) $v) ? (string) $v : '/trust-and-support',
                    'slug' => trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $v)), '-') ?: substr(md5(json_encode($data)), 0, 8),
                    default => $this->text($v),
                };
            }
        }
        return $out;
    }

    private function text($v): string
    {
        return trim(html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // Keep only simple formatting: paragraphs, bold/italic, lists, h3/h4,
    // tables and links to http(s), mailto, site paths or #anchors. Every
    // attribute but href is dropped; scripts and the like go with their text.
    public function html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('__root') ?? $doc->documentElement;
        $this->cleanNode($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private function cleanNode(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }
            $this->cleanNode($child);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->nodeName);
            }
            if ($tag === 'a' && preg_match('~^(https?://|mailto:|/(?!/)|#)~i', $href)) {
                $child->setAttribute('href', $href);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function requireDocument(string $slug): array
    {
        $doc = $this->document($slug);
        if (!$doc) {
            throw new \RuntimeException('Document not found.');
        }
        return $doc;
    }

    private function versionRow(string $docId, string $status): ?array
    {
        return $this->db->table('legal_document_version')->where('document_id', $docId)->where('status', $status)
            ->orderBy('version', 'DESC')->get()->getRowArray();
    }

    private function summary(array $v): array
    {
        return ['version' => (int) $v['version'], 'effective_date' => $v['effective_date'], 'material' => (bool) $v['material'],
            'published_at' => $v['published_at'], 'updated_at' => $v['updated_at'] ?? null, 'created_at' => $v['created_at']];
    }

    private function full(array $v, string $kind): array
    {
        $content = json_decode($v['content'], true) ?? [];
        return $this->summary($v) + ['status' => $v['status'], 'change_note' => $v['change_note'], 'content' => $content, 'checks' => $this->check($kind, $content)];
    }
}
