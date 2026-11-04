<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__.'/../src/Domain/Notification/NotificationPolicy.php';
require __DIR__.'/../src/Domain/Notification/PayloadCipher.php';
require __DIR__.'/../src/Domain/Notification/MelipayamakClient.php';

use PESM\Domain\Notification\NotificationPolicy as Policy;
use PESM\Domain\Notification\PayloadCipher as Cipher;
use PESM\Domain\Notification\MelipayamakClient as Client;

function assertN(bool $condition, string $reason): void { if (!$condition) { throw new RuntimeException($reason); } $GLOBALS['checks']++; }
function wp_salt(string $kind): string { return str_repeat('s',64); }
class FakeWpError { public function get_error_code(): string { return 'http_request_failed'; } }
function is_wp_error(mixed $input): bool { return $input instanceof FakeWpError; }
function wp_remote_post(string $url, array $arguments): mixed {
    $GLOBALS['calls'][] = [$url,$arguments];
    return $GLOBALS['network'];
}
function wp_remote_retrieve_response_code(array $r): int { return (int)$r['code']; }
function wp_remote_retrieve_body(array $r): string { return (string)$r['body']; }
const PESM_SMS_USERNAME='test-user';
const PESM_SMS_PASSWORD='test-password';
$GLOBALS['checks']=0;$GLOBALS['calls']=[];
foreach (['09123456789','+989123456789','00989123456789','989123456789','۰۹۱۲۳۴۵۶۷۸۹'] as $number) {
    assertN(Policy::normalizeIranMobile($number) === '09123456789', 'Iranian mobile normalization');
}
foreach (['1234','091234567890','+989100X','00123456789'] as $number) {
    $rejected=false;try { Policy::normalizeIranMobile($number); } catch (InvalidArgumentException $e) {$rejected=true;}
    assertN($rejected,'Bad mobile accepted');
}
assertN(Policy::dedupeKey('ticket_confirmed',123,'email')===Policy::dedupeKey('ticket_confirmed',123,'email'),'Stable dedupe');
assertN(Policy::dedupeKey('ticket_confirmed',123,'email')!==Policy::dedupeKey('ticket_confirmed',123,'sms'),'Channel dedupe');
assertN(Policy::retryDelay(1)===60 && Policy::retryDelay(3)===240,'Retry backoff');
assertN(!str_contains(Policy::orderText(10),'091') && str_contains(Policy::orderText(10),'10'),'No PII in SMS');
$payload=['email'=>'person@example.org','phone'=>'09123456789','order_id'=>123];
$cipher=Cipher::seal($payload);
assertN(!str_contains($cipher,'person@example.org') && !str_contains($cipher,'09123456789'),'Ciphertext leaks PII');
assertN(Cipher::open($cipher)===$payload,'Cipher decrypt');
$raw=base64_decode($cipher,true);$raw[32]=chr(ord($raw[32])^16);
$rejected=false;try {Cipher::open(base64_encode($raw));} catch (Throwable $e) {$rejected=true;}
assertN($rejected,'Altered ciphertext accepted');
$rejected=false;try {Cipher::open('not-base64');} catch (Throwable $e) {$rejected=true;}
assertN($rejected,'Invalid ciphertext accepted');
$client=new Client();
$GLOBALS['network']=['code'=>200,'body'=>'{"Value":"12345","RetStatus":1}'];
$result=$client->sendPattern('09123456789',412,'123');
assertN($result['status']==='sent','Valid provider accepted message');
assertN(count($GLOBALS['calls'])===1 && $GLOBALS['calls'][0][0]===Client::ENDPOINT,'Endpoint not fixed');
assertN($GLOBALS['calls'][0][1]['body']['text']==='123' && $GLOBALS['calls'][0][1]['body']['bodyId']===412,'Pattern args not sent');
$GLOBALS['network']=['code'=>200,'body'=>'{"Value":"-1","RetStatus":0}'];
assertN($client->sendPattern('09123456789',412,'123')['status']==='rejected','Provider rejection');
$GLOBALS['network']=['code'=>502,'body'=>'Bad gateway'];
assertN($client->sendPattern('09123456789',412,'123')['status']==='uncertain','Unknown HTTP must not blindly retry');
$GLOBALS['network']=new FakeWpError();
assertN($client->sendPattern('09123456789',412,'123')['status']==='uncertain','Timeout must not blindly retry');
assertN($client->sendPattern('invalid',412,'123')['status']==='rejected','Invalid mobile must not be sent');
assertN($client->sendPattern('09123456789',0,'123')['status']==='rejected','Invalid pattern must not be sent');
echo 'notification-unit: '.$GLOBALS['checks']." checks passed\n";
