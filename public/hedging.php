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
$symbols = enabledTickerSymbols($config);
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Kyiv'));
$startDay = (string) ($_GET['start_day'] ?? '2026-10-06');
$endDay = (string) ($_GET['end_day'] ?? $today->format('Y-m-d'));
$balance = (string) ($_GET['balance'] ?? '10');
$fee = (string) ($_GET['fee'] ?? '0.06');
$longSymbol = strtoupper((string) ($_GET['long_symbol'] ?? ($symbols[0] ?? '')));
$shortSymbol = strtoupper((string) ($_GET['short_symbol'] ?? ($symbols[1] ?? '')));
$favoriteSymbolsDisabled = $favoriteView && (
    !in_array(strtoupper((string) ($favorite['params']['long_symbol'] ?? '')), $symbols, true)
    || !in_array(strtoupper((string) ($favorite['params']['short_symbol'] ?? '')), $symbols, true)
);
$pairWasRequested = isset($_GET['pair']) || isset($_GET['long_symbol']) || isset($_GET['short_symbol']);
if (!in_array($longSymbol, $symbols, true)) $longSymbol = $symbols[0] ?? '';
if (!in_array($shortSymbol, $symbols, true) || $shortSymbol === $longSymbol) {
    $shortSymbol = current(array_values(array_filter($symbols, static fn (string $symbol): bool => $symbol !== $longSymbol))) ?: '';
}
$basicParams = $_GET;
unset($basicParams['long_symbol'], $basicParams['short_symbol'], $basicParams['pair']);
$basicParams['start_day'] = $startDay;
$basicParams['end_day'] = $endDay;
$basicParams['balance'] = $balance;
$basicParams['fee'] = $fee;
$basicUrl = './?' . http_build_query($basicParams);
$hedgeParams = $_GET;
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
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#8995ad;--text:#e9eefb;--line:#273149;--green:#42d6a4;--red:#ff7188;--blue:#86a8ff}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:none;margin:0 auto;padding:30px 20px}.page-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:14px}.eyebrow{color:#8ea5ff;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:700}.view-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 20px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.view-nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.view-nav a:hover{background:#1b2843;color:var(--text)}.view-nav a[aria-current=page]{background:#263968;color:#fff}.panel{background:color-mix(in srgb,var(--panel) 95%,transparent);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 18px 50px #0003}.filters{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.field{display:grid;gap:7px;color:var(--muted);font-size:13px;font-weight:600}.field select,.field input{font:inherit;color:var(--text);background:#0c1222;border:1px solid #34415e;border-radius:10px;padding:11px 13px;min-width:160px}.field.pair-field select{min-width:360px}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:0 0 18px}.stat{background:#0c1222;border:1px solid var(--line);border-radius:12px;padding:13px 15px;min-width:0}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:19px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.gain{color:var(--green)}.loss{color:var(--red)}.chart-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px}.chart-head h2{font-size:17px;margin:0}.count,.small-note,.status{color:var(--muted);font-size:13px}.legend{display:flex;gap:18px;flex-wrap:wrap;color:#b7c1d5;font-size:13px;margin:14px 0}.legend span{display:inline-flex;gap:7px;align-items:center}.dot{width:9px;height:9px;border-radius:50%}.chart-wrap{position:relative}#hedge-chart{width:100%;height:clamp(260px,38vw,520px);display:block;overflow:visible}#tooltip{position:absolute;display:none;z-index:2;pointer-events:none;min-width:190px;padding:10px 12px;border:1px solid #394765;border-radius:10px;background:#0b1020f2;font-size:12px;white-space:nowrap}.status.error{color:#ffb3bf}.empty{display:grid;place-items:center;min-height:230px;color:var(--muted);text-align:center;padding:20px}.small-note{margin:12px 0 0}@media(max-width:650px){.wrap{padding:22px 14px}.panel{padding:16px}.page-heading{align-items:flex-start}.field,.field select,.field input,.field.pair-field select{width:100%;min-width:0}.filters{align-items:stretch}.view-nav{width:100%}.view-nav a{flex:1;text-align:center}#hedge-chart{height:70vw}}
</style>
</head>
<body><main class="wrap">
<header class="page-heading"><div class="eyebrow">Market data · <?= $h($config['category']) ?></div><span class="count">Симуляция по сохранённым котировкам</span></header>
<nav class="view-nav" aria-label="Разделы"><a href="<?= $h($basicUrl) ?>">Базовые проверки</a><a href="<?= $h($hedgeUrl) ?>" aria-current="page">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php">Настройки</a></nav>
<?php if ($favoriteView): ?><p class="small-note">Просмотр сохранённой пары «Лонг <?= $h($longSymbol) ?> / шорт <?= $h($shortSymbol) ?>». Параметры доступны только для чтения. <a href="favorites.php">← Вернуться к избранному</a></p><?php endif; ?>
<?php if ($favoriteSymbolsDisabled): ?><p class="status error">Один из тикеров этой сохранённой пары выключен в настройках и не участвует в расчётах.</p><?php endif; ?>
<h1>Хеджирование между тикерами</h1>
<section class="panel"><form class="filters" id="hedge-filters">
<label class="field pair-field">Пара: лонг / шорт<select name="pair" id="hedge-pair" required><?php foreach ($symbols as $long): foreach ($symbols as $short): if ($long === $short) continue; $value = $long . '|' . $short; ?><option value="<?= $h($value) ?>" <?= $long === $longSymbol && $short === $shortSymbol ? 'selected' : '' ?>>Лонг <?= $h($long) ?> / шорт <?= $h($short) ?></option><?php endforeach; endforeach; ?></select><span class="small-note" id="pair-ranking-status" aria-live="polite">При открытии страницы рассчитываю доходность пар…</span></label>
<?php if (!$favoriteView): ?><button type="button" id="add-hedge-favorite">☆ В избранное</button><?php endif; ?>
<label class="field">С даты<input type="date" name="start_day" value="<?= $h($startDay) ?>" required></label>
<label class="field">По дату<input type="date" name="end_day" value="<?= $h($endDay) ?>" required></label>
<label class="field">Стартовый баланс (USDT)<input type="number" name="balance" min="0.01" max="1000000000" step="0.01" value="<?= $h($balance) ?>" required></label>
<label class="field">Комиссия за сторону (%)<input type="number" name="fee" min="0" max="5" step="0.001" value="<?= $h($fee) ?>" required></label>
</form><?php if (!$favoriteView): ?><p class="small-note" id="hedge-favorite-status" aria-live="polite"></p><?php endif; ?><p class="small-note">Баланс поровну распределяется между двумя позициями. Каждая сторона открывается по доступному ask/bid, закрывается для оценки по bid/ask, комиссия учитывается при входе и выходе. Несинхронные котировки сопоставляются по последнему уже известному снимку; снимки старше 120 секунд пропускаются.</p></section>
<p class="status" id="hedge-status" role="status" aria-live="polite">Загружаю котировки выбранной пары…</p>
<section class="summary" id="hedge-summary" hidden></section>
<section class="panel"><div class="chart-head"><h2 id="hedge-chart-title">Сравнение динамики</h2><span class="count" id="hedge-point-count"></span></div><div class="legend"><span><i class="dot" style="background:#42d6a4"></i>Лонг: доходность</span><span><i class="dot" style="background:#ff7188"></i>Шорт: доходность</span><span><i class="dot" style="background:#86a8ff"></i>Общий баланс</span></div><div class="chart-wrap"><svg id="hedge-chart" role="img" aria-label="График доходности лонга, шорта и общего баланса"></svg><div id="tooltip"></div></div><p class="small-note">Индексы начинаются около 100. Общий баланс включает комиссии открытия и предполагаемого закрытия на последнем синхронизированном снимке.</p></section>
</main>
<script>
const form=document.querySelector('#hedge-filters'),pairSelect=document.querySelector('#hedge-pair'),statusNode=document.querySelector('#hedge-status'),summaryNode=document.querySelector('#hedge-summary'),chart=document.querySelector('#hedge-chart'),pointCount=document.querySelector('#hedge-point-count'),tooltip=document.querySelector('#tooltip'),ns='http://www.w3.org/2000/svg';
const favoriteView=<?= $favoriteView ? 'true' : 'false' ?>,favoriteSymbolsDisabled=<?= $favoriteSymbolsDisabled ? 'true' : 'false' ?>,favoriteId=<?= json_encode($favoriteId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,favoriteSavedParams=<?= json_encode($favorite['params'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
if(favoriteView)form.querySelectorAll('select,input,button').forEach(control=>control.disabled=true);
const palette={long_index:'#42d6a4',short_index:'#ff7188',equity_index:'#86a8ff'};
const fmt=(n,d=4)=>Number(n).toLocaleString('ru-RU',{minimumFractionDigits:d,maximumFractionDigits:d});
const money=n=>(Number(n)>0?'+':'')+fmt(n)+' USDT';
const signed=(n,d=2)=>(Number(n)>0?'+':Number(n)<0?'−':'')+fmt(Math.abs(Number(n)),d);
const pairRankingStatus=document.querySelector('#pair-ranking-status');
let pairRankingRequest=0;
const pairWasRequestedOnPageLoad=<?= $pairWasRequested ? 'true' : 'false' ?>;
let pairRankingLoaded=false;
let pairSelectionTouched=false;
pairSelect.addEventListener('change',()=>{pairSelectionTouched=true});
document.querySelector('#add-hedge-favorite')?.addEventListener('click',async()=>{const button=document.querySelector('#add-hedge-favorite'),status=document.querySelector('#hedge-favorite-status'),params=new URLSearchParams(new FormData(form)),[longSymbol,shortSymbol]=pairSelect.value.split('|');params.set('favorite_type','hedge');params.set('long_symbol',longSymbol);params.set('short_symbol',shortSymbol);params.set('favorite_entry_label',`Лонг ${longSymbol} / шорт ${shortSymbol}`);params.set('favorite_exit_label','Закрытие по последнему синхронизированному снимку');params.set('favorite_name',`Лонг ${longSymbol} / шорт ${shortSymbol}`);button.disabled=true;status.textContent='Сохраняю пару…';try{const response=await fetch('favorites.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:params}),result=await response.json();if(!response.ok||result.status!=='ok')throw new Error(result.error||'Не удалось сохранить пару');button.textContent='✓ Добавлено';status.textContent='Пара добавлена в избранное.'}catch(error){status.textContent=error.message||'Не удалось сохранить пару';button.disabled=false}});
async function loadPairRanking(){const requestId=++pairRankingRequest,params=new URLSearchParams(new FormData(form));params.delete('pair');const url=new URL('hedge-ranking.php',location.href);url.search=params.toString();pairRankingStatus.textContent='Рассчитываю доходность всех пар по актуальным котировкам…';try{const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();if(requestId!==pairRankingRequest)return;if(!response.ok||data.status!=='ready')throw new Error(data.message||'Не удалось рассчитать доходность пар');const currentPair=pairSelect.value,preserveSelection=pairRankingLoaded||pairWasRequestedOnPageLoad||pairSelectionTouched,selectedPair=preserveSelection?currentPair:(data.pairs[0]?data.pairs[0].long_symbol+'|'+data.pairs[0].short_symbol:currentPair),fragment=document.createDocumentFragment();for(const pair of data.pairs){const option=document.createElement('option'),value=pair.long_symbol+'|'+pair.short_symbol;option.value=value;option.textContent=pair.net_pnl===null?`Лонг ${pair.long_symbol} / шорт ${pair.short_symbol} · доходность нет данных`:`Лонг ${pair.long_symbol} / шорт ${pair.short_symbol} · доходность ${signed(pair.net_pnl)} USDT (${signed(pair.return_percent)}%)`;option.selected=value===selectedPair;fragment.append(option)}pairSelect.replaceChildren(fragment);if(!pairSelect.value&&pairSelect.options.length)pairSelect.selectedIndex=0;pairRankingLoaded=true;pairRankingStatus.textContent=`Отсортировано ${data.pairs.length} пар по чистой доходности за ${data.start_day} — ${data.end_day}; рейтинг пересчитывается при каждом открытии страницы.`;if(pairSelect.value!==currentPair)loadData()}catch(error){if(requestId===pairRankingRequest)pairRankingStatus.textContent='Не удалось обновить доходность пар: '+error.message}}
function el(tag,attrs={},text=''){const node=document.createElementNS(ns,tag);for(const[key,value]of Object.entries(attrs))node.setAttribute(key,value);if(text)node.textContent=text;chart.append(node);return node}
function render(data){window.currentHedgeData=data;chart.replaceChildren();const points=data.points||[];if(points.length<2){summaryNode.hidden=true;statusNode.textContent=data.message||'Недостаточно синхронизированных снимков для графика.';pointCount.textContent='';return}
const width=Math.max(700,chart.clientWidth||1000),height=Math.max(260,chart.clientHeight||420),pad={l:78,r:28,t:24,b:44};chart.setAttribute('viewBox',`0 0 ${width} ${height}`);chart.setAttribute('preserveAspectRatio','none');
const times=points.map(p=>Number(p.t)),series=Object.keys(palette),values=points.flatMap(p=>series.map(key=>Number(p[key]))),xmin=Math.min(...times),xmax=Math.max(...times),ymin=Math.min(...values),ymax=Math.max(...values),yrange=Math.max(1,ymax-ymin),low=ymin-yrange*.12,high=ymax+yrange*.12;
const X=t=>pad.l+(t-xmin)/Math.max(1,xmax-xmin)*(width-pad.l-pad.r),Y=v=>pad.t+(high-v)/(high-low)*(height-pad.t-pad.b);
for(let i=0;i<5;i++){const value=low+(high-low)*i/4,y=Y(value);el('line',{x1:pad.l,y1:y,x2:width-pad.r,y2:y,stroke:'#273149'});el('text',{x:pad.l-10,y:y+4,'text-anchor':'end',fill:'#8995ad','font-size':12},fmt(value,2))}
for(let i=0;i<5;i++){const time=xmin+(xmax-xmin)*i/4,x=X(time);el('line',{x1:x,y1:pad.t,x2:x,y2:height-pad.b,stroke:'#202a40'});el('text',{x,y:height-12,'text-anchor':'middle',fill:'#8995ad','font-size':11},new Date(time).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}))}
for(const key of series){const path=points.map((point,index)=>`${index?'L':'M'}${X(Number(point.t)).toFixed(2)},${Y(Number(point[key])).toFixed(2)}`).join(' ');el('path',{d:path,fill:'none',stroke:palette[key],'stroke-width':key==='equity_index'?3:2,'stroke-linejoin':'round','stroke-linecap':'round'})}
const cursor=el('line',{y1:pad.t,y2:height-pad.b,stroke:'#d5defa','stroke-dasharray':'4 5',visibility:'hidden'}),hit=el('rect',{x:pad.l,y:pad.t,width:width-pad.l-pad.r,height:height-pad.t-pad.b,fill:'transparent'});hit.addEventListener('pointermove',event=>{const rect=chart.getBoundingClientRect(),ratio=(event.clientX-rect.left)/rect.width,index=Math.max(0,Math.min(points.length-1,Math.round(ratio*(points.length-1)))),point=points[index],x=X(Number(point.t));cursor.setAttribute('x1',x);cursor.setAttribute('x2',x);cursor.setAttribute('visibility','visible');tooltip.innerHTML=`<strong>${new Date(point.t).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · Киев</strong><br>Лонг: ${fmt(point.long_index,3)}<br>Шорт: ${fmt(point.short_index,3)}<br>Баланс: ${fmt(point.equity_index,3)}<br>Итог: ${money(point.net_pnl)}`;tooltip.style.display='block';tooltip.style.left=`${Math.min(event.clientX-rect.left+12,rect.width-tooltip.offsetWidth-4)}px`;tooltip.style.top=`${Math.max(2,event.clientY-rect.top-tooltip.offsetHeight-10)}px`});hit.addEventListener('pointerleave',()=>{cursor.setAttribute('visibility','hidden');tooltip.style.display='none'});
const s=data.summary,stat=(label,value,cls='')=>`<div class="stat"><span>${label}</span><strong class="${cls}">${value}</strong></div>`;summaryNode.innerHTML=stat('Стартовый баланс',fmt(data.starting_balance,2)+' USDT')+stat('Лонг '+data.long_symbol,money(s.long_pnl),Number(s.long_pnl)>=0?'gain':'loss')+stat('Шорт '+data.short_symbol,money(s.short_pnl),Number(s.short_pnl)>=0?'gain':'loss')+stat('Общий итог',money(s.net_pnl),Number(s.net_pnl)>=0?'gain':'loss')+stat('Баланс после закрытия',fmt(s.ending_balance,4)+' USDT')+stat('Размеры позиций',fmt(s.long_notional,2)+' / '+fmt(s.short_notional,2)+' USDT');summaryNode.hidden=false;statusNode.textContent=`Вход ${new Date(s.entry_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · последний общий снимок ${new Date(s.last_aligned_time_ms).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv'})} · комиссия входа ${fmt(s.entry_fees,4)} USDT`;pointCount.textContent=`${data.raw_aligned_points} синхронизированных точек`;
}
async function loadData(){if(favoriteSymbolsDisabled){statusNode.textContent='Этот тикер выключен в настройках.';statusNode.classList.add('error');return}const [longSymbol,shortSymbol]=pairSelect.value.split('|'),params=new URLSearchParams(new FormData(form));if(favoriteView)for(const[key,value]of Object.entries(favoriteSavedParams))params.set(key,String(value));params.delete('pair');params.set('long_symbol',longSymbol);params.set('short_symbol',shortSymbol);const url=new URL('hedge-data.php',location.href);url.search=params.toString();statusNode.textContent='Загружаю и сопоставляю снимки…';statusNode.classList.remove('error');chart.replaceChildren();try{const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();if(!response.ok||data.status==='error')throw new Error(data.message||'Не удалось загрузить данные пары');if(data.status!=='ready'){render(data);return}render(data);const next=new URL(location.href);for(const[key,value]of params)next.searchParams.set(key,value);next.searchParams.set('pair',pairSelect.value);if(favoriteView)next.searchParams.set('favorite_id',favoriteId);history.replaceState(null,'',next);const basicParams=new URLSearchParams(location.search);for(const key of ['long_symbol','short_symbol','pair','favorite_id'])basicParams.delete(key);for(const key of ['start_day','end_day','balance','fee'])basicParams.set(key,params.get(key));document.querySelector('.view-nav a[href^="./?"]').href='./?'+basicParams.toString()}catch(error){summaryNode.hidden=true;statusNode.textContent=error.message;statusNode.classList.add('error')}}
form.addEventListener('submit',event=>{event.preventDefault();loadPairRanking();loadData()});form.addEventListener('change',event=>{if(event.target===pairSelect){loadData();return}loadPairRanking();loadData()});window.addEventListener('resize',()=>{if(window.currentHedgeData)render(window.currentHedgeData)});if(!favoriteView)loadPairRanking();loadData();
</script></body></html>
