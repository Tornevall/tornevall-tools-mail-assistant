<?php

namespace MailSupportAssistant\Support;

class Logger
{
    private string $logFile;
    private string $lastRunFile;

    public function __construct(?string $logFile = null, ?string $lastRunFile = null)
    {
        $this->logFile = $logFile ?: ProjectPaths::logs() . DIRECTORY_SEPARATOR . 'mail-support-assistant.log';
        $this->lastRunFile = $lastRunFile ?: ProjectPaths::storage() . DIRECTORY_SEPARATOR . 'last-run.json';
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        ProjectPaths::ensureStorage();
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    public function saveLastRun(array $summary): void
    {
        ProjectPaths::ensureStorage();
        file_put_contents($this->lastRunFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function lastRun(): array
    {
        if (!is_readable($this->lastRunFile)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->lastRunFile), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function tail(int $lines = 30): array
    {
        if (!is_readable($this->logFile)) {
            return [];
        }

        $content = file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [];
        return array_slice($content, -1 * max(1, $lines));
    }
}

