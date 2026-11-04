<?php

declare(strict_types=1);

namespace PESM\Domain\Notification;

use RuntimeException;

/** Fixed HTTPS provider endpoint. Never accepts arbitrary remote URLs from orders/admin requests. */
final class MelipayamakClient
{
    public const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

    /** @return array{status:string,code:string} status = sent | rejected | uncertain | unavailable */
    public function sendPattern(string $phone, int $patternId, string $variables): array
    {
        if (!defined('PESM_SMS_USERNAME') || !defined('PESM_SMS_PASSWORD') ||
            trim((string) constant('PESM_SMS_USERNAME')) === '' || (string) constant('PESM_SMS_PASSWORD') === '') {
            return ['status'=>'unavailable','code'=>'credentials_missing'];
        }
        if ($patternId < 1 || $patternId > 2147483647 || !preg_match('/^[0-9]{1,12}$/D', $variables)) {
            return ['status'=>'rejected','code'=>'invalid_pattern_arguments'];
        }
        try { $phone = NotificationPolicy::normalizeIranMobile($phone); }
        catch (\InvalidArgumentException $e) { return ['status'=>'rejected','code'=>'invalid_mobile']; }
        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 12, 'redirection' => 0, 'reject_unsafe_urls' => true,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'username' => (string) constant('PESM_SMS_USERNAME'),
                'password' => (string) constant('PESM_SMS_PASSWORD'),
                'text' => $variables, 'to' => $phone, 'bodyId' => $patternId,
            ],
        ]);
        // Timeout or an interrupted response is ambiguous: never auto-retry an SMS that might have been sent.
        if (is_wp_error($response)) { return ['status'=>'uncertain','code'=>'transport_unknown']; }
        $http = (int) wp_remote_retrieve_response_code($response);
        if ($http !== 200) { return ['status'=>'uncertain','code'=>'http_response_unknown']; }
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['Value']) || !is_scalar($decoded['Value'])) {
            return ['status'=>'uncertain','code'=>'provider_response_unknown'];
        }
        // Official REST library describes Value as a RecId on success, otherwise an error number.
        // Require a positive receipt AND a positive provider status when that field is present.
        $value = (string) $decoded['Value'];
        if (!preg_match('/^[0-9]+$/D', $value) || (float) $value <= 0 ||
            (isset($decoded['RetStatus']) && (int) $decoded['RetStatus'] !== 1)) {
            return ['status'=>'rejected','code'=>'provider_rejected'];
        }
        return ['status'=>'sent','code'=>'provider_accepted'];
    }
}
