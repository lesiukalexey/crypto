<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Главная · Bitget</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--line:#273149;--text:#e9eefb;--muted:#8995ad}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.6 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1200px;margin:0 auto;padding:36px 24px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 28px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.nav a:hover{background:#1b2843;color:var(--text)}.nav a[aria-current=page]{background:#263968;color:#fff}.panel{max-width:780px;padding:28px;border:1px solid var(--line);border-radius:18px;background:color-mix(in srgb,var(--panel) 95%,transparent);box-shadow:0 18px 50px #0003}h1{margin:0 0 10px;font-size:32px}.panel p{margin:0;color:#b7c1d5}@media(max-width:600px){.wrap{padding:24px 14px}.nav{width:100%}.nav a{flex:1;text-align:center}.panel{padding:20px}}
</style>
</head>
<body><main class="wrap">
<nav class="nav" aria-label="Разделы">
<a href="index.php" aria-current="page">Главная</a>
<a href="basic-checks.php">Базовые проверки</a>
<a href="basic-checks-2.php">Базовые проверки 2</a>
<a href="hedging.php">Хеджирование</a>

<a href="settings.php">Настройки</a>
<a href="autotrading.php?mode=paper">Автоторговля (Paper)</a>
<a href="autotrading.php?mode=demo">Автотрейдинг (демо)</a>
<a href="autotrading.php?mode=real">Автотрейдинг (real)</a>
<a href="autotrading-pi.php">Raspberry Pi</a>
</nav>
<section class="panel">
<h1>Главная</h1>
<p>Добро пожаловать в Bitget. Здесь собраны инструменты для анализа котировок, хеджирования и проверки торговых стратегий. Выберите нужный раздел в меню.</p>
</section>
</main></body>
</html>
