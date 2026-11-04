<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
require __DIR__.'/../src/Domain/Booking/HoldException.php';
require __DIR__.'/../src/Domain/Ticket/OwnershipTransferPolicy.php';
use PESM\Domain\Ticket\OwnershipTransferPolicy as Policy;
use PESM\Domain\Booking\HoldException;
$n=0;
function check024(bool $v,string $description):void {global $n;if(!$v)throw new RuntimeException($description);$n++;}
function reject024(callable $fn,string $expected):void {try{$fn();}catch(HoldException $e){check024($e->errorCode===$expected,"Expected $expected got {$e->errorCode}");return;}throw new RuntimeException("Expected $expected");}
foreach (['seated','general'] as $type) {Policy::validateKind($type);check024(true,'accepted kind');}
foreach (['bad','capacity','SEATED',''] as $bad) reject024(fn()=>Policy::validateKind($bad),'invalid_ticket_type');
Policy::validateKey(str_repeat('a',32));check024(true,'good request');
foreach (['',str_repeat('a',31),str_repeat('z',40),str_repeat('b',129)] as $bad)reject024(fn()=>Policy::validateKey($bad),'invalid_request_key');
Policy::validateBearer(str_repeat('b',64));check024(true,'bearer');
foreach (['',str_repeat('b',63),str_repeat('x',64)] as $bad)reject024(fn()=>Policy::validateBearer($bad),'permission_denied');
$future='2026-11-05 10:00:00';$now='2026-11-03 09:00:00';
Policy::transferable('active',false,$future,$now,'published','published');check024(true,'valid window');
foreach ([['cancelled',false,$future,$now,'published','published'],['active',true,$future,$now,'published','published'],['active',false,$future,$now,'cancelled','published'],['active',false,$future,$now,'published','cancelled']] as $args)reject024(fn()=>Policy::transferable(...$args),'ticket_locked');
reject024(fn()=>Policy::transferable('active',false,$now,$now,'published','published'),'transfer_window_closed');
$secret=str_repeat('K',40);$code='10002000';$hash=hash_hmac('sha256','ownership-transfer:4:'.$code,$secret);
check024(Policy::verifyOtp($code,$hash,$secret,4,0,$future,$now),'OTP valid');
check024(!Policy::verifyOtp('10002001',$hash,$secret,4,0,$future,$now),'OTP invalid');
check024(!Policy::verifyOtp($code,$hash,$secret,4,5,$future,$now),'attempt limit');
check024(!Policy::verifyOtp($code,$hash,$secret,4,0,$now,$now),'expired');
check024(!Policy::verifyOtp('1000200',$hash,$secret,4,0,$future,$now),'invalid length');
echo "PASS: $n ownership transfer policy assertions.\n";
