<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$bizDate = trim((string) ($_REQUEST['biz_date'] ?? ''));

if ($bizDate === '') {
    json_out(['ok' => false, 'bridge' => 'log-pack', 'error' => 'parameter biz_date is required'], 400);
}

$sourceDir = STORAGE_PATH . '/monitor-logs';
$archiveDir = STORAGE_PATH . '/archives';
if (!is_dir($sourceDir)) {
    mkdir($sourceDir, 0777, true);
}
if (!is_dir($archiveDir)) {
    mkdir($archiveDir, 0777, true);
}

$archivePath = $archiveDir . '/monitor-log-' . $bizDate . '.tar';
$sourcePath = str_replace('\\', '/', $sourceDir);
$archivePathForCommand = str_replace('\\', '/', $archivePath);
$tarBinary = PHP_OS_FAMILY === 'Windows' && is_file('C:\\Windows\\System32\\tar.exe')
    ? '"C:\\Windows\\System32\\tar.exe"'
    : 'tar';
$command = $tarBinary . ' -cf ' . $archivePathForCommand . ' -C ' . $sourcePath . ' .';

$output = [];
$exitCode = 1;
exec($command . ' 2>&1', $output, $exitCode);

$text = implode("\n", $output);
if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
    $text = mb_convert_encoding($text, 'UTF-8', 'GBK');
}

json_out([
    'ok' => true,
    'bridge' => 'log-pack',
    'biz_date' => $bizDate,
    'archive' => $archivePath,
    'archive_exists' => is_file($archivePath),
    'command' => $command,
    'exit_code' => $exitCode,
    'output' => mb_substr($text, 0, 6000),
]);
