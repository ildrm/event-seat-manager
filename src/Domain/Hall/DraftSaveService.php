<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use PESM\Security\Capabilities;
use PESM\Support\Audit;
use RuntimeException;
use Throwable;

final class DraftConflictException extends RuntimeException {}

/**
 * Autosave never mutates published layout versions or a layout used by sessions.
 * Hall row serializes with import/publish; draft revision provides explicit CAS.
 */
final class DraftSaveService
{
    /**
     * @return array{layout_id:int,revision:int,created:bool}
     */
    public function save(int $hallId, int $sourceLayoutId, int $actorId, int $expectedRevision, string $requestKey, string $json): array
    {
        // Validate before acquiring any database lock.
        $content = LayoutDocument::parseFull($json);
        $seats = $content['seats'];
        $payloadHash = hash('sha256', LayoutDocument::encodeFull($seats, $content['objects']));
        if ($hallId < 1 || $sourceLayoutId < 1 || $actorId < 1 || $expectedRevision < 0 ||
            !preg_match('/^[a-f0-9]{32,64}$/D', $requestKey) || !user_can($actorId, 'pesm_edit_layouts')) {
            throw new RuntimeException(\PESM\Support\I18n::text('درخواست ذخیره پیش‌نویس معتبر نیست.'));
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $hash = hash_hmac('sha256', $hallId . ':' . $sourceLayoutId . ':' . $actorId . ':' . $requestKey, wp_salt('auth'));
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException(\PESM\Support\I18n::text('آغاز تراکنش ذخیره ممکن نشد.'));
        }
        try {
            $hall = $wpdb->get_row($wpdb->prepare(
                "SELECT id,center_id FROM {$p}halls WHERE id=%d FOR UPDATE", $hallId
            ));
            if (!$hall || !Capabilities::centerAllowed((int) $hall->center_id, $actorId)) {
                throw new RuntimeException(\PESM\Support\I18n::text('دسترسی به سالن مجاز نیست.'));
            }
            $receipt = $wpdb->get_row($wpdb->prepare(
                "SELECT layout_id,revision,payload_hash,base_revision FROM {$p}layout_save_receipts WHERE request_hash=%s AND hall_id=%d FOR UPDATE",
                $hash, $hallId
            ));
            if ($receipt) {
                if (!hash_equals((string) $receipt->payload_hash, $payloadHash) || (int) $receipt->base_revision !== $expectedRevision) {
                    throw new DraftConflictException(\PESM\Support\I18n::text('محتوای درخواست تکراری با نسخه ذخیره‌شده تفاوت دارد؛ نقشه را بازخوانی کنید.'));
                }
                if ($wpdb->query('COMMIT') === false) { throw new RuntimeException(\PESM\Support\I18n::text('تراکنش ذخیره تکمیل نشد.')); }
                return ['layout_id' => (int) $receipt->layout_id, 'revision' => (int) $receipt->revision, 'created' => false];
            }
            // A duplicated create request must return its original draft, not create a second version.
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT id,revision,status,editor_payload_hash FROM {$p}layout_versions WHERE hall_id=%d AND editor_request_hash=%s FOR UPDATE",
                $hallId, $hash
            ));
            if ($previous) {
                if ($previous->status !== 'draft' || !hash_equals((string) $previous->editor_payload_hash, $payloadHash)) {
                    throw new DraftConflictException(\PESM\Support\I18n::text('محتوای درخواست تکراری با نسخه ذخیره‌شده تفاوت دارد؛ نقشه را بازخوانی کنید.'));
                }
                if ($wpdb->query('COMMIT') === false) { throw new RuntimeException(\PESM\Support\I18n::text('تراکنش ذخیره تکمیل نشد.')); }
                return ['layout_id' => (int) $previous->id, 'revision' => (int) $previous->revision, 'created' => false];
            }
            $source = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status,revision,format FROM {$p}layout_versions WHERE hall_id=%d AND id=%d FOR UPDATE",
                $hallId, $sourceLayoutId
            ));
            if (!$source || !in_array($source->status, ['published', 'draft'], true)) {
                throw new RuntimeException(\PESM\Support\I18n::text('نسخه انتخاب‌شده برای ویرایش معتبر نیست.'));
            }
            if (($source->format ?? LayoutDocument::FORMAT) !== LayoutDocument::FORMAT) {
                throw new DraftConflictException('Use the venue designer to edit this layout.');
            }
            if ($source->status === 'published') {
                if ($expectedRevision !== (int) $source->revision) {
                    throw new DraftConflictException(\PESM\Support\I18n::text('نسخه مبنای نقشه تغییر کرده است؛ دوباره بارگذاری کنید.'));
                }
                $nextVersion = 1 + (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(MAX(version_no),0) FROM {$p}layout_versions WHERE hall_id=%d", $hallId
                ));
                if ($wpdb->insert($p . 'layout_versions', [
                    'hall_id' => $hallId, 'version_no' => $nextVersion,
                    'status' => 'draft', 'revision' => 1, 'editor_request_hash' => $hash,
                    'editor_payload_hash' => $payloadHash,
                    'seat_count' => count($seats), 'created_at' => gmdate('Y-m-d H:i:s'),
                ]) !== 1) {
                    throw new RuntimeException(\PESM\Support\I18n::text('ایجاد پیش‌نویس ممکن نشد.'));
                }
                $draftId = (int) $wpdb->insert_id;
                $revision = 1;
                $created = true;
            } else {
                // Avoid rewriting layouts already attached to any session, even if data was corrupted.
                $inUse = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}sessions WHERE layout_id=%d", $sourceLayoutId
                ));
                if ($inUse > 0) { throw new DraftConflictException(\PESM\Support\I18n::text('این نقشه به جلسه متصل است و قابل تغییر نیست.')); }
                if ((int) $source->revision !== $expectedRevision) {
                    throw new DraftConflictException(\PESM\Support\I18n::text('نقشه در پنجره دیگری ویرایش شده است. نسخه جدید را بارگذاری کنید.'));
                }
                $draftId = $sourceLayoutId;
                $revision = $expectedRevision + 1;
                $created = false;
                if ($wpdb->update($p . 'layout_versions',
                    ['revision' => $revision, 'seat_count' => count($seats), 'editor_payload_hash' => $payloadHash],
                    ['id' => $draftId, 'hall_id' => $hallId, 'revision' => $expectedRevision, 'status' => 'draft']
                ) !== 1) {
                    throw new DraftConflictException(\PESM\Support\I18n::text('ذخیره با ویرایش هم‌زمان تداخل پیدا کرد.'));
                }
                if ($wpdb->query($wpdb->prepare("DELETE FROM {$p}seats WHERE layout_id=%d", $draftId)) === false) {
                    throw new RuntimeException(\PESM\Support\I18n::text('حذف داده پیش‌نویس قبلی ناموفق بود.'));
                }
            }
            foreach (array_chunk($seats, 100) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $s) {
                    $values[] = '(%d,%s,%s,%d,%s,%d,%d)';
                    array_push($params, $draftId, $s['code'], $s['row_label'], $s['number'], $s['zone'], $s['x'], $s['y']);
                }
                $sql = "INSERT INTO {$p}seats (layout_id,seat_code,row_label,seat_number,zone_label,pos_x,pos_y) VALUES " . implode(',', $values);
                if ($wpdb->query($wpdb->prepare($sql, ...$params)) !== count($chunk)) {
                    throw new RuntimeException(\PESM\Support\I18n::text('ذخیره صندلی‌ها کامل نشد.'));
                }
            }
            LayoutObjectStorage::replace($draftId, $content['objects']);
            if ($wpdb->insert($p . 'layout_save_receipts', [
                'request_hash' => $hash, 'hall_id' => $hallId, 'layout_id' => $draftId,
                'base_revision' => $expectedRevision, 'revision' => $revision,
                'payload_hash' => $payloadHash, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت نهایی پیش‌نویس ناموفق بود.'));
            }
            Audit::record($created ? 'layout_draft_created' : 'layout_draft_saved', 'layout', $draftId, $actorId);
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException(\PESM\Support\I18n::text('ثبت نهایی پیش‌نویس ناموفق بود.')); }
            return ['layout_id' => $draftId, 'revision' => $revision, 'created' => $created];
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}
