<?php

namespace App\Controllers;

use App\Libraries\UserAuthContext;
use App\Models\TenantModel;
use App\Models\DisputeModel;
use App\Models\SettlementModel;
use App\Models\AmlFlagModel;

// All routes behind jwtSuperAdmin.
class AdminController extends BaseController
{
    public function dashboard()
    {
        $tenantModel = new TenantModel();
        $disputeModel = new DisputeModel();
        $settlementModel = new SettlementModel();
        $amlFlagModel = new AmlFlagModel();

        // BR-49: "included in the Super Admin's audit console" —
        // platform-wide, not scoped to any single tenant.
        $db = \Config\Database::connect();
        $highValueDisposals = $db->table('high_value_disposal_record hvdr')
            ->select('hvdr.*, t.name as tenant_name')
            ->join('tenant t', 't.id = hvdr.tenant_id')
            ->orderBy('hvdr.created_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $todayStart = date('Y-m-d 00:00:00');

        $listingsByFormatToday = $db->table('sale_event')
            ->select('sale_format, COUNT(*) as cnt')
            ->where('created_at >=', $todayStart)
            ->groupBy('sale_format')
            ->get()->getResultArray();

        $bidsPlacedToday = $db->table('bid')->where('placed_at >=', $todayStart)->countAllResults();
        $salesClosedToday = $db->table('settlement')->where('created_at >=', $todayStart)->where('status', 'completed')->countAllResults();
        $disputesFiledToday = $disputeModel->where('created_at >=', $todayStart)->countAllResults();

        // Aging on stalled settlements — how long each has sat unresolved,
        // oldest first, so Super Admin can see what needs escalation.
        // Days stalled computed here, not in SQL: the Postgres-only
        // EXTRACT(DAY FROM now() - ...) form doesn't run on MySQL.
        $stalledAging = $db->table('settlement s')
            ->select('s.id, s.final_price, s.created_at, s.stall_flagged_at')
            ->where('s.status', 'stalled')
            ->orderBy('s.created_at', 'ASC')
            ->limit(20)
            ->get()->getResultArray();
        foreach ($stalledAging as &$row) {
            // Stalled since it was flagged (the sale itself may be older).
            $row['stalled_since'] = $row['stall_flagged_at'] ?: $row['created_at'];
            $row['days_stalled'] = (int) floor((time() - strtotime($row['stalled_since'])) / 86400);
        }
        unset($row);

        // Newest sign-ups, for the dashboard's Users preview (the full
        // list is the User Directory).
        $recentUsers = $db->table('party')
            ->select('id, mobile_number, full_name, entity_type, kyc_status, star_rating, seller_star_rating, seller_delisted_at, shadow_banned_at_buyer, shadow_banned_at_seller, created_at')
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        return $this->apiResponse([
            'tenants' => $tenantModel->salvageManagersFirst()->findAll(),
            'openDisputes' => $disputeModel->whereIn('status', ['filed', 'evidence_window', 'appealed'])->countAllResults(),
            'stalledSettlements' => $settlementModel->where('status', 'stalled')->countAllResults(),
            'openAmlFlags' => $amlFlagModel->where('status', 'open')->countAllResults(),
            'highValueDisposals' => $highValueDisposals,
            'listingsByFormatToday' => $listingsByFormatToday,
            'bidsPlacedToday' => $bidsPlacedToday,
            'salesClosedToday' => $salesClosedToday,
            'disputesFiledToday' => $disputesFiledToday,
            'stalledAging' => $stalledAging,
            'recentUsers' => $recentUsers,
        ]);
    }

    // Aggregates the same "needs attention" signals that were already
    // scattered across separate admin pages (AML flags, stalled
    // settlements, open disputes, pending payout reviews, pending rating
    // reviews) into one triage view — no new underlying data, just one
    // place to see everything queued for a decision.
    public function alerts()
    {
        $db = \Config\Database::connect();

        $amlFlags = $db->table('aml_flag af')
            ->select('af.*, p.mobile_number')
            ->join('party p', 'p.id = af.party_id')
            ->where('af.status', 'open')
            ->orderBy('af.created_at', 'DESC')->get()->getResultArray();

        $stalledSettlements = $db->table('settlement')->where('status', 'stalled')->orderBy('created_at', 'ASC')->get()->getResultArray();

        $openDisputes = $db->table('dispute')
            ->whereIn('status', ['filed', 'evidence_window', 'appealed'])
            ->orderBy('created_at', 'ASC')->get()->getResultArray();

        $pendingPayoutReviews = $db->table('payout_release_review')->where('status', 'pending')->orderBy('created_at', 'ASC')->get()->getResultArray();

        $pendingRatingReviews = (new \App\Models\RatingEventModel())->findAllPending();

        // Tech Stack §3.10: unacknowledged server-time drift alerts.
        $driftAlerts = (new \App\Models\ServerTimeCheckModel())->findUnacknowledgedDriftAlerts();

        return $this->apiResponse([
            'amlFlags' => $amlFlags,
            'stalledSettlements' => $stalledSettlements,
            'openDisputes' => $openDisputes,
            'pendingPayoutReviews' => $pendingPayoutReviews,
            'pendingRatingReviews' => $pendingRatingReviews,
            'driftAlerts' => $driftAlerts,
        ]);
    }

    // Tech Stack §3.10: Super Admin acknowledges a server-time drift
    // alert, clearing it from the Alerts list.
    public function acknowledgeServerTimeDrift(string $checkId)
    {
        try {
            (new \App\Libraries\ServerTimeIntegrityService())->acknowledge($checkId, UserAuthContext::partyId());
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'acknowledge_failed', $e->getMessage());
        }
        return $this->apiResponse(['message' => 'Drift alert acknowledged.']);
    }

    // D-106: "Lot Directory" -- the Custodian had no way to browse every
    // listing platform-wide across every Tenant; only per-tenant pending
    // queues existed.
    public function lotDirectory()
    {
        $q = trim((string) $this->request->getGet('q'));
        $tenantId = $this->request->getGet('tenant_id');
        $format = $this->request->getGet('format');
        $status = $this->request->getGet('status');
        $pg = \App\Libraries\Paginator::fromRequest($this->request);

        $svc = new \App\Libraries\AdminDirectoryService();
        $qOrNull = $q !== '' ? $q : null;
        $total = $svc->countListings($qOrNull, $tenantId, $format, $status);
        $listings = $svc->findListings($qOrNull, $tenantId, $format, $status, $pg['perPage'], $pg['offset']);
        $tenants = (new TenantModel())->salvageManagersFirst()->findAll();

        return $this->apiResponse([
            'listings' => $listings, 'tenants' => $tenants,
            'q' => $q, 'tenantId' => $tenantId, 'format' => $format, 'status' => $status,
            'page' => $pg['page'], 'perPage' => $pg['perPage'],
            'totalPages' => \App\Libraries\Paginator::totalPages($total, $pg['perPage']), 'total' => $total,
        ]);
    }

    // D-106: "Trading Session Directory" -- same gap, for sale events
    // rather than listings.
    public function tradingSessionDirectory()
    {
        $tenantId = $this->request->getGet('tenant_id');
        $format = $this->request->getGet('format');
        $status = $this->request->getGet('status');
        $pg = \App\Libraries\Paginator::fromRequest($this->request);

        $svc = new \App\Libraries\AdminDirectoryService();
        $total = $svc->countSaleEvents($tenantId, $format, $status);
        $saleEvents = $svc->findSaleEvents($tenantId, $format, $status, $pg['perPage'], $pg['offset']);
        $tenants = (new TenantModel())->salvageManagersFirst()->findAll();

        return $this->apiResponse([
            'saleEvents' => $saleEvents, 'tenants' => $tenants,
            'tenantId' => $tenantId, 'format' => $format, 'status' => $status,
            'page' => $pg['page'], 'perPage' => $pg['perPage'],
            'totalPages' => \App\Libraries\Paginator::totalPages($total, $pg['perPage']), 'total' => $total,
        ]);
    }

    // Custodian Console › Decision Queue: every item waiting on a decision,
    // across all queues, oldest first. `owner` says who can decide it:
    // 'custodian' items are acted on here; 'tsx_master' items (lot and
    // seller approvals, BR-09/BR-13) are shown so the Custodian can see
    // the backlog, but only the tenant's own TSX Master can approve them.
    // D-132: the Custodian watches Lots and Trading Sessions but never
    // approves them — that stays with each TSX Master (BR-09/BR-13). What
    // the Custodian can do is stop any of them, with an audited reason.
    private const LIVE_SESSION = ['pending_approval', 'grace_period', 'active'];

    // POST /admin/sale-events/:id/stop {reason}: BR-14 emergency stop —
    // cancels the session, withdraws bids, releases every EMD hold.
    public function stopSaleEvent(string $saleEventId)
    {
        $reason = trim((string) $this->input('reason'));
        if ($reason === '') {
            return $this->jsonError(422, 'reason_required', 'Give a reason for stopping this Trading Session.');
        }
        $se = (new \App\Models\SaleEventModel())->find($saleEventId);
        if (!$se) {
            return $this->jsonError(404, 'not_found', 'Trading Session not found.');
        }
        if (!in_array($se['status'], self::LIVE_SESSION, true)) {
            return $this->jsonError(409, 'not_live', 'This Trading Session has already ended (' . str_replace('_', ' ', $se['status']) . ').');
        }
        try {
            $saleEvent = (new \App\Libraries\ListingLifecycleService())->emergencyStop($saleEventId, "Custodian: {$reason}", UserAuthContext::partyId());
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'stop_failed', $e->getMessage());
        }
        return $this->apiResponse(['saleEvent' => $saleEvent, 'message' => 'Trading Session stopped; bids withdrawn and deposits released.']);
    }

    // POST /admin/listings/:id/stop {reason}: stops a Lot — any live
    // Trading Session on it is emergency-stopped first, then the Lot is
    // suspended with the reason shown to its seller.
    public function stopListing(string $listingId)
    {
        $reason = trim((string) $this->input('reason'));
        if ($reason === '') {
            return $this->jsonError(422, 'reason_required', 'Give a reason for stopping this Lot.');
        }
        $listingModel = new \App\Models\ListingModel();
        $listing = $listingModel->find($listingId);
        if (!$listing) {
            return $this->jsonError(404, 'not_found', 'Lot not found.');
        }
        if (in_array($listing['status'], ['sold', 'cycle_ended_unsold', 'suspended'], true)) {
            return $this->jsonError(409, 'not_live', 'This Lot is already ' . str_replace('_', ' ', $listing['status']) . '.');
        }
        $actor = UserAuthContext::partyId();
        $lifecycle = new \App\Libraries\ListingLifecycleService();
        $stopped = [];
        foreach ((new \App\Models\SaleEventModel())->where('listing_id', $listingId)->whereIn('status', self::LIVE_SESSION)->findAll() as $se) {
            $lifecycle->emergencyStop($se['id'], "Custodian stopped the Lot: {$reason}", $actor);
            $stopped[] = $se['id'];
        }
        $listingModel->update($listingId, ['status' => 'suspended', 'rejection_reason' => "Stopped by the Custodian: {$reason}", 'updated_at' => date('Y-m-d H:i:s')]);
        (new \App\Libraries\AuditLogService())->log('listing.stopped_by_custodian', $actor, [
            'listingId' => $listingId, 'reason' => $reason, 'saleEventsStopped' => $stopped,
        ]);
        return $this->apiResponse(['listing' => $listingModel->find($listingId), 'saleEventsStopped' => count($stopped),
            'message' => 'Lot stopped' . ($stopped ? ' and its live Trading Session cancelled.' : '.')]);
    }

    // GET /admin/listings/:id/dossier (D-133): everything about one Lot for
    // the Custodian — nothing withheld. The Lot (archived or not) and the
    // versions it superseded or was superseded by, its seller, TradeSphereX
    // and TSX Masters, media, every Trading Session with its bids, offers,
    // deposits, Tender interest, settlement, disputes and chronicle, the
    // engagement figures, and every audit-chain entry that names it.
    public function lotDossier(string $listingId)
    {
        $db = \Config\Database::connect();
        $listing = $db->table('listing')->where('id', $listingId)->get()->getRowArray();
        if (!$listing) {
            return $this->jsonError(404, 'not_found', 'Lot not found.');
        }
        // Whole party rows: the Custodian sees everything. ApiResponse still
        // strips credential columns (mpin_hash, totp_secret, …).
        $partyCols = '*';
        $rows = fn($table, $col, $ids, $order) => $ids ? $db->table($table)->whereIn($col, $ids)->orderBy($order, 'ASC')->get()->getResultArray() : [];

        $sessions = $db->table('sale_event')->where('listing_id', $listingId)->orderBy('created_at', 'ASC')->get()->getResultArray();
        $seIds = array_column($sessions, 'id');
        $bids = $rows('bid', 'sale_event_id', $seIds, 'placed_at');
        $offers = $rows('offer', 'sale_event_id', $seIds, 'created_at');
        $holds = $rows('emd_hold', 'sale_event_id', $seIds, 'held_at');
        $interest = $rows('tender_interest', 'sale_event_id', $seIds, 'registered_at');
        $settlements = $rows('settlement', 'sale_event_id', $seIds, 'created_at');
        $disputes = $rows('dispute', 'sale_event_id', $seIds, 'created_at');
        $chronicles = $seIds ? $db->table('trading_session_chronicle')->select('id, sale_event_id, settlement_id, reference_number, version, superseded_by_chronicle_id, generated_at')
            ->whereIn('sale_event_id', $seIds)->orderBy('generated_at', 'ASC')->get()->getResultArray() : [];

        // BR-16 (kept, D-134): while a session is live the Custodian is blind
        // to who is bidding — amounts and timings show, bidders read as
        // "Bidder 1, 2, …" (stable within the session). Names unlock at close.
        $mask = [];
        $relabel = function (array &$list, string $col, string $seId, int &$n) use (&$mask) {
            foreach ($list as &$r) {
                if ($r['sale_event_id'] === $seId && !empty($r[$col])) {
                    $mask[$seId][$r[$col]] ??= 'Bidder ' . (++$n);
                    $r[$col] = $mask[$seId][$r[$col]];
                }
            }
        };
        foreach ($sessions as &$se) {
            if (!in_array($se['status'], self::LIVE_SESSION, true)) {
                continue;
            }
            $n = 0;
            $relabel($bids, 'bidder_party_id', $se['id'], $n);
            $relabel($offers, 'buyer_party_id', $se['id'], $n);
            $relabel($holds, 'party_id', $se['id'], $n);
            $relabel($interest, 'party_id', $se['id'], $n);
            if (!empty($se['current_high_bidder_party_id'])) {
                $mask[$se['id']][$se['current_high_bidder_party_id']] ??= 'Bidder ' . (++$n);
                $se['current_high_bidder_party_id'] = $mask[$se['id']][$se['current_high_bidder_party_id']];
            }
            $se['identities_hidden_until_close'] = true;
        }
        unset($se);

        $byEvent = fn(array $list) => array_reduce($list, function ($acc, $r) { $acc[$r['sale_event_id']][] = $r; return $acc; }, []);
        [$bidsBy, $offersBy, $holdsBy, $interestBy, $settleBy, $disputeBy, $chronBy] = array_map($byEvent, [$bids, $offers, $holds, $interest, $settlements, $disputes, $chronicles]);
        foreach ($sessions as &$se) {
            $id = $se['id'];
            $se += ['bids' => $bidsBy[$id] ?? [], 'offers' => $offersBy[$id] ?? [], 'emdHolds' => $holdsBy[$id] ?? [],
                'tenderInterest' => $interestBy[$id] ?? [], 'settlements' => $settleBy[$id] ?? [], 'disputes' => $disputeBy[$id] ?? [], 'chronicles' => $chronBy[$id] ?? []];
        }
        unset($se);

        // Everyone the file mentions, by id, so the page can name them.
        $partyIds = array_merge([$listing['seller_party_id']],
            array_column($bids, 'bidder_party_id'), array_column($offers, 'buyer_party_id'), array_column($holds, 'party_id'),
            array_column($interest, 'party_id'), array_column($settlements, 'buyer_party_id'),
            array_column($disputes, 'filed_by_party_id'), array_column($disputes, 'respondent_party_id'), array_column($disputes, 'ruled_by_party_id'));
        if (!empty($listing['inspector_party_id'])) {
            $partyIds[] = $listing['inspector_party_id'];
        }
        $tenant = $db->table('tenant')->select('id, name, tenant_class, subscription_tier, subdomain, custom_domain, suspended_at')->where('id', $listing['tenant_id'])->get()->getRowArray();
        $masters = $db->table('party_role r')->select('p.id, p.full_name, p.mobile_number')->join('party p', 'p.id = r.party_id')
            ->where('r.tenant_id', $listing['tenant_id'])->where('r.role', 'tenant_admin')->where('r.revoked_at', null)->get()->getResultArray();
        $partyIds = array_values(array_unique(array_filter($partyIds)));
        $parties = $partyIds ? array_column($db->table('party')->select($partyCols)->whereIn('id', $partyIds)->get()->getResultArray(), null, 'id') : [];

        $versions = $db->table('listing')->select('id, title, status, archived_at, superseded_by_listing_id, created_at')
            ->groupStart()->where('superseded_by_listing_id', $listingId)->orWhere('id', $listing['superseded_by_listing_id'] ?? '')->groupEnd()
            ->get()->getResultArray();

        $audit = $db->table('audit_log al')->select('al.sequence_number, al.occurred_at, al.event_type, al.actor_party_id, p.full_name AS actor_name, p.mobile_number AS actor_mobile, al.ip_address, al.payload')
            ->join('party p', 'p.id = al.actor_party_id', 'left')
            ->groupStart()->like('al.payload', $listingId);
        foreach ($seIds as $id) {
            $audit->orLike('al.payload', $id);
        }
        $audit = $audit->groupEnd()->orderBy('al.sequence_number', 'ASC')->limit(500)->get()->getResultArray();
        // …and in the audit trail of a live session, the same masking.
        foreach ($audit as &$entry) {
            foreach ($mask as $seId => $labels) {
                if (str_contains((string) $entry['payload'], $seId)) {
                    foreach ($labels as $pid => $lbl) {
                        $entry['payload'] = str_replace($pid, $lbl, (string) $entry['payload']);
                        if ($entry['actor_party_id'] === $pid) {
                            $entry['actor_party_id'] = $lbl;
                            $entry['actor_name'] = $lbl;
                            $entry['actor_mobile'] = null;
                            $entry['ip_address'] = null;
                        }
                    }
                }
            }
        }
        unset($entry);

        return $this->apiResponse([
            'listing' => $listing, 'tenant' => $tenant, 'tsxMasters' => $masters,
            'seller' => $parties[$listing['seller_party_id']] ?? null, 'parties' => $parties,
            'media' => $db->table('listing_media')->where('listing_id', $listingId)->get()->getResultArray(),
            'versions' => $versions, 'sessions' => $sessions,
            'engagement' => [
                'views' => $db->table('listing_view')->where('listing_id', $listingId)->countAllResults(),
                'uniqueViewers' => (int) ($db->query('SELECT COUNT(DISTINCT party_id) AS n FROM listing_view WHERE listing_id = ?', [$listingId])->getRowArray()['n'] ?? 0),
                'favorites' => $db->table('listing_favorite')->where('listing_id', $listingId)->countAllResults(),
                'sellerMessages' => $db->table('seller_message')->where('listing_id', $listingId)->orderBy('created_at', 'ASC')->get()->getResultArray(),
            ],
            'audit' => $audit,
        ]);
    }

    public function decisionQueue()
    {
        $db = \Config\Database::connect();
        $items = [];
        $add = function (string $type, string $id, string $title, string $detail, ?string $since, string $owner, array $extra = []) use (&$items) {
            $items[] = ['type' => $type, 'id' => $id, 'title' => $title, 'detail' => $detail, 'since' => $since, 'owner' => $owner] + $extra;
        };
        $who = fn(array $r) => trim((string) ($r['full_name'] ?? '')) ?: ($r['mobile_number'] ?? '—');

        foreach ($db->table('party')->select('id, full_name, mobile_number, entity_type, kyc_submitted_at')
            ->where('kyc_status', 'submitted')->where('archived_at', null)->get()->getResultArray() as $r) {
            $add('kyc', $r['id'], $who($r), ucfirst((string) ($r['entity_type'] ?: 'individual')) . ' · ' . ($r['mobile_number'] ?? ''), $r['kyc_submitted_at'], 'custodian');
        }
        foreach ($db->table('aml_flag af')->select('af.id, af.flag_type, af.detail, af.created_at, p.full_name, p.mobile_number')
            ->join('party p', 'p.id = af.party_id')->where('af.status', 'open')->get()->getResultArray() as $r) {
            $add('aml', $r['id'], ucfirst(str_replace('_', ' ', (string) $r['flag_type'])) . ' · ' . $who($r), (string) ($r['detail'] ?? ''), $r['created_at'], 'custodian');
        }
        foreach ($db->table('payout_release_review r')->select('r.id, r.amount, r.release_type, r.created_at, p.full_name, p.mobile_number')
            ->join('party p', 'p.id = r.party_id')->where('r.status', 'pending')->get()->getResultArray() as $r) {
            $add('payout', $r['id'], 'Payout release · ₹' . number_format((float) $r['amount']), ucfirst(str_replace('_', ' ', (string) $r['release_type'])) . ' · ' . $who($r), $r['created_at'], 'custodian', ['amount' => (float) $r['amount']]);
        }
        // BR-36: a downgrade to 2.0 or below needs the Super Admin as well as
        // the Tenant Admin; a milder one needs only the Tenant Admin, so
        // the Super Admin's approval alone would not apply it.
        foreach ((new \App\Models\RatingEventModel())->findAllPending() as $r) {
            $needsSuperAdmin = (float) $r['new_value'] <= 2.0 && empty($r['super_admin_approved_at']);
            $add('rating', $r['id'], 'Rating change · ' . ($r['mobile_number'] ?? ''),
                sprintf('%s rating %s → %s%s', $r['rating_role'] === 'seller_star_rating' ? 'Market Maker' : 'Trader', (float) $r['previous_value'], (float) $r['new_value'], $r['reason'] ? ' · ' . $r['reason'] : '')
                    . ($needsSuperAdmin ? '' : ' · waiting on the TSX Master'),
                $r['created_at'], $needsSuperAdmin ? 'custodian' : 'tsx_master');
        }
        foreach ($db->table('settlement s')->select('s.id, s.final_price, s.created_at, s.stall_flagged_at, t.name AS tenant_name')
            ->join('sale_event se', 'se.id = s.sale_event_id', 'left')->join('listing l', 'l.id = se.listing_id', 'left')->join('tenant t', 't.id = l.tenant_id', 'left')
            ->where('s.status', 'stalled')->get()->getResultArray() as $r) {
            $add('settlement', $r['id'], 'Stalled settlement · ₹' . number_format((float) $r['final_price']), (string) ($r['tenant_name'] ?? ''), $r['stall_flagged_at'] ?: $r['created_at'], 'custodian', ['overdue' => true]);
        }
        foreach ($db->table('dispute d')->select('d.id, d.category, d.status, d.evidence_deadline_at, d.created_at, t.name AS tenant_name')
            ->join('sale_event se', 'se.id = d.sale_event_id', 'left')->join('listing l', 'l.id = se.listing_id', 'left')->join('tenant t', 't.id = l.tenant_id', 'left')
            ->whereIn('d.status', ['filed', 'evidence_window', 'appealed'])->get()->getResultArray() as $r) {
            $deadline = $r['evidence_deadline_at'] ? strtotime($r['evidence_deadline_at']) : null;
            $add('dispute', $r['id'], ucfirst(str_replace('_', ' ', (string) $r['category'])), ($r['status'] === 'appealed' ? 'Appealed — your ruling' : 'Evidence window') . ($r['tenant_name'] ? ' · ' . $r['tenant_name'] : ''), $r['created_at'], $r['status'] === 'appealed' ? 'custodian' : 'tsx_master',
                ['overdue' => $deadline !== null && $deadline < strtotime('tomorrow')]);
        }
        foreach ($db->table('tenant_media_waiver w')->select('w.id, w.category, w.created_at, t.name AS tenant_name')
            ->join('tenant t', 't.id = w.tenant_id')->where('w.status', 'pending')->get()->getResultArray() as $r) {
            $add('waiver', $r['id'], 'Media waiver · ' . $r['tenant_name'], (string) $r['category'], $r['created_at'], 'custodian');
        }
        foreach ($db->table('chargeback_case c')->select('c.id, c.amount, c.filed_reason, c.filed_at')
            ->whereIn('c.status', ['filed', 'represented'])->get()->getResultArray() as $r) {
            $add('chargeback', $r['id'], 'Chargeback · ₹' . number_format((float) $r['amount']), (string) ($r['filed_reason'] ?? ''), $r['filed_at'], 'custodian');
        }
        foreach ($db->table('listing l')->select('l.id, l.title, l.make_model, l.category, l.updated_at, t.name AS tenant_name')
            ->join('tenant t', 't.id = l.tenant_id')->where('l.status', 'pending_approval')->where('l.archived_at', null)->get()->getResultArray() as $r) {
            $add('lot', $r['id'], $r['title'] ?: ($r['make_model'] ?: $r['category']), 'Waiting on ' . $r['tenant_name'] . '\'s TSX Master', $r['updated_at'], 'tsx_master');
        }
        foreach ($db->table('seller_application a')->select('a.id, a.applied_at, t.name AS tenant_name, p.full_name, p.mobile_number')
            ->join('tenant t', 't.id = a.tenant_id')->join('party p', 'p.id = a.party_id')->where('a.status', 'pending')->get()->getResultArray() as $r) {
            $add('seller', $r['id'], $who($r), 'Wants to sell on ' . $r['tenant_name'] . ' · waiting on its TSX Master', $r['applied_at'], 'tsx_master');
        }

        // Oldest first; a few rules mark items overdue on top of their own flags.
        $limits = ['kyc' => 2, 'aml' => 2, 'payout' => 2, 'rating' => 3, 'waiver' => 5, 'chargeback' => 3, 'lot' => 3, 'seller' => 3, 'dispute' => 7];
        foreach ($items as &$it) {
            $age = $it['since'] ? (time() - strtotime($it['since'])) / 86400 : 0;
            $it['overdue'] = ($it['overdue'] ?? false) || $age > ($limits[$it['type']] ?? 3);
        }
        unset($it);
        usort($items, fn($a, $b) => strcmp((string) $a['since'], (string) $b['since']));

        $counts = [];
        foreach ($items as $it) {
            $counts[$it['type']] = ($counts[$it['type']] ?? 0) + 1;
        }
        return $this->apiResponse(['items' => $items, 'counts' => $counts, 'total' => count($items)]);
    }

    // Custodian Console › Platform health: the live release, the latest
    // backups, lot photos on disk and server clock drift. Paths follow the
    // deploy's layout (BASE/adwitix-releases/<release>, BASE/adwitix-backups);
    // anything not found is reported as null rather than guessed.
    public function systemHealth()
    {
        $releaseDir = realpath(ROOTPATH) ?: ROOTPATH;
        $release = basename(rtrim($releaseDir, '/'));
        $deployedAt = null;
        $commit = null;
        if (preg_match('/^(\d{14})-([0-9a-f]{7})$/', $release, $m)) {
            $deployedAt = \DateTime::createFromFormat('YmdHis', $m[1], new \DateTimeZone('UTC'))->format(DATE_ATOM);
            $commit = $m[2];
        }

        $backups = ['database' => null, 'files' => null];
        $backupDir = dirname(dirname($releaseDir)) . '/adwitix-backups';
        if (is_dir($backupDir) && is_readable($backupDir)) {
            foreach (['database' => 'adwitix-db-*.sql.gz', 'files' => 'adwitix-files-*.tar.gz'] as $kind => $pattern) {
                $latest = null;
                foreach (glob($backupDir . '/' . $pattern) ?: [] as $f) {
                    if ($latest === null || filemtime($f) > filemtime($latest)) {
                        $latest = $f;
                    }
                }
                if ($latest) {
                    $backups[$kind] = ['at' => date(DATE_ATOM, filemtime($latest)), 'bytes' => filesize($latest), 'label' => explode('-', basename($latest))[2] ?? null];
                }
            }
        }

        $rows = \Config\Database::connect()->table('listing_media')->select('listing_id, file_path')->get()->getResultArray();
        $missingLots = [];
        $available = 0;
        foreach ($rows as $r) {
            if (\App\Libraries\StoragePaths::findPublicFile((string) $r['file_path']) !== null) {
                $available++;
            } else {
                $missingLots[$r['listing_id']] = ($missingLots[$r['listing_id']] ?? 0) + 1;
            }
        }

        return $this->apiResponse([
            'release' => ['name' => $release, 'commit' => $commit, 'deployedAt' => $deployedAt],
            'backups' => $backups,
            'media' => ['total' => count($rows), 'available' => $available, 'missing' => count($rows) - $available, 'lotsWithMissing' => count($missingLots)],
            'clockDriftAlerts' => count((new \App\Models\ServerTimeCheckModel())->findUnacknowledgedDriftAlerts()),
            'serverTime' => date(DATE_ATOM),
        ]);
    }
}
