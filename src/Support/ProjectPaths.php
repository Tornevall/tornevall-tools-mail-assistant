<?php

namespace MailSupportAssistant\Support;

class ProjectPaths
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function storage(): string
    {
        return static::root() . DIRECTORY_SEPARATOR . 'storage';
    }

    public static function logs(): string
    {
        return static::storage() . DIRECTORY_SEPARATOR . 'logs';
    }

    public static function cache(): string
    {
        return static::storage() . DIRECTORY_SEPARATOR . 'cache';
    }

    public static function state(): string
    {
        return static::storage() . DIRECTORY_SEPARATOR . 'state';
    }

    public static function messageStateFile(): string
    {
        return static::state() . DIRECTORY_SEPARATOR . 'message-state.json';
    }

    public static function messageCopies(): string
    {
        return static::cache() . DIRECTORY_SEPARATOR . 'message-copies';
    }

    public static function ensureStorage(): void
    {
        foreach ([static::storage(), static::logs(), static::cache(), static::state(), static::messageCopies()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    }
}

