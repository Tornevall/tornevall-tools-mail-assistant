<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use MailSupportAssistant\Mail\MimeDecoder;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;
function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}
$runner = new MailAssistantRunner(
    new class extends ToolsApiClient {
        public function __construct()
        {
            parent::__construct('https://example.invalid/api', 'test-token');
        }
    },
    new Logger(sys_get_temp_dir() . '/mail-assistant-subject-issue.log', sys_get_temp_dir() . '/mail-assistant-subject-issue-last-run.json')
);
$method = new ReflectionMethod($runner, 'buildReplySubject');
$method->setAccessible(true);
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_ID_ENABLED=true');
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_LABEL=Issue');
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_PREFIX=MSA');
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_LENGTH=8');
$firstSubject = (string) $method->invoke($runner, [
    'subject' => 'Help',
    'subject_normalized' => 'Help',
    'message_id' => '<subject-issue@example.test>',
    'message_key' => '<subject-issue@example.test>',
    'from' => 'customer@example.test',
    'to' => 'support@example.test',
    'thread_context' => ['messages' => []],
], 'Re:');
assertTrueValue((bool) preg_match('/^Re:\s+\[Issue\s+MSA-[A-F0-9]{8}]\s+Help$/u', $firstSubject), 'First reply subject should get exactly one generated issue id tag.');


assertSameValue('Help', MimeDecoder::normalizeReplySubject($firstSubject), 'Subject normalization should strip the generated issue id tag for thread matching.');
$secondSubject = (string) $method->invoke($runner, [
    'subject' => $firstSubject,
    'subject_normalized' => 'Help',
    'message_id' => '<subject-issue-follow-up@example.test>',
    'message_key' => '<subject-issue-follow-up@example.test>',
    'from' => 'customer@example.test',
    'to' => 'support@example.test',
    'thread_context' => [
        'messages' => [
            ['reply_issue_id' => 'MSA-ABCDEF12'],
        ],
    ],
], 'Re:');
assertSameValue($firstSubject, $secondSubject, 'A reply subject that already contains the issue id must not get another issue id appended.');
assertSameValue(1, substr_count($secondSubject, '[Issue '), 'Reply subject should contain exactly one issue tag.');
$continuedSubject = (string) $method->invoke($runner, [
    'subject' => 'Re: Help',
    'subject_normalized' => 'Help',
    'message_id' => '<subject-issue-continuation@example.test>',
    'message_key' => '<subject-issue-continuation@example.test>',
    'from' => 'customer@example.test',
    'to' => 'support@example.test',
    'thread_context' => [
        'messages' => [
            ['reply_issue_id' => 'MSA-ABCDEF12'],
        ],
    ],
], 'Re:');
assertSameValue('Re: [Issue MSA-ABCDEF12] Help', $continuedSubject, 'A continuation reply without the tag in the incoming subject should reuse the earlier stored issue id.');
assertSameValue('Help', MimeDecoder::normalizeReplySubject($continuedSubject), 'Normalization should still strip a reused issue id tag.');
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_ID_ENABLED');
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_LABEL');
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_PREFIX');
putenv('MAIL_ASSISTANT_SUBJECT_ISSUE_LENGTH');
fwrite(STDOUT, "subject-issue-id-regression: ok\n");
