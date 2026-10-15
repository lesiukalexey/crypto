<?php
declare(strict_types=1);
require_once __DIR__ . '/strategy-return-run-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$stream = (string) ($_GET['rank_progress'] ?? '') === '1' && (string) ($_GET['rank_options'] ?? '') === '1';
if ($stream) {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) ob_end_flush();
}
$respond = static function (array $data) use ($stream): never {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ($stream ? "\n" : '');
    exit;
};
try {
    $key = (string) ($_GET['run_key'] ?? '');
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $run = strategyReturnRunStatus($key);
    $db = strategyReturnRunDatabase($key, $symbol);
    if ($run && ($run['status'] ?? '') === 'pending') $respond(['status'=>'running']);
    if ($run && ($run['status'] ?? '') === 'error') $respond(['status'=>'error','message'=>'Расчёт завершился ошибкой; запустите его повторно.']);
    if (!$run || !$db) $respond(['status'=>'rebuild_required','message'=>'Для этого периода нужна доходность из отдельного расчёта. Старые смешанные точки не используются.']);
    if (($run['status'] ?? '') !== 'ready') $respond(['status'=>'running']);
    $start = (string) ($_GET['start_day'] ?? $run['start_day']);
    $end = (string) ($_GET['end_day'] ?? $run['end_day']);
    if ($start !== $run['start_day'] || $end !== $run['end_day']) $respond(['status'=>'rebuild_required','message'=>'Для нового диапазона нужен отдельный расчёт доходности.']);
    if (isset($_GET['exit_confirmation_mode']) && $_GET['exit_confirmation_mode'] !== ($run['exit_confirmation_mode'] ?? 'immediate')) $respond(['status'=>'rebuild_required','message'=>'Режим выхода изменён: нужен соответствующий расчёт.']);
    if (($run['exit_confirmation_mode'] ?? 'immediate') !== 'immediate' && isset($_GET['exit_confirmation_snapshots']) && (int)$_GET['exit_confirmation_snapshots'] !== (int)$run['exit_confirmation_snapshots']) $respond(['status'=>'rebuild_required','message'=>'Интервал подтверждения изменён: нужен соответствующий расчёт.']);
    try { $context = json_decode((string)$db->query("SELECT value FROM metadata WHERE name='context'")->fetchColumn(),true); } catch (PDOException $error) { $context=null; }
    if (!is_array($context)) $respond(['status'=>'rebuild_required','message'=>'Параметры старого расчёта не сохранены; пересчитайте доходность.']);
    foreach (is_array($context)?$context:[] as $name=>$value) {
        if (!array_key_exists($name,$_GET)) continue;
        if (is_numeric($value) && is_numeric($_GET[$name]) ? abs((float)$value-(float)$_GET[$name])>1e-10 : (string)$value!==(string)$_GET[$name]) $respond(['status'=>'rebuild_required','message'=>'Параметры расчёта изменились: нужен соответствующий расчёт доходности.']);
    }
    $entry = (string) ($_GET['entry_id'] ?? '');
    $exit = (string) ($_GET['exit_id'] ?? '');
    if ((string) ($_GET['rank_options'] ?? '') === '1') {
        if ($stream) { echo "{\"status\":\"progress\",\"progress\":10}\n"; flush(); }
        $minimum = max(1, (int) ($_GET['min_trades'] ?? 1));
        $query = $db->prepare('SELECT entry_id AS id,MAX(entry_label) AS label,MAX(cumulative_pnl) AS best_pnl FROM totals WHERE closed_trade_count>=? AND cumulative_pnl IS NOT NULL GROUP BY entry_id ORDER BY best_pnl DESC,entry_id');
        $query->execute([$minimum]);$entries = $query->fetchAll();
        if ((string) ($_GET['prefer_best_entry'] ?? '') === '1' || $entry === '') $entry = (string) ($entries[0]['id'] ?? '');
        $query = $db->prepare('SELECT exit_id AS id,exit_label AS label,cumulative_pnl AS pnl,closed_trade_count AS trade_count FROM totals WHERE entry_id=? AND closed_trade_count>=? AND cumulative_pnl IS NOT NULL ORDER BY pnl DESC,exit_id');
        $query->execute([$entry,$minimum]);$exits=$query->fetchAll();
        foreach ($exits as &$item) $item['label'] .= ' · ' . $item['trade_count'] . ' сделок · ' . sprintf('%+.4f%%', $item['pnl']);
        unset($item);
        $respond(['status'=>'ready','run'=>$run,'symbol'=>$symbol,'selected_entry_id'=>$entry,'ranked_entries'=>$entries,'ranked_exits'=>$exits]);
    }
    $query=$db->prepare('SELECT * FROM totals WHERE entry_id=? AND exit_id=?');$query->execute([$entry,$exit]);$result=$query->fetch();
    if (!$result) $respond(['status'=>'missing']);
    $respond(['status'=>'ready','run'=>$run,'chart_range'=>['start_day'=>$start,'end_day'=>$end],
        'selected'=>['symbol'=>$symbol,'entry_id'=>$entry,'entry_label'=>$result['entry_label'],'exit_id'=>$exit,'exit_label'=>$result['exit_label']],
        'points'=>json_decode($result['points'],true,512,JSON_THROW_ON_ERROR)]);
} catch (Throwable $error) {
    error_log('Isolated strategy returns: ' . $error->getMessage());
    http_response_code(503);$respond(['status'=>'error','message'=>'Не удалось загрузить доходность выбранного расчёта.']);
}
