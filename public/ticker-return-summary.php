<?php
declare(strict_types=1);
require_once __DIR__ . '/strategy-return-run-lib.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405);throw new InvalidArgumentException('Используйте POST.'); }
    $key=(string) ($_POST['run_key'] ?? '');$run=strategyReturnRunStatus($key);
    $symbols=json_decode((string) ($_POST['symbols'] ?? '[]'),true);
    $minimum=(string) ($_POST['save_mode'] ?? '1') === '0' ? 1 : 3;
    $leaders=[];
    if (!$run || $run['status'] !== 'ready' || $run['start_day'] !== ($_POST['start_day'] ?? '') || $run['end_day'] !== ($_POST['end_day'] ?? '')) {
        echo json_encode(['status'=>'missing','symbols'=>[]]);exit;
    }
    foreach (is_array($symbols)?$symbols:[] as $symbol) {
        $db=strategyReturnRunDatabase($key,(string) $symbol);if(!$db)continue;
        $query=$db->prepare('SELECT entry_id,entry_label,exit_id,exit_label,cumulative_pnl AS pnl,closed_trade_count AS trade_count FROM totals WHERE closed_trade_count>=? AND cumulative_pnl IS NOT NULL ORDER BY pnl DESC,entry_id,exit_id LIMIT 1');
        $query->execute([$minimum]);$row=$query->fetch();if($row)$leaders[]=['symbol'=>$symbol]+$row;
    }
    usort($leaders,static fn($a,$b)=>($b['pnl']<=>$a['pnl'])?:strcmp($a['symbol'],$b['symbol']));
    echo json_encode(['status'=>'ready','run_key'=>$key,'symbols'=>$leaders,'ranked_count'=>count($leaders),'universe_size'=>count($symbols),'start_day'=>$run['start_day'],'end_day'=>$run['end_day']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $error) {http_response_code(503);echo json_encode(['status'=>'error','message'=>'Не удалось загрузить результаты этого расчёта.'],JSON_UNESCAPED_UNICODE);}
