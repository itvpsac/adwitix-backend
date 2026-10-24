<?php

namespace App\Controllers;

use App\Libraries\LegalDocumentService;
use App\Libraries\UserAuthContext;

// D-137: published legal/help documents (public), the Custodian's
// Documents page (jwtSuperAdmin), and a user's acceptance of material
// changes (jwtAuth, BR-51).
class LegalDocumentController extends BaseController
{
    private LegalDocumentService $docs;

    public function __construct()
    {
        $this->docs = new LegalDocumentService();
    }

    // GET /legal/:slug — the published version a public page shows.
    public function show(string $slug)
    {
        $doc = $this->docs->publicDocument($slug);
        return $doc ? $this->apiResponse($doc) : $this->jsonError(404, 'not_found', 'Document not found.');
    }

    // GET /admin/legal
    public function index()
    {
        return $this->apiResponse(['documents' => $this->docs->listForConsole()]);
    }

    // GET /admin/legal/:slug
    public function detail(string $slug)
    {
        $d = $this->docs->forConsole($slug);
        return $d ? $this->apiResponse($d) : $this->jsonError(404, 'not_found', 'Document not found.');
    }

    // GET /admin/legal/:slug/versions/:n
    public function version(string $slug, string $n)
    {
        $v = $this->docs->versionForConsole($slug, (int) $n);
        return $v ? $this->apiResponse(['version' => $v]) : $this->jsonError(404, 'not_found', 'Version not found.');
    }

    // POST /admin/legal/:slug/draft {content, effective_date, material, change_note}
    public function saveDraft(string $slug)
    {
        return $this->attempt(fn() => ['draft' => $this->docs->saveDraft(
            $slug, $this->request->getJsonVar('content', true), $this->input('effective_date'),
            filter_var($this->input('material'), FILTER_VALIDATE_BOOLEAN), $this->input('change_note'), UserAuthContext::partyId()
        ), 'message' => 'Draft saved.']);
    }

    // POST /admin/legal/:slug/publish
    public function publish(string $slug)
    {
        return $this->attempt(fn() => $this->docs->publish($slug, UserAuthContext::partyId()) + ['message' => 'Published.']);
    }

    // POST /admin/legal/:slug/discard-draft
    public function discardDraft(string $slug)
    {
        return $this->attempt(function () use ($slug) {
            $this->docs->discardDraft($slug, UserAuthContext::partyId());
            return ['message' => 'Draft discarded.'];
        });
    }

    // POST /admin/legal/:slug/restore/:n — a new draft from version n.
    public function restore(string $slug, string $n)
    {
        return $this->attempt(fn() => ['draft' => $this->docs->restore($slug, (int) $n, UserAuthContext::partyId()),
            'message' => "Version {$n} copied into a draft — review it, then publish."]);
    }

    // POST /me/accept-documents {slugs: [...]}
    public function accept()
    {
        $slugs = $this->request->getJsonVar('slugs', true);
        return $this->attempt(fn() => $this->docs->accept(UserAuthContext::partyId(), is_array($slugs) ? $slugs : [], $this->request->getIPAddress()));
    }

    private function attempt(callable $fn)
    {
        try {
            return $this->apiResponse($fn());
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'legal_document', $e->getMessage());
        }
    }
}
