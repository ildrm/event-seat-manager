<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void{if(str_starts_with($class,'PESM\\')){require_once __DIR__.'/../src/'.str_replace('\\','/',substr($class,5)).'.php';}});
function wp_salt(string $kind):string{return str_repeat('test-only-secret-',4);}
use PESM\Domain\Allocation\Policy;
use PESM\Domain\Allocation\Credential;
use PESM\Domain\Checkout\FinancialSnapshot;
use PESM\Domain\Booking\HoldException;
$n=0;
function check(bool $ok,string $label):void{global$n;++$n;if(!$ok){throw new RuntimeException($label);}}
function rejected(callable $fn,string $code):void{try{$fn();}catch(HoldException$e){check($e->errorCode===$code,'Expected '.$code.' got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);}
$settings=Policy::settings(['pricing'=>['sections'=>['S'=>10],'categories'=>['VIP'=>20],'section_categories'=>['S'=>['VIP'=>30]],'groups'=>['T'=>50]],'group_modes'=>['T'=>'both']]);
check(Policy::price(1,'S','VIP',$settings)===30,'section/category wins');
check(Policy::price(1,'X','VIP',$settings)===20,'category wins section');
check(Policy::price(1,'S','STANDARD',$settings)===10,'section fallback');
check(Policy::price(1,'X','STANDARD',$settings)===1,'session fallback');
check(array_sum(Policy::allocate(10,[3,2,1]))===10,'integer allocation conserves total');
check(Policy::allocate(10,[3,2,1])===[1=>4,2=>3,3=>3],'remainder stable by seat');
check(Policy::entry('together',8,0,1)===8,'party together consumes whole group');
check(Policy::entry('incremental',8,3,2)===2,'partial arrival exact quantity');
rejected(fn()=>Policy::entry('incremental',8,7,2),'admission_quantity');
rejected(fn()=>Policy::entry('incremental',8,8,1),'already_checked_in');
rejected(fn()=>Policy::entry('together',8,1,1),'admission_conflict');
rejected(fn()=>Policy::units([['type'=>'group','id'=>'T'],['type'=>'table','id'=>'T']]),'duplicate_resource');
rejected(fn()=>Policy::units([['type'=>'seat','id'=>0]]),'invalid_selection');
rejected(fn()=>Policy::settings(['ticket_mode'=>'both']),'invalid_settings');
rejected(fn()=>Policy::settings(['pricing'=>['section_categories'=>'invalid']]),'invalid_settings');
rejected(fn()=>Policy::settings(['pricing'=>['categories'=>['VIP'=>-1]]]),'price_mismatch');
$id=str_repeat('a',32);$person=Credential::create('P',$id);$group=Credential::create('G',$id);
check($person!==$group,'credential domains differ');check(Credential::parse($group)===['G',$id],'group parse');
check(Credential::valid($person,'P',$id,1),'valid person credential');check(!Credential::valid($person,'P',$id,2),'rotation revokes old credential');
check(!Credential::valid($person,'G',$id,1),'person cannot authenticate group');
check(FinancialSnapshot::minor('3.000',2)===300,'insignificant precision accepted');
check(FinancialSnapshot::minor('999999999.99',2)===99999999999,'exact large decimal');
rejected(fn()=>FinancialSnapshot::minor('3.001',2),'price_mismatch');
rejected(fn()=>FinancialSnapshot::minor('3e2',2),'price_mismatch');
rejected(fn()=>FinancialSnapshot::minor('-3.00',2),'price_mismatch');
$order=new class{public function get_currency():string{return 'USD';}public function get_total():string{return '3.00';}};
FinancialSnapshot::validate($order,300,'USD',2,false);check(true,'valid immutable financial snapshot');
rejected(fn()=>FinancialSnapshot::validate($order,300,'EUR',2,false),'price_mismatch');
echo "PASS: $n allocation/pricing/admission/financial policy assertions.\n";
