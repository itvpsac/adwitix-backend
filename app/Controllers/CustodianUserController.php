<?php

namespace App\Controllers;

use App\Libraries\UserAuthContext;
use App\Models\PartyModel;

// D-134: traders are the Custodian's to monitor and control. One file per
// person, across every TradeSphereX (BR-37: one global identity), with
// three deliberate limits the project owner confirmed:
//   * BR-16 stays — while a Trading Session is live the Custodian does not
//     see this person's bids, offers or deposits on it (only how many);
//     they appear once the session closes.
//   * Browsing stays private — no search history, saved searches,
//     preferences, favourites or lots viewed.
//   * Credentials never leave the server (ApiResponse strips them).
// Control: freeze / unfreeze, each with an audited reason. Star ratings are
// never edited here — they move only through rating events (BR-36).
class CustodianUserController extends BaseController
{
    private const LIVE_SESSION = ['pending_approval', 'grace_period', 'active'];

    // GET /admin/users/:id/dossier
    public function dossier(string $partyId)
    {
        $db = \Config\Database::connect();
        $party = $db->table('party')->where('id', $partyId)->get()->getRowArray();
        if (!$party) {
            return $this->jsonError(404, 'not_found', 'User not found.');
        }
        $all = fn(string $table, string $col, string $order = 'created_at') => $db->table($table)->where($col, $partyId)->orderBy($order, 'ASC')->get()->getResultArray();
        $sessionInfo = 'se.ern, se.sale_format, se.status AS session_status, l.id AS listing_id, l.title AS listing_title, t.name AS tenant_name';
        $withSession = fn(string $table, string $alias, string $col, string $order) => $db->table("{$table} {$alias}")
            ->select("{$alias}.*, {$sessionInfo}")
            ->join('sale_event se', "se.id = {$alias}.sale_event_id", 'left')->join('listing l', 'l.id = se.listing_id', 'left')->join('tenant t', 't.id = se.tenant_id', 'left')
            ->where("{$alias}.{$col}", $partyId)->orderBy("{$alias}.{$order}", 'ASC')->get()->getResultArray();

        // BR-16: rows on a live session are withheld, only counted.
        $hidden = [];
        $closed = function (array $rows, string $kind) use (&$hidden) {
            $out = [];
            foreach ($rows as $r) {
                if (in_array($r['session_status'] ?? null, self::LIVE_SESSION, true)) {
                    $hidden[$kind] = ($hidden[$kind] ?? 0) + 1;
                    $hidden['sessions'][$r['sale_event_id']] = true;
                } else {
                    $out[] = $r;
                }
            }
            return $out;
        };

        $roles = $db->table('party_role r')->select('r.*, t.name AS tenant_name')->join('tenant t', 't.id = r.tenant_id', 'left')
            ->where('r.party_id', $partyId)->orderBy('r.granted_at', 'ASC')->get()->getResultArray();

        $buying = [
            'bids' => $closed($withSession('bid', 'b', 'bidder_party_id', 'placed_at'), 'bids'),
            'offers' => $closed($withSession('offer', 'o', 'buyer_party_id', 'created_at'), 'offers'),
            'emdHolds' => $closed($withSession('emd_hold', 'h', 'party_id', 'held_at'), 'emdHolds'),
            'tenderInterest' => $closed($withSession('tender_interest', 'ti', 'party_id', 'registered_at'), 'tenderInterest'),
            'tenderEligibility' => $all('tender_eligibility', 'party_id', 'approved_at'),
            'purchases' => $withSession('settlement', 's', 'buyer_party_id', 'created_at'),
        ];

        $listings = $db->table('listing l')->select('l.*, t.name AS tenant_name')->join('tenant t', 't.id = l.tenant_id', 'left')
            ->where('l.seller_party_id', $partyId)->orderBy('l.created_at', 'ASC')->get()->getResultArray();
        $listingIds = array_column($listings, 'id');
        $selling = [
            'applications' => $db->table('seller_application a')->select('a.*, t.name AS tenant_name')->join('tenant t', 't.id = a.tenant_id', 'left')
                ->where('a.party_id', $partyId)->orderBy('a.applied_at', 'ASC')->get()->getResultArray(),
            'lots' => $listings,
            'sessions' => $listingIds ? $db->table('sale_event')->whereIn('listing_id', $listingIds)->orderBy('created_at', 'ASC')->get()->getResultArray() : [],
            'sales' => $withSession('settlement', 's', 'seller_party_id', 'created_at'),
            'sellerMessages' => $all('seller_message', 'seller_party_id'),
        ];

        $ratings = [
            'current' => array_intersect_key($party, array_flip([
                'star_rating', 'seller_star_rating', 'offence_count_buyer', 'offence_count_seller',
                'crawl_back_active_buyer', 'crawl_back_clean_required_buyer', 'crawl_back_clean_completed_buyer',
                'crawl_back_active_seller', 'crawl_back_clean_required_seller', 'crawl_back_clean_completed_seller',
                'shadow_banned_at_buyer', 'shadow_banned_at_seller', 'forced_neutral_count_buyer', 'forced_neutral_count_seller',
                'clean_transaction_streak_buyer', 'clean_transaction_streak_seller', 'deposit_override_amount',
                'seller_delisted_at', 'seller_delisted_reason', 'standing_review_complaint_count',
                'standing_review_cbs_offense_count', 'standing_review_next_annual_at',
            ])),
            'events' => $all('rating_event', 'party_id'),
        ];

        $disputes = $db->table('dispute d')->select("d.*, {$sessionInfo}")
            ->join('sale_event se', 'se.id = d.sale_event_id', 'left')->join('listing l', 'l.id = se.listing_id', 'left')->join('tenant t', 't.id = se.tenant_id', 'left')
            ->groupStart()->where('d.filed_by_party_id', $partyId)->orWhere('d.respondent_party_id', $partyId)->groupEnd()
            ->orderBy('d.created_at', 'ASC')->get()->getResultArray();
        $risk = [
            'disputes' => $disputes,
            'chargebacks' => $all('chargeback_case', 'party_id', 'filed_at'),
            'amlFlags' => $all('aml_flag', 'party_id'),
            'payoutReviews' => $all('payout_release_review', 'party_id'),
        ];

        // Everything this person did (actor) or is named in, oldest first —
        // except entries about a live session they're bidding in (BR-16).
        $liveIds = array_keys($hidden['sessions'] ?? []);
        $audit = $db->table('audit_log al')->select('al.sequence_number, al.occurred_at, al.event_type, al.actor_party_id, p.full_name AS actor_name, al.ip_address, al.user_agent, al.payload')
            ->join('party p', 'p.id = al.actor_party_id', 'left')
            ->groupStart()->where('al.actor_party_id', $partyId)->orLike('al.payload', $partyId)->groupEnd()
            ->orderBy('al.sequence_number', 'ASC')->limit(2000)->get()->getResultArray();
        $audit = array_values(array_filter($audit, function ($a) use ($liveIds, &$hidden) {
            foreach ($liveIds as $id) {
                if (str_contains((string) $a['payload'], $id)) {
                    $hidden['auditEntries'] = ($hidden['auditEntries'] ?? 0) + 1;
                    return false;
                }
            }
            return true;
        }));
        $hiddenOut = $hidden;
        $hiddenOut['sessions'] = count($hidden['sessions'] ?? []);

        return $this->apiResponse([
            'party' => $party,
            'roles' => $roles,
            'kyc' => [
                'addresses' => $all('party_address', 'party_id'),
                'documents' => $db->table('party_document')->select('id, document_type, original_filename, mime_type, uploaded_at')
                    ->where('party_id', $partyId)->orderBy('uploaded_at', 'ASC')->get()->getResultArray(),
                'consents' => $all('consent_event', 'party_id'),
            ],
            'buying' => $buying,
            'selling' => $selling,
            'ratings' => $ratings,
            'risk' => $risk,
            'audit' => $audit,
            'hiddenWhileLive' => $hiddenOut,
        ]);
    }

    // POST /admin/users/:id/freeze {reason}
    public function freeze(string $partyId)
    {
        return $this->setFrozen($partyId, true);
    }

    // POST /admin/users/:id/unfreeze {reason}
    public function unfreeze(string $partyId)
    {
        return $this->setFrozen($partyId, false);
    }

    private function setFrozen(string $partyId, bool $freeze)
    {
        $reason = trim((string) $this->input('reason'));
        if ($reason === '') {
            return $this->jsonError(422, 'reason_required', 'Give a reason.');
        }
        $model = new PartyModel();
        $party = $model->find($partyId);
        if (!$party) {
            return $this->jsonError(404, 'not_found', 'User not found.');
        }
        if ((new \App\Libraries\AuthorizationService())->isSuperAdmin($partyId)) {
            return $this->jsonError(409, 'custodian', 'The Custodian account cannot be frozen.');
        }
        if ($freeze === !empty($party['frozen_at'])) {
            return $this->jsonError(409, 'no_change', $freeze ? 'This account is already frozen.' : 'This account is not frozen.');
        }
        $actor = UserAuthContext::partyId();
        $model->update($partyId, $freeze
            ? ['frozen_at' => date('Y-m-d H:i:s'), 'frozen_reason' => $reason, 'frozen_by_party_id' => $actor]
            : ['frozen_at' => null, 'frozen_reason' => null, 'frozen_by_party_id' => null]);
        (new \App\Libraries\AuditLogService())->log($freeze ? 'party.frozen' : 'party.unfrozen', $actor, [
            'partyId' => $partyId, 'reason' => $reason,
        ]);
        return $this->apiResponse(['party' => $model->find($partyId),
            'message' => $freeze ? 'Account frozen. It can no longer bid, offer, pledge or sell.' : 'Account unfrozen.']);
    }
}
