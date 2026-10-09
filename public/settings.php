<?php
declare(strict_types=1);

require_once __DIR__ . '/ticker-settings-lib.php';
$root = dirname(__DIR__);
$config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);

function settingsRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function settingsH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $symbol = strtoupper(trim((string) ($_POST['symbol'] ?? '')));
    $enabled = filter_var($_POST['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enabled === null) settingsRespond(['status' => 'error', 'error' => 'Укажите состояние тумблера.'], 422);
    try {
        $enabledSymbols = saveTickerEnabledState($config, $symbol, $enabled);
        settingsRespond(['status' => 'ok', 'enabled_symbols' => $enabledSymbols]);
    } catch (InvalidArgumentException $error) {
        settingsRespond(['status' => 'error', 'error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        settingsRespond(['status' => 'error', 'error' => 'Не удалось сохранить настройку тикера.'], 500);
    }
}

$configuredSymbols = configuredTickerSymbols($config);
$enabledLookup = array_fill_keys(enabledTickerSymbols($config), true);
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Настройки тикеров · Bitget</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--line:#273149;--text:#e9eefb;--muted:#8995ad}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1000px;margin:0 auto;padding:36px 24px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.nav a:hover,.nav a[aria-current=page]{background:#263968;color:#fff}h1{margin:0 0 8px;font-size:30px}.intro,.settings-status{color:var(--muted)}.panel{margin-top:20px;padding:12px 20px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}.ticker-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 2px;border-bottom:1px solid var(--line)}.ticker-row:last-child{border-bottom:0}.ticker-name{font-weight:700}.ticker-state{font-size:12px;color:var(--muted)}.switch{position:relative;display:inline-flex;align-items:center;flex:0 0 48px;width:48px;height:28px}.switch input{position:absolute;width:1px;height:1px;opacity:0}.switch-track{width:48px;height:28px;border:1px solid #52617d;border-radius:999px;background:#263149;cursor:pointer;transition:.18s}.switch-track:after{content:"";display:block;width:20px;height:20px;margin:3px;border-radius:50%;background:#b7c1d5;transition:.18s}.switch input:checked+.switch-track{background:#176647;border-color:#42d6a4}.switch input:checked+.switch-track:after{transform:translateX(20px);background:#42d6a4}.switch input:focus-visible+.switch-track{outline:2px solid #8ea5ff;outline-offset:3px}.switch input:disabled+.switch-track{opacity:.55;cursor:progress}.settings-status{min-height:22px;margin:12px 2px 0;font-size:13px}.settings-status.error{color:#ff9eaa}@media(max-width:600px){.wrap{padding:24px 14px}.nav{width:100%}.nav a{flex:1;text-align:center}.panel{padding:10px 14px}}
</style>
<style>
.market-cap-status{margin:8px 0;color:var(--muted);font-size:12px;font-variant-numeric:tabular-nums}.ticker-row-main{min-width:0}.ticker-cap-rank{margin-top:2px;color:#aebbe0;font-size:12px;font-variant-numeric:tabular-nums}.ticker-cap-unranked{color:var(--muted)}@media(max-width:600px){.ticker-row{gap:10px}.ticker-cap-rank{max-width:calc(100vw - 118px);overflow-wrap:anywhere}}
</style>
</head>
<body><main class="wrap">
<nav class="nav" aria-label="Разделы"><a href="./">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php" aria-current="page">Настройки</a></nav>
<h1>Настройки</h1><p class="intro">Выключенные тикеры продолжают собираться и сохраняться в MySQL, но скрыты на других страницах и исключены из расчётов. История котировок и избранное остаются на месте.</p><p class="market-cap-status" id="market-cap-status" role="status" aria-live="polite">Загружаю капитализацию тикеров…</p>
<section class="panel" aria-label="Доступность тикеров">
<?php foreach ($configuredSymbols as $symbol): $isEnabled = isset($enabledLookup[$symbol]); ?>
<div class="ticker-row"><div class="ticker-row-main"><div class="ticker-name"><?= settingsH($symbol) ?></div><div class="ticker-cap-rank" data-market-cap-for="<?= settingsH($symbol) ?>">Загружаю капитализацию…</div><div class="ticker-state" data-state-for="<?= settingsH($symbol) ?>"><?= $isEnabled ? 'Включён' : 'Выключен' ?></div></div><label class="switch"><input type="checkbox" role="switch" data-symbol="<?= settingsH($symbol) ?>" aria-label="Участвует тикер <?= settingsH($symbol) ?>" <?= $isEnabled ? 'checked' : '' ?>><span class="switch-track"></span></label></div>
<?php endforeach; ?>
</section><p class="settings-status" id="settings-status" role="status" aria-live="polite"></p>
</main><script>
const statusNode=document.querySelector('#settings-status');
const marketCapStatusNode=document.querySelector('#market-cap-status');
const marketCapNumberFormat=new Intl.NumberFormat('ru-RU',{notation:'compact',maximumFractionDigits:1});
function renderMarketCapRankings(data){
    for(const [symbol,item] of Object.entries(data.items||{})){
        const row=document.querySelector(`[data-market-cap-for="${CSS.escape(symbol)}"]`);
        if(!row)continue;
        row.classList.toggle('ticker-cap-unranked',!Number.isInteger(item.rank));
        row.textContent=Number.isInteger(item.rank)
            ? `Капитализация: № ${item.rank} из ${item.universe_size} с данными · ${marketCapNumberFormat.format(Number(item.market_cap_usd))} $`
            : `Капитализация: без места · ${item.unranked_reason||'нет данных'}`;
    }
    if(data.updated_at){
        const updated=new Date(data.updated_at).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
        marketCapStatusNode.textContent=`В настройках ${data.configured_count} тикеров · капитализация доступна для ${data.ranked_count} · USD · обновлено ${updated} · Bitget и CoinGecko${data.refreshing?' · обновляю…':''}`;
    }else{
        marketCapStatusNode.textContent=data.refreshing?'Получаю данные капитализации…':'Капитализация временно недоступна.';
    }
}
async function loadMarketCapRankings(){
    try{
        const response=await fetch('market-cap-rankings.php',{headers:{Accept:'application/json'}});
        const data=await response.json();
        if(!response.ok)throw new Error('Не удалось загрузить рейтинг капитализации.');
        renderMarketCapRankings(data);
        if(data.refreshing)setTimeout(loadMarketCapRankings,2000);
    }catch(error){
        marketCapStatusNode.textContent='Не удалось загрузить капитализацию; попробуйте обновить страницу.';
        document.querySelectorAll('[data-market-cap-for]').forEach(row=>row.textContent='Капитализация временно недоступна.');
    }
}
loadMarketCapRankings();
document.querySelectorAll('input[data-symbol]').forEach(toggle=>toggle.addEventListener('change',async()=>{
    const desired=toggle.checked,previous=!desired,symbol=toggle.dataset.symbol;
    toggle.disabled=true;statusNode.classList.remove('error');statusNode.textContent='Сохраняю…';
    try{
        const response=await fetch('settings.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:new URLSearchParams({symbol,enabled:String(desired)})});
        const result=await response.json();
        if(!response.ok||result.status!=='ok')throw new Error(result.error||'Не удалось сохранить настройку.');
        document.querySelector(`[data-state-for="${CSS.escape(symbol)}"]`).textContent=desired?'Включён':'Выключен';
        statusNode.textContent=`${symbol}: ${desired?'включён':'выключен'}. Обновите открытые страницы, чтобы применить настройку.`;
    }catch(error){toggle.checked=previous;statusNode.textContent=error.message||'Ошибка сохранения.';statusNode.classList.add('error')}
    finally{toggle.disabled=false}
}));
</script></body></html>
