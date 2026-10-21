<?php

namespace App\Controllers;

use App\Libraries\SovereignRuleService;
use App\Libraries\UserAuthContext;

// PR-04: the Super Admin's "Rules & Specifications module" — jwtSuperAdmin
// -gated, i.e. only reachable after the real TOTP-verified Super Admin
// login (BR-04), same access boundary as every other admin surface.
class SovereignRuleController extends BaseController
{
    private SovereignRuleService $rules;

    public function __construct()
    {
        $this->rules = new SovereignRuleService();
    }

    public function index()
    {
        return $this->apiResponse(['rules' => $this->rules->listAll()]);
    }

    public function show(string $ruleId)
    {
        $rule = $this->rules->find($ruleId);
        if (!$rule) {
            return $this->jsonError(404, 'not_found', 'Rule not found.');
        }
        return $this->apiResponse(['rule' => $rule, 'revisions' => $this->rules->revisions($ruleId)]);
    }

    public function editSubmit(string $ruleId)
    {
        $numericValue = $this->input('numeric_value');
        // A built-in rule (one the code reads by rule_key) always needs its
        // value; clearing it would silently switch the rule off.
        $existing = $this->rules->find($ruleId);
        if ($existing && !empty($existing['rule_key']) && ($numericValue === null || $numericValue === '')) {
            return $this->jsonError(422, 'value_required', 'This built-in rule needs a numeric value.');
        }
        try {
            $this->rules->update(
                $ruleId,
                (string) $this->input('title'),
                (string) $this->input('statement'),
                (string) $this->input('logic'),
                $numericValue !== null && $numericValue !== '' ? (float) $numericValue : null,
                (string) $this->input('reason_for_modification'),
                UserAuthContext::partyId()
            );
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'update_failed', $e->getMessage());
        }

        return $this->apiResponse(['rule' => $this->rules->find($ruleId), 'message' => 'Rule updated and versioned.']);
    }

    public function createSubmit()
    {
        try {
            $rule = $this->rules->createFreeform(
                (string) $this->input('title'),
                (string) $this->input('statement'),
                (string) $this->input('logic'),
                (string) $this->input('reason_for_modification'),
                UserAuthContext::partyId()
            );
        } catch (\RuntimeException $e) {
            return $this->jsonError(422, 'create_failed', $e->getMessage());
        }

        return $this->apiResponse(['rule' => $rule], null, 201);
    }
}
