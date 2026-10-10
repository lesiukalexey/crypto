<?php
declare(strict_types=1);

function configuredTickerSymbols(array $config): array
{
    $symbols = array_values(array_unique(array_map(
        static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)),
        is_array($config['symbols'] ?? null) ? $config['symbols'] : [],
    )));
    $symbols = array_values(array_filter($symbols, static fn (string $symbol): bool => $symbol !== ''));
    sort($symbols, SORT_STRING);
    return $symbols;
}

function tickerSettingsPath(): string
{
    return (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-symbol-settings.json';
}

function enabledTickerSymbols(array $config): array
{
    $configured = configuredTickerSymbols($config);
    $path = tickerSettingsPath();
    if (!is_file($path)) return $configured;
    $saved = json_decode((string) file_get_contents($path), true);
    if (!is_array($saved) || !is_array($saved['enabled_symbols'] ?? null)) return $configured;
    $enabled = array_values(array_intersect($configured, array_map('strval', $saved['enabled_symbols'])));
    sort($enabled, SORT_STRING);
    return $enabled;
}

function filterEnabledTickerSnapshotIds(array $snapshotIds, array $config): array
{
    $enabled = array_fill_keys(enabledTickerSymbols($config), true);
    return array_intersect_key($snapshotIds, $enabled);
}

function saveTickerEnabledState(array $config, string $symbol, bool $enabled): array
{
    $configured = configuredTickerSymbols($config);
    if (!in_array($symbol, $configured, true)) {
        throw new InvalidArgumentException('Этот тикер не настроен в конфигурации коллектора.');
    }
    $path = tickerSettingsPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось подготовить файл настроек тикеров.');
    }
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Не удалось заблокировать настройки тикеров.');
    }
    rewind($handle);
    $saved = json_decode((string) stream_get_contents($handle), true);
    $current = is_array($saved) && is_array($saved['enabled_symbols'] ?? null)
        ? array_values(array_intersect($configured, array_map('strval', $saved['enabled_symbols'])))
        : $configured;
    $current = array_values(array_unique($current));
    if ($enabled && !in_array($symbol, $current, true)) $current[] = $symbol;
    if (!$enabled) $current = array_values(array_filter($current, static fn (string $item): bool => $item !== $symbol));
    sort($current, SORT_STRING);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(['enabled_symbols' => $current], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $current;
}

function withQuoteSets(array $config, ?callable $update = null): array
{
    $path = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-quote-sets.json';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось подготовить хранилище наборов котировок.');
    }
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Не удалось заблокировать наборы котировок.');
    }
    @chmod($path . '.lock', 0600);
    try {
        $shouldSave = !is_file($path) || $update !== null;
        $enabledSymbols = enabledTickerSymbols($config);
        $baseListInitialized = false;
        if (is_file($path)) {
            $stored = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($stored) || !is_array($stored['sets'] ?? null)) {
                throw new RuntimeException('Файл наборов котировок имеет неверный формат.');
            }
            $sets = $stored['sets'];
            $baseListInitialized = ($stored['base_list_initialized'] ?? false) === true;
        } else {
            $sets = [[
                'id' => bin2hex(random_bytes(8)),
                'name' => 'Базовый список',
                'symbols' => $enabledSymbols,
            ]];
        }

        // Migrate the automatically-created BIT-128 list in place, preserving
        // its ID and membership. Keep user-created lists and their members.
        $baseIndex = null;
        $legacyIndex = null;
        $normalizeName = static fn (string $name): string => function_exists('mb_strtolower')
            ? mb_strtolower(trim($name), 'UTF-8')
            : strtolower(trim($name));
        foreach ($sets as $index => $set) {
            if (!is_array($set)) continue;
            $name = (string) ($set['name'] ?? '');
            if ($normalizeName($name) === $normalizeName('Базовый список')) $baseIndex = $index;
            if ($normalizeName($name) === $normalizeName('Мой список')) $legacyIndex = $index;
        }
        if ($baseIndex === null) {
            if ($legacyIndex !== null) {
                $baseIndex = $legacyIndex;
                if (($sets[$baseIndex]['name'] ?? '') !== 'Базовый список') {
                    $sets[$baseIndex]['name'] = 'Базовый список';
                    $shouldSave = true;
                }
            } else {
                $sets[] = ['id' => bin2hex(random_bytes(8)), 'name' => 'Базовый список', 'symbols' => []];
                $baseIndex = array_key_last($sets);
                $shouldSave = true;
            }
        } elseif (($sets[$baseIndex]['name'] ?? '') !== 'Базовый список') {
            $sets[$baseIndex]['name'] = 'Базовый список';
            $shouldSave = true;
        }
        $baseMembers = array_values(array_unique(array_map('strval', is_array($sets[$baseIndex]['symbols'] ?? null) ? $sets[$baseIndex]['symbols'] : [])));
        if (!$baseListInitialized) {
            foreach ($enabledSymbols as $symbol) {
                if (!in_array($symbol, $baseMembers, true)) {
                    $baseMembers[] = $symbol;
                    $shouldSave = true;
                }
            }
            $baseListInitialized = true;
            $shouldSave = true;
        }
        sort($baseMembers, SORT_STRING);
        if ($baseMembers !== ($sets[$baseIndex]['symbols'] ?? [])) {
            $sets[$baseIndex]['symbols'] = $baseMembers;
            $shouldSave = true;
        }
        if ($update !== null) $sets = $update($sets);
        if (!is_array($sets)) throw new RuntimeException('Не удалось обновить наборы котировок.');

        if ($shouldSave) {
            $temporary = $path . '.tmp';
            $json = json_encode(['base_list_initialized' => $baseListInitialized, 'sets' => array_values($sets)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Не удалось сохранить наборы котировок.');
            }
            @chmod($path, 0600);
        }
        return array_values($sets);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function loadQuoteSets(array $config): array
{
    return withQuoteSets($config);
}

function updateQuoteSets(array $config, callable $update): array
{
    return withQuoteSets($config, $update);
}
