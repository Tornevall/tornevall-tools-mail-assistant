<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;
function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$runner = new MailAssistantRunner(
    new class extends ToolsApiClient {
        public function __construct()
        {
            parent::__construct('https://example.invalid/api', 'test-token');
        }
    },
    new Logger(sys_get_temp_dir() . '/mail-assistant-markdown-html.log', sys_get_temp_dir() . '/mail-assistant-markdown-html-last-run.json')
);
$method = new ReflectionMethod($runner, 'buildReplyContent');
$method->setAccessible(true);
$replyContent = $method->invoke($runner, <<<TEXT
# Reply title
Hello **customer**,
Please review the following:
- First point
- Second point with `inline code`
Visit [our help page](https://example.com/help).
TEXT, [], []);
$html = (string) (($replyContent['html'] ?? null) ?: '');
assertTrueValue($html !== '', 'Markdown reply HTML should not be empty.');
assertTrueValue(strpos($html, '<h1') !== false && strpos($html, 'Reply title') !== false, 'Markdown headings should render as HTML headings.');
assertTrueValue(strpos($html, '<strong>customer</strong>') !== false, 'Markdown bold text should render as <strong>.');
assertTrueValue(strpos($html, '<ul') !== false && strpos($html, '<li style="margin:0 0 8px 0;">First point</li>') !== false, 'Markdown bullet lists should render as HTML lists.');
assertTrueValue(strpos($html, '<code style=') !== false && strpos($html, 'inline code') !== false, 'Markdown inline code should render as HTML code tags.');
assertTrueValue(strpos($html, '<a href="https://example.com/help"') !== false, 'Markdown links should render as clickable HTML anchors.');
assertTrueValue(strpos($html, '**customer**') === false, 'Rendered HTML should not keep raw markdown emphasis markers.');
fwrite(STDOUT, "markdown-html-reply-regression: ok\n");
