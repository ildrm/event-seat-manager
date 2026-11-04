<?php

declare(strict_types=1);

namespace PESM\Security {
require_once __DIR__ . '/../src/Support/I18n.php';
    class RateLimiter { public static function allow(string $scope, int $max = 80, int $windowSeconds = 60): bool { return true; } }
}
namespace {
    require __DIR__ . '/../src/Domain/Booking/HoldException.php';
    require __DIR__ . '/../src/Domain/Guest/GuestAccessPolicy.php';
    require __DIR__ . '/../src/Domain/Guest/GuestAccessService.php';
    require __DIR__ . '/../src/Domain/Reporting/CsvSafety.php';
    require __DIR__ . '/../src/Domain/Reporting/AttendanceReport.php';

    use PESM\Domain\Guest\GuestAccessPolicy as P;
    use PESM\Domain\Guest\GuestAccessService as Service;
    use PESM\Domain\Booking\HoldException;
    use PESM\Domain\Reporting\CsvSafety;
    use PESM\Domain\Reporting\AttendanceReport;

    function is_email(string $value): bool { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
    function wp_salt(string $type): string { return 'isolated-test-secret-' . $type; }
    function wc_get_order(int $orderId): ?object {
        if ($orderId !== 50) { return null; }
        return new class { public function get_meta(string $key): string { return $key === '_pesm_is_ticket_order' ? 'yes' : ''; }
            public function get_billing_email(): string { return 'person@example.org'; } };
    }
    $GLOBALS['mail_outbox'] = [];
    function wp_mail(string $to, string $subject, string $body): bool { $GLOBALS['mail_outbox'][] = [$to,$subject,$body]; return true; }
    function expect(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } $GLOBALS['checks']++; }
    $GLOBALS['checks'] = 0;

    class FakeWpdb {
        public string $prefix = 'wp_';
        public string $now = '2026-11-02 12:00:00';
        public array $requests = [];
        public array $tokens = [];
        private ?array $snapshot = null;
        public function prepare(string $sql, mixed ...$args): string {
            foreach ($args as $arg) { $pos = strpos($sql,'%'); if ($pos === false) { break; }
                $sql = substr_replace($sql, is_int($arg) ? (string) $arg : "'" . str_replace("'","''",(string)$arg) . "'", $pos, 2);
            }
            return $sql;
        }
        public function query(string $sql): int|false {
            if ($sql === 'START TRANSACTION') { $this->snapshot = [$this->requests, $this->tokens]; return 1; }
            if ($sql === 'COMMIT') { $this->snapshot = null; return 1; }
            if ($sql === 'ROLLBACK') { if ($this->snapshot !== null) { [$this->requests,$this->tokens] = $this->snapshot; } $this->snapshot=null; return 1; }
            if (str_contains($sql,'SET attempts=attempts+1')) {
                preg_match('/order_id=(\d+)/',$sql,$m); $id=(int)$m[1];
                if (!isset($this->requests[$id])) { return 0; }
                $this->requests[$id]['attempts']++; return 1;
            }
            return false;
        }
        public function get_var(string $sql): mixed { return $sql === 'SELECT UTC_TIMESTAMP()' ? $this->now : null; }
        public function get_row(string $sql): ?object {
            preg_match('/order_id=(\d+)/',$sql,$m); $id=(int)($m[1]??0);
            return isset($this->requests[$id]) ? (object) $this->requests[$id] : null;
        }
        public function insert(string $table,array $row): int {
            if (str_ends_with($table,'guest_recovery_requests')) {
                if (isset($this->requests[$row['order_id']])) { return 0; }
                $this->requests[$row['order_id']]=$row; return 1;
            }
            if (str_ends_with($table,'guest_access_tokens')) { $this->tokens[]=$row;return 1; }
            return 0;
        }
        public function update(string $table,array $row,array $where): int {
            $id=$where['order_id'];
            if (!isset($this->requests[$id])) { return 0; }
            $this->requests[$id] = array_merge($this->requests[$id],$row);
            return 1;
        }
        public function delete(string $table,array $where): int {
            $id=$where['order_id'];
            if (!isset($this->requests[$id])) { return 0; }
            if (isset($where['code_hash']) && $where['code_hash'] !== $this->requests[$id]['code_hash']) { return 0; }
            unset($this->requests[$id]);return 1;
        }
    }
    $GLOBALS['wpdb'] = new FakeWpdb();
    $db = $GLOBALS['wpdb'];
    $service = new Service();
    expect(P::validateCode('00000000'), 'leading zero 8-digit code');
    expect(!P::validateCode('1234567'), 'reject short code');
    expect(!P::validateCode('۱۲۳۴۵۶۷۸'), 'reject noncanonical digits');
    expect(P::validateToken(str_repeat('a',64)), 'valid token');
    expect(!P::validateToken(str_repeat('a',63)), 'reject short token');
    expect(!P::canVerify(5,'2026-11-02 12:10:00',$db->now), 'max attempts');
    expect(!P::canVerify(0,$db->now,$db->now), 'expiry boundary');
    expect(!P::canResend($db->now,'2026-11-02 12:01:29'), 'resend limit');
    expect(P::canResend($db->now,'2026-11-02 12:01:30'), 'resend boundary');
    $missing=$service->requestCode(999,'other@example.org');
    expect(count($GLOBALS['mail_outbox'])===0, 'unknown order no mail');
    $wrong=$service->requestCode(50,'other@example.org');
    expect($missing===$wrong, 'nondisclosing recovery response');
    $ok=$service->requestCode(50,'PERSON@example.org');
    expect($missing===$ok && count($GLOBALS['mail_outbox'])===1, 'mail dispatched and no enumeration');
    expect($db->requests[50]['attempts']===0, 'request attempts reset');
    $service->requestCode(50,'person@example.org');
    expect(count($GLOBALS['mail_outbox'])===1, 'resend suppressed');
    preg_match('/:\s*([0-9]{8})/',$GLOBALS['mail_outbox'][0][2],$match);
    $code=$match[1]??'';
    expect(P::validateCode($code), 'mail code generated');
    for ($i=0;$i<5;$i++) {
        try { $service->verifyCode(50, $code==='11111111' ? '22222222' : '11111111'); throw new \RuntimeException('Wrong code accepted'); }
        catch (HoldException $e) { expect($e->errorCode==='invalid_code', 'reject incorrect code'); }
    }
    expect($db->requests[50]['attempts']===5, 'attempts persisted');
    try { $service->verifyCode(50,$code); throw new \RuntimeException('Exhausted code accepted'); }
    catch (HoldException $e) { expect($e->errorCode==='invalid_code','valid code locked after five failures'); }
    $db->now='2026-11-02 12:01:31';
    $service->requestCode(50,'person@example.org');
    preg_match('/:\s*([0-9]{8})/',$GLOBALS['mail_outbox'][1][2],$match);
    $new=$match[1]??'';
    $access=$service->verifyCode(50,$new);
    expect(P::validateToken($access['guest_access_token']), 'new random access token');
    expect($access['expires_in']===86400, '24-hour expiration advertised');
    expect(count($db->tokens)===1 && count($db->requests)===0, 'one token and code consumed');
    expect(hash('sha256',$access['guest_access_token'])===$db->tokens[0]['token_hash'], 'only hash stored');
    try { $service->verifyCode(50,$new); throw new \RuntimeException('Replay accepted'); }
    catch (HoldException $e) { expect($e->errorCode==='invalid_code','single-use code'); }
    expect(CsvSafety::safeCell('=1+2')==="'=1+2", 'formula escaped');
    expect(CsvSafety::safeCell(" \t+SUM(1)")==="' \t+SUM(1)", 'whitespace disguised formula escaped');
    expect(CsvSafety::safeCell('متن')==='متن', 'Persian text preserved');
    $csv=AttendanceReport::asCsv(['sessions'=>[['session_id'=>10,'title'=>'=PAYLOAD','starts_at'=>$db->now,
        'capacity'=>900,'available'=>850,'held'=>10,'payment_pending'=>5,'sold'=>35,'blocked'=>0,
        'active_tickets'=>25,'checked_in'=>10,'no_show'=>25]]]);
    expect(str_starts_with($csv,"\xEF\xBB\xBF"), 'BOM for Persian Excel CSV');
    expect(str_contains($csv,"'=PAYLOAD"), 'CSV formula neutralized');
    expect(str_contains($csv,'900'), 'CSV numeric data included');
    foreach (['events','event','seat-map','my-tickets','gate-scanner'] as $block) {
        $meta=json_decode(file_get_contents(__DIR__.'/../blocks/'.$block.'/block.json'),true,512,JSON_THROW_ON_ERROR);
        expect($meta['name']==='pesm/'.$block && $meta['editorScript']==='pesm-blocks-editor', 'block metadata '.$block);
    }
    echo 'PASS: '.$GLOBALS['checks']." guest recovery / report / Gutenberg assertions (fake DB; not WordPress/MySQL).\n";
}
