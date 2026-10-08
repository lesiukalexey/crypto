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
    $thresholdDefault = ['from' => 1, 'to' => 10, 'step' => 1];
    $thresholdMode = (string) ($query['threshold_mode'] ?? 'default');
    $thresholdSource = 'threshold';
    if ($thresholdMode !== 'range') {
        $legacyLongMode = (string) ($query['up_threshold_mode'] ?? 'default');
        $legacyShortMode = (string) ($query['down_threshold_mode'] ?? 'default');
        if ($legacyLongMode === 'range' && $legacyShortMode !== 'range') {
            $thresholdMode = 'range';
            $thresholdSource = 'up_threshold';
        } elseif ($legacyShortMode === 'range' && $legacyLongMode !== 'range') {
            $thresholdMode = 'range';
            $thresholdSource = 'down_threshold';
        }
    }
    if (!in_array($thresholdMode, ['default', 'range'], true)) {
        throw new InvalidArgumentException('Неизвестный режим диапазона.');
    }
    $thresholdRanges = $thresholdMode === 'range'
        ? [rankingSingle($query, $thresholdSource, $thresholdDefault)]
        : $thresholdDefaults;
    $windowDefault = ['from' => 5, 'to' => 40, 'step' => 5];
    $windowMode = (string) ($query['window_mode'] ?? 'default');
    $windowSource = 'window';
    if ($windowMode !== 'range') {
        $legacyLongMode = (string) ($query['up_window_mode'] ?? 'default');
        $legacyShortMode = (string) ($query['down_window_mode'] ?? 'default');
        if ($legacyLongMode === 'range' && $legacyShortMode !== 'range') {
            $windowMode = 'range';
            $windowSource = 'up_window';
        } elseif ($legacyShortMode === 'range' && $legacyLongMode !== 'range') {
            $windowMode = 'range';
            $windowSource = 'down_window';
        }
    }
    if (!in_array($windowMode, ['default', 'range'], true)) {
        throw new InvalidArgumentException('Неизвестный режим диапазона.');
    }
    $windowRanges = $windowMode === 'range'
        ? [rankingRange($query[$windowSource . '_from'] ?? $windowDefault['from'], $query[$windowSource . '_to'] ?? $windowDefault['to'], $query[$windowSource . '_step'] ?? $windowDefault['step'], false)]
        : $windowDefaults;
    $families = ['both', 'long', 'short'];
    $ranges = [];
    $modes = [];
    $entryProfiles = [];
    foreach ($families as $family) {
        $ranges[$family . '_threshold'] = $thresholdRanges;
        $ranges[$family . '_window'] = $windowRanges;
        $modes[$family . '_threshold'] = $thresholdMode;
        $modes[$family . '_window'] = $windowMode;
        $entryProfiles[$family] = [
            'thresholds_cents' => rankingValues($thresholdRanges),
            'windows' => rankingValues($windowRanges),
        ];
    }
    // Preserve the original symmetric-family aliases for existing clients.
    $ranges['threshold'] = $ranges['both_threshold'];
    $ranges['window'] = $ranges['both_window'];
    $modes['threshold'] = $modes['both_threshold'];
    $modes['window'] = $modes['both_window'];
    foreach (['fixed_tp' => 'fixed', 'fixed_sl' => 'fixed', 'profit' => 'profit', 'loss' => 'loss'] as $name => $family) {
        $profile = $exitDefaults[$family];
        $default = ['from' => (int) $profile['min_cents'], 'to' => (int) $profile['max_cents'], 'step' => (int) $profile['step_cents']];
        $ranges[$name] = rankingSingle($query, $name, $default);
    }
    $entryVariantCount = array_sum(array_map(
        static fn (array $profile): int => count($profile['thresholds_cents']) * count($profile['windows']),
        $entryProfiles
    )) + count($entryDefaults['immediate_directions'] ?? []);
    $count = $entryVariantCount
        * (count(rankingValues([$ranges['fixed_tp']])) * count(rankingValues([$ranges['fixed_sl']]))
            + count(rankingValues([$ranges['profit']])) + count(rankingValues([$ranges['loss']])));
    foreach ($entryProfiles as $profile) {
        if (count($profile['thresholds_cents']) > 100 || count($profile['windows']) > 50) {
            throw new InvalidArgumentException('Слишком много сочетаний. Увеличьте шаг или сузьте диапазон.');
        }
    }
    if ($count > 30000000) {
        throw new InvalidArgumentException('Слишком много сочетаний. Увеличьте шаг или сузьте диапазон.');
    }
    return [
        'ranges' => $ranges,
        'modes' => $modes,
        'worker' => [
            'entry_thresholds_cents' => $entryProfiles['both']['thresholds_cents'],
            'entry_windows' => $entryProfiles['both']['windows'],
            'entry_profiles' => $entryProfiles,
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

function rankingEntryConfig(string $entryConfig, array $ranking, array $immediateDirections): ?array
{
    if (preg_match('/^immediate:(short|long)$/', $entryConfig, $matches) === 1
        && in_array($matches[1], $immediateDirections, true)) {
        return ['type' => 'immediate', 'direction' => $matches[1]];
    }
    if (preg_match('/^entry(?::(long|short))?:(\d+):(\d+)$/', $entryConfig, $matches) !== 1) {
        return null;
    }
    $family = ($matches[1] ?? '') !== '' ? $matches[1] : 'both';
    $window = (int) $matches[2];
    $threshold = (int) $matches[3];
    $profile = $ranking['entry_profiles'][$family] ?? null;
    if (!is_array($profile)
        || !in_array($window, $profile['windows'], true)
        || !in_array($threshold, $profile['thresholds_cents'], true)) {
        return null;
    }
    return ['type' => 'momentum', 'family' => $family, 'window' => $window, 'threshold_cents' => $threshold];
}

function rankingHasValue(int $value, array $range): bool
{
    return $value >= $range['from'] && $value <= $range['to'] && ($value - $range['from']) % $range['step'] === 0;
}
