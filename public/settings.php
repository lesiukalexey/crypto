<?php
declare(strict_types=1);
session_start();

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

function autotradingRiskSettingsPath(): string
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $root = getenv('BITGET_AUTOTRADING_DIR') ?: '/var/tmp';
    return rtrim($root, '/') . '/bitget-autotrading-' . $uid . '/risk-settings.json';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_autotrading_risk') {
        if (!in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) settingsRespond(['status' => 'error', 'error' => 'Настройку можно менять только с этого компьютера.'], 403);
        if (!isset($_SESSION['settings_csrf'])) $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
        if (!hash_equals((string) $_SESSION['settings_csrf'], (string) ($_POST['csrf'] ?? ''))) settingsRespond(['status' => 'error', 'error' => 'Сессия формы истекла. Обновите страницу и повторите действие.'], 403);
        $raw = trim((string) ($_POST['minimum_equity_percent'] ?? ''));
        if (!preg_match('/^(?:100(?:\\.0{1,2})?|(?:[1-9]\\d?)(?:\\.\\d{1,2})?)$/', $raw) || (float) $raw < 1 || (float) $raw > 100) settingsRespond(['status' => 'error', 'error' => 'Укажите долю максимального баланса от 1 до 100%.'], 422);
        try {
            $path = autotradingRiskSettingsPath();
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Не удалось создать каталог настроек автоторговли.');
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            if (is_link($directory) || fileowner($directory) !== $uid) throw new RuntimeException('Каталог настроек автоторговли принадлежит другому пользователю.');
            $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($temporary, json_encode(['minimum_equity_percent' => $raw], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) throw new RuntimeException('Не удалось записать настройки автоторговли.');
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Не удалось сохранить настройки автоторговли.'); }
            settingsRespond(['status' => 'ok', 'minimum_equity_percent' => $raw]);
        } catch (Throwable $error) {
            settingsRespond(['status' => 'error', 'error' => $error->getMessage()], 500);
        }
    }
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
$riskSettingsPath = autotradingRiskSettingsPath();
$riskSettings = is_file($riskSettingsPath) ? json_decode((string) file_get_contents($riskSettingsPath), true) : null;
$minimumEquityPercent = is_array($riskSettings) && is_numeric($riskSettings['minimum_equity_percent'] ?? null) && (float) $riskSettings['minimum_equity_percent'] >= 1 && (float) $riskSettings['minimum_equity_percent'] <= 100 ? (string) $riskSettings['minimum_equity_percent'] : '75';
$exportTickerSet = selectedTickerSet($config, (string) ($_GET['ticker_set'] ?? $_SESSION['settings_export_ticker_set'] ?? ''));
$_SESSION['settings_export_ticker_set'] = $exportTickerSet['id'];
$exportTimezone = new DateTimeZone('Europe/Kyiv');
$exportToday = new DateTimeImmutable('today', $exportTimezone);
$exportEndDay = (string) ($_GET['end_day'] ?? $exportToday->format('Y-m-d'));
$parsedExportEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $exportEndDay, $exportTimezone);
if ($parsedExportEnd === false || $parsedExportEnd->format('Y-m-d') !== $exportEndDay) $parsedExportEnd = $exportToday;
$exportEndDay = $parsedExportEnd->format('Y-m-d');
$exportStartDay = (string) ($_GET['start_day'] ?? $parsedExportEnd->sub(new DateInterval('P30D'))->format('Y-m-d'));
$parsedExportStart = DateTimeImmutable::createFromFormat('!Y-m-d', $exportStartDay, $exportTimezone);
if ($parsedExportStart === false || $parsedExportStart->format('Y-m-d') !== $exportStartDay) $parsedExportStart = $parsedExportEnd->sub(new DateInterval('P30D'));
$exportStartDay = $parsedExportStart->format('Y-m-d');
$settingsPages = [
    'data-export' => 'Выгрузить данные',
    'ticker-lists' => 'Списки тикеров',
    'active-tickers' => 'Активные тикеры',
    'general' => 'Общие настройки',
    'autotrading' => 'Автоторговля',
    'ranking-cache' => 'Кэш рейтингов',
];
$settingsPage = (string) ($_GET['page'] ?? 'data-export');
if (!isset($settingsPages[$settingsPage])) $settingsPage = 'ticker-lists';
$isGeneralPage = in_array($settingsPage, ['general', 'autotrading', 'ranking-cache'], true);
if (!isset($_SESSION['settings_csrf'])) $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= settingsH($settingsPages[$settingsPage]) ?> · Настройки · Bitget</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--line:#273149;--text:#e9eefb;--muted:#8995ad}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1000px;margin:0 auto;padding:36px 24px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.nav a:hover,.nav a[aria-current=page]{background:#263968;color:#fff}h1{margin:0 0 8px;font-size:30px}.intro,.settings-status{color:var(--muted)}.panel{margin-top:20px;padding:12px 20px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}.ticker-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 2px;border-bottom:1px solid var(--line)}.ticker-row:last-child{border-bottom:0}.ticker-name{font-weight:700}.ticker-state{font-size:12px;color:var(--muted)}.switch{position:relative;display:inline-flex;align-items:center;flex:0 0 48px;width:48px;height:28px}.switch input{position:absolute;width:1px;height:1px;opacity:0}.switch-track{width:48px;height:28px;border:1px solid #52617d;border-radius:999px;background:#263149;cursor:pointer;transition:.18s}.switch-track:after{content:"";display:block;width:20px;height:20px;margin:3px;border-radius:50%;background:#b7c1d5;transition:.18s}.switch input:checked+.switch-track{background:#176647;border-color:#42d6a4}.switch input:checked+.switch-track:after{transform:translateX(20px);background:#42d6a4}.switch input:focus-visible+.switch-track{outline:2px solid #8ea5ff;outline-offset:3px}.switch input:disabled+.switch-track{opacity:.55;cursor:progress}.settings-status{min-height:22px;margin:12px 2px 0;font-size:13px}.settings-status.error{color:#ff9eaa}@media(max-width:600px){.wrap{padding:24px 14px}.nav{width:100%}.nav a{flex:1;text-align:center}.panel{padding:10px 14px}}
</style>
<style>
.market-cap-status{margin:8px 0;color:var(--muted);font-size:12px;font-variant-numeric:tabular-nums}.ticker-row-main{min-width:0}.ticker-cap-rank{margin-top:2px;color:#aebbe0;font-size:12px;font-variant-numeric:tabular-nums}.ticker-cap-unranked{color:var(--muted)}@media(max-width:600px){.ticker-row{gap:10px}.ticker-cap-rank{max-width:calc(100vw - 118px);overflow-wrap:anywhere}}
.quote-sets-panel{padding:18px 20px}.quote-sets-panel h2{margin:2px 0 6px;font-size:20px}.quote-sets-intro{margin:0 0 14px;color:var(--muted);font-size:13px}.quote-set-create,.quote-set-add{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.quote-set-create input,.quote-set-add select{min-width:220px;padding:9px 11px;border:1px solid #34415e;border-radius:9px;background:#0c1222;color:var(--text);font:inherit}.quote-set-create button,.quote-set-add button{min-width:0;padding:9px 13px;border:0;border-radius:9px;background:#607df4;color:#fff;font:inherit;font-weight:700;cursor:pointer}.quote-set-list{display:grid;gap:12px;margin-top:16px}.quote-set-card{padding:14px;border:1px solid var(--line);border-radius:12px;background:#0e1527}.quote-set-card h3{margin:0 0 10px;font-size:16px}.quote-set-members{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 12px;padding:0;list-style:none}.quote-set-members li{display:inline-flex;align-items:center;gap:7px;padding:5px 8px;border:1px solid #34415e;border-radius:8px;color:#dce5ff;font-size:13px}.quote-set-remove{padding:0;border:0;background:none;color:#ff9eaa;font:inherit;cursor:pointer}.quote-set-empty{margin:0 0 12px;color:var(--muted);font-size:13px}.quote-set-status{min-height:20px;margin:10px 0 0;color:var(--muted);font-size:13px}.quote-set-status.error{color:#ff9eaa}.ranking-cache-reset{padding:9px 13px;border:1px solid #6b5263;border-radius:9px;background:#211b2c;color:#f0d7e1;font:inherit;font-weight:700;cursor:pointer}.ranking-cache-reset:disabled{cursor:progress;opacity:.65}.ranking-cache-status{min-height:20px;margin:10px 0 0;color:var(--muted);font-size:13px}.ranking-cache-status.error{color:#ff9eaa}.field{display:grid;gap:5px;color:var(--muted);font-size:13px}.field input{width:100%}@media(max-width:600px){.quote-sets-panel{padding:14px}.quote-set-create input,.quote-set-add select{min-width:0;width:100%}.quote-set-create button,.quote-set-add button{width:100%}}
.settings-subnav{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 4px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.settings-subnav a{padding:8px 13px;border-radius:8px;color:#b7c1d5;text-decoration:none}.settings-subnav a:hover,.settings-subnav a:focus-visible,.settings-subnav a[aria-current=page]{background:#263968;color:#fff;outline:none}.settings-section>h2{margin:2px 0 8px;font-size:20px}.settings-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:20px}.settings-card{display:block;padding:18px;border:1px solid var(--line);border-radius:14px;background:var(--panel);color:var(--text);text-decoration:none}.settings-card:hover{border-color:#607df4}.settings-card strong{display:block;margin-bottom:5px}.settings-card span{color:var(--muted);font-size:13px}.data-export-form{display:grid;grid-template-columns:minmax(220px,1.3fr) repeat(2,minmax(170px,.8fr)) auto;align-items:end;gap:12px}.data-export-form select,.data-export-form input{min-width:0;padding:10px 11px;border:1px solid #34415e;border-radius:9px;background:#0c1222;color:var(--text);font:inherit}.data-export-form button{min-height:42px;padding:9px 15px;border:0;border-radius:9px;background:#607df4;color:#fff;font:inherit;font-weight:700;cursor:pointer}.data-export-form button:disabled{cursor:progress;opacity:.65}.export-panel .quote-set-status{white-space:pre-line}.export-panel .quote-set-status[data-status=error]{color:#ff9eaa}.export-panel .quote-set-status[data-status=ready],.export-panel .quote-set-status[data-status=partial]{color:#a8f0cf}@media(max-width:700px){.settings-subnav{width:100%}.settings-subnav a{flex:1;text-align:center}.data-export-form{grid-template-columns:1fr}.data-export-form button{width:100%}}
</style>
</head>
<body><main class="wrap">
<nav class="nav" aria-label="Разделы"><a href="index.php">Главная</a><a href="basic-checks.php">Базовые проверки</a><a href="basic-checks-2.php">Базовые проверки 2</a><a href="hedging.php">Хеджирование</a><a href="settings.php" aria-current="page">Настройки</a><a href="autotrading.php?mode=paper">Автоторговля (Paper)</a><a href="autotrading.php?mode=demo">Автотрейдинг (демо)</a><a href="autotrading.php?mode=real">Автотрейдинг (real)</a><a href="autotrading-pi.php">Raspberry Pi</a></nav>
<h1><?= settingsH($settingsPages[$settingsPage]) ?></h1>
<nav class="settings-subnav" aria-label="Разделы настроек">
<a href="settings.php?page=data-export" <?= $settingsPage === 'data-export' ? 'aria-current="page"' : '' ?>>Выгрузить данные</a>
<a href="settings.php?page=ticker-lists" <?= $settingsPage === 'ticker-lists' ? 'aria-current="page"' : '' ?>>Списки тикеров</a>
<a href="settings.php?page=active-tickers" <?= $settingsPage === 'active-tickers' ? 'aria-current="page"' : '' ?>>Активные тикеры</a>
<a href="settings.php?page=general" <?= $isGeneralPage ? 'aria-current="page"' : '' ?>>Общие настройки</a>
</nav>
<?php if ($isGeneralPage): ?><nav class="settings-subnav" aria-label="Общие настройки">
<a href="settings.php?page=autotrading" <?= $settingsPage === 'autotrading' ? 'aria-current="page"' : '' ?>>Автоторговля</a>
<a href="settings.php?page=ranking-cache" <?= $settingsPage === 'ranking-cache' ? 'aria-current="page"' : '' ?>>Кэш рейтингов</a>
</nav><?php endif; ?>
<?php if ($settingsPage === 'general'): ?>
<div class="settings-cards"><a class="settings-card" href="settings.php?page=autotrading"><strong>Автоторговля</strong><span>Порог остановки стратегии при снижении баланса.</span></a><a class="settings-card" href="settings.php?page=ranking-cache"><strong>Кэш рейтингов</strong><span>Остановка расчётов и сброс кэша рейтингов.</span></a></div>
<?php elseif ($settingsPage === 'data-export'): ?>
<p class="intro">Загрузите минутные свечи Bitget для выбранного списка и периода. Новые котировки будут сохранены в базе и станут доступны графикам и расчётам.</p>
<section class="panel settings-section export-panel" aria-labelledby="data-export-heading"><h2 id="data-export-heading">Исторические данные</h2><form id="data-export-form" class="data-export-form"><label class="field">Список тикеров<select name="ticker_set" id="export-ticker-set" required><?php foreach ($exportTickerSet['sets'] as $set): $setMembers = array_values(array_intersect(array_map('strval', $set['symbols'] ?? []), array_keys($enabledLookup))); ?><option value="<?= settingsH((string) ($set['id'] ?? '')) ?>" data-symbol-count="<?= count($setMembers) ?>" <?= ($set['id'] ?? '') === $exportTickerSet['id'] ? 'selected' : '' ?>><?= settingsH((string) ($set['name'] ?? '')) ?></option><?php endforeach; ?></select></label><label class="field">С даты<input type="date" name="start_day" id="export-start-day" value="<?= settingsH($exportStartDay) ?>" required></label><label class="field">По дату<input type="date" name="end_day" id="export-end-day" value="<?= settingsH($exportEndDay) ?>" required></label><button type="submit" id="start-data-export">Выгрузить данные</button></form><p class="quote-sets-intro" id="export-ticker-count"><?= count($exportTickerSet['symbols']) ?> активных тикеров в выбранном списке</p><p class="quote-set-status" id="data-export-status" role="status" aria-live="polite"></p><button class="ranking-cache-reset" type="button" id="cancel-data-export" hidden>■ Остановить выгрузку</button></section>
<?php elseif ($settingsPage === 'ticker-lists'): ?>
<p class="intro">Создавайте списки тикеров и добавляйте в каждый нужные пары.</p>
<section class="panel quote-sets-panel settings-section" aria-labelledby="quote-sets-heading"><h2 id="quote-sets-heading">Списки тикеров</h2><p class="quote-sets-intro">Создавайте списки и добавляйте в каждый нужные тикеры по одному.</p><form class="quote-set-create" id="quote-set-create"><input type="text" name="name" maxlength="100" placeholder="Название списка" aria-label="Название нового списка тикеров" required><button type="submit">Создать список</button></form><p class="quote-set-status" id="quote-set-status" role="status" aria-live="polite"></p><div class="quote-set-list" id="quote-set-list"></div></section>
<?php elseif ($settingsPage === 'active-tickers'): ?>
<p class="intro">Выключенные тикеры продолжают собираться и сохраняться в MySQL, но скрыты на других страницах и исключены из расчётов. История котировок остаётся на месте.</p><p class="market-cap-status" id="market-cap-status" role="status" aria-live="polite">Загружаю капитализацию тикеров…</p>
<section class="panel settings-section" aria-labelledby="active-tickers-heading"><h2 id="active-tickers-heading">Активные тикеры</h2>
<?php foreach ($configuredSymbols as $symbol): $isEnabled = isset($enabledLookup[$symbol]); ?>
<div class="ticker-row"><div class="ticker-row-main"><div class="ticker-name"><?= settingsH($symbol) ?></div><div class="ticker-cap-rank" data-market-cap-for="<?= settingsH($symbol) ?>">Загружаю капитализацию…</div><div class="ticker-state" data-state-for="<?= settingsH($symbol) ?>"><?= $isEnabled ? 'Включён' : 'Выключен' ?></div></div><label class="switch"><input type="checkbox" role="switch" data-symbol="<?= settingsH($symbol) ?>" aria-label="Участвует тикер <?= settingsH($symbol) ?>" <?= $isEnabled ? 'checked' : '' ?>><span class="switch-track"></span></label></div>
<?php endforeach; ?>
</section>
<?php elseif ($settingsPage === 'autotrading'): ?>
<section class="panel settings-section" aria-labelledby="autotrading-risk-heading"><h2 id="autotrading-risk-heading">Автоторговля</h2><p class="quote-sets-intro">Остановить стратегию, если её баланс упадёт до указанной доли от личного максимума. Например, 75% означает закрытие позиций и остановку после снижения от максимума на 25%. Настройка действует на Paper, Demo и Real.</p><form id="autotrading-risk-form" class="quote-set-create"><input type="hidden" name="action" value="save_autotrading_risk"><input type="hidden" name="csrf" value="<?= settingsH((string) $_SESSION['settings_csrf']) ?>"><label class="field">Минимальный баланс от максимума, %<input name="minimum_equity_percent" type="number" min="1" max="100" step="0.01" value="<?= settingsH($minimumEquityPercent) ?>" required></label><button type="submit">Сохранить порог остановки</button></form><p class="quote-set-status" id="autotrading-risk-status" role="status" aria-live="polite"></p></section>
<?php elseif ($settingsPage === 'ranking-cache'): ?>
<section class="panel settings-section ranking-cache-panel" aria-labelledby="ranking-cache-heading"><h2 id="ranking-cache-heading">Кэш рейтингов</h2><p class="quote-sets-intro">Остановить текущие расчёты и очистить очередь, затем сбросить кэш для всех периодов и списков тикеров. История котировок и сохранённые общие рейтинги останутся. После сброса обновите уже открытые страницы рейтингов.</p><button class="ranking-cache-reset" type="button" id="reset-ranking-cache">🧹 Сбросить кэш рейтингов по всем данным</button><p class="ranking-cache-status" id="ranking-cache-status" role="status" aria-live="polite"></p></section>
<?php endif; ?>
<p class="settings-status" id="settings-status" role="status" aria-live="polite"></p>
</main><script>
const statusNode=document.querySelector('#settings-status');
const quoteSetStatusNode=document.querySelector('#quote-set-status');
const autotradingRiskForm=document.querySelector('#autotrading-risk-form');
const autotradingRiskStatus=document.querySelector('#autotrading-risk-status');
autotradingRiskForm?.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const button=autotradingRiskForm.querySelector('button');button.disabled=true;autotradingRiskStatus.textContent='Сохраняю…';
    try{
        const response=await fetch('settings.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:new URLSearchParams(new FormData(autotradingRiskForm))});
        const result=await response.json();
        if(!response.ok||result.status!=='ok')throw new Error(result.error||'Не удалось сохранить порог.');
        autotradingRiskForm.elements.minimum_equity_percent.value=result.minimum_equity_percent;
        autotradingRiskStatus.textContent=`Сохранено: остановка при снижении баланса до ${result.minimum_equity_percent}% от максимума. Работающий исполнитель применит порог в следующем цикле.`;
    }catch(error){autotradingRiskStatus.textContent=error.message||'Ошибка сохранения.';autotradingRiskStatus.classList.add('error')}
    finally{button.disabled=false}
});
const quoteSetListNode=document.querySelector('#quote-set-list');
const configuredQuoteSymbols=<?= json_encode($configuredSymbols, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let quoteSets=<?= json_encode($quoteSets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function renderQuoteSets(){
    if(!quoteSetListNode)return;
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
if(quoteSetListNode){
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
}
const rankingCacheResetButton=document.querySelector('#reset-ranking-cache');
const rankingCacheStatusNode=document.querySelector('#ranking-cache-status');
rankingCacheResetButton?.addEventListener('click',async()=>{
    rankingCacheResetButton.disabled=true;
    rankingCacheResetButton.textContent='Сбрасываю…';
    rankingCacheStatusNode.classList.remove('error');
    rankingCacheStatusNode.textContent='Останавливаю расчёты и сбрасываю кэш рейтингов…';
    let stopped=null;
    try{
        const stopResponse=await fetch('stop-ranking-jobs.php',{method:'POST',headers:{Accept:'application/json'},cache:'no-store'});
        stopped=await stopResponse.json();
        if(!stopResponse.ok||stopped.status!=='ready')throw new Error(stopped.message||'Не удалось остановить расчёты и очистить очередь.');
        const resetResponse=await fetch('reset-strategy-ranking-cache.php',{method:'POST',headers:{Accept:'application/json'},cache:'no-store'});
        const reset=await resetResponse.json();
        if(!resetResponse.ok||reset.status!=='ready')throw new Error(reset.message||'Не удалось сбросить кэш рейтингов.');
        rankingCacheStatusNode.textContent=`Расчёты остановлены: ${Number(stopped.stopped_processes)||0}; удалено из очереди: ${Number(stopped.cancelled_jobs)||0}. Кэш рейтингов сброшен для всех периодов и списков. Обновите открытые страницы рейтингов.`;
    }catch(error){
        rankingCacheStatusNode.textContent=stopped?.status==='ready'?'Расчёты остановлены, но кэш не сброшен: '+(error.message||'ошибка сброса кэша.'):(error.message||'Не удалось сбросить кэш рейтингов.');
        rankingCacheStatusNode.classList.add('error');
    }finally{
        rankingCacheResetButton.disabled=false;
        rankingCacheResetButton.textContent='🧹 Сбросить кэш рейтингов по всем данным';
    }
});
const dataExportForm=document.querySelector('#data-export-form');
const dataExportStatus=document.querySelector('#data-export-status');
const dataExportButton=document.querySelector('#start-data-export');
const dataExportCancel=document.querySelector('#cancel-data-export');
const dataExportTickerCount=document.querySelector('#export-ticker-count');
const exportJobKey='bitget-history-import-job-v1';
let dataExportJobId='';
const exportSleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function exportDateTime(ms){return new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Kyiv',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).format(new Date(ms))}
function updateExportTickerCount(){if(dataExportTickerCount)dataExportTickerCount.textContent=`${Number(dataExportForm?.elements.ticker_set.selectedOptions[0]?.dataset.symbolCount||0)} активных тикеров в выбранном списке`}
document.querySelector('#export-ticker-set')?.addEventListener('change',updateExportTickerCount);
updateExportTickerCount();
function renderDataExportProgress(job){
    dataExportStatus.dataset.status=job.status||'running';
    let message=job.message||'Загружаю исторические свечи…';
    if(job.status==='running'){
        message+=` · ${Number(job.progress||0).toLocaleString('ru-RU',{maximumFractionDigits:1})}%`;
        if(Number.isFinite(Number(job.candles_received)))message+=` · свечей получено: ${Number(job.candles_received).toLocaleString('ru-RU')}`;
        if(Number(job.progress)>0&&Number(job.progress)<100&&Number(job.started_at_ms)>0){const now=Date.now(),elapsed=now-Number(job.started_at_ms),remaining=elapsed*(100/Number(job.progress)-1);message+=`\nСтарт: ${exportDateTime(Number(job.started_at_ms))} · ожидаемое окончание: ${exportDateTime(now+remaining)} (оценка)`}
    }else if(Number(job.started_at_ms)>0){message+=`\nСтарт: ${exportDateTime(Number(job.started_at_ms))} · завершено: ${exportDateTime(Number(job.finished_at_ms)||Date.now())}`}
    if(Number.isFinite(Number(job.snapshots_inserted)))message+=`\nДобавлено минутных котировок в БД: ${Number(job.snapshots_inserted).toLocaleString('ru-RU')}`;
    dataExportStatus.textContent=message;
}
async function pollDataExport(jobId,request){
    dataExportJobId=jobId;dataExportButton.disabled=true;dataExportCancel.hidden=false;dataExportCancel.disabled=false;
    try{
        while(true){
            await exportSleep(1200);
            const response=await fetch('import-candles.php?job_id='+encodeURIComponent(jobId),{headers:{Accept:'application/json'},cache:'no-store'}),job=await response.json();
            if(!response.ok||job.status==='error')throw new Error(job.message||'Не удалось получить состояние выгрузки.');
            renderDataExportProgress(job);
            if(job.status==='cancelled'){sessionStorage.removeItem(exportJobKey);dataExportStatus.dataset.status='cancelled';dataExportJobId='';dataExportButton.disabled=false;dataExportCancel.hidden=true;return}
            if(job.status!=='ready'&&job.status!=='partial')continue;
            dataExportJobId='';sessionStorage.removeItem(exportJobKey);dataExportCancel.hidden=true;dataExportButton.disabled=false;
            const refreshBody=new URLSearchParams({start_day:request.start_day,end_day:request.end_day});
            try{const refreshed=await fetch('refresh-current-day-snapshot.php',{method:'POST',body:refreshBody,headers:{Accept:'application/json'},cache:'no-store'}),result=await refreshed.json();if(!refreshed.ok||result.status!=='ready')throw new Error(result.message||'Не удалось обновить срез расчётов.');dataExportStatus.textContent+=(job.status==='partial'?'\nЧасть тикеров не загружена: '+(job.failures||[]).map(item=>item.symbol).join(', '):'\nДанные сохранены; срез расчётов обновлён.')}
            catch(error){dataExportStatus.dataset.status='error';dataExportStatus.textContent+='\nСвечи загружены, но обновление среза не удалось: '+error.message}
            return;
        }
    }catch(error){dataExportStatus.dataset.status='error';dataExportStatus.textContent=error.message||'Не удалось загрузить данные.';sessionStorage.removeItem(exportJobKey);dataExportJobId='';dataExportButton.disabled=false;dataExportCancel.hidden=true}
}
dataExportForm?.addEventListener('submit',async event=>{
    event.preventDefault();dataExportStatus.classList.remove('error');
    if(!dataExportForm.reportValidity())return;
    const request={scope:'all',ticker_set:dataExportForm.elements.ticker_set.value,start_day:dataExportForm.elements.start_day.value,end_day:dataExportForm.elements.end_day.value};
    if(request.end_day<request.start_day){dataExportStatus.dataset.status='error';dataExportStatus.textContent='Дата окончания должна быть не раньше даты начала.';return}
    if(Number(dataExportForm.elements.ticker_set.selectedOptions[0]?.dataset.symbolCount||0)<1){dataExportStatus.dataset.status='error';dataExportStatus.textContent='В выбранном списке нет активных тикеров.';return}
    dataExportButton.disabled=true;dataExportStatus.dataset.status='running';dataExportStatus.textContent='Запускаю выгрузку минутных свечей…';
    try{const response=await fetch('import-candles.php',{method:'POST',body:new URLSearchParams(request),headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},cache:'no-store'}),result=await response.json();if(!response.ok||!result.job_id)throw new Error(result.message||'Не удалось запустить выгрузку.');sessionStorage.setItem(exportJobKey,JSON.stringify({job_id:result.job_id,request}));pollDataExport(result.job_id,request)}
    catch(error){dataExportStatus.dataset.status='error';dataExportStatus.textContent=error.message||'Не удалось запустить выгрузку.';dataExportButton.disabled=false}
});
dataExportCancel?.addEventListener('click',async()=>{
    if(!dataExportJobId)return;dataExportCancel.disabled=true;dataExportStatus.textContent='Останавливаю выгрузку…';
    try{const response=await fetch('import-candles.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:new URLSearchParams({action:'cancel',job_id:dataExportJobId}),cache:'no-store'}),result=await response.json();if(!response.ok||result.status!=='cancelled')throw new Error(result.message||'Не удалось остановить выгрузку.');sessionStorage.removeItem(exportJobKey);dataExportJobId='';dataExportButton.disabled=false;dataExportCancel.hidden=true;dataExportStatus.dataset.status='cancelled';dataExportStatus.textContent=result.message||'Выгрузка остановлена.'}
    catch(error){dataExportCancel.disabled=false;dataExportStatus.dataset.status='error';dataExportStatus.textContent=error.message||'Не удалось остановить выгрузку.'}
});
if(dataExportForm){
    try{const saved=JSON.parse(sessionStorage.getItem(exportJobKey)||'null');if(saved?.job_id&&saved?.request)pollDataExport(saved.job_id,saved.request);else fetch('import-candles.php?active=1',{headers:{Accept:'application/json'},cache:'no-store'}).then(response=>response.json()).then(job=>{if(job.status==='running'&&job.job_id&&job.ticker_set===dataExportForm.elements.ticker_set.value){const request={scope:job.scope||'all',ticker_set:job.ticker_set,start_day:job.request_start_day||job.start_day,end_day:job.request_end_day||job.end_day};sessionStorage.setItem(exportJobKey,JSON.stringify({job_id:job.job_id,request}));pollDataExport(job.job_id,request)}}).catch(()=>{})}
    catch(_){sessionStorage.removeItem(exportJobKey)}
}
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
    if(!marketCapStatusNode)return;
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
if(marketCapStatusNode)loadMarketCapRankings();
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
