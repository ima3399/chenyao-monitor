<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$node = trim((string) ($_REQUEST['node'] ?? ''));

if ($node === '') {
    json_out(['ok' => false, 'bridge' => 'probe-host', 'error' => 'parameter node is required'], 400);
}

$command = PHP_OS_FAMILY === 'Windows'
    ? 'ping -n 1 -w 800 ' . $node
    : 'ping -c 1 -W 1 ' . $node;

$outputLines = [];
$exitCode = 1;
exec($command . ' 2>&1', $outputLines, $exitCode);

$text = implode("\n", $outputLines);
if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
    $text = mb_convert_encoding($text, 'UTF-8', 'GBK');
}

json_out([
    'ok' => true,
    'bridge' => 'probe-host',
    'node' => $node,
    'command' => $command,
    'exit_code' => $exitCode,
    'output' => mb_substr($text, 0, 6000),
]);
