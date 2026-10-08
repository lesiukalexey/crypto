<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Kyiv');
require_once __DIR__ . '/ranking-parameters.php';
$config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$entryProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$exitProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$rankingError = null;
try {
    $rankingParameters = rankingParameters($_GET, $entryProfileConfig, $exitProfileConfig);
} catch (InvalidArgumentException $exception) {
    $rankingError = $exception->getMessage();
    $rankingParameters = rankingParameters([], $entryProfileConfig, $exitProfileConfig);
}
$rankingRanges = $rankingParameters['ranges'];
$rankingModes = $rankingParameters['modes'];
$thresholdCustomRange = $rankingModes['threshold'] === 'range' ? $rankingRanges['threshold'][0] : ['from' => 1, 'to' => 10, 'step' => 1];
$windowCustomRange = $rankingModes['window'] === 'range' ? $rankingRanges['window'][0] : ['from' => 5, 'to' => 40, 'step' => 5];
$entryThresholdsCents = $rankingParameters['worker']['entry_thresholds_cents'];
$entryWindows = $rankingParameters['worker']['entry_windows'];
$entryThresholdText = implode(', ', array_map(static fn (int $cents): string => '±' . number_format($cents / 100, 2, ',', ' '), $entryThresholdsCents));
$entryWindowText = implode(', ', array_map(static fn (int $window): string => (string) $window, $entryWindows));
$defaultThresholdText = implode(', ', array_map(static fn (int $cents): string => '±' . number_format($cents / 100, 2, ',', ' '), $entryProfileConfig['thresholds_cents']));
$defaultWindowText = implode(', ', array_map('strval', $entryProfileConfig['snapshot_windows']));
$immediateEntryText = implode(' и ', array_map(static fn (string $direction): string => 'сразу — 1 заявка на ' . ($direction === 'short' ? 'продажу' : 'покупку'), $entryProfileConfig['immediate_directions'] ?? []));
$exitRangeText = static function (array $range): string {
    $min = number_format($range['from'] / 100, 2, ',', ' ');
    $max = number_format($range['to'] / 100, 2, ',', ' ');
    $step = number_format($range['step'] / 100, 2, ',', ' ');
    return $min . '–' . $max . ' USDT (шаг ' . $step . ')';
};
$db = $config['mysql'];
$symbols = [];
$rows = [];
$error = $rankingError;
$timezone = new DateTimeZone('Europe/Kyiv');
$today = new DateTimeImmutable('today', $timezone);
$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$startDayInput = (string) ($_GET['start_day'] ?? $_GET['day'] ?? '2026-10-06');
$endDayInput = (string) ($_GET['end_day'] ?? $_GET['day'] ?? $today->format('Y-m-d'));
$defaultEntryConfig = in_array(10, $entryWindows, true) && in_array(1, $entryThresholdsCents, true)
    ? 'entry:10:1' : 'entry:' . $entryWindows[0] . ':' . $entryThresholdsCents[0];
$entryConfig = (string) ($_GET['entry_config'] ?? $defaultEntryConfig);
$saveMode = (string) ($_GET['save_mode'] ?? '1') !== '0';
$startingBalance = trim((string) ($_GET['balance'] ?? '10'));
$feePercent = trim((string) ($_GET['fee'] ?? '0.06'));
$defaultExitConfig = 'fixed:' . $rankingRanges['fixed_tp']['from'] . ':' . $rankingRanges['fixed_sl']['from'];
$exitConfig = (string) ($_GET['exit_config'] ?? $defaultExitConfig);
$symbolSort = (string) ($_GET['symbol_sort'] ?? '') === 'profit' ? 'profit' : '';
$isCurrentDay = false;
$decimalPattern = '/^\d{1,12}(?:\.\d{1,12})?$/';
if (!preg_match($decimalPattern, $startingBalance) || (float) $startingBalance <= 0 || (float) $startingBalance > 1000000000) {
    $error = 'Стартовый баланс должен быть больше 0 и не превышать 1 000 000 000 USDT.';
    $startingBalance = '10';
}
if (!preg_match($decimalPattern, $feePercent) || (float) $feePercent < 0 || (float) $feePercent > 5) {
    $error = 'Комиссия должна быть от 0 до 5 процентов за сторону.';
    $feePercent = '0.06';
}
$isImmediateEntry = preg_match('/^immediate:(short|long)$/', $entryConfig, $immediateMatches) === 1
    && in_array($immediateMatches[1], $entryProfileConfig['immediate_directions'] ?? [], true);
if (!$isImmediateEntry && (!preg_match('/^entry:(\d+):(\d+)$/', $entryConfig, $entryMatches)
    || !in_array((int) $entryMatches[1], $entryWindows, true)
    || !in_array((int) $entryMatches[2], $entryThresholdsCents, true))) {
    $entryConfig = $defaultEntryConfig;
    $entryMatches = [null, (string) $entryWindows[0], (string) $entryThresholdsCents[0]];
}
$momentumLookback = $isImmediateEntry ? 0 : (int) $entryMatches[1];
$momentumThresholdCents = $isImmediateEntry ? 0 : (int) $entryMatches[2];
$momentumThreshold = $momentumThresholdCents / 100;
$profitTarget = '0.10';
$lossThreshold = '-0.10';
if (preg_match('/^fixed:(\d+):(\d+)$/', $exitConfig, $matches)
    && rankingHasValue((int) $matches[1], $rankingRanges['fixed_tp'])
    && rankingHasValue((int) $matches[2], $rankingRanges['fixed_sl'])) {
    $profitTarget = bcdiv($matches[1], '100', 2);
    $lossThreshold = bcdiv((string) (0 - (int) $matches[2]), '100', 2);
} elseif (preg_match('/^loss:(\d+)$/', $exitConfig, $matches)
    && rankingHasValue((int) $matches[1], $rankingRanges['loss'])) {
    $profitTarget = null;
    $lossThreshold = bcdiv((string) (0 - (int) $matches[1]), '100', 2);
} elseif (preg_match('/^profit:(\d+)$/', $exitConfig, $matches)
    && rankingHasValue((int) $matches[1], $rankingRanges['profit'])) {
    $profitTarget = bcdiv($matches[1], '100', 2);
    $lossThreshold = null;
} else {
    $exitConfig = $defaultExitConfig;
}

try {
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') {
        throw new RuntimeException('Не задан пароль подключения к MySQL.');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'],
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $stmt = $pdo->prepare('SELECT DISTINCT symbol FROM quote_snapshots WHERE category = ? ORDER BY symbol');
    $stmt->execute([$config['category']]);
    $symbols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($symbols !== []) {
        if (!in_array($symbol, $symbols, true)) {
            $symbol = $symbols[0];
        }
        $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startDayInput, $timezone);
        $startDateErrors = DateTimeImmutable::getLastErrors();
        if ($startDay === false || ($startDateErrors !== false && ($startDateErrors['warning_count'] > 0 || $startDateErrors['error_count'] > 0)) || $startDay->format('Y-m-d') !== $startDayInput) {
            throw new InvalidArgumentException('Укажите корректную начальную дату.');
        }
        $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endDayInput, $timezone);
        $endDateErrors = DateTimeImmutable::getLastErrors();
        if ($endDay === false || ($endDateErrors !== false && ($endDateErrors['warning_count'] > 0 || $endDateErrors['error_count'] > 0)) || $endDay->format('Y-m-d') !== $endDayInput) {
            throw new InvalidArgumentException('Укажите корректную конечную дату.');
        }
        if ($endDay < $startDay) {
            throw new InvalidArgumentException('Конечная дата не может быть раньше начальной.');
        }
        $isCurrentDay = $endDay->format('Y-m-d') === $today->format('Y-m-d');
        $dayAfterEnd = $endDay->modify('+1 day');
        $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $endUtc = $dayAfterEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $stmt = $pdo->prepare('SELECT received_at_utc, bid_price, ask_price, last_price FROM quote_snapshots WHERE category = ? AND symbol = ? AND received_at_utc >= ? AND received_at_utc < ? ORDER BY received_at_utc, id');
        $stmt->execute([$config['category'], $symbol, $startUtc, $endUtc]);
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error ??= $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось загрузить котировки. Проверьте доступность MySQL и настройки подключения.';
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rankingRangeRow(string $label, string $name, array $range, bool $money): string
{
    $format = static fn (int $value): string => $money ? number_format($value / 100, 2, '.', '') : (string) $value;
    $step = $money ? '0.01' : '1';
    $cells = '';
    foreach (['from' => 'От', 'to' => 'До', 'step' => 'Шаг'] as $key => $caption) {
        $cells .= '<label>' . $caption . '<input class="ranking-range-input" type="number" name="' . $name . '_' . $key
            . '" min="' . $step . '" step="' . $step . '" value="' . h($format($range[$key])) . '" required></label>';
    }
    return '<div class="ranking-range-row"><span>' . h($label) . '</span>' . $cells . '</div>';
}
function money(string $value): string { return number_format((float) $value, 6, '.', ' '); }
function formatKyivTime(string $value, DateTimeZone $timezone): string { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($timezone)->format('d.m H:i:s'); }
function epochMilliseconds(string $value): int { $time = new DateTimeImmutable($value, new DateTimeZone('UTC')); return ((int) $time->format('U')) * 1000 + (int) $time->format('v'); }
$series = ['bid_price' => ['name' => 'Bid', 'color' => '#42d6a4'], 'ask_price' => ['name' => 'Ask', 'color' => '#ff7188'], 'last_price' => ['name' => 'Last', 'color' => '#86a8ff']];
$points = [];
foreach ($rows as $row) {
    $time = (new DateTimeImmutable($row['received_at_utc'], new DateTimeZone('UTC')))->setTimezone($timezone);
    foreach ($series as $key => $meta) {
        $value = (float) $row[$key];
        if (is_finite($value)) $points[$key][] = ['t' => $time->getTimestamp() * 1000, 'v' => $value, 'label' => $row[$key]];
    }
}
$chart = [];
foreach ($series as $key => $meta) $chart[$key] = $points[$key] ?? [];
$feeRate = bcdiv($feePercent, '100', 24);
$balance = $startingBalance;
$trades = [];
$tradeEntryIndex = 0;
$rowCount = count($rows);
$hasContinuousWindow = static function (int $index) use ($rows, $momentumLookback): bool {
    if ($index < $momentumLookback) return false;
    for ($j = $index - $momentumLookback + 1; $j <= $index; $j++) {
        if (strtotime($rows[$j]['received_at_utc']) - strtotime($rows[$j - 1]['received_at_utc']) > 120) return false;
    }
    return true;
};
$findMomentumEntry = static function (int $fromIndex) use ($rows, $rowCount, $momentumLookback, $momentumThreshold, $hasContinuousWindow): ?array {
    for ($i = max($fromIndex, $momentumLookback + 1); $i < $rowCount - 1; $i++) {
        if (!$hasContinuousWindow($i) || !$hasContinuousWindow($i - 1)) continue;
        $priceNow = (float) $rows[$i]['last_price'];
        $priceBefore = (float) $rows[$i - $momentumLookback]['last_price'];
        $pricePrevious = (float) $rows[$i - 1]['last_price'];
        $pricePreviousBefore = (float) $rows[$i - $momentumLookback - 1]['last_price'];
        if ($priceBefore <= 0 || $pricePreviousBefore <= 0) continue;
        $move = $priceNow - $priceBefore;
        $previousMove = $pricePrevious - $pricePreviousBefore;
        if ($move >= $momentumThreshold && $previousMove < $momentumThreshold) return [$i, false];
        if ($move <= -$momentumThreshold && $previousMove > -$momentumThreshold) return [$i, true];
    }
    return null;
};
while (bccomp($balance, '0', 24) > 0) {
    if ($isImmediateEntry) {
        $signal = $tradeEntryIndex === 0 && $rowCount > 1 ? [0, $immediateMatches[1] === 'short'] : null;
    } else {
        $signal = $findMomentumEntry($tradeEntryIndex);
    }
    if ($signal === null) break;
    [$tradeEntryIndex, $tradeIsShort] = $signal;

    $entry = $rows[$tradeEntryIndex];
    $entryPrice = $tradeIsShort ? $entry['bid_price'] : $entry['ask_price'];
    $quantity = bcdiv($balance, bcmul($entryPrice, bcadd('1', $feeRate, 24), 24), 24);
    $entryFee = bcmul(bcmul($quantity, $entryPrice, 24), $feeRate, 24);
    $exitIndex = $rowCount - 1;
    $exitReason = $isCurrentDay ? 'Последний доступный снимок' : 'Конец выбранного периода';
    $tradeNet = '0';
    for ($i = $tradeEntryIndex + 1; $i < $rowCount; $i++) {
        $exitPrice = $tradeIsShort ? $rows[$i]['ask_price'] : $rows[$i]['bid_price'];
        $priceMove = $tradeIsShort ? bcsub($entryPrice, $exitPrice, 24) : bcsub($exitPrice, $entryPrice, 24);
        $gross = bcmul($quantity, $priceMove, 24);
        $exitFee = bcmul(bcmul($quantity, $exitPrice, 24), $feeRate, 24);
        $tradeNet = bcsub(bcsub($gross, $entryFee, 24), $exitFee, 24);
        if ($profitTarget !== null && bccomp($tradeNet, $profitTarget, 24) >= 0) {
            $exitIndex = $i;
            $exitReason = 'Цель: чистая прибыль ' . $profitTarget . ' USDT';
            break;
        }
        if ($lossThreshold !== null && bccomp($tradeNet, $lossThreshold, 24) <= 0) {
            $exitIndex = $i;
            $exitReason = 'Стоп: чистый убыток ' . ltrim($lossThreshold, '-') . ' USDT';
            break;
        }
    }
    $exit = $rows[$exitIndex];
    $exitPrice = $tradeIsShort ? $exit['ask_price'] : $exit['bid_price'];
    $priceMove = $tradeIsShort ? bcsub($entryPrice, $exitPrice, 24) : bcsub($exitPrice, $entryPrice, 24);
    $gross = bcmul($quantity, $priceMove, 24);
    $exitFee = bcmul(bcmul($quantity, $exitPrice, 24), $feeRate, 24);
    $tradeNet = bcsub(bcsub($gross, $entryFee, 24), $exitFee, 24);
    $balance = bcadd($balance, $tradeNet, 24);
    $trades[] = ['entry' => $entry, 'exit' => $exit, 'quantity' => $quantity, 'entry_price' => $entryPrice, 'exit_price' => $exitPrice, 'entry_fee' => $entryFee, 'exit_fee' => $exitFee, 'gross' => $gross, 'net' => $tradeNet, 'balance' => $balance, 'reason' => $exitReason, 'is_short' => $tradeIsShort];

    // Wait for a new momentum threshold crossing after each closed trade.
    $tradeEntryIndex = $exitIndex + 1;
}
$totalPnl = bcsub($balance, $startingBalance, 24);
$entrySideLabel = 'Сторона · цена';
$exitSideLabel = 'Сторона · цена';
$dateRangeLabel = $startDayInput === $endDayInput ? $startDayInput : $startDayInput . ' — ' . $endDayInput;
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bitget — история котировок</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#8995ad;--text:#e9eefb;--line:#273149}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1200px;margin:0 auto;padding:42px 24px}.eyebrow{color:#8ea5ff;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:700}h1{font-size:clamp(26px,4vw,40px);margin:5px 0 16px;letter-spacing:-.04em}.collector-status{display:inline-flex;align-items:center;gap:9px;margin:0 0 22px;padding:7px 11px;border:1px solid #34415e;border-radius:999px;background:#0c1222;color:#b7c1d5;font-size:13px}.collector-status:before{content:"";width:9px;height:9px;border-radius:50%;background:#8995ad;box-shadow:0 0 0 3px #8995ad22}.collector-status[data-status="active"]{color:#a8f0cf;border-color:#28654f}.collector-status[data-status="active"]:before{background:#42d6a4;box-shadow:0 0 0 3px #42d6a422}.collector-status[data-status="delayed"]{color:#ffe0a1;border-color:#705b2e}.collector-status[data-status="delayed"]:before{background:#f4c15f;box-shadow:0 0 0 3px #f4c15f22}.collector-status[data-status="stopped"],.collector-status[data-status="unknown"]{color:#ffb3bf;border-color:#733b49}.collector-status[data-status="stopped"]:before,.collector-status[data-status="unknown"]:before{background:#ff7188;box-shadow:0 0 0 3px #ff718822}.panel{background:color-mix(in srgb,var(--panel) 95%,transparent);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 18px 50px #0003}.filters{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.field{display:grid;gap:7px;color:var(--muted);font-size:13px;font-weight:600}select,input,button{font:inherit;color:var(--text);background:#0c1222;border:1px solid #34415e;border-radius:10px;padding:11px 13px;min-width:190px}button{background:#607df4;border:0;font-weight:700;cursor:pointer;min-width:130px}button:hover{background:#7890ff}.hint{color:var(--muted);margin:15px 0 0;font-size:13px}.chart-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px}.chart-head h2{font-size:17px;margin:0}.count{color:var(--muted);font-size:13px}.legend{display:flex;gap:18px;color:#b7c1d5;font-size:13px;margin:14px 0}.legend span{display:inline-flex;gap:7px;align-items:center}.dot{width:9px;height:9px;border-radius:50%}#chart{width:100%;height:clamp(180px,34vh,300px);display:block;overflow:visible}.chart-wrap{position:relative}.chart-panel-sticky{position:sticky;top:10px;z-index:5;background:#131a2d;box-shadow:0 12px 32px #0009}#tooltip{position:absolute;z-index:2;display:none;min-width:180px;padding:10px 12px;border:1px solid #394765;border-radius:10px;background:#0b1020f2;box-shadow:0 10px 30px #0008;pointer-events:none;font-size:12px;white-space:nowrap}#tooltip .tip-time{color:#dce5ff;font-weight:700;margin-bottom:5px}#tooltip .tip-row{display:flex;justify-content:space-between;gap:18px;font-variant-numeric:tabular-nums}#tooltip b{font-weight:700}.empty{display:grid;place-items:center;min-height:230px;color:var(--muted);text-align:center}.error{border-color:#733b49;color:#ffb3bf}.footer{color:var(--muted);font-size:12px;margin:0 4px}@media(max-width:600px){.wrap{padding:25px 14px}.panel{padding:16px}select,input{width:100%}.field{width:100%}button{width:100%}#chart{height:clamp(150px,27vh,220px)}.chart-panel-sticky{top:4px}.chart-head{align-items:flex-start;flex-direction:column}}
.filters .field select,.filters .field input{min-width:150px}.strategy-link{color:#a9b9ff;text-decoration:none}.strategy-link:hover{text-decoration:underline}.summary{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 16px}.stat{flex:1;min-width:150px;background:#0c1222;border:1px solid var(--line);border-radius:12px;padding:13px 15px}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:19px;font-variant-numeric:tabular-nums}.table-wrap{overflow:auto;border-radius:10px}table{width:100%;min-width:1000px;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}th,td{text-align:right;padding:11px 10px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:#aab5cb;font-size:11px;text-transform:uppercase;letter-spacing:.04em;background:#0d1425;position:sticky;top:0}th:first-child,td:first-child,td.reason{text-align:left}.trade-positive td{background:#123126;color:#b9f2dc}.trade-negative td{background:#351e2a;color:#ffd0d5}.trade-flat td{background:#222b3d}.trade-positive td:first-child{box-shadow:inset 3px 0 #48d6a4}.trade-negative td:first-child{box-shadow:inset 3px 0 #ff7788}.trade-row{cursor:crosshair}.trade-row:hover td,.trade-row:focus td{filter:brightness(1.22)}.small-note{font-size:12px;color:var(--muted);margin:12px 0 0}.loss{color:#ff7788}.gain{color:#48d6a4}
.refresh-symbols{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;min-width:42px;padding:4px;font-size:20px;line-height:1}.refresh-symbols.is-loading{cursor:progress;background:#273653}.refresh-progress{position:relative;display:grid;place-items:center;width:34px;height:34px;font-size:9px;font-weight:700;font-variant-numeric:tabular-nums}.refresh-progress:before{position:absolute;inset:1px;border:2px solid #52617d;border-top-color:#a9b9ff;border-radius:50%;content:"";animation:ranking-spin .8s linear infinite}.refresh-progress-value{position:relative;z-index:1}@keyframes ranking-spin{to{transform:rotate(360deg)}}
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:22px}.page-heading .eyebrow{white-space:nowrap}.header-actions{display:flex;align-items:center;justify-content:flex-end;gap:14px;flex-wrap:wrap}.collector-status{margin:0;white-space:nowrap}.save-mode-toggle{display:inline-flex;align-items:center;gap:9px;color:var(--text);font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}.save-mode-toggle input{position:absolute;opacity:0;width:1px;height:1px;min-width:0}.save-mode-switch{position:relative;width:38px;height:22px;border:1px solid #52617d;border-radius:999px;background:#263149;transition:.2s}.save-mode-switch:after{content:"";position:absolute;left:2px;top:2px;width:16px;height:16px;border-radius:50%;background:#b7c1d5;transition:.2s}.save-mode-toggle input:checked+.save-mode-switch{background:#176647;border-color:#42d6a4}.save-mode-toggle input:checked+.save-mode-switch:after{transform:translateX(16px);background:#42d6a4}.save-mode-toggle input:focus-visible+.save-mode-switch{outline:2px solid #8ea5ff;outline-offset:2px}.save-mode-detail{color:var(--muted);font-weight:400}@media(max-width:700px){.page-heading{align-items:flex-start;flex-wrap:wrap;gap:12px}.header-actions{justify-content:flex-start}}
.wrap{max-width:none;padding-left:20px;padding-right:20px}.chart-wrap{container-type:inline-size}#chart{height:27.1cqw}@media(max-width:600px){#chart{height:69cqw}}
.ranking-table{min-width:760px}.ranking-table td,.ranking-table th{text-align:left}.ranking-table td.numeric,.ranking-table th.numeric{text-align:right}.ranking-subheading{margin:22px 0 8px}.ranking-status{margin:0 0 12px;color:var(--muted)}
#portfolio-ranking-panel .small-note{font-size:15px;margin:0 0 12px}
.ranking-table th.sortable{padding:0 10px}.ranking-sort-button{width:100%;min-width:0;padding:11px 0;border:0;border-radius:0;background:transparent;color:inherit;font:inherit;text-align:inherit;text-transform:inherit;letter-spacing:inherit;cursor:pointer}.ranking-sort-button:hover{background:transparent;color:var(--text)}.ranking-sort-button:focus-visible{outline:2px solid #8ea5ff;outline-offset:-2px}
.portfolio-ranking-head{margin-bottom:0}.portfolio-ranking-head h2{flex:1}.portfolio-ranking-head .refresh-symbols{margin-left:auto}@media(max-width:600px){.portfolio-ranking-head{align-items:center;flex-direction:row}}
.symbol-select-row{display:flex;align-items:end;gap:8px}.symbol-select-row .field{flex:1;min-width:0}.symbol-select-row .field select{width:100%}.reset-symbol-cache{flex:0 0 42px;width:42px;height:42px;min-width:42px;padding:4px;font-size:18px;line-height:1}.reset-symbol-cache:disabled{cursor:progress;opacity:.65}@media(max-width:600px){.symbol-select-row{width:100%}.symbol-select-row .field{width:auto}.symbol-select-row .reset-symbol-cache{width:42px}}
.ranking-parameters{flex-basis:100%;border:1px solid var(--line);border-radius:12px;padding:12px 14px}.ranking-parameters summary{cursor:pointer;color:var(--text);font-weight:700}.ranking-parameters h3{margin:16px 0 8px;font-size:14px}.ranking-range-row{display:grid;grid-template-columns:260px repeat(3,minmax(90px,130px));justify-content:start;gap:8px;align-items:end;margin:8px 0}.ranking-range-row>span{padding-bottom:11px}.ranking-range-row label{display:grid;gap:4px;color:var(--muted);font-size:12px}.ranking-range-row input{width:100%;min-width:0}.ranking-parameters .small-note{margin:8px 0}@media(max-width:650px){.ranking-range-row{grid-template-columns:repeat(3,minmax(0,120px));justify-content:start}.ranking-range-row>span{grid-column:1/-1;padding:0}.ranking-range-row input{padding:9px 6px}}
.ranking-mode-group{display:grid;grid-template-columns:max-content max-content;align-items:center;column-gap:12px}.ranking-mode-group .ranking-range-heading{display:contents}.ranking-mode-group>.small-note,.ranking-mode-group>.ranking-custom{grid-column:1/-1}.ranking-mode-label{display:grid;gap:5px;width:auto;color:var(--muted);font-size:12px}.ranking-mode-label select{min-width:0}.ranking-custom[hidden]{display:none}
.ranking-parameters select{justify-self:start;margin-right:auto;width:auto;max-width:100%}
.ranking-mode-group h3{margin:16px 0 8px}@media(max-width:480px){.ranking-mode-group{grid-template-columns:minmax(0,1fr);align-items:start}.ranking-mode-group .ranking-mode-label{width:100%;margin-bottom:8px}}
</style>
</head><body><main class="wrap">
<header class="page-heading"><div class="eyebrow">Market data · <?= h($config['category']) ?></div><div class="header-actions"><label class="save-mode-toggle" for="save-mode-toggle"><input type="checkbox" id="save-mode-toggle" <?= $saveMode ? 'checked' : '' ?>><span class="save-mode-switch" aria-hidden="true"></span><span>SAVE MODE</span><span class="save-mode-detail" id="save-mode-detail"><?= $saveMode ? 'минимум 2 сделки' : 'от 1 сделки' ?></span></label><div class="collector-status" id="collector-status" data-status="unknown" role="status" aria-live="polite" title="Проверяем свежесть котировок">Проверка сборщика…</div></div></header>
<?php if ($error !== null): ?><section class="panel error"><?= h($error) ?></section><?php endif; ?>
<section class="panel"><form class="filters" method="get">
<input type="hidden" name="symbol_sort" id="symbol-sort-mode" value="<?= h($symbolSort) ?>">
<input type="hidden" name="snapshot_ids" id="portfolio-snapshot-ids" value="<?= h((string) ($_GET['snapshot_ids'] ?? '')) ?>">
<input type="hidden" name="save_mode" id="save-mode-value" value="<?= $saveMode ? '1' : '0' ?>">
<div class="symbol-select-row"><label class="field">Торговая пара<select name="symbol" id="symbol-select" required><?php foreach ($symbols as $option): ?><option value="<?= h($option) ?>" <?= $option === $symbol ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></label><button class="reset-symbol-cache" type="button" id="reset-symbol-cache" aria-label="Сбросить кэш рейтингов пар и пересчитать выбранную пару" title="Сбросить кэш рейтингов ранее рассчитанных пар и пересчитать только выбранную пару">🧹</button></div>
<label class="field">С даты<input type="date" lang="en-GB" name="start_day" value="<?= h($startDayInput) ?>" required></label>
<label class="field">По дату<input type="date" lang="en-GB" name="end_day" value="<?= h($endDayInput) ?>" required></label>

<label class="field">Порог входа<select name="entry_config" id="entry-config"><option value="<?= h($entryConfig) ?>">Загружаю рейтинг порогов…</option></select></label>
<label class="field">TakeProfit &amp; StopLoss<select name="exit_config" id="exit-config"><option value="<?= h($exitConfig) ?>" selected>Загружаю рейтинг вариантов…</option></select></label>
<label class="field">Стартовый баланс (USDT)<input type="number" name="balance" min="0.01" max="1000000000" step="0.01" value="<?= h($startingBalance) ?>" required></label>
<label class="field">Комиссия за сторону (%)<input type="number" name="fee" min="0" max="5" step="0.001" value="<?= h($feePercent) ?>" required></label>
<details class="ranking-parameters"><summary>Параметры расчёта · диапазоны от / до / шаг</summary>
<div class="ranking-mode-group">
<div class="ranking-range-heading"><h3>Порог входа · движение Last (USDT)</h3><label class="ranking-mode-label"><select aria-label="Режим значений порога входа" class="ranking-mode-input" name="threshold_mode" data-range="threshold"><option value="default" <?= $rankingModes['threshold'] === 'default' ? 'selected' : '' ?>>Текущие значения</option><option value="range" <?= $rankingModes['threshold'] === 'range' ? 'selected' : '' ?>>Свой диапазон</option></select></label></div>
<p class="small-note">Текущие значения: <?= h($defaultThresholdText) ?> USDT.</p>
<div class="ranking-custom" id="threshold-custom" <?= $rankingModes['threshold'] === 'range' ? '' : 'hidden' ?>><?= rankingRangeRow('Свой диапазон', 'threshold', $thresholdCustomRange, true) ?></div>
<div class="ranking-range-heading"><h3>Порог входа · окно (число снимков)</h3><label class="ranking-mode-label"><select aria-label="Режим окна входа" class="ranking-mode-input" name="window_mode" data-range="window"><option value="default" <?= $rankingModes['window'] === 'default' ? 'selected' : '' ?>>Текущие значения</option><option value="range" <?= $rankingModes['window'] === 'range' ? 'selected' : '' ?>>Свой диапазон</option></select></label></div>
<p class="small-note">Текущие значения: <?= h($defaultWindowText) ?> снимков.</p>
<div class="ranking-custom" id="window-custom" <?= $rankingModes['window'] === 'range' ? '' : 'hidden' ?>><?= rankingRangeRow('Свой диапазон', 'window', $windowCustomRange, false) ?></div>
</div>
<h3>TakeProfit &amp; StopLoss (USDT)</h3>
<p class="small-note">Совместный расчёт: каждый Take Profit проверяется с каждым Stop Loss.</p>
<?= rankingRangeRow('Take Profit · совместный расчёт', 'fixed_tp', $rankingRanges['fixed_tp'], true) ?>
<?= rankingRangeRow('Stop Loss · совместный расчёт', 'fixed_sl', $rankingRanges['fixed_sl'], true) ?>
<?= rankingRangeRow('Только Take Profit', 'profit', $rankingRanges['profit'], true) ?>
<?= rankingRangeRow('Только Stop Loss', 'loss', $rankingRanges['loss'], true) ?>
<p class="small-note">Граница «до» включается, если на неё попадает шаг.</p><button class="apply-ranking-ranges" type="submit" id="apply-ranking-ranges">Применить диапазоны</button>
</details>
</form><p class="hint">Фильтры применяются автоматически после изменения. Время на графике указано по Киеву; загружаются только сохраненные записи.</p><p class="hint"><strong>Диапазоны входа:</strong> <?= h($entryThresholdText) ?> USDT. <strong>Окна:</strong> <?= h($entryWindowText) ?> снимков. Каждый порог проверяется на каждом окне.</p><p class="hint"><strong>Дополнительные варианты входа:</strong> <?= h($immediateEntryText) ?>. Такая заявка открывается по первому снимку выбранного периода и не повторяется.</p><p class="hint">Список «Порог входа» отсортирован по лучшему чистому результату с учетом списка «TakeProfit &amp; StopLoss». Каждый порог задает движение Last в обе стороны за выбранное число снимков. Take Profit и Stop Loss выбираются во втором списке. Симуляция использует плечо 1×, дробное количество и комиссию за market/taker на обеих сторонах. <a class="strategy-link" href="strategies.php">Описание Пользовательской Стратегии №1 →</a></p><p class="hint"><strong>Диапазоны TakeProfit &amp; StopLoss:</strong></p><ul class="hint"><li>Фиксация TP и SL: TP <?= h($exitRangeText($rankingRanges['fixed_tp'])) ?>; SL <?= h($exitRangeText($rankingRanges['fixed_sl'])) ?>.</li><li>Только Take Profit: <?= h($exitRangeText($rankingRanges['profit'])) ?>, без фиксации убытка.</li><li>Только Stop Loss: <?= h($exitRangeText($rankingRanges['loss'])) ?>, без фиксации прибыли.</li></ul></section>
<section class="panel" id="portfolio-ranking-panel"><div class="chart-head portfolio-ranking-head"><h2>Общий рейтинг порогов входа и TakeProfit &amp; StopLoss</h2><button class="refresh-symbols" type="button" id="refresh-symbols" aria-label="Рассчитать общий рейтинг пар" title="Рассчитать общие лучшие комбинации порога входа и TakeProfit &amp; StopLoss">↻</button></div><div id="portfolio-ranking-content" hidden><p class="ranking-status" id="portfolio-ranking-status" aria-live="polite"></p><div class="table-wrap" id="portfolio-global-table"></div><h3 class="ranking-subheading">Лучшая комбинация каждого тикера</h3><p class="small-note">Для каждой пары показано её место среди всех комбинаций по среднему итогу на рынке.</p><div class="table-wrap" id="portfolio-ticker-table"></div></div></section>
<section class="panel<?= $trades !== [] ? ' chart-panel-sticky' : '' ?>" id="chart-panel"><div class="chart-head"><h2><?= $symbol !== '' ? h($symbol) : 'Котировки' ?> · <?= h($dateRangeLabel) ?></h2><span class="count"><?= count($rows) ?> снимков</span></div>
<?php if ($rows === []): ?><div class="empty">За выбранный период сохраненных данных пока нет.</div><?php else: ?>
<div class="legend"><?php foreach ($series as $meta): ?><span><i class="dot" style="background:<?= h($meta['color']) ?>"></i><?= h($meta['name']) ?></span><?php endforeach; ?></div>
<div class="chart-wrap"><svg id="chart" role="img" aria-label="График bid, ask и last для <?= h($symbol) ?>"></svg><div id="tooltip" aria-hidden="true"></div></div>
<?php endif; ?></section>
<section class="panel" id="results"><div class="chart-head"><h2>Сделки стратегии</h2><span class="count"><?= count($trades) ?> закрытых сделок</span></div>
<div class="summary"><div class="stat"><span>Стартовый баланс</span><strong><?= money($startingBalance) ?> USDT</strong></div><div class="stat"><span>Итог за период</span><strong class="<?= bccomp($totalPnl, '0', 24) > 0 ? 'gain' : (bccomp($totalPnl, '0', 24) < 0 ? 'loss' : '') ?>"><?= bccomp($totalPnl, '0', 24) > 0 ? '+' : '' ?><?= money($totalPnl) ?> USDT</strong></div><div class="stat"><span>Текущий баланс</span><strong><?= money($balance) ?> USDT</strong></div></div>
<?php if (count($rows) < 2): ?><div class="empty">Нужно минимум два снимка в выбранном периоде, чтобы выполнить симуляцию.</div>
<?php elseif ($trades === []): ?><div class="empty"><?= $isImmediateEntry ? 'Для немедленной заявки нужны минимум два снимка в выбранном периоде.' : 'В выбранном периоде не было сигнала на движение ±' . number_format($momentumThreshold, 2, ',', ' ') . ' USDT за ' . $momentumLookback . ' интервалов между снимками.' ?></div>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>#</th><th>Вход · Киев</th><th><?= h($entrySideLabel) ?></th><th>Выход · Киев</th><th><?= h($exitSideLabel) ?></th><th>Количество</th><th>Валовая прибыль</th><th>Комиссия вход + выход</th><th>Итог сделки</th><th>Баланс после</th><th>Причина выхода</th></tr></thead><tbody>
<?php foreach ($trades as $index => $trade): $resultCompare = bccomp($trade['net'], '0', 24); $rowClass = $resultCompare > 0 ? 'trade-positive' : ($resultCompare < 0 ? 'trade-negative' : 'trade-flat'); $totalFees = bcadd($trade['entry_fee'], $trade['exit_fee'], 24); $tradeEntryLabel = $trade['is_short'] ? 'Продажа Bid' : 'Покупка Ask'; $tradeExitLabel = $trade['is_short'] ? 'Покупка Ask' : 'Продажа Bid'; ?>
<tr class="<?= $rowClass ?> trade-row" tabindex="0" data-entry-time="<?= epochMilliseconds($trade['entry']['received_at_utc']) ?>" data-exit-time="<?= epochMilliseconds($trade['exit']['received_at_utc']) ?>"><td><?= $index + 1 ?></td><td><?= h(formatKyivTime($trade['entry']['received_at_utc'], $timezone)) ?></td><td><?= h($tradeEntryLabel . ' · ' . $trade['entry_price']) ?></td><td><?= h(formatKyivTime($trade['exit']['received_at_utc'], $timezone)) ?></td><td><?= h($tradeExitLabel . ' · ' . $trade['exit_price']) ?></td><td><?= money($trade['quantity']) ?></td><td><?= money($trade['gross']) ?></td><td><?= money($totalFees) ?></td><td><?= $resultCompare > 0 ? '+' : '' ?><?= money($trade['net']) ?></td><td><?= money($trade['balance']) ?></td><td class="reason"><?= h($trade['reason']) ?></td></tr>
<?php endforeach; ?></tbody></table></div><p class="small-note">Зеленая строка — итог сделки после комиссий положительный; красная — отрицательный. Из-за промежутка между снимками фактическая прибыль или убыток при выходе могут превысить заданный порог фиксации. Если выбранный диапазон заканчивается сегодня и позиция не закрылась, она условно закрывается по последнему доступному снимку.</p><?php endif; ?></section>
<p class="footer">Источник: локальная база <?= h($db['database']) ?> · шаг сбора <?= (int) $config['poll_interval_seconds'] ?> сек.</p>
</main>
<?php if ($rows !== []): ?><script>
const datasets = <?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
const colors = {bid_price:'#42d6a4',ask_price:'#ff7188',last_price:'#86a8ff'};
const svg=document.querySelector('#chart'), ns='http://www.w3.org/2000/svg', W=1100,H=390,p={l:88,r:22,t:20,b:42};
const all=Object.values(datasets).flat(); const xs=all.map(x=>x.t),ys=all.map(x=>x.v);let xmin=Math.min(...xs),xmax=Math.max(...xs),ymin=Math.min(...ys),ymax=Math.max(...ys);if(xmin===xmax){xmin-=30000;xmax+=30000}if(ymin===ymax){ymin*=.999;ymax*=1.001}const fullXmin=xmin,fullXmax=xmax;let viewMin=xmin,viewMax=xmax;const pad=(ymax-ymin)*.12;ymin-=pad;ymax+=pad;
const X=x=>p.l+(x-viewMin)/(viewMax-viewMin)*(W-p.l-p.r),Y=y=>p.t+(ymax-y)/(ymax-ymin)*(H-p.t-p.b);
function el(tag,attrs={},text=''){const n=document.createElementNS(ns,tag);for(const[k,v]of Object.entries(attrs))n.setAttribute(k,v);if(text)n.textContent=text;svg.appendChild(n);return n}
svg.setAttribute('viewBox',`0 0 ${W} ${H}`);svg.setAttribute('preserveAspectRatio','none');
for(let i=0;i<5;i++){const v=ymin+(ymax-ymin)*i/4,y=Y(v);el('line',{x1:p.l,y1:y,x2:W-p.r,y2:y,stroke:'#273149','stroke-width':1});el('text',{x:p.l-12,y:y+4,'text-anchor':'end',fill:'#8995ad','font-size':12},Number(v).toPrecision(7).replace(/\.?0+$/,''))}
let xTicks=[];function buildXAxis(){for(const tick of xTicks){tick.line.remove();tick.label.remove()}xTicks=[];const tickCount=Math.max(4,Math.floor(svg.clientWidth/180));for(let i=0;i<=tickCount;i++){const line=el('line',{y1:p.t,y2:H-p.b,stroke:'#202a40','stroke-width':1}),label=el('text',{y:H-12,'text-anchor':'middle',fill:'#8995ad','font-size':12});xTicks.push({line,label,index:i/tickCount})}}function renderXAxis(){for(const tick of xTicks){const t=viewMin+(viewMax-viewMin)*tick.index,x=X(t);tick.line.setAttribute('x1',x);tick.line.setAttribute('x2',x);tick.label.setAttribute('x',x);tick.label.textContent=new Date(t).toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'})}}buildXAxis();renderXAxis();window.addEventListener('resize',()=>{buildXAxis();renderXAxis()});
const tradeInterval=el('rect',{y:p.t,height:H-p.t-p.b,fill:'#c4a7ff',opacity:.18,visibility:'hidden','pointer-events':'none'}),tradeEntryLine=el('line',{y1:p.t,y2:H-p.b,stroke:'#d9c6ff','stroke-width':2,'stroke-dasharray':'5 4',visibility:'hidden','pointer-events':'none'}),tradeExitLine=el('line',{y1:p.t,y2:H-p.b,stroke:'#d9c6ff','stroke-width':2,'stroke-dasharray':'5 4',visibility:'hidden','pointer-events':'none'});
const defs=el('defs'),clipPath=document.createElementNS(ns,'clipPath'),clipRect=document.createElementNS(ns,'rect'),clipId='chart-plot-clip';clipPath.setAttribute('id',clipId);clipRect.setAttribute('x',p.l);clipRect.setAttribute('y',p.t);clipRect.setAttribute('width',W-p.l-p.r);clipRect.setAttribute('height',H-p.t-p.b);clipPath.appendChild(clipRect);defs.appendChild(clipPath);const markers={},paths={};for(const[key,arr]of Object.entries(datasets)){const path=el('path',{d:arr.map((q,i)=>`${i?'L':'M'}${X(q.t).toFixed(2)},${Y(q.v).toFixed(2)}`).join(' '),fill:'none',stroke:colors[key],'stroke-width':2.2,'stroke-linejoin':'round','stroke-linecap':'round',opacity:.94,'clip-path':`url(#${clipId})`});paths[key]=path;markers[key]=el('circle',{r:5,fill:colors[key],stroke:'#0b1020','stroke-width':2,visibility:'hidden','pointer-events':'none'})}function renderSeries(){for(const[key,arr]of Object.entries(datasets)){paths[key].setAttribute('d',arr.map((q,i)=>`${i?'L':'M'}${X(q.t).toFixed(2)},${Y(q.v).toFixed(2)}`).join(' '))}}
const crosshair=el('line',{y1:p.t,y2:H-p.b,stroke:'#d5defa','stroke-width':1,'stroke-dasharray':'4 5',opacity:.65,visibility:'hidden','pointer-events':'none'}),tip=document.querySelector('#tooltip'),wrap=document.querySelector('.chart-wrap'),sampleTimes=datasets.last_price.map(q=>q.t);
function nearestIndex(t){let lo=0,hi=sampleTimes.length;while(lo<hi){const m=(lo+hi)>>1;if(sampleTimes[m]<t)lo=m+1;else hi=m}if(lo===0)return 0;if(lo>=sampleTimes.length)return sampleTimes.length-1;return t-sampleTimes[lo-1]<=sampleTimes[lo]-t?lo-1:lo}
function setViewForTrade(start,end){const span=fullXmax-fullXmin;if(end-start>=span*.05){viewMin=fullXmin;viewMax=fullXmax}else{const targetSpan=span*.05,center=(start+end)/2;viewMin=Math.max(fullXmin,Math.min(center-targetSpan/2,fullXmax-targetSpan));viewMax=viewMin+targetSpan}renderXAxis();renderSeries()}
function showTradeInterval(row){const start=Number(row.dataset.entryTime),end=Number(row.dataset.exitTime);setViewForTrade(start,end);const x1=X(start),x2=X(end);tradeInterval.setAttribute('x',x1);tradeInterval.setAttribute('width',Math.max(2,x2-x1));tradeInterval.setAttribute('visibility','visible');tradeEntryLine.setAttribute('x1',x1);tradeEntryLine.setAttribute('x2',x1);tradeExitLine.setAttribute('x1',x2);tradeExitLine.setAttribute('x2',x2);tradeEntryLine.setAttribute('visibility','visible');tradeExitLine.setAttribute('visibility','visible')}
function hideTradeInterval(){viewMin=fullXmin;viewMax=fullXmax;renderXAxis();renderSeries();tradeInterval.setAttribute('visibility','hidden');tradeEntryLine.setAttribute('visibility','hidden');tradeExitLine.setAttribute('visibility','hidden')}
document.querySelectorAll('.trade-row').forEach(row=>{row.addEventListener('pointerenter',()=>showTradeInterval(row));row.addEventListener('pointerleave',hideTradeInterval);row.addEventListener('focus',()=>showTradeInterval(row));row.addEventListener('blur',hideTradeInterval)});
svg.addEventListener('pointermove',event=>{const rect=svg.getBoundingClientRect(),viewX=(event.clientX-rect.left)/rect.width*W;if(viewX<p.l||viewX>W-p.r)return;const t=viewMin+(viewX-p.l)/(W-p.l-p.r)*(viewMax-viewMin),idx=nearestIndex(t),time=sampleTimes[idx],px=X(time);crosshair.setAttribute('x1',px);crosshair.setAttribute('x2',px);crosshair.setAttribute('visibility','visible');let markup=`<div class="tip-time">${new Date(time).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'})} · Киев</div>`;for(const[key,arr]of Object.entries(datasets)){const q=arr[idx];markers[key].setAttribute('cx',X(q.t));markers[key].setAttribute('cy',Y(q.v));markers[key].setAttribute('visibility','visible');const name={bid_price:'Bid',ask_price:'Ask',last_price:'Last'}[key];markup+=`<div class="tip-row"><span style="color:${colors[key]}">${name}</span><b>${q.label}</b></div>`}tip.innerHTML=markup;tip.style.display='block';const wr=wrap.getBoundingClientRect(),left=event.clientX-wr.left+14;tip.style.left=`${Math.min(left,wr.width-tip.offsetWidth-4)}px`;tip.style.top=`${Math.max(4,Math.min(event.clientY-wr.top-20,wr.height-tip.offsetHeight-4))}px`});
svg.addEventListener('pointerleave',()=>{tip.style.display='none';crosshair.setAttribute('visibility','hidden');for(const m of Object.values(markers))m.setAttribute('visibility','hidden')});
</script><?php endif; ?>
<script>
const filtersForm = document.querySelector('.filters');
const saveModeToggle = document.querySelector('#save-mode-toggle');
const saveModeValue = document.querySelector('#save-mode-value');
const symbolSortMode = document.querySelector('#symbol-sort-mode');
const portfolioSnapshotIds = document.querySelector('#portfolio-snapshot-ids');
const symbolSelect = document.querySelector('#symbol-select');
const refreshSymbolsButton = document.querySelector('#refresh-symbols');
const resetSymbolCacheButton = document.querySelector('#reset-symbol-cache');
const portfolioRankingPanel = document.querySelector('#portfolio-ranking-panel');
const portfolioRankingContent = document.querySelector('#portfolio-ranking-content');
const portfolioRankingStatus = document.querySelector('#portfolio-ranking-status');
function setRefreshProgress(progress, title) {
    const percent = Math.max(0, Math.min(100, Math.round(Number(progress) || 0)));
    refreshSymbolsButton.disabled = true;
    refreshSymbolsButton.classList.add('is-loading');
    refreshSymbolsButton.setAttribute('aria-busy', 'true');
    refreshSymbolsButton.setAttribute('aria-label', `Расчет общего рейтинга: ${percent}%`);
    refreshSymbolsButton.title = title || `Расчет общего рейтинга: ${percent}%`;
    let indicator = refreshSymbolsButton.querySelector('.refresh-progress');
    if (!indicator) {
        indicator = document.createElement('span');
        indicator.className = 'refresh-progress';
        indicator.setAttribute('aria-hidden', 'true');
        const value = document.createElement('span');
        value.className = 'refresh-progress-value';
        indicator.appendChild(value);
        refreshSymbolsButton.replaceChildren(indicator);
    }
    indicator.querySelector('.refresh-progress-value').textContent = `${percent}%`;
}
function resetRefreshButton(title = 'Рассчитать общие лучшие комбинации порога входа и TakeProfit & StopLoss') {
    refreshSymbolsButton.disabled = false;
    refreshSymbolsButton.classList.remove('is-loading');
    refreshSymbolsButton.removeAttribute('aria-busy');
    refreshSymbolsButton.setAttribute('aria-label', 'Рассчитать общий рейтинг пар');
    refreshSymbolsButton.title = title;
    refreshSymbolsButton.replaceChildren(document.createTextNode('↻'));
}
function resetPortfolioRankingForRelevantFilterChange() {
    symbolSortMode.value = '';
    portfolioSnapshotIds.value = '';
    resetRefreshButton('Пересчитать рейтинг для измененных фильтров');
}
document.querySelector('#apply-ranking-ranges').addEventListener('click', resetPortfolioRankingForRelevantFilterChange);
document.querySelectorAll('.ranking-mode-input').forEach(control => {
    const update = () => {
        const custom = document.querySelector('#' + control.dataset.range + '-custom');
        const enabled = control.value === 'range';
        custom.hidden = !enabled;
        custom.querySelectorAll('input').forEach(input => { input.disabled = !enabled; });
    };
    control.addEventListener('change', update);
    update();
});
saveModeToggle.addEventListener('change', () => {
    saveModeValue.value = saveModeToggle.checked ? '1' : '0';
    document.querySelector('#save-mode-detail').textContent = saveModeToggle.checked ? 'минимум 2 сделки' : 'от 1 сделки';
    resetPortfolioRankingForRelevantFilterChange();
    filtersForm.requestSubmit();
});
let filterSubmitTimer;
let replayRequest;
async function refreshStrategyResults() {
    replayRequest?.abort();
    replayRequest = new AbortController();
    const params = new URLSearchParams(new FormData(filtersForm));
    const url = location.pathname + '?' + params.toString();
    history.replaceState(null, '', url);
    try {
        const response = await fetch(url, {headers:{Accept:'text/html'}, cache:'no-store', signal:replayRequest.signal});
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const html = await response.text();
        const updatedPage = new DOMParser().parseFromString(html, 'text/html');
        const updatedChart = updatedPage.querySelector('#chart-panel');
        const updatedResults = updatedPage.querySelector('#results');
        if (!updatedChart || !updatedResults) throw new Error('Не удалось получить результаты симуляции');

        document.querySelector('#chart-panel').replaceWith(updatedChart);
        document.querySelector('#results').replaceWith(updatedResults);
        const chartScript = [...updatedPage.scripts].find(script => script.textContent.includes('const datasets ='));
        if (chartScript) new Function(chartScript.textContent)();
    } catch (error) {
        if (error.name !== 'AbortError') console.error('Не удалось обновить результаты стратегии', error);
    }
}
filtersForm.querySelectorAll('select, input').forEach(control => {
    control.addEventListener('change', () => {
        if (control.classList.contains('ranking-range-input') || control.classList.contains('ranking-mode-input')) return;
        if (control.id === 'entry-config' || control.id === 'exit-config') {
            refreshStrategyResults();
            return;
        }
        clearTimeout(filterSubmitTimer);
        if (control.name === 'start_day' || control.name === 'end_day') {
            resetPortfolioRankingForRelevantFilterChange();
        }
        filtersForm.requestSubmit();
    });
    if (control.type === 'number' && !control.classList.contains('ranking-range-input')) {
        control.addEventListener('input', () => {
            clearTimeout(filterSubmitTimer);
            filterSubmitTimer = setTimeout(() => {
                if (control.value !== '' && control.checkValidity()) {
                    filtersForm.requestSubmit();
                }
            }, 700);
        });
    }
});
const exitConfigSelect = document.querySelector('#exit-config');
const entryConfigSelect = document.querySelector('#entry-config');
function renderEntryOptions(entries, selected) {
    if (!Array.isArray(entries) || entries.length === 0) return;
    entryConfigSelect.replaceChildren();
    for (const item of entries) {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.label;
        if (item.id === selected) option.selected = true;
        entryConfigSelect.appendChild(option);
    }
    if (![...entryConfigSelect.options].some(option => option.value === selected)) {
        entryConfigSelect.options[0].selected = true;
    }
}
function promoteRankedSymbol(symbol) {
    const rankedOption = [...symbolSelect.options].find(option => option.value === symbol);
    if (rankedOption && symbolSelect.options[0] !== rankedOption) {
        symbolSelect.insertBefore(rankedOption, symbolSelect.options[0]);
    }
}
let exitRankingRequestVersion = 0;
let isResettingSymbolCache = false;
async function loadExitRanking() {
    if (isResettingSymbolCache) return;
    const requestVersion = exitRankingRequestVersion;
    const params = new URLSearchParams(new FormData(filtersForm));
    try {
        const response = await fetch(`strategy-ranking.php?${params.toString()}`, {headers:{Accept:'application/json'}});
        const ranking = await response.json();
        if (requestVersion !== exitRankingRequestVersion || isResettingSymbolCache) return;
        if (ranking.status === 'insufficient_data') {
            entryConfigSelect.replaceChildren();
            const entryMessage = document.createElement('option');
            entryMessage.textContent = ranking.message || 'Для рейтинга нужно минимум две сделки';
            entryMessage.disabled = true;
            entryMessage.selected = true;
            entryConfigSelect.appendChild(entryMessage);
            exitConfigSelect.replaceChildren();
            const exitMessage = document.createElement('option');
            exitMessage.textContent = 'Недостаточно сделок для рейтинга';
            exitMessage.disabled = true;
            exitMessage.selected = true;
            exitConfigSelect.appendChild(exitMessage);
            return;
        }
        renderEntryOptions(ranking.entries, params.get('entry_config'));
        if (ranking.status === 'entry_unavailable') {
            if (entryConfigSelect.value && entryConfigSelect.value !== params.get('entry_config')) filtersForm.requestSubmit();
            return;
        }
        if (ranking.status === 'pending') {
            const progressLabel = ranking.queued ? 'в очереди' : `${ranking.progress || 0}%`;
            if (!ranking.entries?.length) entryConfigSelect.options[0].textContent = `Рейтинг порогов и выходов… ${progressLabel}`;
            exitConfigSelect.options[0].textContent = `Рейтинг TakeProfit & StopLoss… ${progressLabel}`;
            window.setTimeout(() => {
                if (requestVersion === exitRankingRequestVersion && !isResettingSymbolCache) loadExitRanking();
            }, 1500);
            return;
        }
        if (ranking.status !== 'ready') {
            exitConfigSelect.options[0].textContent = ranking.message || 'Рейтинг временно недоступен';
            return;
        }
        const selected = exitConfigSelect.value;
        exitConfigSelect.replaceChildren();
        for (const group of ranking.groups) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = group.label;
            for (const item of group.items) {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.label;
                if (item.id === selected) option.selected = true;
                optgroup.appendChild(option);
            }
            exitConfigSelect.appendChild(optgroup);
        }
        if (![...exitConfigSelect.options].some(option => option.value === selected)) {
            const fallback = exitConfigSelect.options[0];
            if (fallback) {
                fallback.selected = true;
                if (selected && fallback.value !== selected) filtersForm.requestSubmit();
            }
        }
        promoteRankedSymbol(params.get('symbol'));
    } catch (_) {
        if (requestVersion !== exitRankingRequestVersion || isResettingSymbolCache) return;
        exitConfigSelect.options[0].textContent = 'Не удалось загрузить рейтинг';
    }
}
resetSymbolCacheButton.addEventListener('click', async () => {
    if (isResettingSymbolCache) return;
    isResettingSymbolCache = true;
    let resetSucceeded = false;
    exitRankingRequestVersion++;
    resetSymbolCacheButton.disabled = true;
    resetSymbolCacheButton.textContent = '…';
    resetSymbolCacheButton.title = 'Сбрасываю кэш рейтингов…';
    try {
        const response = await fetch('reset-strategy-ranking-cache.php', {
            method: 'POST',
            headers: {Accept: 'application/json'},
            cache: 'no-store',
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'ready') throw new Error(result.message || 'Не удалось сбросить кэш рейтингов');
        resetSucceeded = true;
        symbolSortMode.value = '';
        portfolioSnapshotIds.value = '';
        const params = new URLSearchParams(new FormData(filtersForm));
        history.replaceState(null, '', location.pathname + '?' + params.toString());
    } catch (error) {
        resetSymbolCacheButton.title = error.message || 'Не удалось сбросить кэш рейтингов';
    } finally {
        isResettingSymbolCache = false;
        resetSymbolCacheButton.disabled = false;
        resetSymbolCacheButton.textContent = '🧹';
        resetSymbolCacheButton.title = 'Сбросить кэш рейтингов ранее рассчитанных пар и пересчитать только выбранную пару';
        if (resetSucceeded) {
            loadExitRanking();
        }
    }
});
const wait = ms => new Promise(resolve => window.setTimeout(resolve, ms));
async function sortSymbolsByProfit() {
    if (!filtersForm.checkValidity()) {
        filtersForm.reportValidity();
        return;
    }
    setRefreshProgress(0, 'Расчет рейтинга пар…');
    try {
        const params = new URLSearchParams(new FormData(filtersForm));
        let ranking;
        do {
            const response = await fetch(`symbol-ranking.php?${params.toString()}`, {headers:{Accept:'application/json'}});
            ranking = await response.json();
            if (!response.ok || ranking.status === 'error') throw new Error(ranking.message || 'Не удалось отсортировать пары');
            if (ranking.status === 'pending') {
                const progressLabel = ranking.queued ? 'в очереди' : `${ranking.progress || 0}%`;
                setRefreshProgress(ranking.progress, `Расчет рейтинга пар… ${progressLabel}${ranking.symbol ? ` · ${ranking.symbol}` : ''}`);
                await wait(1500);
            }
        } while (ranking.status === 'pending');
        if (ranking.status !== 'ready' || !Array.isArray(ranking.symbols)) throw new Error(ranking.message || 'Рейтинг пар пока недоступен');
        const currentSymbol = symbolSelect.value;
        const options = new Map([...symbolSelect.options].map(option => [option.value, option]));
        const sortedOptions = [];
        let rankedCount = 0;
        for (const item of ranking.symbols) {
            const option = options.get(item.symbol);
            if (!option) continue;
            if (item.best_pnl === null || item.best_pnl === undefined) {
                option.textContent = `${item.symbol} · нет надежного рейтинга`;
                option.title = params.get('save_mode') === '0' ? 'Нет варианта выхода хотя бы с одной сделкой' : 'Ни один вариант не дал минимум две сделки';
            } else {
                rankedCount++;
                const pnl = Number(item.best_pnl);
                option.textContent = `${item.symbol} · ${pnl > 0 ? '+' : ''}${new Intl.NumberFormat('ru-RU',{minimumFractionDigits:4,maximumFractionDigits:4}).format(pnl)} USDT`;
                option.title = `Лучший результат входа с ${item.best_exit || 'вариантом TakeProfit & StopLoss'}`;
            }
            sortedOptions.push(option);
        }
        for (const option of options.values()) if (!sortedOptions.includes(option)) sortedOptions.push(option);
        symbolSelect.replaceChildren(...sortedOptions);
        symbolSelect.value = currentSymbol;
        resetRefreshButton(`Пары отсортированы по выбранному порогу входа: надежных рейтингов ${rankedCount} из ${sortedOptions.length}.`);
    } catch (error) {
        resetRefreshButton(error.message || 'Не удалось отсортировать пары');
    } finally {
        refreshSymbolsButton.disabled = false;
    }
}
function rankingTable(container, columns, rows, sortOptions = null) {
    if (!rows.length) {
        container.replaceChildren();
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'Нет комбинаций с достаточным количеством сделок за выбранный период.';
        container.appendChild(empty);
        return;
    }
    let sortKey = sortOptions?.initialKey || null;
    let sortDirection = sortOptions?.initialDirection || 'asc';
    const render = () => {
        container.replaceChildren();
        const table = document.createElement('table');
        table.className = 'ranking-table';
        const head = document.createElement('thead');
        const headerRow = document.createElement('tr');
        for (const column of columns) {
            const cell = document.createElement('th');
            if (column.numeric) cell.className = 'numeric';
            if (sortOptions && column.sortKey) {
                const active = column.sortKey === sortKey;
                const sortButton = document.createElement('button');
                sortButton.type = 'button';
                sortButton.className = 'ranking-sort-button';
                sortButton.textContent = column.label + (active ? (sortDirection === 'asc' ? ' ↑' : ' ↓') : '');
                sortButton.setAttribute('aria-label', `Сортировать по полю «${column.label}»`);
                sortButton.setAttribute('aria-pressed', active ? 'true' : 'false');
                cell.classList.add('sortable');
                cell.setAttribute('aria-sort', active ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
                sortButton.addEventListener('click', () => {
                    if (sortKey === column.sortKey) {
                        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortKey = column.sortKey;
                        sortDirection = column.defaultDirection || 'asc';
                    }
                    render();
                });
                cell.appendChild(sortButton);
            } else {
                cell.textContent = column.label;
            }
            headerRow.appendChild(cell);
        }
        head.appendChild(headerRow);
        table.appendChild(head);
        const body = document.createElement('tbody');
        let sortedRows = rows;
        if (sortOptions && sortKey) {
            sortedRows = [...rows].sort((left, right) => {
                const leftValue = left.sortValues?.[sortKey];
                const rightValue = right.sortValues?.[sortKey];
                if (leftValue === null || leftValue === undefined) return rightValue === null || rightValue === undefined ? 0 : 1;
                if (rightValue === null || rightValue === undefined) return -1;
                const compared = typeof leftValue === 'number'
                    ? leftValue - rightValue
                    : String(leftValue).localeCompare(String(rightValue));
                if (compared !== 0) return sortDirection === 'asc' ? compared : -compared;
                return String(left.sortTieBreak || '').localeCompare(String(right.sortTieBreak || ''));
            });
        }
        for (const row of sortedRows) {
            const tr = document.createElement('tr');
            const values = Array.isArray(row) ? row : row.cells;
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                cell.textContent = value === null || value === undefined ? '—' : String(value);
                if (columns[index].numeric) cell.className = 'numeric';
                tr.appendChild(cell);
            });
            body.appendChild(tr);
        }
        table.appendChild(body);
        container.appendChild(table);
    };
    render();
}
function pnlText(value) {
    if (value === null || value === undefined) return '—';
    const amount = Number(value);
    return (amount > 0 ? '+' : '') + new Intl.NumberFormat('ru-RU',{minimumFractionDigits:4,maximumFractionDigits:4}).format(amount) + ' USDT';
}
async function loadPortfolioRanking() {
    portfolioRankingPanel.hidden = false;
    portfolioRankingContent.hidden = false;
    portfolioRankingStatus.textContent = 'Подготавливаю общий рейтинг…';
    setRefreshProgress(0, 'Подготавливаю общий рейтинг…');
    let buttonTitle = 'Рассчитать рейтинг еще раз';
    try {
        const params = new URLSearchParams(new FormData(filtersForm));
        history.replaceState(null, '', location.pathname + '?' + params.toString());
        let ranking;
        do {
            const response = await fetch('portfolio-ranking.php?' + params.toString(), {headers:{Accept:'application/json'}});
            ranking = await response.json();
            if (ranking.snapshot_ids && !params.get('snapshot_ids')) {
                const frozenIds = JSON.stringify(ranking.snapshot_ids);
                params.set('snapshot_ids', frozenIds);
                portfolioSnapshotIds.value = frozenIds;
                history.replaceState(null, '', location.pathname + '?' + params.toString());
            }
            if (!response.ok || ranking.status === 'error') throw new Error(ranking.message || 'Не удалось рассчитать общий рейтинг');
            if (ranking.status === 'pending') {
                const progressText = ranking.queued
                    ? 'Расчет рейтинга ожидает очереди…'
                    : 'Идет расчет рейтинга для всех тикеров… ' + (ranking.progress || 0) + '%';
                portfolioRankingStatus.textContent = ranking.queued
                    ? 'Расчет ожидает очереди общего обработчика.'
                    : 'Перебираю комбинации для всех тикеров… ' + (ranking.progress || 0) + '%';
                setRefreshProgress(ranking.progress, progressText);
                await wait(1500);
            }
        } while (ranking.status === 'pending');
        if (ranking.status !== 'ready') throw new Error(ranking.message || 'Общий рейтинг пока недоступен');
        const globalRows = (ranking.global_top || []).map((item, index) => [
            String(index + 1), item.entry_label, item.exit_label, pnlText(item.average_pnl),
            item.eligible_symbols + ' из ' + ranking.universe_size,
            String(item.profitable_symbols),
        ]);
        rankingTable(document.querySelector('#portfolio-global-table'), [
            {label:'#'}, {label:'Порог входа'}, {label:'TakeProfit & StopLoss'},
            {label:'Средний итог на тикер',numeric:true}, {label:'Подходят по SAVE MODE',numeric:true}, {label:'В плюсе',numeric:true},
        ], globalRows);
        const tickerRows = (ranking.ticker_leaders || []).map(item => ({
            cells: [
                item.global_rank === null ? '—' : String(item.global_rank), item.symbol, item.entry_label, item.exit_label,
                pnlText(item.pnl), pnlText(item.market_average_pnl),
                item.market_eligible_symbols === undefined ? '—' : item.market_eligible_symbols + ' из ' + ranking.universe_size,
                item.trade_count ? String(item.trade_count) : 'нет надежных данных',
            ],
            sortValues: {
                globalRank: item.global_rank === null || item.global_rank === undefined ? null : Number(item.global_rank),
                tickerPnl: item.pnl === null || item.pnl === undefined ? null : Number(item.pnl),
                marketAverage: item.market_average_pnl === null || item.market_average_pnl === undefined ? null : Number(item.market_average_pnl),
            },
            sortTieBreak: item.symbol,
        }));
        rankingTable(document.querySelector('#portfolio-ticker-table'), [
            {label:'Место комбинации в общем рейтинге',sortKey:'globalRank',defaultDirection:'asc'}, {label:'Тикер'}, {label:'Лучший порог входа'}, {label:'Лучший TakeProfit & StopLoss'},
            {label:'Итог тикера',numeric:true,sortKey:'tickerPnl',defaultDirection:'desc'}, {label:'Средний итог этой пары на рынке',numeric:true,sortKey:'marketAverage',defaultDirection:'desc'},
            {label:'Поддержка',numeric:true}, {label:'Сделок',numeric:true},
        ], tickerRows, {initialKey:'globalRank',initialDirection:'asc'});
        portfolioRankingStatus.textContent = 'Средний итог рассчитан по ' + ranking.universe_size
            + ' тикерам с данными за период; пары без подходящей выборки учитываются как 0. SAVE MODE требует минимум '
            + ranking.min_trades + (ranking.min_trades === 1 ? ' сделку.' : ' сделки.');
        const currentSymbol = symbolSelect.value;
        const options = new Map([...symbolSelect.options].map(option => [option.value, option]));
        const sortedOptions = [];
        let rankedCount = 0;
        for (const item of ranking.symbols || []) {
            const option = options.get(item.symbol);
            if (!option) continue;
            if (item.best_pnl === null || item.best_pnl === undefined) {
                option.textContent = item.symbol + ' · нет надежного рейтинга';
                option.title = params.get('save_mode') === '0' ? 'Нет варианта выхода хотя бы с одной сделкой' : 'Ни один вариант не дал минимум две сделки';
            } else {
                rankedCount++;
                option.textContent = item.symbol + ' · ' + pnlText(item.best_pnl);
                option.title = 'Лучший результат выбранного порога входа: ' + (item.best_exit || 'нет данных');
            }
            sortedOptions.push(option);
        }
        for (const option of options.values()) if (!sortedOptions.includes(option)) sortedOptions.push(option);
        symbolSelect.replaceChildren(...sortedOptions);
        symbolSelect.value = currentSymbol;
        buttonTitle = 'Пары отсортированы по выбранному порогу входа: надежных рейтингов ' + rankedCount + ' из ' + sortedOptions.length + '.';
    } catch (error) {
        portfolioRankingStatus.textContent = error.message || 'Не удалось рассчитать общий рейтинг';
        buttonTitle = error.message || 'Не удалось рассчитать общий рейтинг';
    } finally {
        resetRefreshButton(buttonTitle);
    }
}
refreshSymbolsButton.addEventListener('click', () => {
    symbolSortMode.value = 'profit';
    loadPortfolioRanking();
});
const collectorStatus = document.querySelector('#collector-status');
async function refreshCollectorStatus() {
    try {
        const response = await fetch('collector-status.php', {headers:{Accept:'application/json'}, cache:'no-store'});
        const status = await response.json();
        collectorStatus.dataset.status = status.status || 'unknown';
        collectorStatus.textContent = status.label || 'Не удалось проверить сборщик';
        collectorStatus.title = status.title || status.label || 'Статус сборщика недоступен';
    } catch (_) {
        collectorStatus.dataset.status = 'unknown';
        collectorStatus.textContent = 'Не удалось проверить сборщик';
        collectorStatus.title = 'Проверьте доступность базы данных';
    }
}
refreshCollectorStatus();
window.setInterval(refreshCollectorStatus, 30000);
if (symbolSortMode.value === 'profit') {
    loadPortfolioRanking();
}
loadExitRanking();
</script></body></html>
