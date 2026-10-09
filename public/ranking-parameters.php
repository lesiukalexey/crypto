<?php
declare(strict_types=1);

function rankingTenthsPercent(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Укажите числовые границы диапазонов.');
    $text = (string) $value;
    if (!preg_match('/^\d+(?:\.\d)?$/', $text)) throw new InvalidArgumentException('Процентные пороги указываются с точностью до 0,1%.');
    [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    return (int) $whole * 10 + (int) str_pad($fraction, 1, '0');
}

function rankingRange(mixed $from, mixed $to, mixed $step): array
{
    $convert = static function (mixed $value): int {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d+$/', (string) $value)) {
            throw new InvalidArgumentException('Окно входа должно быть целым числом снимков.');
        }
        return (int) $value;
    };
    $range = ['from' => $convert($from), 'to' => $convert($to), 'step' => $convert($step)];
    $limit = 1000;
    if ($range['from'] < 1 || $range['to'] > $limit || $range['to'] < $range['from'] || $range['step'] < 1) {
        throw new InvalidArgumentException('Проверьте диапазон: от > 0, до ≥ от, шаг > 0.');
    }
    return $range;
}

function rankingSinglePercent(array $query, string $name, array $default): array
{
    if (!array_key_exists($name . '_from', $query)) return $default;
    $range = ['from' => rankingTenthsPercent($query[$name . '_from']),
        'to' => rankingTenthsPercent($query[$name . '_to'] ?? null),
        'step' => rankingTenthsPercent($query[$name . '_step'] ?? null)];
    if ($range['from'] < 1 || $range['to'] > 10000 || $range['to'] < $range['from'] || $range['step'] < 1) {
        throw new InvalidArgumentException('Проверьте процентный диапазон TP/SL: от > 0, до ≥ от, шаг > 0.');
    }
    return $range;
}

function rankingPercentSegments(array $query, string $name, array $defaults): array
{
    $raw = $query[$name . '_ranges'] ?? null;
    if (!is_array($raw)) {
        if (array_key_exists($name . '_from', $query)) return [rankingSinglePercent($query, $name, $defaults[0] ?? ['from' => 1, 'to' => 1, 'step' => 1])];
        return $defaults;
    }
    if ($raw === [] || count($raw) > 50) throw new InvalidArgumentException('Укажите от 1 до 50 диапазонов.');
    $segments = [];
    foreach ($raw as $segment) {
        if (!is_array($segment)) throw new InvalidArgumentException('Проверьте диапазоны от / до / шаг.');
        $segments[] = rankingSinglePercent([
            $name . '_from' => $segment['from'] ?? null,
            $name . '_to' => $segment['to'] ?? null,
            $name . '_step' => $segment['step'] ?? null,
        ], $name, $defaults[0] ?? ['from' => 1, 'to' => 1, 'step' => 1]);
    }
    return $segments;
}

function rankingFixedSegments(array $query, string $name, array $defaults): array
{
    $raw = $query[$name . '_ranges'] ?? null;
    if (!is_array($raw)) {
        if (!array_key_exists($name . '_from', $query)) return $defaults;
        $legacy = rankingSinglePercent($query, $name, $defaults[0] ?? ['from' => 1, 'to' => 500, 'step' => 1]);
        return [['from' => $legacy['from'] * 10, 'to' => $legacy['to'] * 10, 'step' => $legacy['step'] * 10]];
    }
    if ($raw === [] || count($raw) > 50) throw new InvalidArgumentException('Укажите от 1 до 50 диапазонов.');
    $segments = [];
    foreach ($raw as $segment) {
        if (!is_array($segment)) throw new InvalidArgumentException('Проверьте диапазоны от / до / шаг.');
        $values = [];
        foreach (['from', 'to', 'step'] as $field) {
            $value = $segment[$field] ?? null;
            if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException('Укажите числовые границы диапазонов.');
            $text = (string) $value;
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) throw new InvalidArgumentException('Совместные TP/SL задаются с точностью до 0,01%.');
            [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
            $values[$field] = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        }
        if ($values['from'] < 1 || $values['to'] > 5000 || $values['to'] < $values['from'] || $values['step'] < 1) {
            throw new InvalidArgumentException('Проверьте диапазон TP/SL: от 0,01% до 50%, шаг не меньше 0,01%.');
        }
        $segments[] = $values;
    }
    return $segments;
}

function rankingWindowSegments(array $query, string $name, array $defaults): array
{
    $raw = $query[$name . '_ranges'] ?? null;
    if (!is_array($raw)) {
        if (!array_key_exists($name . '_from', $query)) return $defaults;
        return [rankingRange($query[$name . '_from'], $query[$name . '_to'] ?? null, $query[$name . '_step'] ?? null)];
    }
    if ($raw === [] || count($raw) > 50) throw new InvalidArgumentException('Укажите от 1 до 50 диапазонов.');
    $segments = [];
    foreach ($raw as $segment) {
        if (!is_array($segment)) throw new InvalidArgumentException('Проверьте диапазоны от / до / шаг.');
        $segments[] = rankingRange($segment['from'] ?? null, $segment['to'] ?? null, $segment['step'] ?? null);
    }
    return $segments;
}

function rankingMartingaleRiskLimits(array $query): array
{
    $limits = [];
    foreach ([
        'martingale_max_chain_loss' => 'max_chain_loss',
        'martingale_max_day_loss' => 'max_day_loss',
    ] as $queryName => $key) {
        $value = $query[$queryName] ?? '0';
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Лимиты убытка мартингейла задаются числом в USDT.');
        }
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/', $value) || (float) $value > 1000000000) {
            throw new InvalidArgumentException('Лимит убытка должен быть от 0 до 1 000 000 000 USDT.');
        }
        $limits[$key] = $value;
    }
    return $limits;
}

function rankingMartingaleCacheIdentity(string $mode, array $riskLimits): array
{
    if ($mode === 'none') return [];
    return [
        'martingale-negative-stop-v1-balance-cap-v1-risk-usdt-v1-independent-chains-v1',
        $riskLimits,
    ];
}

function rankingValues(array $ranges): array
{
    if (isset($ranges['values_basis_points']) && is_array($ranges['values_basis_points'])) {
        $values = array_values(array_unique(array_map('intval', $ranges['values_basis_points'])));
        sort($values, SORT_NUMERIC);
        return $values;
    }
    if (isset($ranges['segments']) && is_array($ranges['segments'])) {
        return rankingValues($ranges['segments']);
    }
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
        $entryDefaults['threshold_ranges_tenths_percent']
    );
    $windowDefaults = array_map(
        static fn (array $row): array => ['from' => $row[0], 'to' => $row[1], 'step' => $row[2]],
        $entryDefaults['window_ranges']
    );
    if (rankingValues($thresholdDefaults) !== array_map('intval', $entryDefaults['thresholds_tenths_percent'])
        || rankingValues($windowDefaults) !== array_map('intval', $entryDefaults['snapshot_windows'])) {
        throw new LogicException('Наборы значений входа не совпадают с настройками диапазонов.');
    }
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
        ? rankingPercentSegments($query, $thresholdSource, $thresholdDefaults)
        : $thresholdDefaults;
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
        ? rankingWindowSegments($query, $windowSource, $windowDefaults)
        : $windowDefaults;
    $entrySignalMode = (string) ($query['entry_signal_mode'] ?? 'exact');
    if (!in_array($entrySignalMode, ['exact', 'within'], true)) {
        throw new InvalidArgumentException('Неизвестный тип сигнала входа.');
    }
    $entryDirectionMode = (string) ($query['entry_direction_mode'] ?? 'follow');
    if (!in_array($entryDirectionMode, ['follow', 'reverse'], true)) {
        throw new InvalidArgumentException('Неизвестное направление входа.');
    }
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
            'thresholds_tenths_percent' => rankingValues($thresholdRanges),
            'windows' => rankingValues($windowRanges),
        ];
    }
    // Preserve the original symmetric-family aliases for existing clients.
    $ranges['threshold'] = $ranges['both_threshold'];
    $ranges['window'] = $ranges['both_window'];
    $modes['threshold'] = $modes['both_threshold'];
    $modes['window'] = $modes['both_window'];
    // Old URLs can contain stale custom range values from previous UI builds.
    // They must not silently override the configured segmented grid.
    $fixedGridMode = (string) ($query['fixed_grid_mode'] ?? 'default');
    if (!in_array($fixedGridMode, ['default', 'custom'], true)) {
        throw new InvalidArgumentException('Неизвестный режим сетки совместного расчёта TP и SL.');
    }
    // Older page state could submit "custom" while its hidden inputs were
    // disabled by the browser. Treat that incomplete state as the configured
    // stepped grid instead of silently expanding to 0.1%–50% by 0.1%.
    if ($fixedGridMode === 'custom'
        && ((!isset($query['fixed_tp_ranges']) && !array_key_exists('fixed_tp_from', $query))
            || (!isset($query['fixed_sl_ranges']) && !array_key_exists('fixed_sl_from', $query)))) {
        $fixedGridMode = 'default';
    }
    $fixedValues = [];
    foreach ($exitDefaults['fixed']['segments_basis_points'] ?? [] as $segment) {
        $from = (int) ($segment['from'] ?? 0);
        $to = (int) ($segment['to'] ?? 0);
        $step = (int) ($segment['step'] ?? 0);
        if ($from < 1 || $to < $from || $step < 1) throw new LogicException('Некорректная сетка совместного расчёта TP/SL.');
        for ($value = $from; $value <= $to; $value += $step) $fixedValues[$value] = $value;
        $fixedValues[$to] = $to;
    }
    $fixedValues = array_values($fixedValues);
    sort($fixedValues, SORT_NUMERIC);
    $modes['fixed_grid'] = $fixedGridMode;
    foreach (['fixed_tp' => 'fixed', 'fixed_sl' => 'fixed', 'profit' => 'profit', 'loss' => 'loss'] as $name => $family) {
        $profile = $exitDefaults[$family];
        if (($name === 'fixed_tp' || $name === 'fixed_sl') && $fixedGridMode === 'default') {
            $segments = array_map(static fn (array $segment): array => [
                'from' => (int) $segment['from'],
                'to' => (int) $segment['to'],
                'step' => (int) $segment['step'],
            ], $exitDefaults['fixed']['segments_basis_points'] ?? []);
            $ranges[$name] = [
                'from' => 1, 'to' => 500, 'step' => 1,
                'values_basis_points' => $fixedValues,
                'segments' => $segments,
            ];
            continue;
        }
        $defaultSegments = $family === 'fixed'
            ? array_map(static fn (array $segment): array => [
                'from' => (int) $segment['from'],
                'to' => (int) $segment['to'],
                'step' => (int) $segment['step'],
            ], $exitDefaults['fixed']['segments_basis_points'] ?? [])
            : [[
                'from' => (int) $profile['min_tenths_percent'],
                'to' => (int) $profile['max_tenths_percent'],
                'step' => (int) $profile['step_tenths_percent'],
            ]];
        $segments = $family === 'fixed'
            ? rankingFixedSegments($query, $name, $defaultSegments)
            : rankingPercentSegments($query, $name, $defaultSegments);
        $range = $segments[0];
        if ($family === 'fixed') {
            $range['values_basis_points'] = rankingValues($segments);
            $range['segments'] = $segments;
            $ranges[$name] = $range;
        } else {
            $ranges[$name] = $segments;
        }
    }
    $entryVariantCount = array_sum(array_map(
        static fn (array $profile): int => count($profile['thresholds_tenths_percent']) * count($profile['windows']),
        $entryProfiles
    )) + count($entryDefaults['immediate_directions'] ?? []);
    $count = $entryVariantCount
        * (count(rankingValues($ranges['fixed_tp'])) * count(rankingValues($ranges['fixed_sl']))
            + count(rankingValues($ranges['profit'])) + count(rankingValues($ranges['loss'])));
    foreach ($entryProfiles as $profile) {
        if (count($profile['thresholds_tenths_percent']) > 100 || count($profile['windows']) > 50) {
            throw new InvalidArgumentException('Слишком много сочетаний. Увеличьте шаг или сузьте диапазон.');
        }
    }
    if ($count > 35000000) {
        throw new InvalidArgumentException('Слишком много сочетаний. Увеличьте шаг или сузьте диапазон.');
    }
    return [
        'ranges' => $ranges,
        'modes' => $modes,
        'worker' => [
            'entry_thresholds_tenths_percent' => $entryProfiles['both']['thresholds_tenths_percent'],
            'entry_windows' => $entryProfiles['both']['windows'],
            'entry_profiles' => $entryProfiles,
            'entry_signal_mode' => $entrySignalMode,
            'entry_direction_mode' => $entryDirectionMode,
            'immediate_directions' => $entryDefaults['immediate_directions'] ?? [],
            'exit_profiles' => [
                'fixed_tp' => $ranges['fixed_tp'],
                'fixed_sl' => $ranges['fixed_sl'],
                'profit' => ['segments' => $ranges['profit']],
                'loss' => ['segments' => $ranges['loss']],
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
    if (preg_match('/^entry(?::(long|short))?:(\d+):(\d+)(?::(within))?(?::(reverse))?$/', $entryConfig, $matches) !== 1) {
        return null;
    }
    $family = ($matches[1] ?? '') !== '' ? $matches[1] : 'both';
    $window = (int) $matches[2];
    $threshold = (int) $matches[3];
    $profile = $ranking['entry_profiles'][$family] ?? null;
    if (!is_array($profile)
        || !in_array($window, $profile['windows'], true)
        || !in_array($threshold, $profile['thresholds_tenths_percent'], true)) {
        return null;
    }
    $mode = ($matches[4] ?? '') === 'within' ? 'within' : 'exact';
    if ($mode !== ($ranking['entry_signal_mode'] ?? 'exact')) return null;
    $directionMode = ($matches[5] ?? '') === 'reverse' ? 'reverse' : 'follow';
    if ($directionMode !== ($ranking['entry_direction_mode'] ?? 'follow')) return null;
    return ['type' => 'momentum', 'family' => $family, 'window' => $window, 'threshold_tenths_percent' => $threshold, 'signal_mode' => $mode, 'direction_mode' => $directionMode];
}

function rankingHasValue(int $value, array $range): bool
{
    if (isset($range['values_basis_points']) && is_array($range['values_basis_points'])) {
        return in_array($value, $range['values_basis_points'], true);
    }
    if (array_is_list($range) || isset($range['segments'])) {
        return in_array($value, rankingValues($range), true);
    }
    return $value >= $range['from'] && $value <= $range['to'] && ($value - $range['from']) % $range['step'] === 0;
}
