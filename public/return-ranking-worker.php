<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/return-summary-cache.php';
require_once __DIR__ . '/return-ranking-lib.php';
$cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
$jobPath = $argv[1] ?? '';
if (!preg_match('~^' . preg_quote($cacheDir, '~') . '/portfolio-([a-f0-9]{64})\.returns\.job\.json$~', $jobPath, $match)) exit(2);
$prefix = $cacheDir . '/portfolio-' . $match[1];
$statusPath = $prefix . '.returns.status.json';
$lock = fopen($prefix . '.returns.worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    $job = json_decode((string) file_get_contents($jobPath), true, 512, JSON_THROW_ON_ERROR);
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') throw new RuntimeException('Database password unavailable.');
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$db['host'],$db['port'],$db['database']),$db['user'],$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $rows = (static function () use ($pdo,$job,$statusPath): Generator {
        $total = count($job['symbols']);
        foreach ($job['symbols'] as $index=>$symbol) {
            writeReturnSummaryJson($statusPath,[
                'status'=>'pending','phase'=>'aggregating_returns','progress'=>round($index*99/max(1,$total),1),
                'completed_symbols'=>$index,'total_symbols'=>$total,'current_symbol'=>$symbol,
                'message'=>"Подготавливаю сводку: {$index} из {$total} · {$symbol}",'pid'=>getmypid(),'updated_at'=>time(),
            ]);
            yield from returnSummaryRows($pdo,$job,$symbol);
        }
        writeReturnSummaryJson($statusPath,['status'=>'pending','phase'=>'finalizing_return_ranking','progress'=>99,'message'=>'Сортирую готовые итоги стратегий','pid'=>getmypid(),'updated_at'=>time()]);
    })();
    $result = array_merge(rankStrategyReturnRows($rows,$job['symbols'],(int)$job['min_trades']),$job['diagnostics'],[
        'status'=>'ready','progress'=>100,'return_run_key'=>$job['run_key'],
        'ranking_data_source'=>'strategy_return_points','return_summary_version'=>1,'cache_key'=>$match[1],
    ]);
    writeReturnSummaryJson($prefix . '.json',$result);
    writeReturnSummaryJson($statusPath,['status'=>'ready','progress'=>100,'updated_at'=>time()]);
} catch (Throwable $error) {
    error_log('Return ranking worker failed: '.$error->getMessage());
    writeReturnSummaryJson($statusPath,['status'=>'error','message'=>'Не удалось подготовить общий рейтинг по сохранённой доходности.','updated_at'=>time()]);
    exit(1);
} finally {
    flock($lock,LOCK_UN); fclose($lock);
}
