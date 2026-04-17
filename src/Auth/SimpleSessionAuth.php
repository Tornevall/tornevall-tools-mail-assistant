<?php

namespace MailSupportAssistant\Auth;

use MailSupportAssistant\Support\Env;

class SimpleSessionAuth
{
    private const SESSION_KEY = 'mail_support_assistant_auth';

    public function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function check(): bool
    {
        $this->boot();
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public function attempt(string $username, string $password): bool
    {
        $this->boot();
        $expectedUser = (string) Env::get('MAIL_ASSISTANT_WEB_USER', 'admin');
        $expectedPassword = (string) Env::get('MAIL_ASSISTANT_WEB_PASSWORD', 'change-me');
        if ($username !== $expectedUser || $password !== $expectedPassword) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = [
            'username' => $username,
            'logged_in_at' => date('c'),
        ];

        return true;
    }

    public function logout(): void
    {
        $this->boot();
        unset($_SESSION[self::SESSION_KEY]);
    }
}

