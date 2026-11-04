<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
require __DIR__.'/../src/Domain/Booking/HoldException.php';
require __DIR__.'/../src/Domain/Ticket/AttendeeChangePolicy.php';
use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\AttendeeChangePolicy;
$n=0;
function verify17(bool $ok,string $name):void {global $n;if(!$ok)throw new RuntimeException('FAILED '.$name);$n++;}
function denied17(callable $fn,string $code):void {try{$fn();throw new RuntimeException('Accepted invalid value');}catch(HoldException $e){verify17($e->errorCode===$code,$code);}}
verify17(AttendeeChangePolicy::normalizedName('  زهرا   محمدی ') === 'زهرا محمدی','persian spacing');
verify17(AttendeeChangePolicy::normalizedName('John  Doe') === 'John Doe','latin spacing');
foreach (['', ' ', '12345', '<script>', "X\nY", str_repeat('الف',70), "x\u{202E}y"] as $val) {
 denied17(fn()=>AttendeeChangePolicy::normalizedName($val),'invalid_attendee');
}
AttendeeChangePolicy::canChange('active',false,'2027-11-03 10:00:00','2026-11-02 10:00:00');verify17(true,'future active');
foreach ([['checked_in',false],['cancelled',false],['active',true]] as [$status,$scan]) {
 denied17(fn()=>AttendeeChangePolicy::canChange($status,$scan,'2027-01-01 00:00:00','2026-12-31 00:00:00'),'ticket_locked');
}
denied17(fn()=>AttendeeChangePolicy::canChange('active',false,'2026-11-02 10:00:00','2026-11-02 10:00:00'),'change_window_closed');
denied17(fn()=>AttendeeChangePolicy::canChange('active',false,'2026-11-02 09:59:59','2026-11-02 10:00:00'),'change_window_closed');
echo "PASS: {$n} attendee policy assertions (pure PHP).\n";
