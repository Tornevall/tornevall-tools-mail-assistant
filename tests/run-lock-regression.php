<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\RunLock;
use MailSupportAssistant\Tools\ToolsApiClient;
function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$baseDir = sys_get_temp_dir() . '/mail-assistant-run-lock-' . bin2hex(random_bytes(4));
if (!is_dir($baseDir) && !mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
    throw new RuntimeException('Could not create temp directory for run-lock regression.');
}
$lockFile = $baseDir . '/run.lock';
$holderScript = $baseDir . '/hold-lock.php';
file_put_contents($holderScript, str_replace('__AUTOLOAD__', var_export(__DIR__ . '/../vendor/autoload.php', true), <<<'SCRIPT'
<?php
require __AUTOLOAD__;
$lock = new MailSupportAssistant\Support\RunLock($argv[1] ?? '');
$result = $lock->acquire('regression_holder');
if (empty($result['acquired'])) {
    fwrite(STDERR, "failed\n");
    exit(2);
}
echo "locked\n";
flush();
sleep(6);
SCRIPT
));
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$command = 'php ' . escapeshellarg($holderScript) . ' ' . escapeshellarg($lockFile);
$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start helper process for run-lock regression.');
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
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Helper process never acquired the run lock. ' . trim((string) $stderr));
}
$runner = new MailAssistantRunner(
    new class extends ToolsApiClient {
        public function __construct()
        {
            parent::__construct('https://example.invalid/api', 'test-token');
        }
    },
    new Logger($baseDir . '/test.log', $baseDir . '/last-run.json'),
    null,
    new RunLock($lockFile)
);
$result = $runner->run(['dry_run' => true]);
assertTrueValue(empty($result['ok']), 'Runner should refuse to start while another process holds the run lock.');
assertTrueValue((string) ($result['reason'] ?? '') === 'runner_already_active', 'Overlap failure should report runner_already_active.');
assertTrueValue((string) (($result['run_lock']['lock_file'] ?? '')) === $lockFile, 'Run summary should expose the current lock file path.');
assertTrueValue((string) (($result['run_lock']['metadata']['owner'] ?? '')) === 'regression_holder', 'Run summary should expose the current lock holder metadata.');
proc_terminate($process);
@fclose($pipes[0]);
@fclose($pipes[1]);
@fclose($pipes[2]);
proc_close($process);
fwrite(STDOUT, "run-lock-regression: ok\n");
