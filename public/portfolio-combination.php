<?php
declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$query = $_GET;
$query['combination_detail'] = '1';
$endpoint = 'portfolio-combination-data.php?' . http_build_query($query);
$entryLabel = trim((string) ($_GET['entry_label'] ?? $_GET['entry_config'] ?? 'Порог входа'));
$exitLabel = trim((string) ($_GET['exit_label'] ?? $_GET['exit_config'] ?? 'TakeProfit & StopLoss'));
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Результаты комбинации по тикерам</title>
<style>
:root{color-scheme:dark;--bg:#101723;--panel:#182235;--line:#2c3b55;--text:#e8edf6;--muted:#a4b1c7;--green:#7ee0ad;--red:#ff8e99}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,sans-serif}.wrap{width:min(1500px,calc(100% - 32px));margin:32px auto}.panel{padding:22px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}h1{margin:0 0 18px;font-size:24px}.pair{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:0 0 18px}.pair div{padding:12px;border:1px solid var(--line);border-radius:10px;overflow-wrap:anywhere}.pair dt{color:var(--muted);font-size:13px}.pair dd{margin:5px 0 0;font-weight:700}.status{margin:0 0 12px;color:var(--muted)}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:10px}table{width:100%;border-collapse:collapse;min-width:650px}th,td{padding:11px 13px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}th{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em}td.numeric{text-align:right;font-variant-numeric:tabular-nums}.positive{color:var(--green)}.negative{color:var(--red)}.no-data{color:var(--muted)}tr:last-child td{border-bottom:0}.back{display:inline-block;margin-bottom:14px;color:#b8c7ff}.page-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 20px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.page-nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.page-nav a:hover{background:#1b2843;color:#e9eefb}@media(max-width:600px){.page-nav{width:100%}.page-nav a{flex:1;text-align:center}}.error{color:var(--red)}
</style>
</head>
<body><main class="wrap"><a class="back" href="./">← Назад к рейтингу</a><nav class="page-nav" aria-label="Разделы"><a href="./">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php">Настройки</a></nav><section class="panel">
<h1>Итог выбранной комбинации по каждому тикеру</h1>
<dl class="pair"><div><dt>Лучший порог входа</dt><dd><?= h($entryLabel) ?></dd></div><div><dt>TakeProfit &amp; StopLoss</dt><dd><?= h($exitLabel) ?></dd></div></dl>
<p class="status" id="status" role="status" aria-live="polite">Подготавливаю результаты по тикерам…</p>
<div class="table-wrap"><table><thead><tr><th>Тикер</th><th>Порог входа</th><th>TakeProfit &amp; StopLoss</th><th class="numeric">Итог тикера</th><th class="numeric">Ордеров</th><th class="numeric">Цепочек</th></tr></thead><tbody id="results"></tbody></table></div>
</section></main>
<script>
const endpoint=<?= json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const statusNode=document.querySelector('#status');
const bodyNode=document.querySelector('#results');
const money=value=>{if(value===null||value===undefined)return 'нет надежных данных';const n=Number(value);return (n>0?'+':'')+n.toFixed(4).replace('.',',')+' USDT'};
async function load(){try{for(;;){const response=await fetch(endpoint,{headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||data.status==='error')throw new Error(data.message||'Не удалось получить результаты комбинации');if(data.status==='pending'){statusNode.textContent='Рассчитываю выбранную пару для всех тикеров…';await new Promise(resolve=>setTimeout(resolve,1200));continue;}if(data.status!=='ready')throw new Error('Результаты комбинации пока недоступны');bodyNode.replaceChildren(...data.tickers.map(item=>{const row=document.createElement('tr');const values=[item.symbol,data.entry_label,data.exit_label,money(item.pnl),String(item.trade_count),String(item.chain_count??item.trade_count)];values.forEach((value,index)=>{const cell=document.createElement('td');cell.textContent=value;if(index===3){cell.className='numeric';if(item.pnl!==null)cell.classList.add(Number(item.pnl)>=0?'positive':'negative');else cell.classList.add('no-data');}if(index>=4)cell.className='numeric';row.append(cell);});return row;}));statusNode.textContent='Тикеров: '+data.tickers.length+' · минимум независимых выборок для рейтинга: '+data.min_trades+'. Показаны все доступные результаты; без закрытых позиций указано «нет надёжных данных».';return;}}catch(error){statusNode.textContent=error.message;statusNode.classList.add('error');}}
load();
</script>
</body></html>
