<?php

namespace App\Controllers;

use App\Libraries\PlanService;
use App\Libraries\UserAuthContext;

// D-138: TSX subscription plans — the public comparison (Pricing page)
// and the Custodian's Plans sheet, where a plan's limits are edited.
class PlanController extends BaseController
{
    // GET /plans — public; no audit fields.
    public function index()
    {
        $plans = array_map(fn($p) => array_diff_key($p, ['updated_by_party_id' => 1]), PlanService::all());
        return $this->apiResponse(['plans' => $plans]);
    }

    // GET /admin/plans — plans, which fields are enforced, and how many
    // TSXs are on each.
    public function adminIndex()
    {
        $counts = [];
        foreach (\Config\Database::connect()->table('tenant')->select('subscription_tier, COUNT(*) AS n')->groupBy('subscription_tier')->get()->getResultArray() as $r) {
            $counts[$r['subscription_tier']] = (int) $r['n'];
        }
        return $this->apiResponse([
            'plans' => array_map(fn($p) => $p + ['tenant_count' => $counts[$p['plan_key']] ?? 0], PlanService::all()),
            'enforced' => PlanService::ENFORCED,
            'apiLevels' => PlanService::API_LEVELS,
        ]);
    }

    // POST /admin/plans/:key {field: value, …}
    public function update(string $key)
    {
        try {
            $plan = PlanService::update($key, (array) $this->request->getJSON(true), UserAuthContext::partyId());
            return $this->apiResponse(['plan' => $plan, 'message' => "{$plan['name']} plan saved."]);
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'plan', $e->getMessage());
        }
    }
}
