<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class FooterSignoffDedupToolsApiClient extends ToolsApiClient
{
    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function fetchConfig(): array
    {
        return ['mailboxes' => []];
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$runner = new MailAssistantRunner(
    new FooterSignoffDedupToolsApiClient(),
    new Logger(sys_get_temp_dir() . '/mail-assistant-footer-dedup.log', sys_get_temp_dir() . '/mail-assistant-footer-dedup-last-run.json'),
    new MessageStateStore(sys_get_temp_dir() . '/mail-assistant-footer-dedup-state.json')
);

$stripMethod = new ReflectionMethod(MailAssistantRunner::class, 'stripTrailingGeneratedSignoff');
$stripMethod->setAccessible(true);

$applyFooterMethod = new ReflectionMethod(MailAssistantRunner::class, 'applyGenericFallbackFooter');
$applyFooterMethod->setAccessible(true);

$aiReply = "Hello Thomas,\n\nYour delisting request has been queued.\n\nBest regards,\nTornevall Networks Support\n\nRegards,\nTornevall Networks DNSBL Support";
$stripped = (string) $stripMethod->invoke($runner, $aiReply);

assertTrueValue(stripos($stripped, 'best regards') === false, 'All trailing signoff blocks should be removed before static footer append.');
assertTrueValue(stripos($stripped, "Regards,\nTornevall Networks DNSBL Support") === false, 'Secondary trailing signoff should also be removed.');

$mailbox = [
    'defaults' => [
        'footer' => "Regards,\nTornevall Networks DNSBL Support",
    ],
];

$finalReply = (string) $applyFooterMethod->invoke($runner, $mailbox, $aiReply, '');
assertTrueValue(substr_count(strtolower($finalReply), 'best regards') === 0, 'Final reply should not keep AI-generated best-regards signoff.');
assertTrueValue(substr_count(strtolower($finalReply), 'regards,') === 1, 'Final reply should contain one regards signoff block after footer append.');

fwrite(STDOUT, "footer-signoff-dedup-regression: ok\n");

