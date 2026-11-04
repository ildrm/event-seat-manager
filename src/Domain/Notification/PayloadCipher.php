<?php

declare(strict_types=1);

namespace PESM\Domain\Notification;

use RuntimeException;

/** AES-256-GCM authenticated encryption; key derived from a WP secret, never stored in DB. */
final class PayloadCipher
{
    private static function key(): string
    {
        $secret = defined('PESM_NOTIFICATION_ENCRYPTION_KEY') ? (string) constant('PESM_NOTIFICATION_ENCRYPTION_KEY') : wp_salt('secure_auth');
        if (strlen($secret) < 32) { throw new RuntimeException('Notification encryption secret is too short.'); }
        return hash('sha256', 'PESM/notification/payload/v1|' . $secret, true);
    }

    /** @param array<string,string|int> $payload */
    public static function seal(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 4096) { throw new RuntimeException('Notification payload exceeds limit.'); }
        $nonce = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($json, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, 'PESM-notify-v1', 16);
        if ($encrypted === false || strlen($tag) !== 16) { throw new RuntimeException('Could not encrypt notification.'); }
        return base64_encode($nonce . $tag . $encrypted);
    }

    /** @return array<string,mixed> */
    public static function open(string $blob): array
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29 || strlen($raw) > 8192) { throw new RuntimeException('Invalid encrypted notification.'); }
        $data = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16), 'PESM-notify-v1');
        if ($data === false) { throw new RuntimeException('Notification authentication failed.'); }
        $payload = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new RuntimeException('Invalid notification data.'); }
        return $payload;
    }
}
