<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$streamRanks = (string) ($_GET['rank_progress'] ?? '') === '1' && (string) ($_GET['rank_options'] ?? '') === '1';
if ($streamRanks) {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) ob_end_flush();
}
$emitRankProgress = static function (float $progress) use ($streamRanks): void {
    if (!$streamRanks) return;
    echo json_encode(['status'=>'progress','progress'=>round($progress, 1)], JSON_THROW_ON_ERROR) . "\n";
    flush();
};

try {
    $runKey = trim((string) ($_GET['run_key'] ?? ''));
    $rankWithoutRun = $runKey === '' && (string) ($_GET['rank_options'] ?? '') === '1';
    if (!$rankWithoutRun && !preg_match('/^[a-f0-9]{64}$/', $runKey)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Некорректный ключ расчёта.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') throw new RuntimeException('Не задан пароль подключения к MySQL.');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    $run = null;
    if ($rankWithoutRun) {
        $mode = (string) ($_GET['exit_confirmation_mode'] ?? 'immediate');
        if (!in_array($mode, ['immediate', 'both_delayed', 'tp_delayed', 'sl_delayed', 'none'], true)) throw new InvalidArgumentException('Invalid confirmation mode.');
        $run = ['status'=>'ready', 'start_day'=>(string) ($_GET['start_day'] ?? ''),
            'end_day'=>(string) ($_GET['end_day'] ?? ''), 'exit_confirmation_mode'=>$mode,
            'exit_confirmation_snapshots'=>$mode === 'immediate' ? 0 : (int) ($_GET['exit_confirmation_snapshots'] ?? 60)];
    }
    foreach (glob($cacheDir . '/*.status.json') ?: [] as $statusPath) {
        if ($rankWithoutRun) break;
        $candidateStatus = json_decode((string) @file_get_contents($statusPath), true);
        if (is_array($candidateStatus) && ($candidateStatus['return_run_key'] ?? '') === $runKey) {
            $run = $candidateStatus;
            break;
        }
    }
    if (!is_array($run)) {
        echo json_encode(['status' => 'missing'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($run['status'] !== 'ready') {
        echo json_encode(['status' => $run['status']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $exitConfirmationMode = (string) ($run['exit_confirmation_mode'] ?? 'immediate');
    $exitConfirmationSnapshots = (int) ($run['exit_confirmation_snapshots'] ?? 0);
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $entryId = trim((string) ($_GET['entry_id'] ?? ''));
    $exitId = trim((string) ($_GET['exit_id'] ?? ''));
    $rangeStartDay = trim((string) ($_GET['start_day'] ?? $run['start_day']));
    $rangeEndDay = trim((string) ($_GET['end_day'] ?? $run['end_day']));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStartDay)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEndDay)
        || $rangeStartDay < $run['start_day'] || $rangeEndDay > $run['end_day'] || $rangeStartDay > $rangeEndDay) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Выбранный период не входит в рассчитанный диапазон.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($entryId !== '' && $exitId !== '' && $symbol !== '') {
        $pointStartUtc = (new DateTimeImmutable($rangeStartDay . ' 00:00:00', new DateTimeZone('Europe/Kyiv')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $pointEndUtc = (new DateTimeImmutable($rangeEndDay . ' 00:00:00', new DateTimeZone('Europe/Kyiv')))
            ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $eventsQuery = $pdo->prepare(
            'SELECT s.entry_label,s.exit_label,p.point_minute,p.return_percent,p.trade_count '
            . 'FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
            . 'WHERE p.symbol=? AND s.entry_id=? AND s.exit_id=? AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? '
            . 'AND p.point_minute>=? AND p.point_minute<? ORDER BY p.point_minute'
        );
        $eventsQuery->execute([$symbol, $entryId, $exitId, $exitConfirmationMode, $exitConfirmationSnapshots, $pointStartUtc, $pointEndUtc]);
        $events = $eventsQuery->fetchAll();
        $entryLabel = (string) ($events[0]['entry_label'] ?? $entryId);
        $exitLabel = (string) ($events[0]['exit_label'] ?? $exitId);
        $closedCount = 0;
        $cumulativeFactor = 1.0;
        $kyiv = new DateTimeZone('Europe/Kyiv');
        $utc = new DateTimeZone('UTC');
        $rangeStartUtc = new DateTimeImmutable($rangeStartDay . ' 00:00:00', $kyiv);
        $rangeEndUtc = (new DateTimeImmutable($rangeEndDay . ' 00:00:00', $kyiv))->modify('+1 day');
        $events = array_values(array_filter($events, static function (array $event) use ($rangeStartUtc, $rangeEndUtc, $utc): bool {
            $exit = new DateTimeImmutable((string) $event['point_minute'], $utc);
            return $exit >= $rangeStartUtc && $exit < $rangeEndUtc;
        }));
        foreach ($events as &$event) {
            $closedCount += (int) $event['trade_count'];
            $exitTime = new DateTimeImmutable((string) $event['point_minute'], $utc);
            $cumulativeFactor *= 1.0 + (float) $event['return_percent'] / 100.0;
            $event['cumulative_return_percent'] = sprintf('%.12f', ($cumulativeFactor - 1.0) * 100.0);
            $event['trade_index'] = $closedCount;
            $event['point_type'] = 'closed';
            $event['entry_at_utc'] = $exitTime->format('Y-m-d\\TH:i:s\\Z');
            $event['exit_at_utc'] = $exitTime->format('Y-m-d\\TH:i:s.v\\Z');
            $event['point_day'] = $exitTime->setTimezone($kyiv)->format('Y-m-d');
            $event['cumulative_pnl'] = $event['cumulative_return_percent'];
            $event['closed_trade_count'] = $closedCount;
        }
        unset($event);
        $selectedPoints = $events;
        echo json_encode([
            'status' => 'ready', 'run' => $run, 'chart_range' => ['start_day' => $rangeStartDay, 'end_day' => $rangeEndDay], 'symbols' => [$symbol],
            'selected' => [
                'symbol' => $symbol, 'entry_id' => $entryId, 'entry_label' => $entryLabel,
                'exit_id' => $exitId, 'exit_label' => $exitLabel,
            ],
            'points' => array_map(static function (array $point): array {
                unset($point['entry_label'], $point['exit_label']);
                return $point;
            }, $selectedPoints),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
    if ((string) ($_GET['rank_options'] ?? '') === '1' && $symbol !== '') {
        $minTrades = max(1, min(1000000, (int) ($_GET['min_trades'] ?? 1)));
        $zone = new DateTimeZone('Europe/Kyiv');
        $startUtc = (new DateTimeImmutable($rangeStartDay . ' 00:00:00', $zone))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = (new DateTimeImmutable($rangeEndDay . ' 00:00:00', $zone))->modify('+1 day')
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $totalsSql = 'SELECT p.strategy_id,(EXP(SUM(LN(1 + p.return_percent / 100))) - 1) * 100 AS cumulative_pnl,'
            . 'SUM(p.trade_count) AS closed_trade_count FROM strategy_return_points p '
            . 'JOIN strategy_return_profiles profile ON profile.strategy_id=p.strategy_id '
            . 'WHERE p.symbol=? AND profile.exit_confirmation_mode=? AND profile.exit_confirmation_snapshots=? '
            . 'AND p.point_minute>=? AND p.point_minute<? GROUP BY p.strategy_id';
        $totalsParams = [$symbol, $exitConfirmationMode, $exitConfirmationSnapshots, $startUtc, $endUtc];
        $rankedEntries = [];
        $emitRankProgress(1);
        $profilesQuery = $pdo->prepare('SELECT DISTINCT entry_id FROM strategy_return_profiles WHERE exit_confirmation_mode=? AND exit_confirmation_snapshots=? ORDER BY entry_id');
        $profilesQuery->execute([$exitConfirmationMode, $exitConfirmationSnapshots]);
        $entryIds = $profilesQuery->fetchAll(PDO::FETCH_COLUMN);
        $completedEntries = 0;
        foreach (array_chunk($entryIds, 12) as $chunk) {
            $chunkSql = str_replace(' GROUP BY p.strategy_id',
                ' AND profile.entry_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY p.strategy_id', $totalsSql);
            $entriesQuery = $pdo->prepare(
                'SELECT s.entry_id,MAX(s.entry_label) AS entry_label,MAX(t.cumulative_pnl) AS best_pnl '
                . 'FROM (' . $chunkSql . ') t JOIN strategy_return_profiles s ON s.strategy_id=t.strategy_id '
                . 'WHERE t.closed_trade_count>=? GROUP BY s.entry_id'
            );
            $entriesQuery->execute([...$totalsParams, ...$chunk, $minTrades]);
            while (($row = $entriesQuery->fetch()) !== false) {
                $rankedEntries[] = ['id'=>$row['entry_id'], 'best_pnl'=>(float) $row['best_pnl'], 'label'=>$row['entry_label']];
            }
            $completedEntries += count($chunk);
            $emitRankProgress(90 * $completedEntries / max(1, count($entryIds)));
        }
        usort($rankedEntries, static fn(array $a, array $b): int => ($b['best_pnl'] <=> $a['best_pnl']) ?: strcmp($a['id'], $b['id']));
        $bestEntryId = (string) ($rankedEntries[0]['id'] ?? '');
        if ((string) ($_GET['prefer_best_entry'] ?? '') === '1' || $entryId === '' || !in_array($entryId, array_column($rankedEntries, 'id'), true)) $entryId = $bestEntryId;
        $exitRanks = [];
        if ($entryId !== '') {
            $exitsQuery = $pdo->prepare(
                'SELECT s.exit_id,MAX(s.exit_label) AS exit_label,'
                . '(EXP(SUM(LN(1 + p.return_percent / 100))) - 1) * 100 AS cumulative_pnl,SUM(p.trade_count) AS closed_trade_count '
                . 'FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
                . 'WHERE p.symbol=? AND s.entry_id=? AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? '
                . 'AND p.point_minute>=? AND p.point_minute<? GROUP BY p.strategy_id,s.exit_id '
                . 'HAVING closed_trade_count>=? ORDER BY cumulative_pnl DESC,s.exit_id ASC'
            );
            $exitsQuery->execute([$symbol, $entryId, $exitConfirmationMode, $exitConfirmationSnapshots, $startUtc, $endUtc, $minTrades]);
            while (($row = $exitsQuery->fetch()) !== false) {
                $pnl = (float) $row['cumulative_pnl'];
                $count = (int) $row['closed_trade_count'];
                $exitRanks[] = ['id'=>$row['exit_id'], 'pnl'=>$pnl, 'trade_count'=>$count,
                    'label'=>$row['exit_label'] . ' · ' . $count . ' сделок · ' . sprintf('%+.4f', $pnl) . '%'];
            }
        }
        $emitRankProgress(100);
        echo json_encode([
            'status' => 'ready', 'run' => $run, 'symbol' => $symbol, 'selected_entry_id' => $entryId,
            'ranked_entries' => $rankedEntries, 'ranked_exits' => $exitRanks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ($streamRanks ? "\n" : '');
        exit;
    }
    $symbolQuery = $pdo->prepare('SELECT DISTINCT p.symbol FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id WHERE s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? ORDER BY p.symbol');
    $symbolQuery->execute([$exitConfirmationMode, $exitConfirmationSnapshots]);
    $symbols = array_column($symbolQuery->fetchAll(), 'symbol');
    if ($symbol === '' && $symbols !== []) $symbol = (string) $symbols[0];
    if (!in_array($symbol, $symbols, true)) $symbol = (string) ($symbols[0] ?? '');
    $entryQuery = $pdo->prepare(
        'SELECT s.entry_id,MAX(s.entry_label) AS entry_label '
        . 'FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
        . 'WHERE p.symbol=? AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? GROUP BY s.entry_id ORDER BY s.entry_id'
    );
    $entryQuery->execute([$symbol, $exitConfirmationMode, $exitConfirmationSnapshots]);
    $entries = $entryQuery->fetchAll();
    $entryIds = array_map(static fn (array $entry): string => (string) $entry['entry_id'], $entries);
    if (!in_array($entryId, $entryIds, true)) $entryId = (string) ($entryIds[0] ?? '');
    $exitQuery = $pdo->prepare(
        'SELECT s.exit_id,MAX(s.exit_label) AS exit_label FROM strategy_return_points p '
        . 'JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
        . 'WHERE p.symbol=? AND s.entry_id=? AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? GROUP BY s.exit_id ORDER BY s.exit_id'
    );
    $exitQuery->execute([$symbol, $entryId, $exitConfirmationMode, $exitConfirmationSnapshots]);
    $exits = $exitQuery->fetchAll();
    $exitIds = array_values(array_unique(array_map(static fn (array $option): string => (string) $option['exit_id'], $exits)));
    if (!in_array($exitId, $exitIds, true)) $exitId = (string) ($exitIds[0] ?? '');
    $selectedEntryIndex = $entryId === '' ? false : array_search($entryId, $entryIds, true);
    $selectedEntry = $selectedEntryIndex === false ? ['entry_label' => $entryId] : $entries[$selectedEntryIndex];
    $options = array_map(
        static fn (array $option): array => ['entry_id' => $entryId, 'entry_label' => (string) $selectedEntry['entry_label']] + $option,
        $exits,
    );
    $pointsQuery = $pdo->prepare(
        'SELECT p.point_minute,p.return_percent,p.trade_count '
        . 'FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
        . 'WHERE p.symbol=? AND s.entry_id=? AND s.exit_id=? AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? ORDER BY p.point_minute'
    );
    $pointsQuery->execute([$symbol, $entryId, $exitId, $exitConfirmationMode, $exitConfirmationSnapshots]);
    $events = $pointsQuery->fetchAll();
    $rangeStartUtc = new DateTimeImmutable($rangeStartDay . ' 00:00:00', new DateTimeZone('Europe/Kyiv'));
    $rangeEndUtc = (new DateTimeImmutable($rangeEndDay . ' 00:00:00', new DateTimeZone('Europe/Kyiv')))->modify('+1 day');
    $events = array_values(array_filter($events, static function (array $event) use ($rangeStartUtc, $rangeEndUtc): bool {
        $utc = new DateTimeZone('UTC');
        $point = new DateTimeImmutable((string) $event['point_minute'], $utc);
        return $point >= $rangeStartUtc && $point < $rangeEndUtc;
    }));
    $cumulativeFactor = 1.0;
    $closedCount = 0;
    $kyiv = new DateTimeZone('Europe/Kyiv');
    $utc = new DateTimeZone('UTC');
    foreach ($events as &$event) {
        $closedCount += (int) $event['trade_count'];
        $exitTime = new DateTimeImmutable((string) $event['point_minute'], $utc);
        $cumulativeFactor *= 1.0 + (float) $event['return_percent'] / 100.0;
        $event['cumulative_return_percent'] = sprintf('%.12f', ($cumulativeFactor - 1.0) * 100.0);
        $event['trade_index'] = $closedCount;
        $event['point_type'] = 'closed';
        $event['entry_at_utc'] = $exitTime->format('Y-m-d\\TH:i:s\\Z');
        $event['exit_at_utc'] = $exitTime->format('Y-m-d\\TH:i:s.v\\Z');
        $event['point_day'] = $exitTime->setTimezone($kyiv)->format('Y-m-d');
        $event['cumulative_pnl'] = $event['cumulative_return_percent'];
        $event['closed_trade_count'] = $closedCount;
    }
    unset($event);
    echo json_encode([
        'status' => 'ready', 'run' => $run, 'symbols' => $symbols, 'entries' => $entries,
        'exits' => $exits, 'options' => $options,
        'selected' => ['symbol' => $symbol, 'entry_id' => $entryId, 'exit_id' => $exitId],
        'points' => $events,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Не удалось загрузить сохранённую доходность.'], JSON_UNESCAPED_UNICODE);
}
