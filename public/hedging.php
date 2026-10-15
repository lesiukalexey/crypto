<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Kyiv');
$root = dirname(__DIR__);
$config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
require_once __DIR__ . '/ticker-settings-lib.php';
session_name('bitget_hedging_session');
$hedgeSessionOpen = session_start(['cookie_httponly'=>true, 'cookie_samesite'=>'Lax']);
$requestedTickerSet = trim((string) ($_GET['ticker_set'] ?? ($hedgeSessionOpen ? ($_SESSION['ticker_set'] ?? '') : '')));
$tickerSetSelection = selectedTickerSet($config, $requestedTickerSet);
if ($hedgeSessionOpen) {
    $_SESSION['ticker_set'] = $tickerSetSelection['id'];
    session_write_close();
}

$tickerSetId = $tickerSetSelection['id'];
$tickerSetOptions = $tickerSetSelection['sets'];
$symbols = $tickerSetSelection['symbols'];
$startDayAuto = (string) ($_GET['start_day_auto'] ?? '') === '1'
    || (!array_key_exists('start_day', $_GET) && !array_key_exists('day', $_GET));
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Kyiv'));
$defaultStartDay = '2026-10-06';
if ($startDayAuto && $symbols !== []) {
    $db = $config['mysql'];
    $passwordEnv = (string) ($db['password_env'] ?? '');
    $password = $passwordEnv !== '' ? (getenv($passwordEnv) ?: '') : '';
    if ($password !== '') {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
                $db['user'], $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $placeholders = implode(', ', array_fill(0, count($symbols), '?'));
            $statement = $pdo->prepare(
                'SELECT MIN(received_at_utc) FROM quote_snapshots '
                . 'WHERE category = ? AND symbol IN (' . $placeholders . ')'
            );
            $statement->execute([$config['category'], ...$symbols]);
            $earliestQuote = $statement->fetchColumn();
            if (is_string($earliestQuote) && $earliestQuote !== '') {
                $defaultStartDay = (new DateTimeImmutable($earliestQuote, new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Europe/Kyiv'))
                    ->format('Y-m-d');
            }
        } catch (Throwable $error) {
            error_log('Unable to determine default hedge start date: ' . $error->getMessage());
        }
    }
}
$demoAvailableSymbols = array_fill_keys(loadDemoTickerAvailability($config, $pdo ?? null), true);
$startDay = $startDayAuto ? $defaultStartDay : (string) ($_GET['start_day'] ?? $_GET['day'] ?? $defaultStartDay);
$endDay = (string) ($_GET['end_day'] ?? $today->format('Y-m-d'));
$balanceAutoManaged = (string) ($_GET['balance_auto'] ?? '0') === '1';
$balance = (string) ($_GET['balance'] ?? '100');
$fee = (string) ($_GET['fee'] ?? '0.06');
$intervalMode = in_array((string) ($_GET['interval'] ?? 'two_weeks'), ['week', 'two_weeks', 'month'], true) ? (string) $_GET['interval'] : 'two_weeks';
$excludedIntervals = filter_var($_GET['exclude_last_intervals'] ?? '1', FILTER_VALIDATE_INT);
if ($excludedIntervals === false || $excludedIntervals < 0 || $excludedIntervals > 2) $excludedIntervals = 1;
$longSymbol = strtoupper((string) ($_GET['long_symbol'] ?? ($symbols[0] ?? '')));
$shortSymbol = strtoupper((string) ($_GET['short_symbol'] ?? ($symbols[1] ?? '')));
if ($longSymbol !== '' && !in_array($longSymbol, $symbols, true)) $longSymbol = $symbols[0] ?? '';
if (($shortSymbol !== '' && !in_array($shortSymbol, $symbols, true)) || ($shortSymbol !== '' && $shortSymbol === $longSymbol)) {
    $shortSymbol = current(array_values(array_filter($symbols, static fn (string $symbol): bool => $symbol !== $longSymbol))) ?: '';
}
$basicParams = $_GET;
$basicParams['ticker_set'] = $tickerSetId;
$basicParams['start_day_auto'] = $startDayAuto ? '1' : '0';
unset($basicParams['long_symbol'], $basicParams['short_symbol'], $basicParams['pair']);
$basicParams['start_day'] = $startDay;
$basicParams['end_day'] = $endDay;
$basicParams['balance'] = $balance;
$basicParams['fee'] = $fee;
$basicUrl = 'basic-checks.php?' . http_build_query($basicParams);
$hedgeParams = $_GET;
$hedgeParams['ticker_set'] = $tickerSetId;
$hedgeParams['start_day_auto'] = $startDayAuto ? '1' : '0';
$hedgeParams['start_day'] = $startDay;
$hedgeParams['end_day'] = $endDay;
$hedgeParams['balance'] = $balance;
$hedgeParams['fee'] = $fee;
$hedgeParams['long_symbol'] = $longSymbol;
$hedgeParams['short_symbol'] = $shortSymbol;
$hedgeUrl = 'hedging.php?' . http_build_query($hedgeParams);
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bitget — хеджирование</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#8995ad;--text:#e9eefb;--line:#273149;--green:#42d6a4;--red:#ff7188;--blue:#86a8ff}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:none;margin:0 auto;padding:30px 20px}.page-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:14px}.eyebrow{color:#8ea5ff;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:700}.view-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 20px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.view-nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.view-nav a:hover{background:#1b2843;color:var(--text)}.view-nav a[aria-current=page]{background:#263968;color:#fff}.panel{background:color-mix(in srgb,var(--panel) 95%,transparent);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 18px 50px #0003}.filters{display:block}.hedge-filter-grid{display:grid;grid-template-columns:minmax(360px,.85fr) minmax(0,1.5fr);gap:18px 28px;align-items:start}.hedge-pair-controls{display:grid;grid-template-columns:48px repeat(2,minmax(0,1fr));gap:12px;align-items:end}.hedge-pair-controls .field select{width:100%;min-width:0}.hedge-pair-controls .small-note{grid-column:1/-1;margin:0}.pair-ranking-trigger{width:44px;height:44px;display:grid;place-items:center;align-self:end;margin-bottom:21px;padding:0;border:1px solid #52658d;border-radius:50%;background:#18233d;color:#c6d2ee;cursor:pointer}.pair-ranking-trigger:hover{background:#263968;color:#fff;border-color:#8299ef}.pair-ranking-trigger:focus-visible{outline:2px solid #a9b9ff;outline-offset:2px}.pair-ranking-trigger:disabled{opacity:.65;cursor:wait}.pair-ranking-trigger svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.pair-ranking-trigger[aria-busy="true"] svg{animation:pair-ranking-spin 1s linear infinite}@keyframes pair-ranking-spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.pair-ranking-trigger[aria-busy="true"] svg{animation-duration:3s}}.hedge-period-controls{display:grid;grid-template-columns:repeat(6,minmax(0,220px));gap:12px;align-items:end;justify-content:start;padding:16px;border:1px solid #273149;border-radius:14px;background:#0c1222}.hedge-period-controls .field input{width:100%;min-width:0}.hedge-period-controls #minimum-balance-note{grid-column:1/-1;margin:0}.field{display:grid;gap:7px;color:var(--muted);font-size:13px;font-weight:600}.field select,.field input{font:inherit;color:var(--text);background:#0c1222;border:1px solid #34415e;border-radius:10px;padding:11px 13px;min-width:160px}.field select option.demo-available-ticker{color:#f2d77d;background:#292716}.field.pair-field select{min-width:360px}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:0 0 18px}.stat{background:#0c1222;border:1px solid var(--line);border-radius:12px;padding:13px 15px;min-width:0}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:19px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.gain{color:var(--green)}.loss{color:var(--red)}.chart-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px}.chart-head h2{font-size:17px;margin:0}.count,.small-note,.status{color:var(--muted);font-size:13px}.legend{display:flex;gap:18px;flex-wrap:wrap;color:#b7c1d5;font-size:13px;margin:14px 0}.legend span{display:inline-flex;gap:7px;align-items:center}.dot{width:9px;height:9px;border-radius:50%}.chart-wrap{position:relative}#hedge-chart{width:100%;height:clamp(260px,38vw,520px);display:block;overflow:visible}.chart-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:12px;background:#0c1222b8;color:#e9eefb;font-size:17px;font-weight:700;letter-spacing:.04em;z-index:1}.chart-loading[hidden]{display:none}.chart-loading-spinner{width:28px;height:28px;border:3px solid #34415e;border-top-color:#86a8ff;border-radius:50%;animation:chart-loading-spin .8s linear infinite}@keyframes chart-loading-spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.chart-loading-spinner{animation-duration:2s}}#tooltip{position:absolute;display:none;z-index:2;pointer-events:none;min-width:190px;padding:10px 12px;border:1px solid #394765;border-radius:10px;background:#0b1020f2;font-size:12px;white-space:nowrap}.status.error{color:#ffb3bf}.empty{display:grid;place-items:center;min-height:230px;color:var(--muted);text-align:center;padding:20px}.small-note{margin:12px 0 0}@media(max-width:1050px){.hedge-filter-grid{grid-template-columns:1fr}.hedge-period-controls{grid-template-columns:repeat(4,minmax(0,1fr))}}@media(max-width:650px){.wrap{padding:22px 14px}.panel{padding:16px}.page-heading{align-items:flex-start}.field,.field select,.field input,.field.pair-field select{width:100%;min-width:0}.hedge-period-controls{grid-template-columns:repeat(2,minmax(0,1fr));padding:12px}.view-nav{width:100%}.view-nav a{flex:1;text-align:center}#hedge-chart{height:70vw}}
.chart-mode{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.chart-mode button{border:1px solid #34415e;border-radius:9px;padding:9px 13px;background:#0c1222;color:#b7c1d5;font:inherit;cursor:pointer}.chart-mode button[aria-pressed="true"]{background:#263968;border-color:#5974bd;color:#fff}.chart-mode button:focus-visible{outline:2px solid #86a8ff;outline-offset:2px}
.ticker-set-panel{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 18px;padding:14px 18px}.ticker-set-panel label{font-weight:700}.ticker-set-panel select{min-width:240px;padding:10px 12px;border:1px solid #34415e;border-radius:9px;background:#0c1222;color:var(--text);font:inherit}.ticker-set-panel .small-note{margin:0}.stat small{display:block;color:var(--muted);font-size:12px;font-weight:400;margin-top:3px}#local-moves-panel .chart-head{flex-wrap:wrap}.local-moves-scale{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:var(--muted);font-size:13px;font-weight:600}.local-moves-scale select{font:inherit;color:var(--text);background:#0c1222;border:1px solid #34415e;border-radius:9px;padding:8px 10px}
.table-wrap{overflow:auto}.local-moves-table{width:100%;border-collapse:collapse;min-width:760px;font-size:13px}.local-moves-table th,.local-moves-table td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.local-moves-table th{color:var(--muted);font-weight:600}.local-moves-table th:first-child,.local-moves-table td:first-child{text-align:left}
.symbol-select-control{position:relative;width:100%}.ticker-sort-indicator[hidden]{display:none}.ticker-sort-indicator{position:absolute;top:50%;right:38px;transform:translateY(-50%);display:flex;align-items:center;gap:1px;padding:3px 6px;border-radius:6px;background:#0c1222;color:#a9b9ff;font-size:11px;font-weight:600;line-height:1.2;pointer-events:none}.ticker-sort-indicator .ticker-sort-dot{animation:ticker-sort-pulse 1.1s infinite;opacity:.25}.ticker-sort-indicator .ticker-sort-dot:nth-child(3){animation-delay:.18s}.ticker-sort-indicator .ticker-sort-dot:nth-child(4){animation-delay:.36s}@keyframes ticker-sort-pulse{0%,60%,100%{opacity:.25}30%{opacity:1}}@media(prefers-reduced-motion:reduce){.ticker-sort-indicator .ticker-sort-dot{animation:none;opacity:1}}
.symbol-select-control[aria-busy="true"] select{padding-right:135px}
.hedge-page-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px}.hedge-page-actions button,.hedge-page-actions a{display:inline-flex;align-items:center;justify-content:center;min-width:145px;border:1px solid #52658d;border-radius:9px;padding:11px 16px;background:#18233d;color:#e9eefb;font:700 14px system-ui,sans-serif;text-decoration:none;cursor:pointer}.hedge-page-actions button.primary{border-color:#6c8cff;background:#304b99}.hedge-page-actions button.primary:hover{background:#3c5db8}.hedge-page-actions a:hover{background:#263968}.hedge-page-actions button:focus-visible,.hedge-page-actions a:focus-visible{outline:2px solid #a9b9ff;outline-offset:2px}
.small-note.error{color:#ffb3bf}
</style>
</head>
<body><main class="wrap">
<header class="page-heading"><div class="eyebrow">Market data · <?= $h($config['category']) ?></div><span class="count">Симуляция по сохранённым котировкам</span></header>
<nav class="view-nav" aria-label="Разделы"><a href="index.php">Главная</a><a href="<?= $h($basicUrl) ?>">Базовые проверки</a><a href="<?= $h($hedgeUrl) ?>" aria-current="page">Хеджирование</a><a href="settings.php">Настройки</a><a id="autotrading-paper-link" href="autotrading.php?mode=paper&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Автоторговля (Paper)</a><a id="autotrading-demo-link" href="autotrading.php?mode=demo&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Автотрейдинг (демо)</a><a id="autotrading-real-link" href="autotrading.php?mode=real&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Автотрейдинг (real)</a><a href="autotrading-pi.php">Raspberry Pi</a></nav>
<section class="panel ticker-set-panel" aria-label="Выбранный список тикеров"><label for="ticker-set-select">Выбранный список тикеров</label><select id="ticker-set-select" ><?php foreach ($tickerSetOptions as $tickerSet): ?><option value="<?= $h((string) ($tickerSet['id'] ?? '')) ?>" <?= ($tickerSet['id'] ?? '') === $tickerSetId ? 'selected' : '' ?>><?= $h((string) ($tickerSet['name'] ?? '')) ?></option><?php endforeach; ?></select><span class="small-note"><?= count($symbols) ?> активных тикеров в списке</span></section>
<h1>Хеджирование между тикерами</h1>
<section class="panel"><form class="filters" id="hedge-filters">
<input type="hidden" name="ticker_set" value="<?= $h($tickerSetId) ?>">
<input type="hidden" name="start_day_auto" value="<?= $startDayAuto ? '1' : '0' ?>">
<div class="hedge-filter-grid">
<div class="hedge-pair-controls">
<button class="pair-ranking-trigger" type="button" id="rank-hedge-pairs" aria-label="Найти лучшую пару и отсортировать тикеры" title="Найти лучшую пару среди всех сочетаний лонга и шорта и отсортировать тикеры"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4.8 9a7.5 7.5 0 0 1 12.8-3L20 9M4 17v-5h5m10.2 3a7.5 7.5 0 0 1-12.8 3L4 15"/></svg></button>
<label class="field">Лонг<div class="symbol-select-control"><select id="hedge-long-symbol" ><option value="" <?= $longSymbol === '' ? 'selected' : '' ?>></option><?php foreach ($symbols as $symbol): ?><option value="<?= $h($symbol) ?>" class="<?= isset($demoAvailableSymbols[$symbol]) ? 'demo-available-ticker' : '' ?>" <?= $symbol === $longSymbol ? 'selected' : '' ?>><?= $h($symbol) ?></option><?php endforeach; ?></select><span class="ticker-sort-indicator" id="hedge-long-sort-indicator" role="status" aria-label="Считаю коэффициенты пар" hidden>Считаю<span class="hedge-sort-progress"></span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span></span></div></label>
<label class="field">Шорт<div class="symbol-select-control"><select id="hedge-short-symbol" ><option value="" <?= $shortSymbol === '' ? 'selected' : '' ?>></option><?php foreach ($symbols as $symbol): ?><option value="<?= $h($symbol) ?>" class="<?= isset($demoAvailableSymbols[$symbol]) ? 'demo-available-ticker' : '' ?>" <?= $symbol === $shortSymbol ? 'selected' : '' ?>><?= $h($symbol) ?></option><?php endforeach; ?></select><span class="ticker-sort-indicator" id="hedge-short-sort-indicator" role="status" aria-label="Считаю коэффициенты пар" hidden>Считаю<span class="hedge-sort-progress"></span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span></span></div></label>
<span class="small-note">Оставьте одну сторону пустой для расчёта одного тикера; весь баланс используется на выбранную позицию. Жёлтым отмечены тикеры, доступные в Bitget Demo.</span>
<span class="small-note" id="hedge-ticker-coverage-note" hidden></span>

</div>
<input type="hidden" name="pair" id="hedge-pair" value="<?= $h($longSymbol . '|' . $shortSymbol) ?>">
<div class="hedge-period-controls" aria-label="Период и параметры расчёта">
<label class="field">С даты<input type="date" name="start_day" value="<?= $h($startDay) ?>" required></label>
<label class="field">По дату<input type="date" name="end_day" value="<?= $h($endDay) ?>" required></label>
<label class="field">Стартовый баланс (USDT)<input type="number" name="balance" min="0.01" max="1000000000" step="0.01" value="<?= $h($balance) ?>" required></label>
<input type="hidden" name="balance_auto" value="<?= $balanceAutoManaged ? '1' : '0' ?>">
<label class="field">Комиссия за сторону (%)<input type="number" name="fee" min="0" max="5" step="0.001" value="<?= $h($fee) ?>" required></label>
<label class="field">Разбить по интервалам<select name="interval" id="hedge-interval"><option value="none" <?= $intervalMode === 'none' ? 'selected' : '' ?>>Без разбивки (всё как сейчас)</option><option value="week" <?= $intervalMode === 'week' ? 'selected' : '' ?>>Неделя</option><option value="two_weeks" <?= $intervalMode === 'two_weeks' ? 'selected' : '' ?>>2 недели</option><option value="month" <?= $intervalMode === 'month' ? 'selected' : '' ?>>Месяц</option></select></label>
<label class="field">Не учитывать последние интервалы<select name="exclude_last_intervals" id="hedge-exclude-intervals"><?php foreach ([0, 1, 2] as $excludedOption): ?><option value="<?= $excludedOption ?>" <?= $excludedIntervals === $excludedOption ? 'selected' : '' ?>><?= $excludedOption ?></option><?php endforeach; ?></select></label>
<p class="small-note" id="minimum-balance-note" aria-live="polite">Для выбранной пары рассчитывается сумма минимальных размеров лонга и шорта с комиссией Bitget.</p>
</div>
</div>
<div class="hedge-page-actions"><a id="hedge-add-paper" href="autotrading.php?mode=paper&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Добавить в Paper</a><a id="hedge-add-demo" href="autotrading.php?mode=demo&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Добавить в Demo</a><a id="hedge-add-real" href="autotrading.php?mode=real&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Добавить в Real</a></div>
<p class="small-note">Кнопка откроет подготовку пары в выбранном портфеле. Добавление подтверждается на следующей странице; исполнитель не запускается.</p>
</form><p class="small-note" id="pair-ranking-status" role="status" aria-live="polite">Расчёт не запущен. Нажмите ↻ слева от тикеров, чтобы найти лучшую пару и отсортировать селекторы.</p><p class="small-note"><a id="hedge-pair-matrix-link" href="hedge-pair-matrix.php" target="_blank" rel="noopener">Открыть таблицу всех сочетаний лонга и шорта</a></p><p class="small-note">Баланс поровну распределяется между двумя позициями. Каждая сторона открывается по доступному ask/bid, закрывается для оценки по bid/ask, комиссия учитывается при входе и выходе. Несинхронные котировки сопоставляются по последнему уже известному снимку; снимки старше 120 секунд пропускаются.</p></section>
<p class="status" id="hedge-status" role="status" aria-live="polite">Загружаю данные выбранной позиции…</p>
<section class="summary" id="hedge-summary" hidden></section>
<section class="panel" id="hedge-interval-panel" hidden><div class="chart-head"><h2>Результаты по интервалам</h2></div><p class="small-note">Показаны только полные интервалы от 00:00 до 00:00 по Киеву; правая граница не включается. Неполные интервалы в начале и конце доступных данных исключаются.</p><div class="table-wrap"><table class="local-moves-table"><thead><tr><th>Период</th><th>Лонг</th><th>Шорт</th><th>Общий итог</th><th>Максимум за период</th><th>Максимальное снижение на графике</th><th>Максимум / снижение</th></tr></thead><tbody id="hedge-interval-rows"></tbody></table></div><section class="summary" id="hedge-interval-summary" aria-label="Сводка по худшему интервалу"></section></section>
<section class="panel"><div class="chart-mode" role="group" aria-label="Вид графика хеджирования"><button type="button" data-chart-view="net" aria-pressed="true">Чистая доходность по хеджированию</button><button type="button" data-chart-view="ticker" aria-pressed="false">Хеджирование между тикерами</button></div><div class="chart-head"><h2 id="hedge-chart-title">Чистая доходность по хеджированию</h2><span class="count" id="hedge-point-count"></span></div><div class="legend" id="hedge-legend"></div><div class="chart-wrap"><svg id="hedge-chart" role="img" aria-label="Чистая доходность по хеджированию во времени"></svg><div id="chart-loading" class="chart-loading" hidden role="status" aria-live="polite"><span class="chart-loading-spinner" aria-hidden="true"></span><span>Loading</span></div><div id="tooltip"></div></div><p class="small-note" id="hedge-chart-note">По вертикали — чистый результат в USDT с учётом комиссий открытия и расчётного закрытия. Позиции открываются на первом синхронизированном снимке выбранного периода.</p></section>
<section class="panel" id="local-moves-panel" hidden><div class="chart-head"><h2>Крупнейшие локальные падения</h2><label class="local-moves-scale">Длительность падения<select id="local-moves-scale" aria-label="Минимальная длительность локального падения"><option value="minimum">Минимальная (как сейчас)</option><option value="medium">Средняя длительность</option><option value="large">Большая длительность</option></select></label><span class="count" id="local-moves-summary"></span></div><p class="small-note" id="local-moves-threshold"></p><div class="table-wrap"><table class="local-moves-table"><thead><tr><th>№</th><th>Максимум · время</th><th>Максимум · USDT</th><th>Минимум · время</th><th>Минимум · USDT</th><th>Падение</th><th>Длительность</th></tr></thead><tbody id="local-moves-rows"></tbody></table></div></section>
</main>
<script>
const form=document.querySelector('#hedge-filters'),pairSelect=document.querySelector('#hedge-pair'),longSymbolSelect=document.querySelector('#hedge-long-symbol'),shortSymbolSelect=document.querySelector('#hedge-short-symbol'),statusNode=document.querySelector('#hedge-status'),summaryNode=document.querySelector('#hedge-summary'),chart=document.querySelector('#hedge-chart'),chartLoading=document.querySelector('#chart-loading'),pointCount=document.querySelector('#hedge-point-count'),tooltip=document.querySelector('#tooltip'),ns='http://www.w3.org/2000/svg';
const allTickerOptions=[...longSymbolSelect.options].map(option=>option.cloneNode(true)),tickerCoverageNote=document.querySelector('#hedge-ticker-coverage-note');
let tickerCoverageRequest=0;
let preferredLongSymbol=longSymbolSelect.value,preferredShortSymbol=shortSymbolSelect.value;
const tickerCoverageCache=new Map();
function dateAtUtcMidnight(value){return new Date(value+'T00:00:00Z')}
function subtractCalendarMonths(date,months){const result=new Date(date),day=result.getUTCDate();result.setUTCDate(1);result.setUTCMonth(result.getUTCMonth()-months);const lastDay=new Date(Date.UTC(result.getUTCFullYear(),result.getUTCMonth()+1,0)).getUTCDate();result.setUTCDate(Math.min(day,lastDay));return result}
async function refreshTickerCoverage(){
  const interval=form.elements.namedItem('interval').value,request=++tickerCoverageRequest;
  if(!['two_weeks','month'].includes(interval)){tickerCoverageNote.hidden=true;applyTickerCoverage(new Set(allTickerOptions.map(option=>option.value).filter(Boolean)));return}
  const startDay=form.elements.namedItem('start_day').value,endDay=form.elements.namedItem('end_day').value;
  if(!startDay||!endDay){tickerCoverageNote.hidden=false;tickerCoverageNote.textContent='Укажите диапазон дат, чтобы отфильтровать тикеры по истории.';return}
  const cacheKey=[form.elements.namedItem('ticker_set').value,startDay,endDay].join('|');
  try{
    let coverage=tickerCoverageCache.get(cacheKey);
    if(!coverage){const params=new URLSearchParams({ticker_set:form.elements.namedItem('ticker_set').value,start_day:startDay,end_day:endDay});const response=await fetch('hedge-symbol-coverage.php?'+params,{headers:{Accept:'application/json'},cache:'no-store'});const data=await response.json();if(!response.ok||data.status!=='ready')throw new Error(data.message||'Не удалось проверить историю тикеров.');coverage=data.coverage||{};tickerCoverageCache.set(cacheKey,coverage)}
    if(request!==tickerCoverageRequest)return;
    const eligible=new Set(),requiredDays=interval==='two_weeks'?28:null;
    for(const[symbol,range]of Object.entries(coverage)){
      const first=dateAtUtcMidnight(range.first_day),end=dateAtUtcMidnight(range.last_day_exclusive);
      if(interval==='two_weeks'?end-first>=requiredDays*86400000:first<=subtractCalendarMonths(end,2))eligible.add(symbol);
    }
    applyTickerCoverage(eligible);
    tickerCoverageNote.hidden=false;
    tickerCoverageNote.textContent=interval==='two_weeks'?`Показаны тикеры с историей не менее 4 недель в выбранном диапазоне: ${eligible.size} из ${allTickerOptions.length-1}.`:`Показаны тикеры с историей не менее 2 месяцев в выбранном диапазоне: ${eligible.size} из ${allTickerOptions.length-1}.`;
  }catch(error){if(request!==tickerCoverageRequest)return;tickerCoverageNote.hidden=false;tickerCoverageNote.textContent=error.message+' Список тикеров пока не изменён.'}
}
function applyTickerCoverage(eligible){
  const previousLong=longSymbolSelect.value,previousShort=shortSymbolSelect.value;
  for(const select of [longSymbolSelect,shortSymbolSelect]){
    const current=select===longSymbolSelect?preferredLongSymbol:preferredShortSymbol,options=allTickerOptions.filter(option=>option.value===''||eligible.has(option.value)).map(option=>option.cloneNode(true));
    select.replaceChildren(...options);
    if(current&&eligible.has(current))select.value=current;
  }
  if(!eligible.has(longSymbolSelect.value))longSymbolSelect.value=[...eligible].find(symbol=>symbol!==shortSymbolSelect.value)||'';
  if(!eligible.has(shortSymbolSelect.value)||shortSymbolSelect.value===longSymbolSelect.value)shortSymbolSelect.value=[...eligible].find(symbol=>symbol!==longSymbolSelect.value)||'';
  if(previousLong!==longSymbolSelect.value||previousShort!==shortSymbolSelect.value){syncManualPairSelection();syncHedgeUrlFromForm();scheduleSelectedHedgeCalculation()}
}
function setChartLoading(loading){chartLoading.hidden=!loading}
const tickerSetSelect=document.querySelector('#ticker-set-select');
tickerSetSelect?.addEventListener('change',()=>{const params=new URLSearchParams(location.search);params.set('ticker_set',tickerSetSelect.value);for(const key of ['pair','long_symbol','short_symbol'])params.delete(key);location.href=location.pathname+'?'+params.toString()});
const startDayAutoInput=form.elements.namedItem('start_day_auto');
const demoAvailableTickerSymbols=new Set(<?= json_encode(array_keys($demoAvailableSymbols), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
const palette={long_index:'#42d6a4',short_index:'#ff7188',equity_index:'#86a8ff'};
const chartModeButtons=[...document.querySelectorAll('[data-chart-view]')],chartTitle=document.querySelector('#hedge-chart-title'),chartLegend=document.querySelector('#hedge-legend'),chartNote=document.querySelector('#hedge-chart-note');
let chartView=new URLSearchParams(location.search).get('chart_view')==='ticker'?'ticker':'net';
const fmt=(n,d=4)=>Number(n).toLocaleString('ru-RU',{minimumFractionDigits:d,maximumFractionDigits:d});
const money=n=>(Number(n)>0?'+':'')+fmt(n)+' USDT';
const signed=(n,d=2)=>(Number(n)>0?'+':Number(n)<0?'−':'')+fmt(Math.abs(Number(n)),d);
const HEDGE_SESSION_PREFIX='hedge-results:v1:';
function hedgeFilterScope(){return JSON.stringify([form.elements.start_day.value,form.elements.end_day.value,form.elements.balance.value,form.elements.fee.value,form.elements.interval.value])}
function hedgeResultScope(){return JSON.stringify([form.elements.namedItem('ticker_set').value,...JSON.parse(hedgeFilterScope())])}
function hedgeRankingSessionKey(){return HEDGE_SESSION_PREFIX+'ranking:'+JSON.stringify([hedgeResultScope(),form.elements.exclude_last_intervals.value])}
function hedgeIntervalSessionKey(){return HEDGE_SESSION_PREFIX+'interval:'+hedgeResultScope()}
function hedgeSelectorSessionKey(){return HEDGE_SESSION_PREFIX+'selectors:'+JSON.stringify([hedgeResultScope(),form.elements.exclude_last_intervals.value])}
function clearHedgeSessionResults(){try{for(let i=sessionStorage.length-1;i>=0;i--){const key=sessionStorage.key(i);if(key?.startsWith(HEDGE_SESSION_PREFIX))sessionStorage.removeItem(key)}sessionStorage.setItem(HEDGE_SESSION_PREFIX+'filter-scope',hedgeFilterScope())}catch{}}
function reconcileHedgeSession(){try{const key=HEDGE_SESSION_PREFIX+'filter-scope',current=hedgeFilterScope(),previous=sessionStorage.getItem(key);if(previous!==null&&previous!==current)clearHedgeSessionResults();sessionStorage.setItem(key,current)}catch{}}
function saveHedgeIntervalResult(data,longSymbol,shortSymbol){try{const key=hedgeIntervalSessionKey(),saved=JSON.parse(sessionStorage.getItem(key)||'{}'),entries=saved.entries&&typeof saved.entries==='object'?saved.entries:{},pairKey=JSON.stringify([longSymbol,shortSymbol]);entries[pairKey]={long_symbol:longSymbol,short_symbol:shortSymbol,starting_balance:data.starting_balance,interval_rows:data.interval_rows||[],updated_at:Date.now()};const keep=Object.entries(entries).sort((a,b)=>Number(b[1]?.updated_at||0)-Number(a[1]?.updated_at||0)).slice(0,50);sessionStorage.setItem(key,JSON.stringify({entries:Object.fromEntries(keep)}))}catch{}}
function restoreHedgeIntervalResult(){try{const saved=JSON.parse(sessionStorage.getItem(hedgeIntervalSessionKey())||'{}'),pairKey=JSON.stringify([longSymbolSelect.value,shortSymbolSelect.value]),data=saved.entries?.[pairKey];if(data&&Array.isArray(data.interval_rows))renderIntervalResults(data)}catch{}}
function saveHedgeSelectorLabels(){try{sessionStorage.setItem(hedgeSelectorSessionKey(),JSON.stringify({long_symbol:longSymbolSelect.value,short_symbol:shortSymbolSelect.value,long_options:[...longSymbolSelect.options].map(option=>({value:option.value,text:option.textContent,className:option.className})),short_options:[...shortSymbolSelect.options].map(option=>({value:option.value,text:option.textContent,className:option.className}))}))}catch{}}
function restoreHedgeSelectorLabels(){try{const saved=JSON.parse(sessionStorage.getItem(hedgeSelectorSessionKey())||'null');if(!saved)return;for(const[select,items]of [[longSymbolSelect,saved.long_options],[shortSymbolSelect,saved.short_options]]){const allowed=new Set([...select.options].map(option=>option.value));const options=(items||[]).filter(item=>allowed.has(item.value)).map(item=>{const option=document.createElement('option');option.value=item.value;option.textContent=item.text;option.className=item.className;option.selected=item.value===select.value;return option});if(options.length)select.replaceChildren(...options)}}catch{}}
const durationLabel=milliseconds=>{let minutes=Math.floor(Math.max(0,Number(milliseconds))/60000);const days=Math.floor(minutes/1440);minutes%=1440;const hours=Math.floor(minutes/60);minutes%=60;const parts=[];if(days)parts.push(`${days} д`);if(hours)parts.push(`${hours} ч`);if(minutes)parts.push(`${minutes} мин`);return parts.join(' ')||'меньше минуты'};
function findLocalDeclines(points){
  const peaks=[],troughs=[];let direction=0;
  for(let i=1;i<points.length;i++){
    const difference=Number(points[i].net_pnl)-Number(points[i-1].net_pnl);
    if(difference===0)continue;
    const nextDirection=difference>0?1:-1;
    if(direction===1&&nextDirection===-1)peaks.push(i-1);
    if(direction===-1&&nextDirection===1)troughs.push(i-1);
    direction=nextDirection;
  }
  const declines=[];let troughCursor=0;
  for(const peakIndex of peaks){
    while(troughCursor<troughs.length&&troughs[troughCursor]<=peakIndex)troughCursor++;
    if(troughCursor>=troughs.length)continue;
    const minimumIndex=troughs[troughCursor++],peak=points[peakIndex],minimum=points[minimumIndex];
    declines.push({peakIndex:Number(peak.sourceIndex??peakIndex),minimumIndex:Number(minimum.sourceIndex??minimumIndex),peakValue:Number(peak.net_pnl),minimumValue:Number(minimum.net_pnl),peakTime:Number(peak.t),minimumTime:Number(minimum.t),decline:Number(peak.net_pnl)-Number(minimum.net_pnl)});
  }
  return declines;
}
function samplePointsAtInterval(points,intervalMs){
  if(intervalMs<=0||points.length<2)return points.map((point,sourceIndex)=>({...point,sourceIndex}));
  const start=Number(points[0].t),sampled=[];let nextBoundary=start;
  for(let sourceIndex=0;sourceIndex<points.length;sourceIndex++){
    const point=points[sourceIndex],time=Number(point.t);
    if(time<nextBoundary)continue;
    sampled.push({...point,sourceIndex});
    nextBoundary=start+(Math.floor((time-start)/intervalMs)+1)*intervalMs;
  }
  return sampled;
}
const localMovesPanel=document.querySelector('#local-moves-panel'),localMovesSummary=document.querySelector('#local-moves-summary'),localMovesRows=document.querySelector('#local-moves-rows'),localMovesScale=document.querySelector('#local-moves-scale'),localMovesThreshold=document.querySelector('#local-moves-threshold');
const pairRankingStatus=document.querySelector('#pair-ranking-status');
let pairRankingRequest=0;
let pairSelectionVersion=0;
let hedgeDataRequest=0;
let rankedPairData=null;
function setHedgeRankingLoading(loading){
  const trigger=document.querySelector('#rank-hedge-pairs');trigger.disabled=loading;trigger.setAttribute('aria-busy',String(loading));
  for(const select of [longSymbolSelect,shortSymbolSelect]){
    select.closest('.symbol-select-control').setAttribute('aria-busy',String(loading));
    const indicator=document.querySelector(select===longSymbolSelect?'#hedge-long-sort-indicator':'#hedge-short-sort-indicator');
    indicator.hidden=!loading;indicator.querySelector('.hedge-sort-progress').textContent='';
  }
}
function updateHedgeRankingProgress(message){
  const percent=message.match(/(\d+)%/);
  for(const node of document.querySelectorAll('.hedge-sort-progress'))node.textContent=percent?' '+percent[1]+'%':'';
}

function setAutoTradingLinksReady(ready){for(const id of ['#autotrading-paper-link','#autotrading-demo-link','#autotrading-real-link','#hedge-add-paper','#hedge-add-demo','#hedge-add-real']){const link=document.querySelector(id);if(!link)continue;link.setAttribute('aria-disabled',ready?'false':'true');link.tabIndex=ready?0:-1;link.style.pointerEvents=ready?'':'none';link.style.opacity=ready?'':'0.55'}}
function updateAutoTradingLinks(){if(!longSymbolSelect||!shortSymbolSelect)return;for(const[mode,id]of [['paper','#autotrading-paper-link'],['demo','#autotrading-demo-link'],['real','#autotrading-real-link'],['paper','#hedge-add-paper'],['demo','#hedge-add-demo'],['real','#hedge-add-real']]){const link=document.querySelector(id);if(!link)continue;const params=new URLSearchParams({mode,view:'setup',hedge_long:longSymbolSelect.value,hedge_short:shortSymbolSelect.value,hedge_start_day:form.elements.start_day.value,hedge_end_day:form.elements.end_day.value,hedge_balance:form.elements.balance.value,hedge_fee:form.elements.fee.value});link.href='autotrading.php?'+params.toString()}const matrixLink=document.querySelector('#hedge-pair-matrix-link');if(matrixLink){const params=new URLSearchParams(new FormData(form));params.delete('pair');params.set('ticker_set',form.elements.namedItem('ticker_set').value);params.set('symbols',[...new Set([...longSymbolSelect.options,...shortSymbolSelect.options].map(option=>option.value).filter(Boolean))].join(','));matrixLink.href='hedge-pair-matrix.php?'+params.toString()}}
function syncManualPairSelection(){if(longSymbolSelect&&shortSymbolSelect){pairSelect.value=longSymbolSelect.value+'|'+shortSymbolSelect.value;updateAutoTradingLinks()}}
function syncHedgeUrlFromForm(){const next=new URL(location.href);for(const key of ['start_day','end_day','balance','balance_auto','fee','start_day_auto','interval','exclude_last_intervals'])next.searchParams.set(key,form.elements.namedItem(key).value);next.searchParams.set('long_symbol',longSymbolSelect.value);next.searchParams.set('short_symbol',shortSymbolSelect.value);next.searchParams.set('pair',pairSelect.value);history.replaceState(null,'',next)}
let selectedHedgeCalculationTimer=0;
function scheduleSelectedHedgeCalculation(){window.clearTimeout(selectedHedgeCalculationTimer);selectedHedgeCalculationTimer=window.setTimeout(()=>{selectedHedgeCalculationTimer=0;loadData()},400)}
function markHedgeCalculationPending(invalidateRanking=true){
  hedgeDataRequest++;
  if(invalidateRanking){
    pairRankingRequest++;setHedgeRankingLoading(false);rankedPairData=null;
    for(const select of [longSymbolSelect,shortSymbolSelect])for(const option of select.options)option.textContent=option.value;
    pairRankingStatus.textContent='Рейтинг сброшен: нажмите ↻ слева от тикеров, чтобы рассчитать доходность для новых параметров.';
  }else if(rankedPairData){
    renderCurrentTickerRankings();
    pairRankingStatus.textContent='Рейтинг всех пар сохранён; списки отсортированы относительно выбранных тикеров, доходность показана в вариантах.';
  }
  window.currentHedgeData=null;setChartLoading(false);summaryNode.hidden=true;localMovesPanel.hidden=true;chart.replaceChildren();chartLegend.replaceChildren();pointCount.textContent='';statusNode.classList.remove('error');statusNode.textContent='Параметры изменены. График выбранной позиции пересчитается автоматически.';setAutoTradingLinksReady(Boolean(longSymbolSelect.value&&shortSymbolSelect.value))
}
function renderTickerRanking(select,pairs,partnerSide){
  if(!select||!Array.isArray(pairs))return;
  const selected=select.value,bySymbol=new Map(pairs.map(pair=>[pair[partnerSide==='short'?'long_symbol':'short_symbol'],pair]));
  const options=[...select.options].map(option=>({symbol:option.value,pair:bySymbol.get(option.value)}));
  options.sort((left,right)=>{
    if(left.symbol===''||right.symbol==='')return left.symbol===''?-1:1;
    const leftValue=left.pair?.maximum_interval_ratio,rightValue=right.pair?.maximum_interval_ratio;
    if(leftValue===null||leftValue===undefined)return rightValue===null||rightValue===undefined?left.symbol.localeCompare(right.symbol):1;
    if(rightValue===null||rightValue===undefined)return -1;
    return Number(rightValue)-Number(leftValue)||Number(right.pair?.net_pnl||0)-Number(left.pair?.net_pnl||0)||left.symbol.localeCompare(right.symbol);
  });
  select.replaceChildren(...options.map(({symbol,pair})=>{
    const option=document.createElement('option');option.value=symbol;
    const returnLabel=pair?.net_pnl===null||pair?.net_pnl===undefined?'нет данных':`итог ${signed(pair.return_percent)}% · max score ${pair.maximum_interval_ratio===null||pair.maximum_interval_ratio===undefined?'—':fmt(pair.maximum_interval_ratio,2)} · min score ${pair.peak_drawdown_ratio===null||pair.peak_drawdown_ratio===undefined?'—':fmt(pair.peak_drawdown_ratio,2)}`;
    option.classList.toggle('demo-available-ticker',demoAvailableTickerSymbols.has(symbol));
    option.textContent=pair?`${symbol} · ${returnLabel}`:symbol;
    option.selected=symbol===selected;return option;
  }));
}
function renderCurrentTickerRankings(){
  if(!rankedPairData){return}
  const{symbols,pair_matrix:matrix,pair_ratio_matrix:ratios,pair_maximum_ratio_matrix:maximumRatios,starting_balance:balance}=rankedPairData,index=new Map(symbols.map((symbol,i)=>[symbol,i]));
  const longIndex=index.get(longSymbolSelect.value),shortIndex=index.get(shortSymbolSelect.value);
  if(longIndex===undefined||shortIndex===undefined)return;
  const makePair=(longSymbol,shortSymbol)=>{const longIndex=index.get(longSymbol),shortIndex=index.get(shortSymbol),net_pnl=matrix[longIndex][shortIndex];return{long_symbol:longSymbol,short_symbol:shortSymbol,net_pnl,peak_drawdown_ratio:ratios[longIndex][shortIndex],maximum_interval_ratio:maximumRatios[longIndex][shortIndex],return_percent:net_pnl===null?null:Number(net_pnl)/Number(balance)*100}};
  renderTickerRanking(longSymbolSelect,symbols.filter(symbol=>symbol!==shortSymbolSelect.value).map(symbol=>makePair(symbol,shortSymbolSelect.value)),'short');
  renderTickerRanking(shortSymbolSelect,symbols.filter(symbol=>symbol!==longSymbolSelect.value).map(symbol=>makePair(longSymbolSelect.value,symbol)),'long');
  saveHedgeSelectorLabels();
}
for(const select of [longSymbolSelect,shortSymbolSelect])select?.addEventListener('change',()=>{if(select===longSymbolSelect)preferredLongSymbol=select.value;else preferredShortSymbol=select.value;pairSelectionVersion++;syncManualPairSelection()});
pairSelect.addEventListener('change',()=>{pairSelectionVersion++});
const waitForHistoryImport=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds));
const formatImportDateTime=timestamp=>new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Kyiv',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).format(new Date(timestamp));
function formatImportDuration(milliseconds){let seconds=Math.max(0,Math.floor(milliseconds/1000));const days=Math.floor(seconds/86400);seconds%=86400;const hours=Math.floor(seconds/3600);seconds%=3600;const minutes=Math.floor(seconds/60);seconds%=60;return days?`${days} д ${hours} ч ${minutes} мин`:hours?`${hours} ч ${minutes} мин ${seconds} сек`:`${minutes} мин ${seconds} сек`}
function rankingImportTiming(status){
  const startedAt=Number(status.started_at_ms),progress=Number(status.progress||0),now=Date.now();
  if(!Number.isFinite(startedAt)||startedAt<=0)return '';
  const elapsed=Math.max(0,now-startedAt),parts=[`старт: ${formatImportDateTime(startedAt)}`];
  if(progress>0&&progress<100){const remaining=elapsed*(100-progress)/progress;parts.push(`расчётное окончание: ${formatImportDateTime(now+remaining)}`)}
  else parts.push('расчётное окончание появится после первых страниц');
  parts.push(`прошло: ${formatImportDuration(elapsed)}`);
  return parts.join(' · ');
}
async function requireCompleteRankingHistory(params,requestId){
  const completenessParams=new URLSearchParams({
    start_day:params.get('start_day')||'',
    end_day:params.get('end_day')||'',
    ticker_set:params.get('ticker_set')||'',
    exclude_end_day:params.get('interval')&&params.get('interval')!=='none'?'1':'0',
    coverage_only:'1',
  });
  const completenessResponse=await fetch('candle-completeness.php?'+completenessParams.toString(),{headers:{Accept:'application/json'},cache:'no-store'});
  const completeness=await completenessResponse.json();
  if(!completenessResponse.ok||completeness.status!=='ready')throw new Error(completeness.message||'Не удалось проверить полноту исторических данных. Рейтинг не запущен.');
  if(!completeness.all_incomplete)return true;
  const missingDetails=(completeness.all_incomplete_details||[]).slice(0,5).map(item=>`${item.symbol}: ${item.first_quote||'нет начала'} — ${item.last_quote||'нет конца'}`).join(', ');
  const missingSuffix=missingDetails?` Покрытие ${completeness.coverage_start_day} — ${completeness.coverage_end_day}: ${missingDetails}${(completeness.all_incomplete_details||[]).length>5?' и другие.':'.'}`:'';
  pairRankingStatus.classList.add('error');
  pairRankingStatus.textContent='Ошибка: для выбранного периода не хватает минутных данных.'+missingSuffix+' Рейтинг не запущен; запускаю подкачивание недостающей истории с Bitget…';
  const selectedEndDay=params.get('end_day')||'';
  const intervalEndDay=params.get('interval')&&params.get('interval')!=='none'&&selectedEndDay
    ? new Date(new Date(selectedEndDay+'T12:00:00Z').getTime()-86400000).toISOString().slice(0,10)
    : selectedEndDay;
  const importRequest=new URLSearchParams({scope:'all',ticker_set:params.get('ticker_set')||'',start_day:params.get('start_day')||'',end_day:intervalEndDay});
  const importResponse=await fetch('import-candles.php',{method:'POST',body:importRequest,headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},cache:'no-store'});
  const importResult=await importResponse.json();
  const jobId=importResult.job_id||importResult.active_job_id;
  if(!jobId)throw new Error(importResult.message||'Недостающие данные найдены, но подкачивание с Bitget не запустилось.');
  const waitingForAnotherJob=!importResult.job_id&&Boolean(importResult.active_job_id);
  if(waitingForAnotherJob){
    const activeResponse=await fetch('import-candles.php?active=1',{headers:{Accept:'application/json'},cache:'no-store'});
    const active=await activeResponse.json();
    const activeRange=active.request_start_day&&active.request_end_day?` за ${active.request_start_day} — ${active.request_end_day}`:'';
    pairRankingStatus.textContent=`Ошибка полноты данных: рейтинг остановлен. Сейчас выполняется другая подкачка${activeRange}; ожидаю её завершения, затем запущу загрузку выбранного периода.`;
  }
  while(requestId===pairRankingRequest){
    await waitForHistoryImport(1200);
    const statusResponse=await fetch('import-candles.php?job_id='+encodeURIComponent(jobId),{headers:{Accept:'application/json'},cache:'no-store'});
    const importStatus=await statusResponse.json();
    if(!statusResponse.ok||importStatus.status==='error')throw new Error(importStatus.message||'Подкачивание истории с Bitget завершилось ошибкой.');
    const progress=Number(importStatus.progress||0).toLocaleString('ru-RU',{maximumFractionDigits:1});
    const timing=rankingImportTiming(importStatus);
    pairRankingStatus.textContent=`Ошибка полноты данных: рейтинг остановлен. Подкачивание с Bitget: ${importStatus.message||'выполняется'} · ${progress}%${timing?' · '+timing:''}`;
    if(importStatus.status==='cancelled')throw new Error('Подкачивание недостающих данных остановлено. Рейтинг не запущен.');
    if(importStatus.status!=='ready'&&importStatus.status!=='partial')continue;
    if(waitingForAnotherJob){
      pairRankingStatus.textContent='Предыдущее задание завершено. Повторно проверяю данные и запускаю подкачивание выбранного периода…';
      return requireCompleteRankingHistory(params,requestId);
    }
    pairRankingStatus.classList.toggle('error',importStatus.status==='partial');
    pairRankingStatus.textContent=importStatus.status==='partial'
      ? 'Подкачивание завершилось частично. Для некоторых тикеров данные по-прежнему отсутствуют; рейтинг не запущен.'
      : 'Недостающие данные подкачаны с Bitget. Нажмите ↻ ещё раз для расчёта рейтинга.';
    return false;
  }
  return false;
}
async function loadPairRanking(selectLeader=false){
  if(!longSymbolSelect.value||!shortSymbolSelect.value){pairRankingRequest++;setHedgeRankingLoading(false);pairRankingStatus.textContent='Для сортировки всех пар выберите тикер и для лонга, и для шорта.';return;}
  if(!pairSelect.value){setChartLoading(false);pairRankingStatus.textContent='Для расчёта пары выберите список минимум с двумя активными тикерами.';if(selectLeader)statusNode.textContent='Для хеджирования нужны минимум два активных тикера.';return}
  const selectionVersion=pairSelectionVersion;
  setHedgeRankingLoading(true);
  const requestId=++pairRankingRequest,params=new URLSearchParams(new FormData(form));params.delete('pair');
  const url=new URL('hedge-ranking.php',location.href);url.search=params.toString();
  let lastRankingProgress=Date.now(),lastRankingMessage='Проверяю кэш рейтинга пар…';
  let progressTimer=0;
  if(selectLeader){summaryNode.hidden=true;localMovesPanel.hidden=true;chart.replaceChildren();pointCount.textContent='';setChartLoading(true);statusNode.classList.remove('error');statusNode.textContent='Сначала рассчитываю коэффициенты всех пар; затем загружу график лучшей пары…'}
  pairRankingStatus.textContent='Проверяю кэш рейтинга пар…';
  try{
    pairRankingStatus.classList.remove('error');
    if(!await requireCompleteRankingHistory(params,requestId))return;
    progressTimer=setInterval(()=>{if(requestId!==pairRankingRequest)return;const waitingSeconds=Math.floor((Date.now()-lastRankingProgress)/1000);if(waitingSeconds>=10)pairRankingStatus.textContent=lastRankingMessage+' — жду следующий ответ сервера: '+waitingSeconds+' сек.'},5000);
    const response=await fetch(url,{headers:{Accept:'application/x-ndjson, application/json'},cache:'no-store'});
    let data=null;
    if(response.headers.get('content-type')?.includes('application/x-ndjson')){
      const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='';
      const consume=chunk=>{buffer+=chunk;const lines=buffer.split('\n');buffer=lines.pop();for(const line of lines){if(!line.trim())continue;const event=JSON.parse(line);if(event.type==='progress'){lastRankingProgress=Date.now();lastRankingMessage=event.message;pairRankingStatus.textContent=event.message;updateHedgeRankingProgress(event.message)}if(event.type==='error')throw new Error(event.message);if(event.type==='result')data=event.data}};
      while(true){const{value,done}=await reader.read();if(done)break;consume(decoder.decode(value,{stream:true}))}
      consume(decoder.decode());if(buffer.trim()){const event=JSON.parse(buffer);if(event.type==='error')throw new Error(event.message);if(event.type==='result')data=event.data}
    }else{data=await response.json()}
    if(requestId!==pairRankingRequest)return;
    if(!response.ok||data?.status!=='ready')throw new Error(data?.message||'Не удалось рассчитать доходность пар');
    try{sessionStorage.setItem(hedgeRankingSessionKey(),'1')}catch{}
    const availableTickerSymbols=new Set([...longSymbolSelect.options].map(option=>option.value).filter(Boolean));
    const leader=data.pairs?.find(pair=>pair.maximum_interval_ratio!==null&&availableTickerSymbols.has(pair.long_symbol)&&availableTickerSymbols.has(pair.short_symbol));
    rankedPairData={symbols:data.symbols,pair_matrix:data.pair_matrix,pair_ratio_matrix:data.pair_ratio_matrix,pair_maximum_ratio_matrix:data.pair_maximum_ratio_matrix,starting_balance:data.starting_balance};
    renderCurrentTickerRankings();
    if(leader&&selectLeader&&selectionVersion===pairSelectionVersion){longSymbolSelect.value=leader.long_symbol;shortSymbolSelect.value=leader.short_symbol;syncManualPairSelection();renderCurrentTickerRankings();await loadData()}
    else if(!leader&&selectLeader&&selectionVersion===pairSelectionVersion){setChartLoading(false);statusNode.textContent='Нет пар с ненулевым максимальным снижением для расчёта коэффициента.'}
    const pairCount=data.ordered_pair_count??0;
    const intervalLabel={week:'недельному',two_weeks:'двухнедельному',month:'месячному'}[data.interval_mode];
    const excludedCount=Number(data.excluded_interval_count||0),rankingBasis=intervalLabel?`сортировка по максимальному коэффициенту «Максимум / снижение» среди полных интервалов (${intervalLabel}${excludedCount?`, исключены последние ${excludedCount}`:''})`:'сортировка по коэффициенту за весь выбранный период';
    const rankingEndDay=excludedCount&&data.ranking_end_day?data.ranking_end_day:data.end_day;pairRankingStatus.textContent=`${data.cache_hit?'Загружен сохранённый рейтинг':'Рассчитана доходность'} ${pairCount.toLocaleString('ru-RU')} пар за ${data.start_day} — ${rankingEndDay}; ${rankingBasis}. Лидер: ${leader?`лонг ${leader.long_symbol} / шорт ${leader.short_symbol}`:'не найден'}.`;
  }catch(error){if(requestId===pairRankingRequest){setChartLoading(false);pairRankingStatus.classList.add('error');pairRankingStatus.textContent='Не удалось обновить доходность пар: '+error.message;if(selectLeader&&selectionVersion===pairSelectionVersion)await loadData()}}finally{clearInterval(progressTimer);if(requestId===pairRankingRequest)setHedgeRankingLoading(false)}
}
function el(tag,attrs={},text=''){const node=document.createElementNS(ns,tag);for(const[key,value]of Object.entries(attrs))node.setAttribute(key,value);if(text)node.textContent=text;chart.append(node);return node}
function renderIntervalResults(data){
  const intervalPanel=document.querySelector('#hedge-interval-panel'),intervalRows=document.querySelector('#hedge-interval-rows'),intervalSummary=document.querySelector('#hedge-interval-summary');
  const fmtIntervalDate=ms=>new Date(Number(ms)).toLocaleDateString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',year:'numeric'});
  const intervalStartingBalance=Number(data.starting_balance);
  const intervalMoney=value=>{const amount=Number(value),percent=intervalStartingBalance>0?amount/intervalStartingBalance*100:0;return `${amount.toLocaleString('ru-RU',{maximumFractionDigits:4})} USDT (${signed(percent)}%)`};
  intervalRows.replaceChildren();
  (data.interval_rows||[]).forEach(row=>{const tr=document.createElement('tr');const values=[`${fmtIntervalDate(row.start_ms)} — ${fmtIntervalDate(Number(row.end_ms)-1)}`,row.long_pnl,row.short_pnl,row.net_pnl,row.maximum_net_pnl,row.maximum_drawdown,row.peak_drawdown_ratio];values.forEach((value,index)=>{const td=document.createElement('td');td.textContent=index===0?value:(value===null?'—':index===6?Number(value).toLocaleString('ru-RU',{maximumFractionDigits:4}):intervalMoney(value));tr.append(td)});intervalRows.append(tr)});
  intervalSummary.replaceChildren();
  const worstInterval=(data.interval_rows||[]).filter(row=>Number.isFinite(Number(row.net_pnl))).reduce((worst,row)=>worst===null||Number(row.net_pnl)<Number(worst.net_pnl)?row:worst,null);
  if(worstInterval){
    const period=`${fmtIntervalDate(worstInterval.start_ms)} — ${fmtIntervalDate(Number(worstInterval.end_ms)-1)}`;
    const addIntervalStat=(label,value,detail,valueClass='')=>{const card=document.createElement('div');card.className='stat';const title=document.createElement('span');title.textContent=label;const strong=document.createElement('strong');strong.className=valueClass;strong.textContent=value;card.append(title,strong);if(detail){const note=document.createElement('span');note.textContent=detail;card.append(note)}intervalSummary.append(card)};
    addIntervalStat('Минимальный общий итог',money(worstInterval.net_pnl),period);
    addIntervalStat('Максимальное снижение этого интервала',`${fmt(worstInterval.maximum_drawdown,4)} USDT`,period);
    const ratioIntervals=(data.interval_rows||[]).filter(row=>row.peak_drawdown_ratio!==null&&row.peak_drawdown_ratio!==''&&Number.isFinite(Number(row.peak_drawdown_ratio)));
    const maximumRatioInterval=ratioIntervals.reduce((best,row)=>best===null||Number(row.peak_drawdown_ratio)>Number(best.peak_drawdown_ratio)?row:best,null);
    const minimumRatioInterval=ratioIntervals.reduce((worst,row)=>worst===null||Number(row.peak_drawdown_ratio)<Number(worst.peak_drawdown_ratio)?row:worst,null);
    if(maximumRatioInterval)addIntervalStat('Максимальный максимум / снижение',fmt(maximumRatioInterval.peak_drawdown_ratio,4),`${fmtIntervalDate(maximumRatioInterval.start_ms)} — ${fmtIntervalDate(Number(maximumRatioInterval.end_ms)-1)}`,'gain');
    if(minimumRatioInterval)addIntervalStat('Минимальный максимум / снижение',fmt(minimumRatioInterval.peak_drawdown_ratio,4),`${fmtIntervalDate(minimumRatioInterval.start_ms)} — ${fmtIntervalDate(Number(minimumRatioInterval.end_ms)-1)}`,'loss');
  }
  intervalPanel.hidden=!(data.interval_rows||[]).length;
}
function render(data){
  setChartLoading(false);
  window.currentHedgeData=data;
  renderIntervalResults(data);
  chart.replaceChildren();
  const netView=chartView==='net';
  const series=netView
    ? [{key:'net_pnl',label:'Чистая доходность',color:'#86a8ff'}]
    : [{key:'long_index',label:'Лонг: доходность',color:palette.long_index},{key:'short_index',label:'Шорт: доходность',color:palette.short_index},{key:'equity_index',label:'Общий баланс',color:palette.equity_index}].filter(item=>item.key==='equity_index'||(item.key==='long_index'?data.long_symbol:data.short_symbol));
  const single=!data.long_symbol||!data.short_symbol;
  chartTitle.textContent=single?(netView?'Чистая доходность':'Доходность')+' · '+(data.long_symbol?'Лонг '+data.long_symbol:'Шорт '+data.short_symbol):(netView?'Чистая доходность по хеджированию':'Хеджирование между тикерами');
  chart.setAttribute('aria-label',netView?'График чистой доходности в USDT во времени':'График доходности лонга, шорта и общего баланса');
  chartModeButtons.forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.chartView===(netView?'net':'ticker'))));
  chartLegend.innerHTML=series.map(item=>`<span><i class="dot" style="background:${item.color}"></i>${item.label}</span>`).join('')+(netView?'<span><i class="dot" style="background:#86a8ff"></i>Максимум за период</span><span><i class="dot" style="background:#ff7188"></i>Снижение после максимума</span><span><i class="dot" style="background:#f2d77d"></i>Максимальное снижение на графике</span><span><i class="dot" style="background:#7bdff2"></i>Локальные максимумы</span><span><i class="dot" style="background:#f3a6b3"></i>Следующие локальные минимумы</span>':'');
  chartNote.textContent=netView
    ? 'По вертикали — чистый результат в USDT с учётом комиссий открытия и расчётного закрытия. Синяя точка — максимум периода, золотая линия — максимальное снижение между пиком и последующим минимумом; цветные точки отмечают локальные падения. Позиции открываются на первом синхронизированном снимке.'
    : 'Индексы начинаются около 100. Общий баланс включает комиссии открытия и предполагаемого закрытия на последнем синхронизированном снимке.';
  const points=data.points||[];
  const summary=data.summary;
  if(points.length<2){summaryNode.hidden=true;localMovesPanel.hidden=true;statusNode.textContent=data.message||'Недостаточно синхронизированных снимков для графика.';pointCount.textContent='';return}
  const rangeDuration=Math.max(0,Number(points.at(-1).t)-Number(points[0].t));
  const scaleThreshold=localMovesScale.value==='large'?Math.max(2*60*60*1000,rangeDuration/30):localMovesScale.value==='medium'?Math.max(30*60*1000,rangeDuration/100):0;
  const scaleLabel=localMovesScale.value==='large'?'Большая':localMovesScale.value==='medium'?'Средняя':'Минимальная';
  const analysisPoints=scaleThreshold?samplePointsAtInterval(points,scaleThreshold):points.map((point,sourceIndex)=>({...point,sourceIndex}));
  const localMoves=netView?findLocalDeclines(analysisPoints).filter(move=>move.minimumTime-move.peakTime>=scaleThreshold).sort((a,b)=>b.decline-a.decline||a.peakTime-b.peakTime).slice(0,10):[];
  const localMoveByPeak=new Map(localMoves.map(move=>[move.peakIndex,move]));
  const localMoveByMinimum=new Map(localMoves.map(move=>[move.minimumIndex,move]));
  const averageDecline=localMoves.length?localMoves.reduce((total,move)=>total+move.decline,0)/localMoves.length:null;
  localMovesPanel.hidden=!netView;
  localMovesSummary.textContent=netView?`${localMoves.length} падений · среднее ${averageDecline===null?'—':fmt(averageDecline,4)+' USDT ('+fmt(averageDecline/Number(data.starting_balance)*100,2)+'%)'}`:'';
  localMovesThreshold.textContent=netView?`Режим «${scaleLabel}»: шаг поиска крупных поворотов ${scaleThreshold?durationLabel(scaleThreshold):'без ограничения'}. В топ‑10 сначала показаны самые большие падения перед последующим ростом; краткие колебания не попадают в список. Интервал зависит от длины диапазона.`:'';
  localMovesRows.innerHTML=localMoves.length?localMoves.map((move,index)=>`<tr><td>${index+1}</td><td>${new Date(move.peakTime).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})}</td><td>${money(move.peakValue)}</td><td>${new Date(move.minimumTime).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})}</td><td>${money(move.minimumValue)}</td><td>${fmt(move.decline,4)} USDT · ${fmt(move.decline/(Number(data.starting_balance)+move.peakValue)*100,2)}%</td><td>${durationLabel(move.minimumTime-move.peakTime)}</td></tr>`).join(''):'<tr><td colspan="7">Для этого интервала локальных падений не найдено.</td></tr>';
  const width=Math.max(700,chart.clientWidth||1000),height=Math.max(260,chart.clientHeight||420),pad={l:96,r:28,t:24,b:44};
  chart.setAttribute('viewBox',`0 0 ${width} ${height}`);
  chart.setAttribute('preserveAspectRatio','none');
  const times=points.map(point=>Number(point.t));
  const values=points.flatMap(point=>series.map(item=>Number(point[item.key])));
  if(netView){values.push(Number(data.summary.maximum_net_pnl),Number(data.summary.minimum_after_maximum_net_pnl),Number(data.summary.maximum_drawdown_peak_net_pnl),Number(data.summary.maximum_drawdown_trough_net_pnl));}
  const xmin=Math.min(...times),xmax=Math.max(...times),ymin=Math.min(...values),ymax=Math.max(...values);
  const range=netView?Math.max(Math.abs(ymax-ymin),Math.abs(ymin),Math.abs(ymax),0.01):Math.max(1,ymax-ymin);
  const low=netView?Math.min(0,ymin-range*.12):ymin-range*.12;
  const high=netView?Math.max(0,ymax+range*.12):ymax+range*.12;
  const X=time=>pad.l+(time-xmin)/Math.max(1,xmax-xmin)*(width-pad.l-pad.r);
  const Y=value=>pad.t+(high-value)/(high-low)*(height-pad.t-pad.b);
  const add=(tag,attrs={},text='')=>{const node=document.createElementNS(ns,tag);for(const[key,value]of Object.entries(attrs))node.setAttribute(key,value);if(text)node.textContent=text;chart.append(node);return node};
  for(let i=0;i<5;i++){
    const value=low+(high-low)*i/4,y=Y(value);
    add('line',{x1:pad.l,y1:y,x2:width-pad.r,y2:y,stroke:'#273149'});
    add('text',{x:pad.l-10,y:y+4,'text-anchor':'end',fill:'#8995ad','font-size':12},netView?signed(value,2)+' USDT':fmt(value,2));
  }
  if(netView){const zero=Y(0);add('line',{x1:pad.l,y1:zero,x2:width-pad.r,y2:zero,stroke:'#60708e','stroke-dasharray':'3 4'});}
  for(let i=0;i<5;i++){
    const time=xmin+(xmax-xmin)*i/4,x=X(time);
    add('line',{x1:x,y1:pad.t,x2:x,y2:height-pad.b,stroke:'#202a40'});
    add('text',{x,y:height-12,'text-anchor':'middle',fill:'#8995ad','font-size':11},new Date(time).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}));
  }
  for(const item of series){
    const path=points.map((point,index)=>`${index?'L':'M'}${X(Number(point.t)).toFixed(2)},${Y(Number(point[item.key])).toFixed(2)}`).join(' ');
    add('path',{d:path,fill:'none',stroke:item.color,'stroke-width':item.key==='equity_index'||netView?3:2,'stroke-linejoin':'round','stroke-linecap':'round'});
  }
  if(netView&&localMoves.length){
    const peakPath=[],minimumPath=[],declinePath=[];
    for(const move of localMoves){
      const peakX=X(move.peakTime),peakY=Y(move.peakValue),minimumX=X(move.minimumTime),minimumY=Y(move.minimumValue);
      peakPath.push(`M${(peakX-2.5).toFixed(1)},${peakY.toFixed(1)}a2.5,2.5 0 1,0 5,0a2.5,2.5 0 1,0 -5,0`);
      minimumPath.push(`M${(minimumX-2.5).toFixed(1)},${minimumY.toFixed(1)}a2.5,2.5 0 1,0 5,0a2.5,2.5 0 1,0 -5,0`);
      declinePath.push(`M${peakX.toFixed(1)},${peakY.toFixed(1)}L${minimumX.toFixed(1)},${minimumY.toFixed(1)}`);
    }
    add('path',{d:declinePath.join(' '),fill:'none',stroke:'#f3a6b3','stroke-width':1,'stroke-dasharray':'2 4',opacity:.45});
    add('path',{d:peakPath.join(' '),fill:'#7bdff2',stroke:'#0b1020','stroke-width':.7,opacity:.9});
    add('path',{d:minimumPath.join(' '),fill:'#f3a6b3',stroke:'#0b1020','stroke-width':.7,opacity:.9});
  }
  if(netView){
    const drawdownPeakX=X(Number(summary.maximum_drawdown_peak_time_ms)),drawdownPeakY=Y(Number(summary.maximum_drawdown_peak_net_pnl));
    const drawdownTroughX=X(Number(summary.maximum_drawdown_trough_time_ms)),drawdownTroughY=Y(Number(summary.maximum_drawdown_trough_net_pnl));
    const peakX=X(Number(summary.maximum_time_ms)),peakY=Y(Number(summary.maximum_net_pnl));
    const lowX=X(Number(summary.minimum_after_maximum_time_ms)),lowY=Y(Number(summary.minimum_after_maximum_net_pnl));
    add('line',{x1:peakX,y1:peakY,x2:lowX,y2:lowY,stroke:'#ff7188','stroke-width':2,'stroke-dasharray':'5 4'});
    const peakMarker=add('circle',{cx:peakX,cy:peakY,r:6,fill:'#86a8ff',stroke:'#0b1020','stroke-width':2});
    const peakTitle=document.createElementNS(ns,'title');
    peakTitle.textContent=`Максимум ${money(summary.maximum_net_pnl)} · ${new Date(summary.maximum_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · Киев`;
    peakMarker.append(peakTitle);
    add('circle',{cx:lowX,cy:lowY,r:5,fill:'#ff7188',stroke:'#0b1020','stroke-width':2});
    const labelX=peakX>width-pad.r-170?peakX-10:peakX+10;
    add('text',{x:labelX,y:Math.max(pad.t+14,peakY-12),'text-anchor':labelX<peakX?'end':'start',fill:'#c7d5ff','font-size':12,'font-weight':700},'Максимум '+money(summary.maximum_net_pnl));
    add('line',{x1:drawdownPeakX,y1:drawdownPeakY,x2:drawdownTroughX,y2:drawdownTroughY,stroke:'#f2d77d','stroke-width':4,'stroke-dasharray':'8 5'});
    const diamondPath=(x,y)=>`M${x.toFixed(1)},${(y-9).toFixed(1)}L${(x+9).toFixed(1)},${y.toFixed(1)}L${x.toFixed(1)},${(y+9).toFixed(1)}L${(x-9).toFixed(1)},${y.toFixed(1)}Z`;
    const drawdownPeakMarker=add('path',{d:diamondPath(drawdownPeakX,drawdownPeakY),fill:'none',stroke:'#f2d77d','stroke-width':3});
    const drawdownPeakTitle=document.createElementNS(ns,'title');
    drawdownPeakTitle.textContent=`Пик максимального снижения ${money(summary.maximum_drawdown_peak_net_pnl)} · ${new Date(summary.maximum_drawdown_peak_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · Киев`;
    drawdownPeakMarker.append(drawdownPeakTitle);
    const drawdownTroughMarker=add('path',{d:diamondPath(drawdownTroughX,drawdownTroughY),fill:'none',stroke:'#f2d77d','stroke-width':3});
    const drawdownTroughTitle=document.createElementNS(ns,'title');
    drawdownTroughTitle.textContent=`Минимум максимального снижения ${money(summary.maximum_drawdown_trough_net_pnl)} · ${new Date(summary.maximum_drawdown_trough_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · Киев`;
    drawdownTroughMarker.append(drawdownTroughTitle);
  }
  const cursor=add('line',{y1:pad.t,y2:height-pad.b,stroke:'#d5defa','stroke-dasharray':'4 5',visibility:'hidden'});
  const horizontalCursor=add('line',{x1:pad.l,x2:width-pad.r,stroke:'#d5defa','stroke-dasharray':'4 5',visibility:'hidden'});
  const cursorValueBackground=add('rect',{x:2,y:pad.t-10,width:pad.l-6,height:20,rx:4,fill:'#293657',stroke:'#d5defa','stroke-width':.7,visibility:'hidden'});
  const cursorValueLabel=add('text',{x:pad.l-9,y:pad.t+4,'text-anchor':'end',fill:'#fff','font-size':12,'font-weight':700,visibility:'hidden'});
  const cursorDateBackground=add('rect',{x:pad.l,y:height-30,width:132,height:20,rx:4,fill:'#293657',stroke:'#d5defa','stroke-width':.7,visibility:'hidden'});
  const cursorDateLabel=add('text',{x:pad.l+66,y:height-16,'text-anchor':'middle',fill:'#fff','font-size':11,'font-weight':700,visibility:'hidden'});
  const hit=add('rect',{x:pad.l,y:pad.t,width:width-pad.l-pad.r,height:height-pad.t-pad.b,fill:'transparent'});
  hit.addEventListener('pointermove',event=>{
    const rect=chart.getBoundingClientRect(),ratio=(event.clientX-rect.left)/rect.width;
    const chartX=ratio*width,plotRatio=Math.max(0,Math.min(1,(chartX-pad.l)/(width-pad.l-pad.r)));
    const chartY=(event.clientY-rect.top)/rect.height*height;
    const y=Math.max(pad.t,Math.min(height-pad.b,chartY));
    const targetTime=xmin+plotRatio*(xmax-xmin);
    let lowIndex=0,highIndex=points.length-1;
    while(lowIndex<highIndex){const middle=Math.floor((lowIndex+highIndex)/2);if(Number(points[middle].t)<targetTime)lowIndex=middle+1;else highIndex=middle;}
    if(lowIndex>0&&Math.abs(Number(points[lowIndex-1].t)-targetTime)<Math.abs(Number(points[lowIndex].t)-targetTime))lowIndex--;
    const point=points[lowIndex],x=X(Number(point.t));
    cursor.setAttribute('x1',x);cursor.setAttribute('x2',x);cursor.setAttribute('visibility','visible');
    horizontalCursor.setAttribute('y1',y);horizontalCursor.setAttribute('y2',y);horizontalCursor.setAttribute('visibility','visible');
    const valueAtPointer=high-(y-pad.t)/(height-pad.t-pad.b)*(high-low),valueLabelY=Math.max(pad.t+10,Math.min(height-pad.b-2,y+4));
    cursorValueBackground.setAttribute('y',Math.max(pad.t,valueLabelY-14));
    cursorValueLabel.setAttribute('y',valueLabelY);cursorValueLabel.textContent=netView?signed(valueAtPointer,2)+' USDT':fmt(valueAtPointer,2);
    cursorValueBackground.setAttribute('visibility','visible');cursorValueLabel.setAttribute('visibility','visible');
    const dateLabelX=Math.max(pad.l+66,Math.min(width-pad.r-66,x));
    cursorDateBackground.setAttribute('x',dateLabelX-66);cursorDateLabel.setAttribute('x',dateLabelX);
    cursorDateLabel.textContent=new Date(point.t).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
    cursorDateBackground.setAttribute('visibility','visible');cursorDateLabel.setAttribute('visibility','visible');
    const localPeak=localMoveByPeak.get(lowIndex),localMinimum=localMoveByMinimum.get(lowIndex);
    const localDetails=localPeak?`<br>Локальный максимум → следующий минимум: ${money(localPeak.peakValue)} → ${money(localPeak.minimumValue)}<br>Падение: ${money(localPeak.decline)} · ${durationLabel(localPeak.minimumTime-localPeak.peakTime)}`:localMinimum?`<br>Локальный минимум после ${money(localMinimum.peakValue)}<br>Падение от максимума: ${money(localMinimum.decline)} · ${durationLabel(localMinimum.minimumTime-localMinimum.peakTime)}`:'';
    tooltip.innerHTML=netView
      ? `<strong>${new Date(point.t).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · Киев</strong><br>Чистый итог: ${money(point.net_pnl)}${localDetails}`
      : `<strong>${new Date(point.t).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · Киев</strong><br>Лонг: ${fmt(point.long_index,3)}<br>Шорт: ${fmt(point.short_index,3)}<br>Баланс: ${fmt(point.equity_index,3)}<br>Итог: ${money(point.net_pnl)}`;
    if(single&&!netView)tooltip.innerHTML=`<strong>${new Date(point.t).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · Киев</strong><br>${data.long_symbol?'Лонг':'Шорт'}: ${fmt(data.long_symbol?point.long_index:point.short_index,3)}<br>Баланс: ${fmt(point.equity_index,3)}<br>Итог: ${money(point.net_pnl)}`;
    tooltip.style.display='block';
    tooltip.style.left=`${Math.min(event.clientX-rect.left+12,rect.width-tooltip.offsetWidth-4)}px`;
    tooltip.style.top=`${Math.max(2,event.clientY-rect.top-tooltip.offsetHeight-10)}px`;
  });
  hit.addEventListener('pointerleave',()=>{cursor.setAttribute('visibility','hidden');horizontalCursor.setAttribute('visibility','hidden');cursorValueBackground.setAttribute('visibility','hidden');cursorValueLabel.setAttribute('visibility','hidden');cursorDateBackground.setAttribute('visibility','hidden');cursorDateLabel.setAttribute('visibility','hidden');tooltip.style.display='none'});
  const stat=(label,value,cls='')=>`<div class="stat"><span>${label}</span><strong class="${cls}">${value}</strong></div>`;
  const startingBalance=Number(data.starting_balance);
  const percentFromStart=value=>{const percent=Number(value)/startingBalance*100;return `${percent>0?'+':''}${fmt(percent,2)}% от стартового баланса`};
  const displayPeakDeclinePercent=value=>value===null?'—':`${fmt(value,2)}%`;
  const statReturn=(label,value,cls='')=>`<div class="stat"><span>${label}</span><strong class="${cls}">${money(value)}</strong><small>${percentFromStart(value)}</small></div>`;
  const declineClass=Number(summary.decline_after_maximum)>0?'loss':'gain';
  const maxTime=new Date(summary.maximum_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const lowTime=new Date(summary.minimum_after_maximum_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const declineDuration=durationLabel(Number(summary.minimum_after_maximum_time_ms)-Number(summary.maximum_time_ms));
  const drawdownClass=Number(summary.maximum_drawdown)>0?'loss':'gain';
  const drawdownPeakTime=new Date(summary.maximum_drawdown_peak_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const drawdownTroughTime=new Date(summary.maximum_drawdown_trough_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const drawdownDuration=durationLabel(Number(summary.maximum_drawdown_trough_time_ms)-Number(summary.maximum_drawdown_peak_time_ms));
  summaryNode.innerHTML=stat('Стартовый баланс',fmt(startingBalance,2)+' USDT')+(data.long_symbol?statReturn('Лонг '+data.long_symbol,summary.long_pnl,Number(summary.long_pnl)>=0?'gain':'loss'):'')+(data.short_symbol?statReturn('Шорт '+data.short_symbol,summary.short_pnl,Number(summary.short_pnl)>=0?'gain':'loss'):'')+statReturn('Общий итог',summary.net_pnl,Number(summary.net_pnl)>=0?'gain':'loss')+`<div class="stat"><span>Максимум за период</span><strong>${money(summary.maximum_net_pnl)}</strong><small>${percentFromStart(summary.maximum_net_pnl)} · ${maxTime} · Киев</small></div>`+`<div class="stat"><span>Снижение после максимума</span><strong class="${declineClass}">${fmt(summary.decline_after_maximum,4)} USDT · ${displayPeakDeclinePercent(summary.decline_after_maximum_percent)}</strong><small>Длительность: ${declineDuration}<br>Минимум: ${money(summary.minimum_after_maximum_net_pnl)} · ${percentFromStart(summary.minimum_after_maximum_net_pnl)} · ${lowTime}</small></div>`+`<div class="stat"><span>Максимальное снижение на графике</span><strong class="${drawdownClass}">${fmt(summary.maximum_drawdown,4)} USDT · ${displayPeakDeclinePercent(summary.maximum_drawdown_percent)}</strong><small>От ${money(summary.maximum_drawdown_peak_net_pnl)} · ${drawdownPeakTime}<br>До ${money(summary.maximum_drawdown_trough_net_pnl)} · ${drawdownTroughTime}<br>Длительность: ${drawdownDuration}</small></div>`+`<div class="stat"><span>Баланс после закрытия</span><strong>${fmt(summary.ending_balance,4)} USDT</strong><small>Доходность: ${percentFromStart(summary.net_pnl)}</small></div>`+`<div class="stat"><span>Максимум / максимальное снижение</span><strong style="color:#c4a1ff">${Number(summary.maximum_drawdown)>0?fmt(Number(summary.maximum_net_pnl)/Number(summary.maximum_drawdown),2):'—'}</strong><small>${money(summary.maximum_net_pnl)} / ${fmt(summary.maximum_drawdown,4)} USDT${Number(summary.maximum_drawdown)>0?'':' · снижения не было'}<br>Чем выше, тем лучше</small></div>`;
  summaryNode.hidden=false;
  statusNode.textContent=`Вход ${new Date(summary.entry_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · ${single?'последний снимок':'последний общий снимок'} ${new Date(summary.last_aligned_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · комиссия входа ${fmt(summary.entry_fees,4)} USDT`;
  const interval=value=>Number(value).toLocaleString('ru-RU',{maximumFractionDigits:2});
  pointCount.textContent='Снимки для расчёта: '+['long','short'].filter(side=>data[side+'_symbol']).map(side=>`${data[side+'_symbol']} — ${data['sampled_'+side+'_quotes']} из ${data[side+'_quotes']} (интервал ${interval(data[side+'_sampling_interval'])})`).join(', ');
  if(single)chartNote.textContent='Одна позиция на весь стартовый баланс. Учтены цены Bid/Ask и комиссии открытия и расчётного закрытия; максимумы и снижения рассчитаны по этой позиции.';
}
async function loadData(){const requestId=++hedgeDataRequest;setAutoTradingLinksReady(false);if(!longSymbolSelect.value&&!shortSymbolSelect.value){setChartLoading(false);summaryNode.hidden=true;localMovesPanel.hidden=true;window.currentHedgeData=null;chart.replaceChildren();pointCount.textContent='';statusNode.textContent='Выберите хотя бы один тикер: лонг или шорт.';return}const [longSymbol,shortSymbol]=pairSelect.value.split('|');updateAutoTradingLinks();const params=new URLSearchParams(new FormData(form));params.delete('pair');params.set('long_symbol',longSymbol);params.set('short_symbol',shortSymbol);const url=new URL('hedge-data.php',location.href);url.search=params.toString();statusNode.textContent='Загружаю сохранённый результат пары или котировки…';statusNode.classList.remove('error');chart.replaceChildren();setChartLoading(true);try{const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();if(requestId!==hedgeDataRequest)return;if(!response.ok||data.status==='error')throw new Error(data.message||'Не удалось загрузить данные пары');if(data.starting_balance!==undefined&&data.minimum_starting_balance!==undefined){const requested=Number(data.requested_starting_balance??params.get('balance')??0),minimum=Number(data.minimum_starting_balance),adjusted=Number(data.starting_balance),note=document.querySelector('#minimum-balance-note'),requirements=data.minimum_order_requirements||{};form.elements.balance.value=adjusted.toFixed(2);params.set('balance',adjusted.toFixed(2));if(note){const long= requirements.long||{},short=requirements.short||{};const pairMinimum=[[longSymbol,long],[shortSymbol,short]].filter(([symbol])=>symbol).map(([symbol,rule])=>`${symbol}: ${Number(rule.minimum_balance_usdt||0).toFixed(2)}`).join(' + ')+' USDT';note.textContent=adjusted<requested?`Стартовый баланс уменьшен с ${requested.toFixed(2)} до ${adjusted.toFixed(2)} USDT для выбранной пары. Минимумы с комиссией Bitget: ${pairMinimum}.`:adjusted>requested?`Стартовый баланс увеличен с ${requested.toFixed(2)} до ${adjusted.toFixed(2)} USDT для выбранной пары. Минимумы с комиссией Bitget: ${pairMinimum}.`:`Минимум для выбранной пары: ${pairMinimum} (всего ${minimum.toFixed(2)} USDT с комиссией Bitget).`;}updateAutoTradingLinks();setAutoTradingLinksReady(Boolean(longSymbol&&shortSymbol))}if(data.status!=='ready'){render(data);return}saveHedgeIntervalResult(data,longSymbol,shortSymbol);render(data);if(data.cache_hit)statusNode.textContent='Загружен сохранённый расчёт пары. '+statusNode.textContent;const next=new URL(location.href);for(const[key,value]of params)next.searchParams.set(key,value);next.searchParams.set('pair',pairSelect.value);next.searchParams.set('chart_view',chartView);history.replaceState(null,'',next);const basicParams=new URLSearchParams(location.search);for(const key of ['long_symbol','short_symbol','pair','chart_view'])basicParams.delete(key);for(const key of ['start_day','end_day','balance','balance_auto','fee','interval'])basicParams.set(key,params.get(key));document.querySelector('.view-nav a[href^="basic-checks.php?"]').href='basic-checks.php?'+basicParams.toString()}catch(error){if(requestId!==hedgeDataRequest)return;setChartLoading(false);summaryNode.hidden=true;statusNode.textContent=error.message;statusNode.classList.add('error')}}
function setChartView(nextView){chartView=nextView;chartModeButtons.forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.chartView===chartView)));const next=new URL(location.href);next.searchParams.set('chart_view',chartView);history.replaceState(null,'',next);if(window.currentHedgeData)render(window.currentHedgeData)}
chartModeButtons.forEach(button=>button.addEventListener('click',()=>setChartView(button.dataset.chartView)));
localMovesScale.addEventListener('change',()=>{if(window.currentHedgeData)render(window.currentHedgeData)});
form.addEventListener('submit',event=>{event.preventDefault();scheduleSelectedHedgeCalculation()});
document.querySelector('#rank-hedge-pairs')?.addEventListener('click',()=>loadPairRanking(true));
form.addEventListener('input',event=>{if(event.target.name==='exclude_last_intervals')return;if(['start_day','end_day','balance','fee','interval'].includes(event.target.name))clearHedgeSessionResults();if(event.target.name==='balance')form.elements.balance_auto.value='0';updateAutoTradingLinks();markHedgeCalculationPending(event.target!==longSymbolSelect&&event.target!==shortSymbolSelect);scheduleSelectedHedgeCalculation()});
form.addEventListener('change',event=>{
  if(['start_day','end_day','balance','fee','interval'].includes(event.target.name))clearHedgeSessionResults();
  if(event.target.name==='start_day')startDayAutoInput.value='0';
  updateAutoTradingLinks();
  syncHedgeUrlFromForm();
  if(event.target.name==='exclude_last_intervals'){if(longSymbolSelect.value&&shortSymbolSelect.value)void loadPairRanking(false);return}
  if(['start_day','end_day','interval'].includes(event.target.name))void refreshTickerCoverage();
  markHedgeCalculationPending(event.target!==longSymbolSelect&&event.target!==shortSymbolSelect);
  scheduleSelectedHedgeCalculation();
});
window.addEventListener('resize',()=>{if(window.currentHedgeData)render(window.currentHedgeData)});
syncManualPairSelection();
reconcileHedgeSession();
const restoreRankingOnLoad=(()=>{try{return sessionStorage.getItem(hedgeRankingSessionKey())==='1'}catch{return false}})();
void refreshTickerCoverage().finally(()=>{restoreHedgeSelectorLabels();restoreHedgeIntervalResult();if(restoreRankingOnLoad&&longSymbolSelect.value&&shortSymbolSelect.value)void loadPairRanking(false);scheduleSelectedHedgeCalculation()});
setAutoTradingLinksReady(Boolean(longSymbolSelect.value&&shortSymbolSelect.value));
</script></body></html>
