<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function runCommand(string $command, array $env = []): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start test command: ' . $command);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [
        'exit_code' => $exitCode,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}
$projectRoot = dirname(__DIR__);
$cronScript = $projectRoot . '/cron-run.sh';
$baseDir = sys_get_temp_dir() . '/mail-assistant-cron-lock-' . bin2hex(random_bytes(4));
if (!mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
    throw new RuntimeException('Could not create temp directory for cron lock regression.');
}
$lockDir = $baseDir . '/cron-run.lock.d';
$holderScript = $baseDir . '/hold-cron-lock.sh';
file_put_contents($holderScript, <<<'SCRIPT'
#!/bin/sh
set -eu
LOCK_DIR=$1
INFO_FILE="$LOCK_DIR/holder"
mkdir "$LOCK_DIR"
printf 'pid=%s\nowner=%s\nstarted_at=%s\n' "$$" 'regression_holder' '2026-04-21T00:00:00Z' > "$INFO_FILE"
printf 'locked\n'
sleep 6
SCRIPT
);
chmod($holderScript, 0700);
$staleLockDir = $baseDir . '/stale-cron.lock.d';
mkdir($staleLockDir, 0777, true);
file_put_contents($staleLockDir . '/holder', "pid=999999\nowner=stale_test\n");
$staleResult = runCommand('sh ' . escapeshellarg($cronScript) . ' --help', [
    'MAIL_ASSISTANT_CRON_LOCK_DIR' => $staleLockDir,
]);
assertTrueValue($staleResult['exit_code'] === 0, 'cron-run.sh should recover from a stale PID lock and still reach the CLI help output.');
assertTrueValue(strpos($staleResult['stdout'], 'Usage:') !== false, 'Recovered stale cron lock should still allow the CLI help to run.');
assertTrueValue(!is_dir($staleLockDir), 'Recovered stale cron lock directory should be cleaned up after the wrapper exits.');
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$holderProcess = proc_open('sh ' . escapeshellarg($holderScript) . ' ' . escapeshellarg($lockDir), $descriptors, $pipes);
if (!is_resource($holderProcess)) {
    throw new RuntimeException('Could not start cron lock holder helper.');
}
stream_set_blocking($pipes[1], false);
$locked = false;
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    $output = stream_get_contents($pipes[1]);
    if (strpos((string) $output, 'locked') !== false) {
        $locked = true;
        break;
    }
    usleep(100000);
}
if (!$locked) {
    $stderr = stream_get_contents($pipes[2]);
    proc_terminate($holderProcess);
    proc_close($holderProcess);
    throw new RuntimeException('Cron lock holder never acquired the lock. ' . trim((string) $stderr));
}
$activeResult = runCommand('sh ' . escapeshellarg($cronScript) . ' --help', [
    'MAIL_ASSISTANT_CRON_LOCK_DIR' => $lockDir,
]);
assertTrueValue($activeResult['exit_code'] === 0, 'cron-run.sh should exit cleanly when another cron process already holds the lock.');
assertTrueValue(stripos($activeResult['stderr'], 'already running') !== false, 'Active cron lock should report the current running process.');
assertTrueValue(strpos($activeResult['stdout'], 'Usage:') === false, 'Active cron lock should stop before the PHP CLI help runs.');
proc_terminate($holderProcess);
@fclose($pipes[0]);
@fclose($pipes[1]);
@fclose($pipes[2]);
proc_close($holderProcess);
fwrite(STDOUT, "cron-script-lock-regression: ok\n");
