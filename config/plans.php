<?php
declare(strict_types=1);
/** Commercial allowances for new proposals. Existing signed terms take precedence. */
function erp_plans(): array {
 return [
  'Basic'=>['monthly'=>1500,'students'=>300,'branches'=>1,'features'=>['Admissions & student records','Attendance & fee workflows','School website & notices','Admin, teacher & student portals','Printable reports & data exports']],
  'Standard'=>['monthly'=>3000,'students'=>750,'branches'=>2,'features'=>['Everything in Basic','Results, routines & academic reports','Staff records & payroll workflows','Library & inventory workflows','Onboarding walkthrough']],
  'Premium'=>['monthly'=>5000,'students'=>1500,'branches'=>5,'features'=>['Everything in Standard','Transport & hostel workflows','Branch reporting & role permissions','Migration planning & rollout support','Integration assessment']],
 ];
}
function erp_billing_cycles(): array {return ['monthly'=>['months'=>1,'multiplier'=>1,'label'=>'Monthly'],'quarterly'=>['months'=>3,'multiplier'=>3,'label'=>'Quarterly'],'yearly'=>['months'=>12,'multiplier'=>10,'label'=>'Yearly']];}
function erp_plan_amount(string $plan,string $cycle): int {
 $plans=erp_plans();$cycles=erp_billing_cycles();
 if(!isset($plans[$plan],$cycles[$cycle]))throw new InvalidArgumentException('Unknown plan or billing cycle');
 return $plans[$plan]['monthly']*$cycles[$cycle]['multiplier'];
}
