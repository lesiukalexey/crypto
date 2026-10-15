<?php
declare(strict_types=1);
require_once __DIR__ . '/../hedge_simulation.php';
function nearSingle(float $actual, float $expected): void {if(abs($actual-$expected)>1e-9)throw new RuntimeException("$actual != $expected");}
$rows=[];
foreach([[100,101],[110,111],[105,106]] as $i=>[$bid,$ask])$rows[]=['_timestamp_ms'=>1000000+$i*60000,'bid_price'=>$bid,'ask_price'=>$ask,'last_price'=>($bid+$ask)/2];
$fee=.001;$notional=100/(1+$fee);
$long=simulate_cross_hedge($rows,[],100,$fee,120,true,false);
$short=simulate_cross_hedge([],$rows,100,$fee,120,false,true);
nearSingle($long['summary']['long_notional'],$notional);nearSingle($long['summary']['short_notional'],0);
nearSingle($short['summary']['short_notional'],$notional);nearSingle($short['summary']['long_notional'],0);
$longQuantity=$notional/101;$shortQuantity=$notional/100;
nearSingle($long['summary']['net_pnl'],(105-101)*$longQuantity-$notional*$fee-105*$longQuantity*$fee);
nearSingle($short['summary']['net_pnl'],(100-106)*$shortQuantity-$notional*$fee-106*$shortQuantity*$fee);
nearSingle($long['summary']['short_pnl'],0);nearSingle($short['summary']['long_pnl'],0);
nearSingle($long['summary']['maximum_drawdown'],(110-105)*$longQuantity*(1-$fee));
nearSingle($short['summary']['maximum_drawdown'],(111-101)*$shortQuantity*(1+$fee));
if(count($long['points'])!==3||count($short['points'])!==3)throw new RuntimeException('Missing points');
if(simulate_cross_hedge([],[],100,$fee,120,true,false)['summary']!==null)throw new RuntimeException('Missing quote data');
try{simulate_cross_hedge([],[],100,$fee,120,false,false);throw new RuntimeException('Both empty accepted');}catch(InvalidArgumentException){}
echo "Single long/short fees, full balance, drawdowns and empty cases passed\n";
