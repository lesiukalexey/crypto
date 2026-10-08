<?php
declare(strict_types=1);
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$entryProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$entryThresholdsCents = array_map('intval', $entryProfileConfig['thresholds_cents'] ?? []);
$entryWindows = array_map('intval', $entryProfileConfig['snapshot_windows'] ?? []);
$entryThresholdText = implode(', ', array_map(static fn (int $cents): string => '±' . number_format($cents / 100, 2, ',', ' '), $entryThresholdsCents));
$entryWindowText = implode(', ', array_map(static fn (int $window): string => (string) $window, $entryWindows));
$immediateDirections = $entryProfileConfig['immediate_directions'] ?? [];
$immediateEntryText = implode(' и ', array_map(static fn (string $direction): string => 'сразу — 1 заявка на ' . ($direction === 'short' ? 'продажу' : 'покупку'), $immediateDirections));
$exitProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$exitRangeText = static function (array $profile): string {
    $min = number_format(((int) $profile['min_cents']) / 100, 2, ',', ' ');
    $max = number_format(((int) $profile['max_cents']) / 100, 2, ',', ' ');
    $step = number_format(((int) $profile['step_cents']) / 100, 2, ',', ' ');
    return $min . '–' . $max . ' USDT с шагом ' . $step;
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
<body><main class="wrap"><a class="back" href="/">← Вернуться к графику и симуляции</a><div class="eyebrow">Bitget · симулятор</div><h1>Пользовательская Стратегия №1</h1>
<section class="panel"><span class="tag">Пользовательская Стратегия №1</span><h2>Импульсное движение вверх и вниз</h2>
<p>По умолчанию список «Порог входа» содержит <?= count($entryThresholdsCents) * count($entryWindows) ?> комбинаций движения Last: пороги <span class="formula"><?= h($entryThresholdText) ?> USDT</span> для окон в <span class="formula"><?= h($entryWindowText) ?></span> снимков. На главной странице можно задать собственные диапазоны «от / до / шаг» для порогов и окон. Сигнал возникает при пересечении порога вверх или вниз; длинная позиция открывается покупкой по Ask, короткая — продажей по Bid. Дополнительно доступны входы: <span class="formula"><?= h($immediateEntryText) ?></span>. Для них единственная заявка открывается по первому снимку периода и повторно не открывается. Все варианты сортируются по лучшему чистому результату среди вариантов выхода из списка «TakeProfit &amp; StopLoss» за выбранный период. Для каждого входа отдельно рассчитывается и показывается рейтинг выходов. Окна с пропусками котировок более 120 секунд не используются.</p>
<ul>
<li>Вход срабатывает при новом пересечении порога; пока цена остается за порогом, повторного входа нет.</li>
<li>Порог входа определяет момент и направление открытия позиции. Порог выхода выбирается отдельно из автоматических вариантов Take Profit и Stop Loss ниже.</li>
<li>После закрытия стратегия ждет нового пересечения порога. Если позиция не закрылась до конца дня, она условно закрывается по последнему снимку.</li>
</ul>
<h3>Мартингейл</h3><p>На главной странице можно включить простой или обратный мартингейл; по умолчанию он отключен. Число попыток включает начальный вход, а начальный номинал автоматически подбирается под всю серию с учетом заданного Stop Loss и комиссии. Каждый следующий размер ограничивается свободным балансом после учета результата и комиссии. В простом режиме стоп не закрывает позицию: к ней добавляется сумма, равная её текущему номиналу, и TP/SL всей позиции растут пропорционально её фактическому размеру. В обратном режиме позиция закрывается на стопе и открывается в обратную сторону с удвоенным номиналом, ограниченным доступным балансом. «Сразу» открывает следующий шаг на снимке срабатывания стопа; «по порогу входа» ждёт нового сигнала выбранного порога в нужном направлении. Если сигнала или свободного баланса нет, следующий шаг пропускается, а открытая позиция закрывается по последнему снимку периода.</p>
<p class="note">Это экспериментальное правило: в сохраненной истории сигнал продолжения движения заметнее всего проявлялся на XAGUSDT и не был одинаково устойчив на остальных парах. Результат симуляции на прошлых снимках не гарантирует такого же поведения в будущем.</p>
</section>
<section class="panel"><span class="tag">Подбор выхода</span><h2>Рейтинг Take Profit и Stop Loss</h2>
<p>Для каждой комбинации входа, выбранной пары, периода, баланса и комиссии программа перебирает варианты выхода по сохраненным снимкам и сортирует их по итоговому чистому результату. В рейтинги и выпадающие списки попадают только варианты, давшие минимум две сделки за период: одна сделка считается слишком малой выборкой. Порог входа оценивается по лучшему результату только среди таких вариантов выхода.</p>
<ul>
<li>Стратегия с фиксированными Take Profit и Stop Loss: по умолчанию каждый порог перебирается в диапазоне <span class="formula"><?= h($exitRangeText($exitProfileConfig['fixed'])) ?></span>. На главной странице диапазоны TP и SL можно изменить отдельно. Проверяются все сочетания Take Profit и Stop Loss.</li>
<li>Стратегия с фиксацией только убытка: по умолчанию Stop Loss перебирается в диапазоне <span class="formula"><?= h($exitRangeText($exitProfileConfig['loss'])) ?></span>; прибыль не фиксируется.</li>
<li>Стратегия с фиксацией только прибыли: по умолчанию Take Profit перебирается в диапазоне <span class="formula"><?= h($exitRangeText($exitProfileConfig['profit'])) ?></span>; убыток не фиксируется. Незакрытая на конце периода позиция оценивается по последней котировке.</li>
<li>В каждой автоматической группе варианты упорядочены по чистому результату; отображаются до 500 лучших сочетаний, прошедших порог в две сделки.</li>
</ul>
<p class="note">Рейтинг показывает результат на выбранном историческом периоде. Подбор параметров по тем же данным может переобучиться на прошлые движения и не предсказывает будущую доходность.</p>
</section>
<p><a href="/">← Вернуться к графику и симуляции</a></p></main></body></html>
