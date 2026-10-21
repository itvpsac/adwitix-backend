<?php

namespace App\Libraries;

// Global API response envelope, filter-layer counterpart to
// BaseController::apiResponse() (see its docblock for the full
// rationale — every JSON response, success or error, goes out shaped
// {status, message, data}). Filters run BEFORE any controller and don't
// extend BaseController, so they call this directly on the raw
// `service('response')` instance instead.
class ApiResponse
{
    // Credential columns that must never leave the server, whichever
    // endpoint returns a row holding them (e.g. a whole `party` row).
    // Only ever read server-side (password_verify, TOTP checks).
    private const SECRET_KEYS = ['mpin_hash', 'totp_secret', 'password_hash', 'otp_hash', 'code_hash', 'client_secret_hash'];

    public static function send($response, $data = [], ?string $message = null, int $httpCode = 200)
    {
        $ok = $httpCode < 400;
        return $response->setStatusCode($httpCode)->setJSON([
            'status' => $ok,
            'message' => $message ?? ($ok ? 'Success' : 'Error'),
            'data' => self::withoutSecrets($data ?? []),
        ]);
    }

    private static function withoutSecrets($value)
    {
        if (is_object($value) && !($value instanceof \JsonSerializable)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SECRET_KEYS, true)) {
                unset($value[$key]);
            } elseif (is_array($item) || is_object($item)) {
                $value[$key] = self::withoutSecrets($item);
            }
        }
        return $value;
    }
}
