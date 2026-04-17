<?php

namespace MailSupportAssistant\Tools;

use MailSupportAssistant\Support\Env;
use RuntimeException;

class ToolsApiClient
{
    private const CLIENT_VERSION = '0.3.0';

    private string $baseUrl;
    private string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim((string) ($baseUrl ?: Env::get('MAIL_ASSISTANT_TOOLS_BASE_URL', 'https://tools.tornevall.net/api')), '/');
        $this->token = trim((string) ($token ?: Env::get('MAIL_ASSISTANT_TOOLS_TOKEN', '')));
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    public function fetchConfig(): array
    {
        return $this->request('GET', '/mail-support-assistant/config');
    }

    public function generateAiReply(array $mailbox, array $rule, array $message): array
    {
        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        $cleanBody = $this->sanitizeSummaryText((string) ($message['body_text'] ?? ''), 2400);
        $primaryModel = trim((string) (($rule['reply']['ai_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_MODEL', 'gpt-5.4')));
        $fallbackModel = trim((string) Env::get('MAIL_ASSISTANT_AI_FALLBACK_MODEL', 'gpt-4o-mini'));
        $reasoningEffort = $this->normalizeReasoningEffort(($rule['reply']['ai_reasoning_effort'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_REASONING_EFFORT', 'medium'));

        $contextLines = [
            'Mailbox: ' . (string) ($mailbox['name'] ?? ''),
            'From: ' . (string) ($message['from'] ?? ''),
            'To: ' . (string) ($message['to'] ?? ''),
            'Subject: ' . (string) ($message['subject'] ?? ''),
            'SpamAssassin: present=' . (!empty($spam['present']) ? 'yes' : 'no')
                . ', flagged=' . (!empty($spam['flagged']) ? 'yes' : 'no')
                . ', score=' . (($spam['score'] ?? null) !== null ? (string) $spam['score'] : 'n/a')
                . ', tests=' . implode(',', array_values((array) ($spam['tests'] ?? []))),
            '',
            'Request summary (sanitized):',
            $cleanBody,
        ];

        $customInstruction = trim((string) (($rule['reply']['custom_instruction'] ?? null) ?: ''));
        $userPrompt = 'Reply helpfully to this support email.';
        if ($customInstruction !== '') {
            $userPrompt .= ' ' . $customInstruction;
        }

        $payload = [
            'context' => trim(implode("\n", $contextLines)),
            'user_prompt' => $userPrompt,
            'modifier' => 'short',
            'model' => $primaryModel,
            'request_mode' => 'reply',
            'client_name' => 'Tornevall Tools Mail Assistant',
            'client_version' => self::CLIENT_VERSION,
            'client_platform' => 'php_standalone',
        ];
        if ($reasoningEffort !== null) {
            $payload['reasoning_effort'] = $reasoningEffort;
        }

        try {
            return $this->request('POST', '/ai/socialgpt/respond', $payload);
        } catch (RuntimeException $primaryFailure) {
            if ($fallbackModel === '' || strcasecmp($fallbackModel, $primaryModel) === 0) {
                throw $primaryFailure;
            }

            $fallbackPayload = $payload;
            $fallbackPayload['model'] = $fallbackModel;
            $fallbackResult = $this->request('POST', '/ai/socialgpt/respond', $fallbackPayload);
            if (!array_key_exists('used_fallback_model', $fallbackResult)) {
                $fallbackResult['used_fallback_model'] = true;
            }
            $fallbackResult['fallback_from_model'] = $primaryModel;

            return $fallbackResult;
        }
    }

    private function sanitizeSummaryText(string $text, int $maxLength = 2400): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/<\s*br\s*\/?>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\s*\/\s*(div|p|li|ul|ol|h[1-6])\s*>/iu', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        $lines = preg_split('/\n/', $text) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^--[-_=.a-z0-9]+$/i', $line) === 1) {
                continue;
            }
            if (preg_match('/^(content-type|content-transfer-encoding|mime-version):/i', $line) === 1) {
                continue;
            }
            if (preg_match('/^summary of your request:?$/i', $line) === 1) {
                continue;
            }

            $kept[] = $line;
        }

        $questionLines = array_values(array_filter($kept, static function (string $line): bool {
            return preg_match('/\?$/u', $line) === 1;
        }));
        if (count($questionLines)) {
            $kept = array_slice($questionLines, 0, 4);
        }

        $clean = trim(implode("\n", $kept));
        if ($clean === '') {
            $clean = trim((string) strip_tags($text));
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($clean, 'UTF-8') > $maxLength) {
                $clean = rtrim(mb_substr($clean, 0, $maxLength, 'UTF-8')) . '...';
            }
        } elseif (strlen($clean) > $maxLength) {
            $clean = rtrim(substr($clean, 0, $maxLength)) . '...';
        }

        return $clean;
    }

    private function normalizeReasoningEffort($value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || in_array($value, ['off', 'disabled', 'disable', 'no', 'false', '0'], true)) {
            return null;
        }

        return in_array($value, ['none', 'low', 'medium', 'high', 'xhigh'], true) ? $value : null;
    }

    public function request(string $method, string $path, ?array $payload = null): array
    {
        if (!$this->hasToken()) {
            throw new RuntimeException('MAIL_ASSISTANT_TOOLS_TOKEN is not configured.');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Tools request failed: ' . ($error ?: 'Unknown cURL failure.'));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Tools request returned a non-JSON response (HTTP ' . $status . ').');
        }

        if ($status >= 400 || (!empty($decoded['ok']) === false && isset($decoded['message']))) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $status));
            throw new RuntimeException($message);
        }

        return $decoded;
    }
}

