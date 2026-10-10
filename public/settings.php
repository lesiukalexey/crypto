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
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['set_create', 'set_add_symbol', 'set_remove_symbol'], true)) {
        try {
            $sets = updateQuoteSets($config, static function (array $sets) use ($config, $action): array {
                $name = trim((string) ($_POST['name'] ?? ''));
                $setId = trim((string) ($_POST['set_id'] ?? ''));
                $symbol = strtoupper(trim((string) ($_POST['symbol'] ?? '')));
                if ($action === 'set_create') {
                    $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
                    if ($name === '' || $nameLength > 100) throw new InvalidArgumentException('Введите название списка длиной до 100 символов.');
                    $normalizedName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                    foreach ($sets as $set) {
                        $existingName = (string) ($set['name'] ?? '');
                        $normalizedExisting = function_exists('mb_strtolower') ? mb_strtolower($existingName, 'UTF-8') : strtolower($existingName);
                        if ($normalizedExisting === $normalizedName) throw new InvalidArgumentException('Список с таким названием уже существует.');
                    }
                    $sets[] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'symbols' => []];
                    return $sets;
                }

                $setIndex = null;
                foreach ($sets as $index => $set) {
                    if (($set['id'] ?? '') === $setId) { $setIndex = $index; break; }
                }
                if ($setIndex === null) throw new InvalidArgumentException('Список тикеров не найден.');
                $configured = configuredTickerSymbols($config);
                if (!in_array($symbol, $configured, true)) throw new InvalidArgumentException('Укажите настроенную торговую пару.');
                $members = array_values(array_unique(array_map('strval', $sets[$setIndex]['symbols'] ?? [])));
                if ($action === 'set_add_symbol') {
                    if (!in_array($symbol, $members, true)) $members[] = $symbol;
                    sort($members, SORT_STRING);
                } else {
                    $members = array_values(array_filter($members, static fn (string $item): bool => $item !== $symbol));
                }
                $sets[$setIndex]['symbols'] = $members;
                return $sets;
            });
            settingsRespond(['status' => 'ok', 'sets' => $sets]);
        } catch (InvalidArgumentException $error) {
            settingsRespond(['status' => 'error', 'error' => $error->getMessage()], 422);
        } catch (Throwable) {
            settingsRespond(['status' => 'error', 'error' => 'Не удалось сохранить список тикеров.'], 500);
        }
    }

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
$quoteSets = loadQuoteSets($config);
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
.quote-sets-panel{padding:18px 20px}.quote-sets-panel h2{margin:2px 0 6px;font-size:20px}.quote-sets-intro{margin:0 0 14px;color:var(--muted);font-size:13px}.quote-set-create,.quote-set-add{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.quote-set-create input,.quote-set-add select{min-width:220px;padding:9px 11px;border:1px solid #34415e;border-radius:9px;background:#0c1222;color:var(--text);font:inherit}.quote-set-create button,.quote-set-add button{min-width:0;padding:9px 13px;border:0;border-radius:9px;background:#607df4;color:#fff;font:inherit;font-weight:700;cursor:pointer}.quote-set-list{display:grid;gap:12px;margin-top:16px}.quote-set-card{padding:14px;border:1px solid var(--line);border-radius:12px;background:#0e1527}.quote-set-card h3{margin:0 0 10px;font-size:16px}.quote-set-members{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 12px;padding:0;list-style:none}.quote-set-members li{display:inline-flex;align-items:center;gap:7px;padding:5px 8px;border:1px solid #34415e;border-radius:8px;color:#dce5ff;font-size:13px}.quote-set-remove{padding:0;border:0;background:none;color:#ff9eaa;font:inherit;cursor:pointer}.quote-set-empty{margin:0 0 12px;color:var(--muted);font-size:13px}.quote-set-status{min-height:20px;margin:10px 0 0;color:var(--muted);font-size:13px}.quote-set-status.error{color:#ff9eaa}@media(max-width:600px){.quote-sets-panel{padding:14px}.quote-set-create input,.quote-set-add select{min-width:0;width:100%}.quote-set-create button,.quote-set-add button{width:100%}}
.settings-subnav{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 4px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.settings-subnav a{padding:8px 13px;border-radius:8px;color:#b7c1d5;text-decoration:none}.settings-subnav a:hover,.settings-subnav a:focus-visible{background:#263968;color:#fff;outline:none}.settings-section{scroll-margin-top:18px}.settings-section>h2{margin:2px 0 8px;font-size:20px}@media(max-width:600px){.settings-subnav{width:100%}.settings-subnav a{flex:1;text-align:center}}
</style>
</head>
<body><main class="wrap">
<nav class="nav" aria-label="Разделы"><a href="./">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php" aria-current="page">Настройки</a></nav>
<h1>Настройки</h1><p class="intro">Выключенные тикеры продолжают собираться и сохраняться в MySQL, но скрыты на других страницах и исключены из расчётов. История котировок и избранное остаются на месте.</p><p class="market-cap-status" id="market-cap-status" role="status" aria-live="polite">Загружаю капитализацию тикеров…</p>
<nav class="settings-subnav" aria-label="Разделы настроек"><a href="#ticker-lists">Списки тикеров</a><a href="#active-tickers">Активные тикеры</a></nav>
<section class="panel quote-sets-panel settings-section" id="ticker-lists" aria-labelledby="quote-sets-heading"><h2 id="quote-sets-heading">Списки тикеров</h2><p class="quote-sets-intro">Создавайте списки и добавляйте в каждый нужные тикеры по одному.</p><form class="quote-set-create" id="quote-set-create"><input type="text" name="name" maxlength="100" placeholder="Название списка" aria-label="Название нового списка тикеров" required><button type="submit">Создать список</button></form><p class="quote-set-status" id="quote-set-status" role="status" aria-live="polite"></p><div class="quote-set-list" id="quote-set-list"></div></section>
<section class="panel settings-section" id="active-tickers" aria-labelledby="active-tickers-heading"><h2 id="active-tickers-heading">Активные тикеры</h2>
<?php foreach ($configuredSymbols as $symbol): $isEnabled = isset($enabledLookup[$symbol]); ?>
<div class="ticker-row"><div class="ticker-row-main"><div class="ticker-name"><?= settingsH($symbol) ?></div><div class="ticker-cap-rank" data-market-cap-for="<?= settingsH($symbol) ?>">Загружаю капитализацию…</div><div class="ticker-state" data-state-for="<?= settingsH($symbol) ?>"><?= $isEnabled ? 'Включён' : 'Выключен' ?></div></div><label class="switch"><input type="checkbox" role="switch" data-symbol="<?= settingsH($symbol) ?>" aria-label="Участвует тикер <?= settingsH($symbol) ?>" <?= $isEnabled ? 'checked' : '' ?>><span class="switch-track"></span></label></div>
<?php endforeach; ?>
</section><p class="settings-status" id="settings-status" role="status" aria-live="polite"></p>
</main><script>
const statusNode=document.querySelector('#settings-status');
const quoteSetStatusNode=document.querySelector('#quote-set-status');
const quoteSetListNode=document.querySelector('#quote-set-list');
const configuredQuoteSymbols=<?= json_encode($configuredSymbols, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let quoteSets=<?= json_encode($quoteSets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function renderQuoteSets(){
    quoteSetListNode.replaceChildren();
    for(const set of quoteSets){
        const card=document.createElement('article');card.className='quote-set-card';
        const heading=document.createElement('h3');heading.textContent=set.name;card.append(heading);
        const members=Array.isArray(set.symbols)?set.symbols:[];
        if(members.length){
            const list=document.createElement('ul');list.className='quote-set-members';
            for(const symbol of members){
                const item=document.createElement('li');
                const label=document.createElement('span');label.textContent=symbol;
                const remove=document.createElement('button');remove.type='button';remove.className='quote-set-remove';remove.textContent='×';remove.title=`Удалить ${symbol} из списка ${set.name}`;remove.setAttribute('aria-label',remove.title);remove.dataset.setId=set.id;remove.dataset.removeSymbol=symbol;
                item.append(label,remove);list.append(item);
            }
            card.append(list);
        }else{
            const empty=document.createElement('p');empty.className='quote-set-empty';empty.textContent='В списке пока нет тикеров.';card.append(empty);
        }
        const form=document.createElement('form');form.className='quote-set-add';form.dataset.setId=set.id;
        const select=document.createElement('select');select.name='symbol';select.setAttribute('aria-label',`Добавить тикер в список ${set.name}`);select.required=true;
        const available=configuredQuoteSymbols.filter(symbol=>!members.includes(symbol));
        if(available.length){
            const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Выберите тикер';placeholder.disabled=true;placeholder.selected=true;select.append(placeholder);
            for(const symbol of available){const option=document.createElement('option');option.value=symbol;option.textContent=symbol;select.append(option)}
        }else{
            const option=document.createElement('option');option.value='';option.textContent='Все настроенные пары уже в наборе';select.append(option);select.disabled=true;
        }
        const add=document.createElement('button');add.type='submit';add.textContent='Добавить тикер';add.disabled=!available.length;
        form.append(select,add);card.append(form);quoteSetListNode.append(card);
    }
}
async function saveQuoteSetAction(values){
    quoteSetStatusNode.classList.remove('error');quoteSetStatusNode.textContent='Сохраняю список…';
    try{
        const response=await fetch('settings.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:new URLSearchParams(values)});
        const result=await response.json();
        if(!response.ok||result.status!=='ok')throw new Error(result.error||'Не удалось сохранить список.');
        quoteSets=result.sets;renderQuoteSets();quoteSetStatusNode.textContent='Список сохранён.';return true;
    }catch(error){quoteSetStatusNode.textContent=error.message||'Ошибка сохранения списка.';quoteSetStatusNode.classList.add('error');return false}
}
renderQuoteSets();
document.querySelector('#quote-set-create').addEventListener('submit',async event=>{
    event.preventDefault();const form=event.currentTarget;const input=form.elements.namedItem('name');
    if(await saveQuoteSetAction({action:'set_create',name:input.value.trim()})){form.reset();input.focus()}
});
quoteSetListNode.addEventListener('submit',async event=>{
    const form=event.target.closest('.quote-set-add');if(!form)return;event.preventDefault();
    const symbol=form.elements.namedItem('symbol').value;if(!symbol)return;
    await saveQuoteSetAction({action:'set_add_symbol',set_id:form.dataset.setId,symbol});
});
quoteSetListNode.addEventListener('click',event=>{
    const button=event.target.closest('[data-remove-symbol]');if(!button)return;
    saveQuoteSetAction({action:'set_remove_symbol',set_id:button.dataset.setId,symbol:button.dataset.removeSymbol});
});
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
