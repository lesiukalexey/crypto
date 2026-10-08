<?php
declare(strict_types=1);

function rankingCents(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Укажите числовые границы диапазонов.');
    $text = (string) $value;
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) throw new InvalidArgumentException('Суммы диапазонов указываются с точностью до 0,01 USDT.');
    [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

function rankingRange(mixed $from, mixed $to, mixed $step, bool $money): array
{
    $convert = static function (mixed $value) use ($money): int {
        if ($money) return rankingCents($value);
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d+$/', (string) $value)) {
            throw new InvalidArgumentException('Окно входа должно быть целым числом снимков.');
        }
        return (int) $value;
    };
    $range = ['from' => $convert($from), 'to' => $convert($to), 'step' => $convert($step)];
    $limit = $money ? 10000 : 1000;
    if ($range['from'] < 1 || $range['to'] > $limit || $range['to'] < $range['from'] || $range['step'] < 1) {
        throw new InvalidArgumentException('Проверьте диапазон: от > 0, до ≥ от, шаг > 0.');
    }
    return $range;
}

function rankingSingle(array $query, string $name, array $default): array
{
    if (!array_key_exists($name . '_from', $query)) return $default;
    return rankingRange($query[$name . '_from'], $query[$name . '_to'] ?? null, $query[$name . '_step'] ?? null, true);
}

function rankingValues(array $ranges): array
{
    $values = [];
    foreach ($ranges as $range) {
        for ($value = $range['from']; $value <= $range['to']; $value += $range['step']) $values[$value] = $value;
    }
    sort($values, SORT_NUMERIC);
    return $values;
}

function rankingParameters(array $query, array $entryDefaults, array $exitDefaults): array
{
    $thresholdDefaults = array_map(
        static fn (array $row): array => ['from' => $row[0], 'to' => $row[1], 'step' => $row[2]],
        $entryDefaults['threshold_ranges_cents']
    );
    $windowDefaults = array_map(
        static fn (array $row): array => ['from' => $row[0], 'to' => $row[1], 'step' => $row[2]],
        $entryDefaults['window_ranges']
    );
    if (rankingValues($thresholdDefaults) !== array_map('intval', $entryDefaults['thresholds_cents'])
        || rankingValues($windowDefaults) !== array_map('intval', $entryDefaults['snapshot_windows'])) {
        throw new LogicException('Наборы значений входа не совпадают с настройками диапазонов.');
    }
    $thresholdMode = (string) ($query['threshold_mode'] ?? 'default');
    $windowMode = (string) ($query['window_mode'] ?? 'default');
    if (!in_array($thresholdMode, ['default', 'range'], true) || !in_array($windowMode, ['default', 'range'], true)) {
        throw new InvalidArgumentException('Неизвестный режим диапазона.');
    }
    $ranges = [
        'threshold' => $thresholdMode === 'range'
            ? [rankingSingle($query, 'threshold', ['from' => 1, 'to' => 10, 'step' => 1])]
            : $thresholdDefaults,
        'window' => $windowMode === 'range'
            ? [rankingRange($query['window_from'] ?? 5, $query['window_to'] ?? 40, $query['window_step'] ?? 5, false)]
            : $windowDefaults,
    ];
    foreach (['fixed_tp' => 'fixed', 'fixed_sl' => 'fixed', 'profit' => 'profit', 'loss' => 'loss'] as $name => $family) {
        $profile = $exitDefaults[$family];
        $default = ['from' => (int) $profile['min_cents'], 'to' => (int) $profile['max_cents'], 'step' => (int) $profile['step_cents']];
        $ranges[$name] = rankingSingle($query, $name, $default);
    }
    $thresholds = rankingValues($ranges['threshold']);
    $windows = rankingValues($ranges['window']);
    $count = (count($thresholds) * count($windows) + count($entryDefaults['immediate_directions'] ?? []))
        * (count(rankingValues([$ranges['fixed_tp']])) * count(rankingValues([$ranges['fixed_sl']]))
            + count(rankingValues([$ranges['profit']])) + count(rankingValues([$ranges['loss']])));
    if (count($thresholds) > 100 || count($windows) > 50 || $count > 30000000) {
        throw new InvalidArgumentException('Слишком много сочетаний. Увеличьте шаг или сузьте диапазон.');
    }
    return [
        'ranges' => $ranges,
        'modes' => ['threshold' => $thresholdMode, 'window' => $windowMode],
        'worker' => [
            'entry_thresholds_cents' => $thresholds,
            'entry_windows' => $windows,
            'immediate_directions' => $entryDefaults['immediate_directions'] ?? [],
            'exit_profiles' => [
                'fixed_tp' => $ranges['fixed_tp'],
                'fixed_sl' => $ranges['fixed_sl'],
                'profit' => $ranges['profit'],
                'loss' => $ranges['loss'],
            ],
        ],
    ];
}

function rankingHasValue(int $value, array $range): bool
{
    return $value >= $range['from'] && $value <= $range['to'] && ($value - $range['from']) % $range['step'] === 0;
}
