<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MailSupportAssistant\Auth\SimpleSessionAuth;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;
use MailSupportAssistant\Web\WebApp;

$logger = new Logger();
$tools = new ToolsApiClient();

$app = new WebApp(new SimpleSessionAuth(), $logger, $tools, new MailAssistantRunner($tools, $logger));
$app->handle();

