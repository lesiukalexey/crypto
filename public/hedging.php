<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Kyiv');
$root = dirname(__DIR__);
$config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
require_once __DIR__ . '/ticker-settings-lib.php';
$favoritesPath = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-favorites.json';
$favoriteView = false;
$favorite = null;
$favoriteId = trim((string) ($_GET['favorite_id'] ?? ''));
if ($favoriteId !== '' && is_file($favoritesPath)) {
    $storedFavorites = json_decode((string) file_get_contents($favoritesPath), true);
    if (is_array($storedFavorites)) {
        foreach ($storedFavorites as $storedFavorite) {
            if (is_array($storedFavorite) && (string) ($storedFavorite['id'] ?? '') === $favoriteId
                && (string) ($storedFavorite['type'] ?? '') === 'hedge'
                && is_array($storedFavorite['params'] ?? null)) {
                $favorite = $storedFavorite;
                $_GET = array_merge($_GET, $storedFavorite['params']);
                $favoriteView = true;
                break;
            }
        }
    }
}
$tickerSetSelection = selectedTickerSet($config, trim((string) ($_GET['ticker_set'] ?? '')));
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
$longSymbol = strtoupper((string) ($_GET['long_symbol'] ?? ($symbols[0] ?? '')));
$shortSymbol = strtoupper((string) ($_GET['short_symbol'] ?? ($symbols[1] ?? '')));
$favoriteSymbolsDisabled = $favoriteView && (
    !in_array(strtoupper((string) ($favorite['params']['long_symbol'] ?? '')), $symbols, true)
    || !in_array(strtoupper((string) ($favorite['params']['short_symbol'] ?? '')), $symbols, true)
);
if (!in_array($longSymbol, $symbols, true)) $longSymbol = $symbols[0] ?? '';
if (!in_array($shortSymbol, $symbols, true) || $shortSymbol === $longSymbol) {
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
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#8995ad;--text:#e9eefb;--line:#273149;--green:#42d6a4;--red:#ff7188;--blue:#86a8ff}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:none;margin:0 auto;padding:30px 20px}.page-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:14px}.eyebrow{color:#8ea5ff;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:700}.view-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 20px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.view-nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.view-nav a:hover{background:#1b2843;color:var(--text)}.view-nav a[aria-current=page]{background:#263968;color:#fff}.panel{background:color-mix(in srgb,var(--panel) 95%,transparent);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 18px 50px #0003}.filters{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.field{display:grid;gap:7px;color:var(--muted);font-size:13px;font-weight:600}.field select,.field input{font:inherit;color:var(--text);background:#0c1222;border:1px solid #34415e;border-radius:10px;padding:11px 13px;min-width:160px}.field select option.demo-available-ticker{color:#f2d77d;background:#292716}.field.pair-field select{min-width:360px}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:0 0 18px}.stat{background:#0c1222;border:1px solid var(--line);border-radius:12px;padding:13px 15px;min-width:0}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:19px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.gain{color:var(--green)}.loss{color:var(--red)}.chart-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px}.chart-head h2{font-size:17px;margin:0}.count,.small-note,.status{color:var(--muted);font-size:13px}.legend{display:flex;gap:18px;flex-wrap:wrap;color:#b7c1d5;font-size:13px;margin:14px 0}.legend span{display:inline-flex;gap:7px;align-items:center}.dot{width:9px;height:9px;border-radius:50%}.chart-wrap{position:relative}#hedge-chart{width:100%;height:clamp(260px,38vw,520px);display:block;overflow:visible}.chart-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:12px;background:#0c1222b8;color:#e9eefb;font-size:17px;font-weight:700;letter-spacing:.04em;z-index:1}.chart-loading[hidden]{display:none}.chart-loading-spinner{width:28px;height:28px;border:3px solid #34415e;border-top-color:#86a8ff;border-radius:50%;animation:chart-loading-spin .8s linear infinite}@keyframes chart-loading-spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.chart-loading-spinner{animation-duration:2s}}#tooltip{position:absolute;display:none;z-index:2;pointer-events:none;min-width:190px;padding:10px 12px;border:1px solid #394765;border-radius:10px;background:#0b1020f2;font-size:12px;white-space:nowrap}.status.error{color:#ffb3bf}.empty{display:grid;place-items:center;min-height:230px;color:var(--muted);text-align:center;padding:20px}.small-note{margin:12px 0 0}@media(max-width:650px){.wrap{padding:22px 14px}.panel{padding:16px}.page-heading{align-items:flex-start}.field,.field select,.field input,.field.pair-field select{width:100%;min-width:0}.filters{align-items:stretch}.view-nav{width:100%}.view-nav a{flex:1;text-align:center}#hedge-chart{height:70vw}}
.chart-mode{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.chart-mode button{border:1px solid #34415e;border-radius:9px;padding:9px 13px;background:#0c1222;color:#b7c1d5;font:inherit;cursor:pointer}.chart-mode button[aria-pressed="true"]{background:#263968;border-color:#5974bd;color:#fff}.chart-mode button:focus-visible{outline:2px solid #86a8ff;outline-offset:2px}
.ticker-set-panel{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 18px;padding:14px 18px}.ticker-set-panel label{font-weight:700}.ticker-set-panel select{min-width:240px;padding:10px 12px;border:1px solid #34415e;border-radius:9px;background:#0c1222;color:var(--text);font:inherit}.ticker-set-panel .small-note{margin:0}.stat small{display:block;color:var(--muted);font-size:12px;font-weight:400;margin-top:3px}#local-moves-panel .chart-head{flex-wrap:wrap}.local-moves-scale{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:var(--muted);font-size:13px;font-weight:600}.local-moves-scale select{font:inherit;color:var(--text);background:#0c1222;border:1px solid #34415e;border-radius:9px;padding:8px 10px}
.table-wrap{overflow:auto}.local-moves-table{width:100%;border-collapse:collapse;min-width:760px;font-size:13px}.local-moves-table th,.local-moves-table td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.local-moves-table th{color:var(--muted);font-weight:600}.local-moves-table th:first-child,.local-moves-table td:first-child{text-align:left}
</style>
</head>
<body><main class="wrap">
<header class="page-heading"><div class="eyebrow">Market data · <?= $h($config['category']) ?></div><span class="count">Симуляция по сохранённым котировкам</span></header>
<nav class="view-nav" aria-label="Разделы"><a href="index.php">Главная</a><a href="<?= $h($basicUrl) ?>">Базовые проверки</a><a href="<?= $h($hedgeUrl) ?>" aria-current="page">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php">Настройки</a><a id="autotrading-paper-link" href="autotrading.php?mode=paper&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Автоторговля (Paper)</a><a id="autotrading-demo-link" href="autotrading.php?mode=demo&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Автоторговля (Тест)</a><a id="autotrading-real-link" href="autotrading.php?mode=real&amp;view=setup&amp;hedge_long=<?= $h($longSymbol) ?>&amp;hedge_short=<?= $h($shortSymbol) ?>&amp;hedge_start_day=<?= $h($startDay) ?>&amp;hedge_end_day=<?= $h($endDay) ?>&amp;hedge_balance=<?= $h($balance) ?>&amp;hedge_fee=<?= $h($fee) ?>">Автоторговля (реал)</a><a href="autotrading-pi.php">Raspberry Pi</a></nav>
<section class="panel ticker-set-panel" aria-label="Выбранный список тикеров"><label for="ticker-set-select">Выбранный список тикеров</label><select id="ticker-set-select" <?= $favoriteView ? 'disabled' : '' ?>><?php foreach ($tickerSetOptions as $tickerSet): ?><option value="<?= $h((string) ($tickerSet['id'] ?? '')) ?>" <?= ($tickerSet['id'] ?? '') === $tickerSetId ? 'selected' : '' ?>><?= $h((string) ($tickerSet['name'] ?? '')) ?></option><?php endforeach; ?></select><span class="small-note"><?= count($symbols) ?> активных тикеров в списке</span></section>
<?php if ($favoriteView): ?><p class="small-note">Просмотр сохранённой пары «Лонг <?= $h($longSymbol) ?> / шорт <?= $h($shortSymbol) ?>». Параметры доступны только для чтения. <a href="favorites.php">← Вернуться к избранному</a></p><?php endif; ?>
<?php if ($favoriteSymbolsDisabled): ?><p class="status error">Один из тикеров сохранённой пары выключен или не входит в выбранный список.</p><?php endif; ?>
<h1>Хеджирование между тикерами</h1>
<section class="panel"><form class="filters" id="hedge-filters">
<input type="hidden" name="ticker_set" value="<?= $h($tickerSetId) ?>">
<input type="hidden" name="start_day_auto" value="<?= $startDayAuto ? '1' : '0' ?>">
<label class="field">Лонг<select id="hedge-long-symbol" <?= $favoriteView ? 'disabled' : '' ?> required><?php foreach ($symbols as $symbol): ?><option value="<?= $h($symbol) ?>" class="<?= isset($demoAvailableSymbols[$symbol]) ? 'demo-available-ticker' : '' ?>" <?= $symbol === $longSymbol ? 'selected' : '' ?>><?= $h($symbol) ?></option><?php endforeach; ?></select></label>
<label class="field">Шорт<select id="hedge-short-symbol" <?= $favoriteView ? 'disabled' : '' ?> required><?php foreach ($symbols as $symbol): ?><option value="<?= $h($symbol) ?>" class="<?= isset($demoAvailableSymbols[$symbol]) ? 'demo-available-ticker' : '' ?>" <?= $symbol === $shortSymbol ? 'selected' : '' ?>><?= $h($symbol) ?></option><?php endforeach; ?></select></label>
<span class="small-note">Жёлтым отмечены тикеры, доступные в Bitget Demo.</span>
<input type="hidden" name="pair" id="hedge-pair" value="<?= $h($longSymbol . '|' . $shortSymbol) ?>">
<span class="small-note" id="pair-ranking-status" role="status" aria-live="polite">После загрузки графика рассчитаю доходность всех пар: 0%.</span>
<?php if (!$favoriteView): ?><button type="button" id="add-hedge-favorite">☆ В избранное</button><?php endif; ?>
<label class="field">С даты<input type="date" name="start_day" value="<?= $h($startDay) ?>" required></label>
<label class="field">По дату<input type="date" name="end_day" value="<?= $h($endDay) ?>" required></label>
<label class="field">Стартовый баланс (USDT)<input type="number" name="balance" min="0.01" max="1000000000" step="0.01" value="<?= $h($balance) ?>" required></label><input type="hidden" name="balance_auto" value="<?= $balanceAutoManaged ? '1' : '0' ?>"><p class="small-note" id="minimum-balance-note" aria-live="polite">Для выбранной пары рассчитывается сумма минимальных размеров лонга и шорта с комиссией Bitget.</p>
<label class="field">Комиссия за сторону (%)<input type="number" name="fee" min="0" max="5" step="0.001" value="<?= $h($fee) ?>" required></label>
</form><?php if (!$favoriteView): ?><p class="small-note" id="hedge-favorite-status" aria-live="polite"></p><?php endif; ?><p class="small-note">Баланс поровну распределяется между двумя позициями. Каждая сторона открывается по доступному ask/bid, закрывается для оценки по bid/ask, комиссия учитывается при входе и выходе. Несинхронные котировки сопоставляются по последнему уже известному снимку; снимки старше 120 секунд пропускаются.</p></section>
<p class="status" id="hedge-status" role="status" aria-live="polite">Загружаю котировки выбранной пары…</p>
<section class="summary" id="hedge-summary" hidden></section>
<section class="panel"><div class="chart-mode" role="group" aria-label="Вид графика хеджирования"><button type="button" data-chart-view="net" aria-pressed="true">Чистая доходность по хеджированию</button><button type="button" data-chart-view="ticker" aria-pressed="false">Хеджирование между тикерами</button></div><div class="chart-head"><h2 id="hedge-chart-title">Чистая доходность по хеджированию</h2><span class="count" id="hedge-point-count"></span></div><div class="legend" id="hedge-legend"></div><div class="chart-wrap"><svg id="hedge-chart" role="img" aria-label="Чистая доходность по хеджированию во времени"></svg><div id="chart-loading" class="chart-loading" hidden role="status" aria-live="polite"><span class="chart-loading-spinner" aria-hidden="true"></span><span>Loading</span></div><div id="tooltip"></div></div><p class="small-note" id="hedge-chart-note">По вертикали — чистый результат в USDT с учётом комиссий открытия и расчётного закрытия. Позиции открываются на первом синхронизированном снимке выбранного периода.</p></section>
<section class="panel" id="local-moves-panel" hidden><div class="chart-head"><h2>Крупнейшие локальные падения</h2><label class="local-moves-scale">Длительность падения<select id="local-moves-scale" aria-label="Минимальная длительность локального падения"><option value="minimum">Минимальная (как сейчас)</option><option value="medium">Средняя длительность</option><option value="large">Большая длительность</option></select></label><span class="count" id="local-moves-summary"></span></div><p class="small-note" id="local-moves-threshold"></p><div class="table-wrap"><table class="local-moves-table"><thead><tr><th>№</th><th>Максимум · время</th><th>Максимум · USDT</th><th>Минимум · время</th><th>Минимум · USDT</th><th>Падение</th><th>Длительность</th></tr></thead><tbody id="local-moves-rows"></tbody></table></div></section>
</main>
<script>
const form=document.querySelector('#hedge-filters'),pairSelect=document.querySelector('#hedge-pair'),longSymbolSelect=document.querySelector('#hedge-long-symbol'),shortSymbolSelect=document.querySelector('#hedge-short-symbol'),statusNode=document.querySelector('#hedge-status'),summaryNode=document.querySelector('#hedge-summary'),chart=document.querySelector('#hedge-chart'),chartLoading=document.querySelector('#chart-loading'),pointCount=document.querySelector('#hedge-point-count'),tooltip=document.querySelector('#tooltip'),ns='http://www.w3.org/2000/svg';
function setChartLoading(loading){chartLoading.hidden=!loading}
const tickerSetSelect=document.querySelector('#ticker-set-select');
tickerSetSelect?.addEventListener('change',()=>{const params=new URLSearchParams(location.search);params.set('ticker_set',tickerSetSelect.value);for(const key of ['pair','long_symbol','short_symbol','favorite_id'])params.delete(key);location.href=location.pathname+'?'+params.toString()});
const startDayAutoInput=form.elements.namedItem('start_day_auto');
const favoriteView=<?= $favoriteView ? 'true' : 'false' ?>,favoriteSymbolsDisabled=<?= $favoriteSymbolsDisabled ? 'true' : 'false' ?>,favoriteId=<?= json_encode($favoriteId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,favoriteSavedParams=<?= json_encode($favorite['params'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const demoAvailableTickerSymbols=new Set(<?= json_encode(array_keys($demoAvailableSymbols), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
if(favoriteView)form.querySelectorAll('select,input,button').forEach(control=>control.disabled=true);
const palette={long_index:'#42d6a4',short_index:'#ff7188',equity_index:'#86a8ff'};
const chartModeButtons=[...document.querySelectorAll('[data-chart-view]')],chartTitle=document.querySelector('#hedge-chart-title'),chartLegend=document.querySelector('#hedge-legend'),chartNote=document.querySelector('#hedge-chart-note');
let chartView=new URLSearchParams(location.search).get('chart_view')==='ticker'?'ticker':'net';
const fmt=(n,d=4)=>Number(n).toLocaleString('ru-RU',{minimumFractionDigits:d,maximumFractionDigits:d});
const money=n=>(Number(n)>0?'+':'')+fmt(n)+' USDT';
const signed=(n,d=2)=>(Number(n)>0?'+':Number(n)<0?'−':'')+fmt(Math.abs(Number(n)),d);
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
let rankedPairData=null;
function setAutoTradingLinksReady(ready){for(const id of ['#autotrading-paper-link','#autotrading-demo-link','#autotrading-real-link']){const link=document.querySelector(id);if(!link)continue;link.setAttribute('aria-disabled',ready?'false':'true');link.tabIndex=ready?0:-1;link.style.pointerEvents=ready?'':'none';link.style.opacity=ready?'':'0.55'}}
function updateAutoTradingDemoLink(){if(!longSymbolSelect||!shortSymbolSelect)return;for(const[mode,id]of [['paper','#autotrading-paper-link'],['demo','#autotrading-demo-link'],['real','#autotrading-real-link']]){const link=document.querySelector(id);if(!link)continue;const params=new URLSearchParams({mode,view:'setup',hedge_long:longSymbolSelect.value,hedge_short:shortSymbolSelect.value,hedge_start_day:form.elements.start_day.value,hedge_end_day:form.elements.end_day.value,hedge_balance:form.elements.balance.value,hedge_fee:form.elements.fee.value});link.href='autotrading.php?'+params.toString()}}
function syncManualPairSelection(){if(longSymbolSelect&&shortSymbolSelect){pairSelect.value=longSymbolSelect.value+'|'+shortSymbolSelect.value;updateAutoTradingDemoLink()}}
function renderTickerRanking(select,pairs,partnerSide){
  if(!select||!Array.isArray(pairs))return;
  const selected=select.value,bySymbol=new Map(pairs.map(pair=>[pair[partnerSide==='short'?'long_symbol':'short_symbol'],pair]));
  const options=[...select.options].map(option=>({symbol:option.value,pair:bySymbol.get(option.value)}));
  options.sort((left,right)=>{
    const leftValue=left.pair?.net_pnl,rightValue=right.pair?.net_pnl;
    if(leftValue===null||leftValue===undefined)return rightValue===null||rightValue===undefined?left.symbol.localeCompare(right.symbol):1;
    if(rightValue===null||rightValue===undefined)return -1;
    return Number(rightValue)-Number(leftValue)||left.symbol.localeCompare(right.symbol);
  });
  select.replaceChildren(...options.map(({symbol,pair})=>{
    const option=document.createElement('option');option.value=symbol;
    const returnLabel=pair?.net_pnl===null||pair?.net_pnl===undefined?'нет данных':`${signed(pair.net_pnl)} USDT (${signed(pair.return_percent)}%)`;
    option.classList.toggle('demo-available-ticker',demoAvailableTickerSymbols.has(symbol));
    option.textContent=pair?`${symbol} · доходность ${returnLabel}`:symbol;
    option.selected=symbol===selected;return option;
  }));
}
function renderCurrentTickerRankings(){
  if(!rankedPairData){return}
  const{symbols,pair_matrix:matrix,starting_balance:balance}=rankedPairData,index=new Map(symbols.map((symbol,i)=>[symbol,i]));
  const longIndex=index.get(longSymbolSelect.value),shortIndex=index.get(shortSymbolSelect.value);
  if(longIndex===undefined||shortIndex===undefined)return;
  const makePair=(longSymbol,shortSymbol)=>{const net_pnl=matrix[index.get(longSymbol)][index.get(shortSymbol)];return{long_symbol:longSymbol,short_symbol:shortSymbol,net_pnl,return_percent:net_pnl===null?null:Number(net_pnl)/Number(balance)*100}};
  renderTickerRanking(longSymbolSelect,symbols.filter(symbol=>symbol!==shortSymbolSelect.value).map(symbol=>makePair(symbol,shortSymbolSelect.value)),'short');
  renderTickerRanking(shortSymbolSelect,symbols.filter(symbol=>symbol!==longSymbolSelect.value).map(symbol=>makePair(longSymbolSelect.value,symbol)),'long');
}
for(const select of [longSymbolSelect,shortSymbolSelect])select?.addEventListener('change',()=>{pairSelectionVersion++;syncManualPairSelection();renderCurrentTickerRankings();loadData()});
pairSelect.addEventListener('change',()=>{pairSelectionVersion++});
document.querySelector('#add-hedge-favorite')?.addEventListener('click',async()=>{const button=document.querySelector('#add-hedge-favorite'),status=document.querySelector('#hedge-favorite-status'),params=new URLSearchParams(new FormData(form)),[longSymbol,shortSymbol]=pairSelect.value.split('|');params.set('favorite_type','hedge');params.set('long_symbol',longSymbol);params.set('short_symbol',shortSymbol);params.set('favorite_entry_label',`Лонг ${longSymbol} / шорт ${shortSymbol}`);params.set('favorite_exit_label','Закрытие по последнему синхронизированному снимку');params.set('favorite_name',`Лонг ${longSymbol} / шорт ${shortSymbol}`);button.disabled=true;status.textContent='Сохраняю пару…';try{const response=await fetch('favorites.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:params}),result=await response.json();if(!response.ok||result.status!=='ok')throw new Error(result.error||'Не удалось сохранить пару');button.textContent='✓ Добавлено';status.textContent='Пара добавлена в избранное.'}catch(error){status.textContent=error.message||'Не удалось сохранить пару';button.disabled=false}});
async function loadPairRanking(selectLeader=false){
  if(!pairSelect.value){setChartLoading(false);pairRankingStatus.textContent='Для расчёта пары выберите список минимум с двумя активными тикерами.';if(selectLeader)statusNode.textContent='Для хеджирования нужны минимум два активных тикера.';return}
  const selectionVersion=pairSelectionVersion;
  const requestId=++pairRankingRequest,params=new URLSearchParams(new FormData(form));params.delete('pair');
  const url=new URL('hedge-ranking.php',location.href);url.search=params.toString();
  let lastRankingProgress=Date.now(),lastRankingMessage='Проверяю кэш рейтинга пар…';
  const progressTimer=setInterval(()=>{if(requestId!==pairRankingRequest)return;const waitingSeconds=Math.floor((Date.now()-lastRankingProgress)/1000);if(waitingSeconds>=10)pairRankingStatus.textContent=lastRankingMessage+' — жду следующий ответ сервера: '+waitingSeconds+' сек.'},5000);
  if(selectLeader){summaryNode.hidden=true;localMovesPanel.hidden=true;chart.replaceChildren();pointCount.textContent='';setChartLoading(true);statusNode.classList.remove('error');statusNode.textContent='Сначала рассчитываю доходность всех пар; затем загружу график лучшей пары…'}
  pairRankingStatus.textContent='Проверяю кэш рейтинга пар…';
  try{
    const response=await fetch(url,{headers:{Accept:'application/x-ndjson, application/json'},cache:'no-store'});
    let data=null;
    if(response.headers.get('content-type')?.includes('application/x-ndjson')){
      const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='';
      const consume=chunk=>{buffer+=chunk;const lines=buffer.split('\n');buffer=lines.pop();for(const line of lines){if(!line.trim())continue;const event=JSON.parse(line);if(event.type==='progress'){lastRankingProgress=Date.now();lastRankingMessage=event.message;pairRankingStatus.textContent=event.message}if(event.type==='error')throw new Error(event.message);if(event.type==='result')data=event.data}};
      while(true){const{value,done}=await reader.read();if(done)break;consume(decoder.decode(value,{stream:true}))}
      consume(decoder.decode());if(buffer.trim()){const event=JSON.parse(buffer);if(event.type==='error')throw new Error(event.message);if(event.type==='result')data=event.data}
    }else{data=await response.json()}
    if(requestId!==pairRankingRequest)return;
    if(!response.ok||data?.status!=='ready')throw new Error(data?.message||'Не удалось рассчитать доходность пар');
    const leader=data.pairs?.find(pair=>pair.net_pnl!==null);
    rankedPairData={symbols:data.symbols,pair_matrix:data.pair_matrix,starting_balance:data.starting_balance};
    renderCurrentTickerRankings();
    if(leader&&selectLeader&&selectionVersion===pairSelectionVersion){longSymbolSelect.value=leader.long_symbol;shortSymbolSelect.value=leader.short_symbol;syncManualPairSelection();renderCurrentTickerRankings();await loadData()}
    else if(!leader&&selectLeader&&selectionVersion===pairSelectionVersion){setChartLoading(false);statusNode.textContent='Не нашлось пары с данными для расчёта за выбранный период.'}
    const pairCount=data.ordered_pair_count??0;
    pairRankingStatus.textContent=`${data.cache_hit?'Загружен сохранённый рейтинг':'Рассчитана доходность'} ${pairCount.toLocaleString('ru-RU')} пар за ${data.start_day} — ${data.end_day}; списки пересортировываются относительно выбранного тикера. Лидер: ${leader?`лонг ${leader.long_symbol} / шорт ${leader.short_symbol}`:'не найден'}.`;
  }catch(error){if(requestId===pairRankingRequest){setChartLoading(false);pairRankingStatus.textContent='Не удалось обновить доходность пар: '+error.message;if(selectLeader)statusNode.textContent='Не удалось рассчитать пары: '+error.message}}finally{clearInterval(progressTimer)}
}
function el(tag,attrs={},text=''){const node=document.createElementNS(ns,tag);for(const[key,value]of Object.entries(attrs))node.setAttribute(key,value);if(text)node.textContent=text;chart.append(node);return node}
function render(data){
  setChartLoading(false);
  window.currentHedgeData=data;
  chart.replaceChildren();
  const netView=chartView==='net';
  const series=netView
    ? [{key:'net_pnl',label:'Чистая доходность',color:'#86a8ff'}]
    : [{key:'long_index',label:'Лонг: доходность',color:palette.long_index},{key:'short_index',label:'Шорт: доходность',color:palette.short_index},{key:'equity_index',label:'Общий баланс',color:palette.equity_index}];
  chartTitle.textContent=netView?'Чистая доходность по хеджированию':'Хеджирование между тикерами';
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
  localMovesRows.innerHTML=localMoves.length?localMoves.map((move,index)=>`<tr><td>${index+1}</td><td>${new Date(move.peakTime).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})}</td><td>${money(move.peakValue)}</td><td>${new Date(move.minimumTime).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})}</td><td>${money(move.minimumValue)}</td><td>${fmt(move.decline,4)} USDT · ${fmt(move.decline/Number(data.starting_balance)*100,2)}%</td><td>${durationLabel(move.minimumTime-move.peakTime)}</td></tr>`).join(''):'<tr><td colspan="7">Для этого интервала локальных падений не найдено.</td></tr>';
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
    tooltip.style.display='block';
    tooltip.style.left=`${Math.min(event.clientX-rect.left+12,rect.width-tooltip.offsetWidth-4)}px`;
    tooltip.style.top=`${Math.max(2,event.clientY-rect.top-tooltip.offsetHeight-10)}px`;
  });
  hit.addEventListener('pointerleave',()=>{cursor.setAttribute('visibility','hidden');horizontalCursor.setAttribute('visibility','hidden');cursorValueBackground.setAttribute('visibility','hidden');cursorValueLabel.setAttribute('visibility','hidden');cursorDateBackground.setAttribute('visibility','hidden');cursorDateLabel.setAttribute('visibility','hidden');tooltip.style.display='none'});
  const stat=(label,value,cls='')=>`<div class="stat"><span>${label}</span><strong class="${cls}">${value}</strong></div>`;
  const startingBalance=Number(data.starting_balance);
  const percentFromStart=value=>{const percent=Number(value)/startingBalance*100;return `${percent>0?'+':''}${fmt(percent,2)}% от стартового баланса`};
  const statReturn=(label,value,cls='')=>`<div class="stat"><span>${label}</span><strong class="${cls}">${money(value)}</strong><small>${percentFromStart(value)}</small></div>`;
  const declineClass=Number(summary.decline_after_maximum)>0?'loss':'gain';
  const maxTime=new Date(summary.maximum_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const lowTime=new Date(summary.minimum_after_maximum_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const declineDuration=durationLabel(Number(summary.minimum_after_maximum_time_ms)-Number(summary.maximum_time_ms));
  const drawdownClass=Number(summary.maximum_drawdown)>0?'loss':'gain';
  const drawdownPeakTime=new Date(summary.maximum_drawdown_peak_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const drawdownTroughTime=new Date(summary.maximum_drawdown_trough_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  const drawdownDuration=durationLabel(Number(summary.maximum_drawdown_trough_time_ms)-Number(summary.maximum_drawdown_peak_time_ms));
  summaryNode.innerHTML=stat('Стартовый баланс',fmt(startingBalance,2)+' USDT')+statReturn('Лонг '+data.long_symbol,summary.long_pnl,Number(summary.long_pnl)>=0?'gain':'loss')+statReturn('Шорт '+data.short_symbol,summary.short_pnl,Number(summary.short_pnl)>=0?'gain':'loss')+statReturn('Общий итог',summary.net_pnl,Number(summary.net_pnl)>=0?'gain':'loss')+`<div class="stat"><span>Максимум за период</span><strong>${money(summary.maximum_net_pnl)}</strong><small>${percentFromStart(summary.maximum_net_pnl)} · ${maxTime} · Киев</small></div>`+`<div class="stat"><span>Снижение после максимума</span><strong class="${declineClass}">${fmt(summary.decline_after_maximum,4)} USDT · ${fmt(summary.decline_after_maximum_percent,2)}%</strong><small>Длительность: ${declineDuration}<br>Минимум: ${money(summary.minimum_after_maximum_net_pnl)} · ${percentFromStart(summary.minimum_after_maximum_net_pnl)} · ${lowTime}</small></div>`+`<div class="stat"><span>Максимальное снижение на графике</span><strong class="${drawdownClass}">${fmt(summary.maximum_drawdown,4)} USDT · ${fmt(summary.maximum_drawdown_percent,2)}%</strong><small>От ${money(summary.maximum_drawdown_peak_net_pnl)} · ${drawdownPeakTime}<br>До ${money(summary.maximum_drawdown_trough_net_pnl)} · ${drawdownTroughTime}<br>Длительность: ${drawdownDuration}</small></div>`+`<div class="stat"><span>Баланс после закрытия</span><strong>${fmt(summary.ending_balance,4)} USDT</strong><small>Доходность: ${percentFromStart(summary.net_pnl)}</small></div>`+`<div class="stat"><span>Размеры позиций</span><strong>${fmt(summary.long_notional,2)} / ${fmt(summary.short_notional,2)} USDT</strong><small>Лонг ${percentFromStart(summary.long_notional)} · Шорт ${percentFromStart(summary.short_notional)}</small></div>`;
  summaryNode.hidden=false;
  statusNode.textContent=`Вход ${new Date(summary.entry_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · последний общий снимок ${new Date(summary.last_aligned_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · комиссия входа ${fmt(summary.entry_fees,4)} USDT`;
  const interval=value=>Number(value).toLocaleString('ru-RU',{maximumFractionDigits:2});
  pointCount.textContent=`Снимки для расчёта: ${data.long_symbol} — ${data.sampled_long_quotes} из ${data.long_quotes} (интервал ${interval(data.long_sampling_interval)}), ${data.short_symbol} — ${data.sampled_short_quotes} из ${data.short_quotes} (интервал ${interval(data.short_sampling_interval)})`;
}
async function loadData(){setAutoTradingLinksReady(false);if(!pairSelect.value){setChartLoading(false);summaryNode.hidden=true;statusNode.textContent='В выбранном списке нет двух активных тикеров для хеджирования.';return}if(favoriteSymbolsDisabled){setChartLoading(false);statusNode.textContent='Один из тикеров сохранённой пары не входит в список.';statusNode.classList.add('error');return}const [longSymbol,shortSymbol]=pairSelect.value.split('|');updateAutoTradingDemoLink();const params=new URLSearchParams(new FormData(form));if(favoriteView)for(const[key,value]of Object.entries(favoriteSavedParams))params.set(key,String(value));params.delete('pair');params.set('long_symbol',longSymbol);params.set('short_symbol',shortSymbol);const url=new URL('hedge-data.php',location.href);url.search=params.toString();statusNode.textContent='Загружаю и сопоставляю снимки…';statusNode.classList.remove('error');chart.replaceChildren();setChartLoading(true);try{const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();if(!response.ok||data.status==='error')throw new Error(data.message||'Не удалось загрузить данные пары');if(data.starting_balance!==undefined&&data.minimum_starting_balance!==undefined){const requested=Number(data.requested_starting_balance??params.get('balance')??0),minimum=Number(data.minimum_starting_balance),adjusted=Number(data.starting_balance),note=document.querySelector('#minimum-balance-note'),requirements=data.minimum_order_requirements||{};form.elements.balance.value=adjusted.toFixed(2);params.set('balance',adjusted.toFixed(2));if(note){const long= requirements.long||{},short=requirements.short||{};const pairMinimum=`${long.symbol||longSymbol}: ${Number(long.minimum_balance_usdt||0).toFixed(2)} + ${short.symbol||shortSymbol}: ${Number(short.minimum_balance_usdt||0).toFixed(2)} USDT`;note.textContent=adjusted<requested?`Стартовый баланс уменьшен с ${requested.toFixed(2)} до ${adjusted.toFixed(2)} USDT для выбранной пары. Минимумы с комиссией Bitget: ${pairMinimum}.`:adjusted>requested?`Стартовый баланс увеличен с ${requested.toFixed(2)} до ${adjusted.toFixed(2)} USDT для выбранной пары. Минимумы с комиссией Bitget: ${pairMinimum}.`:`Минимум для выбранной пары: ${pairMinimum} (всего ${minimum.toFixed(2)} USDT с комиссией Bitget).`;}updateAutoTradingDemoLink();setAutoTradingLinksReady(true)}if(data.status!=='ready'){render(data);return}render(data);const next=new URL(location.href);for(const[key,value]of params)next.searchParams.set(key,value);next.searchParams.set('pair',pairSelect.value);next.searchParams.set('chart_view',chartView);if(favoriteView)next.searchParams.set('favorite_id',favoriteId);history.replaceState(null,'',next);const basicParams=new URLSearchParams(location.search);for(const key of ['long_symbol','short_symbol','pair','favorite_id','chart_view'])basicParams.delete(key);for(const key of ['start_day','end_day','balance','balance_auto','fee'])basicParams.set(key,params.get(key));document.querySelector('.view-nav a[href^="basic-checks.php?"]').href='basic-checks.php?'+basicParams.toString()}catch(error){setChartLoading(false);summaryNode.hidden=true;statusNode.textContent=error.message;statusNode.classList.add('error')}}
function setChartView(nextView){chartView=nextView;chartModeButtons.forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.chartView===chartView)));const next=new URL(location.href);next.searchParams.set('chart_view',chartView);history.replaceState(null,'',next);if(window.currentHedgeData)render(window.currentHedgeData)}
chartModeButtons.forEach(button=>button.addEventListener('click',()=>setChartView(button.dataset.chartView)));
localMovesScale.addEventListener('change',()=>{if(window.currentHedgeData)render(window.currentHedgeData)});form.addEventListener('submit',event=>{event.preventDefault();loadPairRanking(true)});form.addEventListener('input',event=>{if(event.target.name==='balance')form.elements.balance_auto.value='0'});form.addEventListener('change',event=>{updateAutoTradingDemoLink();if(event.target.name==='start_day'){startDayAutoInput.value='0';const next=new URL(location.href);next.searchParams.set('start_day',event.target.value);next.searchParams.set('start_day_auto','0');history.replaceState(null,'',next)}if(event.target===pairSelect||event.target===longSymbolSelect||event.target===shortSymbolSelect){if(event.target===pairSelect){const [longSymbol,shortSymbol]=pairSelect.value.split('|');if(longSymbolSelect)longSymbolSelect.value=longSymbol;if(shortSymbolSelect)shortSymbolSelect.value=shortSymbol;updateAutoTradingDemoLink();loadData()}return}loadPairRanking(true)});window.addEventListener('resize',()=>{if(window.currentHedgeData)render(window.currentHedgeData)});updateAutoTradingDemoLink();if(favoriteView)loadData();else loadPairRanking(true);
</script></body></html>
