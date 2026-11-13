<?php
// bench.php — the same work as bench.grav and bench.py, against the same
// local server (phpredis, PHP's fastest common Redis client): see bench/README.md.
$n = isset($argv[1]) ? (int)$argv[1] : 20000;
$url = $argv[2] ?? '127.0.0.1';
$port = isset($argv[3]) ? (int)$argv[3] : 54400;
$db = isset($argv[4]) ? (int)$argv[4] : 9;

$r = new Redis();
$r->connect($url, $port);
$r->select($db);
$r->flushDB();

$t0 = microtime(true);
for ($i = 0; $i < $n; $i++) { $r->set("k$i", "v$i"); }
$setMs = (int)((microtime(true) - $t0) * 1000);

$t1 = microtime(true);
$ok = 0;
for ($i = 0; $i < $n; $i++) { if ($r->get("k$i") === "v$i") { $ok++; } }
$getMs = (int)((microtime(true) - $t1) * 1000);

$t2 = microtime(true);
$pipe = $r->pipeline();
for ($i = 0; $i < $n; $i++) { $pipe->get("k$i"); }
$pipe->exec();
$pipeMs = (int)((microtime(true) - $t2) * 1000);

echo "phpredis: set={$setMs}ms get={$getMs}ms pipelined_get={$pipeMs}ms checked=$ok/$n\n";
$r->flushDB();
