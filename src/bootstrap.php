<?php

spl_autoload_register(static function (string $class): void {
    $prefix = 'MailSupportAssistant\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $file = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/Support/ProjectPaths.php';
require_once __DIR__ . '/Support/Env.php';

use MailSupportAssistant\Support\Env;
use MailSupportAssistant\Support\ProjectPaths;

Env::load(ProjectPaths::root() . DIRECTORY_SEPARATOR . '.env');

date_default_timezone_set((string) Env::get('MAIL_ASSISTANT_TIMEZONE', 'UTC'));

ProjectPaths::ensureStorage();

