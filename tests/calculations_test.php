<?php
require_once '/home/user/family-shopping/includes/group_calculations.php';

$pass = 0; $fail = 0;
function check($label, $actual, $expected, $tol = 0.01) {
    global $pass, $fail;
    if (abs($actual - $expected) <= $tol) { $pass++; echo "  ok   $label ($actual)\n"; }
    else { $fail++; echo "  FAIL $label: got $actual, expected $expected\n"; }
}
function member($id, $nick, $type, $val) {
    return ['id'=>$id,'nickname'=>$nick,'participation_type'=>$type,'participation_value'=>$val];
}
function byNick($r, $nick) {
    foreach ($r['calculations'] as $c) if ($c['member']['nickname']===$nick) return $c;
    return null;
}

// ---- 1. baseline: 3 members equal, no exclusions (regression vs old behaviour)
echo "1. חלוקה שווה בין 3, בלי החרגות\n";
$m = [member(1,'א','percentage',33.34), member(2,'ב','percentage',33.33), member(3,'ג','percentage',33.33)];
$p = [['id'=>1,'member_id'=>1,'amount'=>300,'excluded_ids'=>[]]];
$r = calculateGroupBalance($m,$p);
check('total', $r['totalAmount'], 300);
check('א should pay', byNick($r,'א')['shouldPay'], 100.02);
check('ב should pay', byNick($r,'ב')['shouldPay'], 99.99);
check('unallocated', $r['unallocated'], 0);
check('transfers count', count($r['transfers']), 2);

// ---- 2. THE headline feature: exclusion redistributes, does not create a hole
echo "\n2. החרגה: ג לא משתתף בקנייה של 300 (בשר)\n";
$m = [member(1,'א','percentage',50), member(2,'ב','percentage',25), member(3,'ג','percentage',25)];
$p = [['id'=>1,'member_id'=>1,'amount'=>300,'excluded_ids'=>[3]]];
$r = calculateGroupBalance($m,$p);
check('א should pay (50/75 של 300)', byNick($r,'א')['shouldPay'], 200);
check('ב should pay (25/75 של 300)', byNick($r,'ב')['shouldPay'], 100);
check('ג should pay (מוחרג)', byNick($r,'ג')['shouldPay'], 0);
check('אין סכום לא מוקצה', $r['unallocated'], 0);
check('hasExclusions', $r['hasExclusions'] ? 1 : 0, 1);

// ---- 3. mixed: one excluded purchase + one shared purchase
echo "\n3. שתי קניות - אחת עם החרגה ואחת בלי\n";
$p = [
  ['id'=>1,'member_id'=>1,'amount'=>300,'excluded_ids'=>[3]],  // בשר, בלי ג
  ['id'=>2,'member_id'=>3,'amount'=>100,'excluded_ids'=>[]],   // שתייה, כולם
];
$r = calculateGroupBalance($m,$p);
check('א should pay', byNick($r,'א')['shouldPay'], 200 + 50);
check('ב should pay', byNick($r,'ב')['shouldPay'], 100 + 25);
check('ג should pay', byNick($r,'ג')['shouldPay'], 0 + 25);
check('א paid', byNick($r,'א')['actuallyPaid'], 300);
check('ג paid', byNick($r,'ג')['actuallyPaid'], 100);
check('ג open balance (100-25)', byNick($r,'ג')['openBalance'], 75);
$sumShould = 0; foreach ($r['calculations'] as $c) $sumShould += $c['shouldPay'];
check('סך "צריך לשלם" = סך ההוצאות', $sumShould, 400);

// ---- 4. group percentage under 100 is still preserved as unallocated
echo "\n4. סך אחוזים 80% - הפער נשמר\n";
$m2 = [member(1,'א','percentage',50), member(2,'ב','percentage',30)];
$p2 = [['id'=>1,'member_id'=>1,'amount'=>1000,'excluded_ids'=>[]]];
$r = calculateGroupBalance($m2,$p2);
check('א', byNick($r,'א')['shouldPay'], 500);
check('ב', byNick($r,'ב')['shouldPay'], 300);
check('לא מוקצה', $r['unallocated'], 200);
check('missingPercentage', $r['missingPercentage'], 20);

// ---- 5. 80% baseline + exclusion: gap preserved, excluded share redistributed
echo "\n5. סך 80% + החרגה של ב\n";
$r = calculateGroupBalance($m2, [['id'=>1,'member_id'=>1,'amount'=>1000,'excluded_ids'=>[2]]]);
check('א מקבל את כל 80% הכיסוי', byNick($r,'א')['shouldPay'], 800);
check('ב', byNick($r,'ב')['shouldPay'], 0);
check('הפער של 20% נשמר', $r['unallocated'], 200);

// ---- 6. fixed member proration
echo "\n6. חבר סכום קבוע\n";
$m3 = [member(1,'א','percentage',100), member(2,'סבתא','fixed',200)];
$p3 = [['id'=>1,'member_id'=>1,'amount'=>1000,'excluded_ids'=>[]]];
$r = calculateGroupBalance($m3,$p3);
check('סבתא משלמת בדיוק 200', byNick($r,'סבתא')['shouldPay'], 200);
check('א משלם את היתרה', byNick($r,'א')['shouldPay'], 800);
check('לא מוקצה', $r['unallocated'], 0);

// ---- 7. fixed member excluded from part of the spend pays proportionally less
echo "\n7. חבר סכום קבוע מוחרג מחצי מההוצאה\n";
$p4 = [
  ['id'=>1,'member_id'=>1,'amount'=>500,'excluded_ids'=>[2]],
  ['id'=>2,'member_id'=>1,'amount'=>500,'excluded_ids'=>[]],
];
$r = calculateGroupBalance($m3,$p4);
check('סבתא משלמת חצי מהתחייבותה', byNick($r,'סבתא')['shouldPay'], 100);
check('א משלם את השאר', byNick($r,'א')['shouldPay'], 900);
$sumShould = 0; foreach ($r['calculations'] as $c) $sumShould += $c['shouldPay'];
check('סך = 1000', $sumShould, 1000);

// ---- 8. settlements close the loop
echo "\n8. התחשבנות מקזזת חוב\n";
$m5 = [member(1,'א','percentage',50), member(2,'ב','percentage',50)];
$p5 = [['id'=>1,'member_id'=>1,'amount'=>200,'excluded_ids'=>[]]];
$r = calculateGroupBalance($m5,$p5);
check('לפני: ב חייב 100', abs(byNick($r,'ב')['openBalance']), 100);
check('לפני: העברה אחת', count($r['transfers']), 1);
$r = calculateGroupBalance($m5,$p5,[['from_member_id'=>2,'to_member_id'=>1,'amount'=>100]]);
check('אחרי: ב מאוזן', byNick($r,'ב')['openBalance'], 0);
check('אחרי: א מאוזן', byNick($r,'א')['openBalance'], 0);
check('אחרי: אין העברות', count($r['transfers']), 0);
check('isBalanced', $r['isBalanced'] ? 1:0, 1);

// ---- 9. partial settlement
echo "\n9. התחשבנות חלקית\n";
$r = calculateGroupBalance($m5,$p5,[['from_member_id'=>2,'to_member_id'=>1,'amount'=>40]]);
check('נותרו 60', $r['transfers'][0]['amount'], 60);

// ---- 10. everyone excluded from a purchase -> unallocated, no crash
echo "\n10. כולם מוחרגים מקנייה\n";
$r = calculateGroupBalance($m5,[['id'=>1,'member_id'=>1,'amount'=>100,'excluded_ids'=>[1,2]]]);
check('הכל לא מוקצה', $r['unallocated'], 100);
check('אין קריסה', count($r['calculations']), 2);

// ---- 11. edge cases
echo "\n11. מקרי קצה\n";
$r = calculateGroupBalance([], []);
check('בלי חברים ובלי קניות', $r['totalAmount'], 0);
$r = calculateGroupBalance($m5, []);
check('חברים בלי קניות', count($r['transfers']), 0);
$r = calculateGroupBalance([member(1,'א','fixed',500)], [['id'=>1,'member_id'=>1,'amount'=>100,'excluded_ids'=>[]]]);
check('fixedOverflow מזוהה', $r['fixedOverflow'] ? 1:0, 1);

// ---- 12. transfers always net to zero and never exceed debt
echo "\n12. שלמות ההעברות\n";
$m6 = [member(1,'א','percentage',40),member(2,'ב','percentage',30),member(3,'ג','percentage',20),member(4,'ד','percentage',10)];
$p6 = [
  ['id'=>1,'member_id'=>1,'amount'=>523.40,'excluded_ids'=>[4]],
  ['id'=>2,'member_id'=>2,'amount'=>217.15,'excluded_ids'=>[]],
  ['id'=>3,'member_id'=>3,'amount'=>88.90,'excluded_ids'=>[1,2]],
];
$r = calculateGroupBalance($m6,$p6);
$net = []; foreach ($m6 as $mm) $net[$mm['id']] = 0;
foreach ($r['transfers'] as $t) { $net[$t['from_id']] -= $t['amount']; $net[$t['to_id']] += $t['amount']; }
foreach ($r['calculations'] as $c) {
    $id = $c['member']['id'];
    check("  {$c['member']['nickname']}: העברות מאזנות את היתרה", $net[$id], round($c['openBalance'],2), 0.02);
}
check('סך ההעברות מתאזן', array_sum($net), 0, 0.001);
$sumShould = 0; foreach ($r['calculations'] as $c) $sumShould += $c['shouldPay'];
check('סך "צריך לשלם" = סך ההוצאות', $sumShould, 523.40+217.15+88.90);

echo "\n" . str_repeat('=',50) . "\nעבר: $pass | נכשל: $fail\n";
exit($fail > 0 ? 1 : 0);
