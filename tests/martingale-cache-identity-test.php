<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/ranking-parameters.php';

function keyFor(string $mode, string $chainLoss, string $dayLoss): string
{
    $parts = ['same-request', 'same-snapshot', $mode];
    array_push($parts, ...rankingMartingaleCacheIdentity($mode, [
        'max_chain_loss' => $chainLoss,
        'max_day_loss' => $dayLoss,
    ]));
    return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
}

$baseline = keyFor('simple', '0', '0');
$sameSettings = keyFor('simple', '0', '0');
$chainLimit = keyFor('simple', '2', '0');
$dayLimit = keyFor('simple', '0', '3');
$otherMode = keyFor('reverse', '0', '0');
$withoutMartingale = keyFor('none', '0', '0');

if ($baseline !== $sameSettings
    || count(array_unique([$baseline, $chainLimit, $dayLimit, $otherMode, $withoutMartingale])) !== 5) {
    fwrite(STDERR, "martingale cache identities are not isolated by mode and risk limits\n");
    exit(1);
}

echo "martingale cache identity checks passed\n";
