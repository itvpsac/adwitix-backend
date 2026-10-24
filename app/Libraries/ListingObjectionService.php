<?php

namespace App\Libraries;

use App\Models\ListingModel;

// D-140: the Custodian's objection to a Lot.
//
// The rules: the Custodian never edits a seller's Lot (BR-15) — content
// belongs to the seller and approval to the TSX Master (BR-13). What the
// Custodian can do is object. An objection puts the Lot on hold at once:
// any live Trading Session is stopped (bids withdrawn, deposits released,
// as an emergency stop, BR-14) and nothing can be approved, scheduled or
// sold until the objection is lifted. There is no deadline — the Lot simply
// stays on hold until it is put right, which the seller is told plainly and
// must acknowledge. The seller corrects the Lot and submits the fix; the
// Custodian lifts the hold (or sends it back with a note); the Lot then
// returns for the TSX Master's approval. A stock-photo objection is a CBS
// violation (BR-59/BR-61) unless an active media waiver covers the Lot's
// category on its TradeSphereX (BR-60).
class ListingObjectionService
{
    public const REASONS = [
        'unclear_description' => ['Unclear or incomplete description', 'Rewrite the title and description so a buyer can tell exactly what is being sold: what it is, its make and model, its condition and what is included.'],
        'misleading' => ['Misleading, or does not match the photos', 'Correct the details so they match the actual item and its photos.'],
        'stock_images' => ['Stock, catalogue or generated photos', 'Remove every photo that is not a real photo of this actual item, and upload genuine photos of it (at least 5, one marked as the main photo).'],
        'insufficient_media' => ['Photos missing, unclear or not enough', 'Add clear photos of the actual item from several sides, and mark the best one as the main photo.'],
        'wrong_category' => ['Wrong category', 'Move the Lot to the category that describes it.'],
        'prohibited_item' => ['Item not allowed on the platform', 'ADWITIX only lists salvage, used, surplus, repossessed and similar non-new goods (BR-07). If this item is new retail stock it cannot be listed — tell the Custodian in your fix note if you believe this is a mistake.'],
        'objectionable_content' => ['Objectionable content', 'Remove the offensive or inappropriate words or images.'],
        'other' => ['Other', 'Make the change described in the Custodian\'s note.'],
    ];
    // Seller-correctable fields (in place: the Lot is on hold, so no bidder
    // relies on its current text — every change is logged from/to).
    public const FIXABLE = ['title', 'description', 'category', 'subcategory', 'micro_category', 'make_model', 'physical_condition',
        'quantity', 'yard_location_address', 'yard_location_pin', 'city', 'state'];
    private const LIVE_SESSION = ['pending_approval', 'grace_period', 'active'];
    private const CLOSED_LOT = ['sold', 'cycle_ended_unsold', 'suspended', 'on_hold'];

    private \CodeIgniter\Database\BaseConnection $db;
    private ListingModel $listings;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->listings = new ListingModel();
    }

    public static function reasonLabel(string $key): string
    {
        return self::REASONS[$key][0] ?? $key;
    }

    public function raise(string $listingId, string $reasonKey, string $note, string $actorId): array
    {
        if (!isset(self::REASONS[$reasonKey])) {
            throw new \RuntimeException('Choose a reason from the list.');
        }
        $note = trim(strip_tags($note));
        if ($note === '') {
            throw new \RuntimeException('Say what is wrong, so the seller knows what to fix.');
        }
        $lot = $this->listings->find($listingId);
        if (!$lot || !empty($lot['archived_at'])) {
            throw new \RuntimeException('Lot not found.');
        }
        if (in_array($lot['status'], self::CLOSED_LOT, true)) {
            throw new \RuntimeException($lot['status'] === 'on_hold' ? 'This Lot is already on hold for an objection.' : 'This Lot is ' . str_replace('_', ' ', $lot['status']) . ' — there is nothing to hold.');
        }

        $lifecycle = new ListingLifecycleService();
        $stopped = 0;
        foreach ($this->db->table('sale_event')->select('id')->where('listing_id', $listingId)->whereIn('status', self::LIVE_SESSION)->get()->getResultArray() as $se) {
            $lifecycle->emergencyStop($se['id'], 'Lot put on hold by the Custodian: ' . self::reasonLabel($reasonKey), $actorId);
            $stopped++;
        }

        // BR-60: an active waiver for this category on this TSX means
        // representative images are allowed — no violation is recorded.
        $waiver = null;
        $offense = null;
        if ($reasonKey === 'stock_images') {
            $waiver = $this->db->table('tenant_media_waiver')->where('tenant_id', $lot['tenant_id'])->where('category', $lot['category'])
                ->where('status', 'approved')->where('expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();
            if (!$waiver) {
                $offense = (new StandingReviewService())->recordCbsViolation($lot['seller_party_id'], $actorId, $listingId)['offenseNumber'];
            }
        }

        $id = Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $this->db->table('listing_objection')->insert([
            'id' => $id, 'listing_id' => $listingId, 'tenant_id' => $lot['tenant_id'], 'seller_party_id' => $lot['seller_party_id'],
            'reason_key' => $reasonKey, 'note' => mb_substr($note, 0, 2000), 'status' => 'open', 'listing_status_before' => $lot['status'],
            'sessions_stopped' => $stopped, 'cbs_offense_number' => $offense, 'waiver_id' => $waiver['id'] ?? null,
            'raised_by_party_id' => $actorId, 'raised_at' => $now,
        ]);
        $this->listings->update($listingId, ['status' => 'on_hold', 'updated_at' => $now]);
        (new AuditLogService())->log('listing.objection_raised', $actorId, [
            'listingId' => $listingId, 'objectionId' => $id, 'reason' => $reasonKey, 'note' => $note, 'statusBefore' => $lot['status'],
            'sessionsStopped' => $stopped, 'cbsOffense' => $offense, 'waiverId' => $waiver['id'] ?? null,
        ]);
        $this->notify($lot, $reasonKey, $note);
        return $this->get($id);
    }

    // The seller confirms they have read and understood what the hold means.
    public function acknowledge(string $objectionId, string $partyId): array
    {
        $o = $this->sellerObjection($objectionId, $partyId);
        if (!$o['acknowledged_at']) {
            $this->db->table('listing_objection')->where('id', $objectionId)->update(['acknowledged_at' => date('Y-m-d H:i:s')]);
            (new AuditLogService())->log('listing.objection_acknowledged', $partyId, ['objectionId' => $objectionId, 'listingId' => $o['listing_id']]);
        }
        return $this->get($objectionId);
    }

    // The seller corrects the Lot's details while it is on hold.
    public function fixDetails(string $listingId, array $input, string $partyId): array
    {
        $o = $this->openFor($listingId);
        if (!$o || $o['seller_party_id'] !== $partyId) {
            throw new \RuntimeException('Only the seller can correct a Lot that is on hold.');
        }
        if ($o['status'] !== 'open') {
            throw new \RuntimeException('Your fix is with the Custodian for review. If it is sent back, you can change the Lot again.');
        }
        $lot = $this->listings->find($listingId);
        $changes = [];
        foreach (self::FIXABLE as $f) {
            if (!array_key_exists($f, $input) || $input[$f] === null) {
                continue;
            }
            $v = trim(strip_tags((string) $input[$f]));
            if ($f === 'category' && $v !== (string) $lot['category'] && !in_array($v, ListingLifecycleService::PERMITTED_CATEGORIES, true)) {
                throw new \RuntimeException('BR-07: choose one of the platform\'s permitted categories.');
            }
            if (in_array($f, ['title', 'category', 'physical_condition', 'quantity'], true) && $v === '') {
                throw new \RuntimeException(ucfirst(str_replace('_', ' ', $f)) . ' cannot be empty.');
            }
            if ($f === 'quantity' && (!is_numeric($v) || (float) $v <= 0)) {
                throw new \RuntimeException('Quantity must be a number above 0.');
            }
            if ($f === 'yard_location_pin' && $v !== '' && !preg_match('/^\d{6}$/', $v)) {
                throw new \RuntimeException('PIN code must be 6 digits.');
            }
            $v = $v === '' ? null : mb_substr($v, 0, $f === 'description' ? 5000 : 255);
            if ((string) ($lot[$f] ?? '') !== (string) ($v ?? '')) {
                $changes[$f] = ['from' => $lot[$f] ?? null, 'to' => $v];
            }
        }
        if (!$changes) {
            throw new \RuntimeException('Nothing was changed.');
        }
        $this->listings->update($listingId, array_map(fn($c) => $c['to'], $changes) + ['updated_at' => date('Y-m-d H:i:s')]);
        (new AuditLogService())->log('listing.corrected_on_hold', $partyId, ['listingId' => $listingId, 'objectionId' => $o['id'], 'changes' => $changes]);
        return $this->listings->find($listingId);
    }

    public function submitFix(string $objectionId, string $note, string $partyId): array
    {
        $o = $this->sellerObjection($objectionId, $partyId);
        if ($o['status'] !== 'open') {
            throw new \RuntimeException('This objection is not waiting for a fix.');
        }
        $note = trim(strip_tags($note));
        if ($note === '') {
            throw new \RuntimeException('Tell the Custodian what you changed.');
        }
        $this->db->table('listing_objection')->where('id', $objectionId)->update([
            'status' => 'fix_submitted', 'fix_note' => mb_substr($note, 0, 2000), 'fix_submitted_at' => date('Y-m-d H:i:s'),
            'acknowledged_at' => $o['acknowledged_at'] ?: date('Y-m-d H:i:s'),
        ]);
        (new AuditLogService())->log('listing.objection_fix_submitted', $partyId, ['objectionId' => $objectionId, 'listingId' => $o['listing_id'], 'note' => $note]);
        return $this->get($objectionId);
    }

    // The Custodian lifts the hold, or sends the Lot back with a note.
    public function review(string $objectionId, string $decision, string $note, string $actorId): array
    {
        $o = $this->get($objectionId);
        if (!$o || !in_array($o['status'], ['open', 'fix_submitted'], true)) {
            throw new \RuntimeException('This objection is already closed.');
        }
        $note = trim(strip_tags($note));
        $now = date('Y-m-d H:i:s');
        if ($decision === 'return') {
            if ($o['status'] !== 'fix_submitted') {
                throw new \RuntimeException('The seller has not submitted a fix yet.');
            }
            if ($note === '') {
                throw new \RuntimeException('Say what still needs fixing.');
            }
            $this->db->table('listing_objection')->where('id', $objectionId)->update([
                'status' => 'open', 'review_note' => mb_substr($note, 0, 2000), 'returned_count' => (int) $o['returned_count'] + 1,
            ]);
            (new AuditLogService())->log('listing.objection_fix_returned', $actorId, ['objectionId' => $objectionId, 'listingId' => $o['listing_id'], 'note' => $note]);
            return $this->get($objectionId);
        }
        if (!in_array($decision, ['lift', 'withdraw'], true)) {
            throw new \RuntimeException('Unknown decision.');
        }
        if ($decision === 'withdraw' && $note === '') {
            throw new \RuntimeException('Say why the objection is withdrawn.');
        }
        // Back for the TSX Master's approval (BR-13) — or to the seller's
        // inventory if it had not been submitted yet.
        $next = $o['listing_status_before'] === 'inventory' ? 'inventory' : 'pending_approval';
        $this->db->table('listing_objection')->where('id', $objectionId)->update([
            'status' => $decision === 'lift' ? 'resolved' : 'withdrawn', 'review_note' => $note ?: null, 'closed_by_party_id' => $actorId, 'closed_at' => $now,
        ]);
        // Only a Lot still on hold moves on — one stopped or suspended in the
        // meantime stays as it is.
        $lot = $this->listings->find($o['listing_id']);
        if ($lot && $lot['status'] === 'on_hold') {
            $this->listings->update($o['listing_id'], ['status' => $next, 'updated_at' => $now]);
        } else {
            $next = $lot['status'] ?? $next;
        }
        (new AuditLogService())->log($decision === 'lift' ? 'listing.objection_lifted' : 'listing.objection_withdrawn', $actorId, [
            'objectionId' => $objectionId, 'listingId' => $o['listing_id'], 'note' => $note, 'lotStatusNow' => $next,
        ]);
        return $this->get($objectionId);
    }

    public function get(string $id): ?array
    {
        $o = $this->db->table('listing_objection')->where('id', $id)->get()->getRowArray();
        return $o ? $this->present($o) : null;
    }

    public function openFor(string $listingId): ?array
    {
        $o = $this->db->table('listing_objection')->where('listing_id', $listingId)->whereIn('status', ['open', 'fix_submitted'])
            ->orderBy('raised_at', 'DESC')->get()->getRowArray();
        return $o ? $this->present($o) : null;
    }

    public function forListing(string $listingId): array
    {
        return array_map(fn($o) => $this->present($o), $this->db->table('listing_objection')->where('listing_id', $listingId)->orderBy('raised_at', 'DESC')->get()->getResultArray());
    }

    // A seller's Lots on hold, with the Lot's title — for the dashboard and
    // the must-read notice.
    public function openForSeller(string $partyId): array
    {
        $rows = $this->db->table('listing_objection o')->select('o.*, l.title, l.category, l.make_model')
            ->join('listing l', 'l.id = o.listing_id')->where('o.seller_party_id', $partyId)->whereIn('o.status', ['open', 'fix_submitted'])
            ->orderBy('o.raised_at', 'ASC')->get()->getResultArray();
        return array_map(fn($o) => $this->present($o) + ['lot_title' => $o['title'] ?: ($o['make_model'] ?: $o['category'])], $rows);
    }

    private function present(array $o): array
    {
        $o['reason_label'] = self::reasonLabel($o['reason_key']);
        $o['what_to_fix'] = self::REASONS[$o['reason_key']][1] ?? '';
        return $o;
    }

    private function sellerObjection(string $objectionId, string $partyId): array
    {
        $o = $this->get($objectionId);
        if (!$o || $o['seller_party_id'] !== $partyId) {
            throw new \RuntimeException('Objection not found.');
        }
        return $o;
    }

    // Email the seller and the TSX Master where an address is on file
    // (best effort — the in-app notice the seller must acknowledge is the
    // guaranteed channel).
    private function notify(array $lot, string $reasonKey, string $note): void
    {
        $title = $lot['title'] ?: $lot['category'];
        $body = "The Lot \"{$title}\" has been put on hold by the ADWITIX Custodian.\n\n"
            . 'Reason: ' . self::reasonLabel($reasonKey) . "\nNote: {$note}\n\n"
            . "While it is on hold it cannot be approved, scheduled or sold, and any live Trading Session on it has been stopped (bids withdrawn, deposits released). "
            . "There is no deadline: the Lot stays on hold until it is corrected. The seller can correct it from the Lot's page and submit the fix; "
            . "once the Custodian lifts the hold, the TSX Master approves it again before it can be sold.";
        $mail = new EmailNotificationService();
        $seller = $this->db->table('party')->select('recovery_email')->where('id', $lot['seller_party_id'])->get()->getRowArray();
        if (!empty($seller['recovery_email'])) {
            $mail->send($seller['recovery_email'], "Your Lot \"{$title}\" is on hold", $body);
        }
        foreach ($this->db->table('party_role r')->select('p.recovery_email')->join('party p', 'p.id = r.party_id')
            ->where('r.tenant_id', $lot['tenant_id'])->where('r.role', 'tenant_admin')->where('r.revoked_at', null)->get()->getResultArray() as $m) {
            if (!empty($m['recovery_email'])) {
                $mail->send($m['recovery_email'], "A Lot on your TradeSphereX is on hold: \"{$title}\"", $body);
            }
        }
    }
}
