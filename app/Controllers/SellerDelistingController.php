<?php

namespace App\Controllers;

use App\Libraries\RatingService;
use App\Libraries\UserAuthContext;
use App\Models\PartyModel;

// jwtSuperAdmin-gated — pulled forward from Phase 5 since it's a single
// small action, same migration as the rest of this phase (D-134).
class SellerDelistingController extends BaseController
{
    public function submit()
    {
        $superAdminId = UserAuthContext::partyId();
        $mobile = $this->input('mobile_number');
        $reason = $this->input('confirmed_fraud_reason');

        if (!$mobile || !$reason) {
            return $this->jsonError(422, 'missing_fields', 'Both the seller\'s mobile number and a confirmed-fraud reason are required.');
        }

        $party = (new PartyModel())->findByMobile($mobile);
        if (!$party) {
            return $this->jsonError(404, 'not_found', 'No registered party found with that mobile number.');
        }
        // BR-15: the Custodian is never a Market Maker, so there is nothing to delist.
        if ((new \App\Libraries\AuthorizationService())->isSuperAdmin($party['id'])) {
            return $this->jsonError(409, 'custodian', 'The Custodian account is not a Market Maker and cannot be delisted.');
        }

        try {
            $result = (new RatingService())->delistSellerForFraud($party['id'], $superAdminId, $reason);
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'delist_failed', $e->getMessage());
        }

        return $this->apiResponse([
            'listingsSuspended' => $result['listingsSuspended'],
            'message' => "Seller delisted. {$result['listingsSuspended']} active listing(s) suspended across every tenant.",
        ]);
    }
}
