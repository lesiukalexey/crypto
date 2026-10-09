<?php
declare(strict_types=1);
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$entryProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$entryThresholdsTenthsPercent = array_map('intval', $entryProfileConfig['thresholds_tenths_percent'] ?? []);
$entryWindows = array_map('intval', $entryProfileConfig['snapshot_windows'] ?? []);
$entryThresholdText = implode(', ', array_map(static fn (int $value): string => '±' . number_format($value / 10, 1, ',', ' '), $entryThresholdsTenthsPercent));
$entryWindowText = implode(', ', array_map(static fn (int $window): string => (string) $window, $entryWindows));
$immediateDirections = $entryProfileConfig['immediate_directions'] ?? [];
$immediateEntryText = implode(' и ', array_map(static fn (string $direction): string => 'сразу — 1 заявка на ' . ($direction === 'short' ? 'продажу' : 'покупку'), $immediateDirections));
$exitProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$exitRangeText = static function (array $profile): string {
    if (isset($profile['segments_basis_points'])) {
        return '0,1–1% с шагом 0,1%; 1–2% с шагом 0,2%; 2–3% с шагом 0,25%; 3–10% с шагом 0,5%; 10–20% с шагом 1%; 20–50% с шагом 2,5%';
    }
    $min = number_format(((int) $profile['min_tenths_percent']) / 10, 1, ',', ' ');
    $max = number_format(((int) $profile['max_tenths_percent']) / 10, 1, ',', ' ');
    $step = number_format(((int) $profile['step_tenths_percent']) / 10, 1, ',', ' ');
    return $min . '–' . $max . '% от стартового баланса с шагом ' . $step . '%';
};
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bitget — описание стратегий</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#a3aec4;--text:#e9eefb;--line:#273149}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.65 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:900px;margin:auto;padding:42px 24px}.eyebrow{color:#8ea5ff;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:700}h1{font-size:clamp(28px,4vw,40px);margin:5px 0 24px;letter-spacing:-.04em}.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:24px;margin-bottom:18px}.tag{display:inline-block;padding:4px 9px;border:1px solid #43557f;border-radius:999px;color:#b9c8ff;font-size:12px}h2{margin:12px 0 6px;font-size:22px}h3{margin:18px 0 5px;font-size:15px}p,li{color:var(--muted)}ul{padding-left:22px}a{color:#a9b9ff}.back{display:inline-block;margin:0 0 20px;text-decoration:none}.note{border-left:3px solid #f3b74e;padding:10px 14px;background:#272419;border-radius:4px 10px 10px 4px;color:#e8d9ae}.formula{font-variant-numeric:tabular-nums;color:#e9eefb}@media(max-width:600px){.wrap{padding:26px 14px}.panel{padding:18px}}
</style>
</head>
<body><main class="wrap"><a class="back" href="/">← Вернуться к графику и симуляции</a> <a class="back" href="settings.php">Настройки</a><div class="eyebrow">Bitget · симулятор</div><h1>Пользовательская Стратегия №1</h1>
<section class="panel"><span class="tag">Пользовательская Стратегия №1</span><h2>Импульсное движение вверх и вниз</h2>
<p>По умолчанию используются пороги входа <span class="formula"><?= h($entryThresholdText) ?> от стартового баланса</span> и окна в <span class="formula"><?= h($entryWindowText) ?></span> снимков. Один набор порогов и окон применяется к симметричному режиму ±, росту + и падению −. Поскольку обычная позиция открывается примерно на весь стартовый баланс при плече 1×, порог соответствует сопоставимому процентному движению Last. На главной странице для порога и окна можно задать несколько диапазонов с разными шагами. Тип «За X снимков назад» сравнивает цену с ценой ровно X снимков до текущего. Тип «За X снимков внутри» открывает позицию на первом снимке, где порог пересечён относительно любого из последних X снимков. Направление позиции можно оставить по движению порога или инвертировать: восходящий сигнал открывает короткую позицию, нисходящий — длинную. Длинная позиция открывается покупкой по Ask, короткая — продажей по Bid. Дополнительно доступны входы: <span class="formula"><?= h($immediateEntryText) ?></span>. Для них единственная заявка открывается по первому снимку периода и повторно не открывается. Все варианты сортируются по лучшему чистому результату среди вариантов выхода из списка «TakeProfit &amp; StopLoss» за выбранный период. Для каждого входа отдельно рассчитывается и показывается рейтинг выходов. Окна с пропусками котировок более 120 секунд не используются.</p>
<ul>
<li>Вход срабатывает при новом пересечении порога; пока цена остается за порогом, повторного входа нет.</li>
<li>Порог входа определяет момент и направление открытия позиции. Порог выхода выбирается отдельно из автоматических вариантов Take Profit и Stop Loss ниже.</li>
<li>Тип выхода выбирает немедленное закрытие TP и SL, проверку обоих порогов с учетом интервалов или отдельную проверку только TP либо только SL. При проверке с учетом интервалов порог должен оставаться достигнутым через выбранное число интервалов; иначе позиция остаётся открытой.</li>
<li>После закрытия стратегия ждет нового пересечения порога. Если позиция не закрылась до конца дня, она условно закрывается по последнему снимку.</li>
</ul>
<h3>Мартингейл</h3><p>На главной странице доступны четыре режима: простой по лоссу, обратный по лоссу, простой по профиту и обратный по профиту. В вариантах по лоссу новый шаг запускается после Stop Loss; в вариантах по профиту — после Take Profit. Простой режим продолжает в ту же сторону, а обратный закрывает позицию и открывает новую в противоположную сторону. На каждом шаге номинал удваивается с ограничением доступного баланса. Рейтинг отдельно считает два варианта TP/SL: денежные цели растут пропорционально сумме позиции либо остаются рассчитанными от стартового баланса. Число попыток включает начальный вход. «Сразу» открывает следующий шаг на снимке срабатывания порога; «по порогу входа» ждёт нового сигнала выбранного порога в нужном направлении. Если подходящего сигнала или свободного баланса нет, следующий шаг пропускается.</p>
<p class="note">Это экспериментальное правило: в сохраненной истории сигнал продолжения движения заметнее всего проявлялся на XAGUSDT и не был одинаково устойчив на остальных парах. Результат симуляции на прошлых снимках не гарантирует такого же поведения в будущем.</p>
</section>
<section class="panel"><span class="tag">Подбор выхода</span><h2>Рейтинг Take Profit и Stop Loss</h2>
<p>Для каждой комбинации входа, выбранной пары, периода, стартового баланса и комиссии программа перебирает варианты выхода по сохраненным снимкам и сортирует их по итоговому чистому результату. TP/SL задаются в процентах от стартового баланса и преобразуются в фиксированную сумму USDT на весь расчёт. В SAVE MODE в рейтинги и выпадающие списки попадают только варианты минимум с тремя сделками; при отключенном SAVE MODE достаточно одной сделки. Порог входа оценивается по лучшему результату только среди таких вариантов выхода.</p>
<ul>
<li>Стратегия с фиксированными Take Profit и Stop Loss: по умолчанию каждый порог перебирается по сетке <span class="formula"><?= h($exitRangeText($exitProfileConfig['fixed'])) ?></span>. Значения сетки имеют точность 0,01%. На главной странице можно задать несколько диапазонов TP и SL с разными шагами и точностью 0,1%. Проверяются все сочетания Take Profit и Stop Loss.</li>
<li>Стратегия с фиксацией только убытка: по умолчанию Stop Loss перебирается в диапазоне <span class="formula"><?= h($exitRangeText($exitProfileConfig['loss'])) ?></span>; прибыль не фиксируется.</li>
<li>Стратегия с фиксацией только прибыли: по умолчанию Take Profit перебирается в диапазоне <span class="formula"><?= h($exitRangeText($exitProfileConfig['profit'])) ?></span>; убыток не фиксируется. Незакрытая на конце периода позиция оценивается по последней котировке.</li>
<li>В каждой автоматической группе варианты упорядочены по чистому результату; отображаются до 500 лучших сочетаний, прошедших порог SAVE MODE — три сделки, либо одну сделку при отключённом режиме.</li>
</ul>
<p class="note">Рейтинг показывает результат на выбранном историческом периоде. Подбор параметров по тем же данным может переобучиться на прошлые движения и не предсказывает будущую доходность.</p>
</section>
<p><a href="/">← Вернуться к графику и симуляции</a></p></main></body></html>
