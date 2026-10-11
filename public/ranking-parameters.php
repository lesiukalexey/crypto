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

function rankingSimulationCosts(array $query): array
{
    $fundingRaw = $query['include_funding'] ?? '1';
    if (!in_array((string) $fundingRaw, ['0', '1'], true)) {
        throw new InvalidArgumentException('Учитывать funding можно только как Да или Нет.');
    }
    $slippage = $query['slippage_bps'] ?? '0';
    if (!is_string($slippage) && !is_int($slippage) && !is_float($slippage)) {
        throw new InvalidArgumentException('Проскальзывание задаётся числом в базисных пунктах.');
    }
    $slippage = trim((string) $slippage);
    if (!preg_match('/^\d{1,4}(?:\.\d{1,4})?$/', $slippage) || (float) $slippage >= 10000) {
        throw new InvalidArgumentException('Проскальзывание должно быть от 0 до 9 999,9999 б.п.');
    }
    return ['include_funding' => (string) $fundingRaw === '1', 'slippage_bps' => $slippage];
}

function rankingSimulationCostsIdentity(array $costs): array
{
    return ['shared-futures-cost-model-v2-contract-quantity-v2', $costs['include_funding'], $costs['slippage_bps']];
}

function rankingMartingaleCacheIdentity(string $mode, array $riskLimits): array
{
    if ($mode === 'none') return [];
    return [
        'martingale-negative-stop-v1-balance-cap-v1-risk-usdt-v1-independent-chains-v1-bitget-contract-rules-v3-chain-metrics',
        $riskLimits,
    ];
}

function rankingCurrentBitgetContract(string $productType, string $symbol): array
{
    if (!in_array($productType, ['USDT-FUTURES', 'USDC-FUTURES', 'COIN-FUTURES'], true)) {
        throw new RuntimeException('Проверка рыночных ордеров настроена только для бессрочных фьючерсов Bitget.');
    }
    $safeType = strtolower((string) preg_replace('/[^a-z0-9-]/i', '', $productType));
    $root = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-contract-specs';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Не удалось подготовить кэш правил контрактов Bitget.');
    }
    $path = $root . '/' . $safeType . '.json';
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Не удалось заблокировать кэш правил контрактов Bitget.');
    }
    try {
        $cache = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($cache) || !is_array($cache['contracts'] ?? null)
            || (int) ($cache['fetched_at'] ?? 0) < time() - 3600) {
            if (!function_exists('curl_init')) throw new RuntimeException('Для обновления правил Bitget требуется PHP cURL.');
            $curl = curl_init('https://api.bitget.com/api/v2/mix/market/contracts?productType=' . rawurlencode($productType));
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'BitgetHistoricalReplay/1.0']);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);
            if (!is_string($body) || $status !== 200) throw new RuntimeException('Не удалось получить актуальные правила Bitget: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $status));
            $payload = json_decode($body, true);
            if (!is_array($payload) || ($payload['code'] ?? null) !== '00000' || !is_array($payload['data'] ?? null)) {
                throw new RuntimeException('Bitget вернул некорректные правила контрактов.');
            }
            $contracts = [];
            foreach ($payload['data'] as $item) {
                if (!is_array($item) || !is_string($item['symbol'] ?? null)) continue;
                foreach (['symbolStatus', 'minTradeUSDT', 'minTradeNum', 'sizeMultiplier', 'volumePlace', 'pricePlace', 'priceEndStep', 'maxMarketOrderQty', 'maxOrderQty', 'minLever', 'maxLever', 'takerFeeRate'] as $field) {
                    if (!array_key_exists($field, $item)) throw new RuntimeException('Bitget не вернул поле ' . $field . ' для ' . $item['symbol'] . '.');
                }
                $contracts[$item['symbol']] = $item;
            }
            $cache = ['fetched_at' => time(), 'product_type' => $productType, 'contracts' => $contracts];
            $encoded = json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $temporary = tempnam($root, '.contracts-');
            if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $path)) {
                if (is_string($temporary)) @unlink($temporary);
                throw new RuntimeException('Не удалось сохранить актуальные правила Bitget.');
            }
        }
        $contract = $cache['contracts'][$symbol] ?? null;
        if (!is_array($contract)) throw new RuntimeException('Bitget не вернул текущие правила для ' . $symbol . '.');
        return $contract;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function rankingFundingDecimal(int|float|string $value): string
{
    if (!is_numeric($value) || !is_finite((float) $value)) throw new RuntimeException('Bitget вернул некорректную ставку funding.');
    // BCMath does not accept exponent notation. Keep API strings exact where
    // possible, and expand legacy float cache values to a plain decimal.
    $decimal = (string) $value;
    if (stripos($decimal, 'e') !== false) $decimal = number_format((float) $value, 16, '.', '');
    if (str_contains($decimal, '.')) $decimal = rtrim(rtrim($decimal, '0'), '.');
    if ($decimal === '' || $decimal === '-0') return '0';
    return $decimal;
}

function rankingContractRulesIdentity(string $productType, array $symbols): array
{
    $fields = ['symbolStatus', 'minTradeUSDT', 'minTradeNum', 'sizeMultiplier', 'volumePlace', 'maxMarketOrderQty', 'minLever', 'maxLever'];
    $rules = [];
    $symbols = array_values(array_unique(array_map('strval', $symbols)));
    sort($symbols, SORT_STRING);
    foreach ($symbols as $symbol) {
        if ($symbol === '') continue;
        $contract = rankingCurrentBitgetContract($productType, $symbol);
        $rules[$symbol] = [];
        foreach ($fields as $field) $rules[$symbol][$field] = (string) $contract[$field];
    }
    return ['current-bitget-order-rules-v1', hash('sha256', json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
}

function rankingHistoricalFundingRates(string $productType, string $symbol, int $startMilliseconds, int $endMilliseconds): array
{
    $safe = strtolower((string) preg_replace('/[^a-z0-9-]/i', '', $productType . '-' . $symbol));
    $root = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-funding-rates';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Не удалось подготовить кэш истории funding Bitget.');
    }
    $path = $root . '/' . $safe . '.json';
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Не удалось заблокировать кэш funding Bitget.');
    try {
        $cache = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $covered = is_array($cache) && is_array($cache['rates'] ?? null)
            && (int) ($cache['fetched_at'] ?? 0) >= time() - 21600
            && (int) ($cache['first_timestamp'] ?? PHP_INT_MAX) <= intdiv($startMilliseconds, 1000)
            && ((int) ($cache['last_timestamp'] ?? 0) >= intdiv($endMilliseconds, 1000)
                || (int) ($cache['latest_request_time'] ?? 0) >= intdiv($endMilliseconds, 1000));
        if (!$covered) {
            $rates = [];
            $page = 1;
            $first = PHP_INT_MAX;
            $last = 0;
            $latestRequestTime = 0;
            while ($page <= 100) {
                $url = 'https://api.bitget.com/api/v2/mix/market/history-fund-rate?' . http_build_query([
                    'symbol' => $symbol, 'productType' => $productType, 'pageSize' => 100, 'pageNo' => $page,
                ]);
                $curl = curl_init($url);
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'BitgetHistoricalReplay/1.0']);
                $body = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $curlError = curl_error($curl);
                curl_close($curl);
                if (!is_string($body) || $status !== 200) throw new RuntimeException('Не удалось получить историю funding Bitget: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $status));
                $payload = json_decode($body, true);
                if (!is_array($payload) || ($payload['code'] ?? null) !== '00000' || !is_array($payload['data'] ?? null)) throw new RuntimeException('Bitget вернул некорректную историю funding.');
                $latestRequestTime = max($latestRequestTime, intdiv((int) ($payload['requestTime'] ?? 0), 1000));
                $data = $payload['data'];
                if ($data === []) break;
                $oldestInPage = PHP_INT_MAX;
                foreach ($data as $item) {
                    if (!is_array($item) || !isset($item['fundingTime'], $item['fundingRate']) || !is_numeric($item['fundingTime']) || !is_numeric($item['fundingRate'])) throw new RuntimeException('Bitget вернул неполную запись funding.');
                    $time = (int) $item['fundingTime'];
                    $rate = rankingFundingDecimal((string) $item['fundingRate']);
                    $rates[$time] = $rate;
                    $first = min($first, intdiv($time, 1000));
                    $last = max($last, intdiv($time, 1000));
                    $oldestInPage = min($oldestInPage, $time);
                }
                if ($oldestInPage <= $startMilliseconds) break;
                if (count($data) < 100) break;
                $page++;
            }
            if ($page > 100) throw new RuntimeException('История funding Bitget превысила 100 страниц.');
            if ($rates === [] || $first > intdiv($startMilliseconds, 1000)
                || ($last < intdiv($endMilliseconds, 1000) && $latestRequestTime < intdiv($endMilliseconds, 1000))) {
                throw new RuntimeException('История funding Bitget не покрывает выбранный период. Расчёт остановлен, ставка не подменялась нулём.');
            }
            ksort($rates, SORT_NUMERIC);
            $cache = ['fetched_at' => time(), 'product_type' => $productType, 'symbol' => $symbol,
                'first_timestamp' => $first, 'last_timestamp' => $last,
                'latest_request_time' => $latestRequestTime,
                'rates' => array_map(static fn (int|string $timestamp, string $rate): array => [intdiv((int) $timestamp, 1000), $rate], array_keys($rates), array_values($rates))];
            $encoded = json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $temporary = tempnam($root, '.funding-');
            if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $path)) {
                if (is_string($temporary)) @unlink($temporary);
                throw new RuntimeException('Не удалось сохранить историю funding Bitget.');
            }
        }
        return array_values(array_map(static fn (array $row): array => [(int) $row[0], rankingFundingDecimal(is_int($row[1]) || is_float($row[1]) || is_string($row[1]) ? $row[1] : '0')], array_filter($cache['rates'], static fn (array $row): bool => (int) $row[0] >= intdiv($startMilliseconds, 1000) && (int) $row[0] <= intdiv($endMilliseconds, 1000))));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function rankingCheckBitgetMarketOrder(string $notional, string $price, array $contract, string $feeRate, string $availableBalance): array
{
    $display = static function (string $value): string {
        if (!str_contains($value, '.')) return $value;
        $trimmed = rtrim(rtrim($value, '0'), '.');
        return $trimmed === '' || $trimmed === '-0' ? '0' : $trimmed;
    };
    if (($contract['symbolStatus'] ?? null) !== 'normal') return ['error' => 'контракт сейчас не разрешает открывать новые позиции (статус: ' . (string) ($contract['symbolStatus'] ?? 'неизвестен') . ')'];
    if (bccomp((string) $contract['minLever'], '1', 12) > 0 || bccomp((string) $contract['maxLever'], '1', 12) < 0) return ['error' => 'плечо 1× вне разрешённого диапазона ' . $contract['minLever'] . '–' . $contract['maxLever'] . '×'];
    $step = (string) $contract['sizeMultiplier'];
    if (bccomp($step, '0', 24) <= 0 || bccomp($price, '0', 24) <= 0 || bccomp($notional, '0', 24) <= 0) return ['error' => 'некорректная цена, сумма заявки или шаг объёма'];
    $rawQuantity = bcdiv($notional, $price, 24);
    $steps = bcdiv($rawQuantity, $step, 0);
    $maximum = (string) $contract['maxMarketOrderQty'];
    if (bccomp($maximum, '0', 24) > 0) {
        $maximumSteps = bcdiv($maximum, $step, 0);
        if (bccomp($steps, $maximumSteps, 0) > 0) $steps = $maximumSteps;
    }
    $quantity = bcmul($steps, $step, (int) $contract['volumePlace']);
    if (bccomp($quantity, (string) $contract['minTradeNum'], 24) < 0) {
        $minimumSteps = bcdiv((string) $contract['minTradeNum'], $step, 0);
        if (bccomp(bcmul($minimumSteps, $step, 24), (string) $contract['minTradeNum'], 24) < 0) $minimumSteps = bcadd($minimumSteps, '1', 0);
        $minimumQuantity = bcmul($minimumSteps, $step, 24);
        $minimumCost = bcmul(bcmul($minimumQuantity, $price, 24), bcadd('1', $feeRate, 24), 24);
        return ['error' => 'после округления объём ' . $display($quantity) . ' меньше минимума ' . $contract['minTradeNum'] . ' (шаг ' . $step . ', цена ' . $display($price) . ' USDT; для минимального объёма нужно не меньше ' . $display($minimumCost) . ' USDT с комиссией, доступно ' . $display($availableBalance) . ' USDT)'];
    }
    $actualNotional = bcmul($quantity, $price, 24);
    if (bccomp($actualNotional, (string) $contract['minTradeUSDT'], 24) < 0) {
        return ['error' => 'после округления сумма ' . $display($actualNotional) . ' USDT меньше минимума ' . $contract['minTradeUSDT'] . ' USDT (объём ' . $display($quantity) . ', шаг ' . $step . ', цена ' . $display($price) . ' USDT)'];
    }
    $marginAndFee = bcadd($actualNotional, bcmul($actualNotional, $feeRate, 24), 24);
    if (bccomp($marginAndFee, $availableBalance, 24) > 0) {
        return ['error' => 'для объёма ' . $display($quantity) . ' нужны ' . $display($marginAndFee) . ' USDT с комиссией, доступно ' . $display($availableBalance) . ' USDT'];
    }
    return ['quantity' => $quantity, 'notional' => $actualNotional];
}

function rankingQuantizeBitgetMarketOrder(string $notional, string $price, array $contract, string $feeRate, string $availableBalance): ?array
{
    $result = rankingCheckBitgetMarketOrder($notional, $price, $contract, $feeRate, $availableBalance);
    return isset($result['error']) ? null : $result;
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
    $exitConfirmationMode = (string) ($query['exit_confirmation_mode'] ?? 'immediate');
    if (!in_array($exitConfirmationMode, ['immediate', 'both_delayed', 'tp_delayed', 'sl_delayed'], true)) {
        throw new InvalidArgumentException('Неизвестный тип проверки Take Profit / Stop Loss.');
    }
    $exitConfirmationSnapshots = filter_var($query['exit_confirmation_snapshots'] ?? 60, FILTER_VALIDATE_INT);
    if ($exitConfirmationSnapshots === false || $exitConfirmationSnapshots < 1 || $exitConfirmationSnapshots > 1000) {
        throw new InvalidArgumentException('Число снимков для проверки выхода должно быть от 1 до 1000.');
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
            'exit_confirmation_mode' => $exitConfirmationMode,
            'exit_confirmation_snapshots' => $exitConfirmationSnapshots,
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
