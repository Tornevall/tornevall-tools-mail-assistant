<?php

namespace MailSupportAssistant\Tools;

use MailSupportAssistant\Support\Env;
use RuntimeException;

class ToolsApiClient
{
    private const CLIENT_VERSION = '0.3.0';

    private string $baseUrl;
    private string $token;
    private string $mailToken;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim((string) ($baseUrl ?: Env::get('MAIL_ASSISTANT_TOOLS_BASE_URL', 'https://tools.tornevall.net/api')), '/');
        $this->token = trim((string) ($token ?: Env::get('MAIL_ASSISTANT_TOOLS_TOKEN', '')));
        $this->mailToken = trim((string) Env::get('MAIL_ASSISTANT_TOOLS_MAIL_TOKEN', ''));
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    public function hasMailToken(): bool
    {
        return $this->mailToken !== '';
    }

    public function fetchConfig(): array
    {
        return $this->request('GET', '/mail-support-assistant/config');
    }

    public function sendReplyViaTools(array $payload): array
    {
        if (!$this->hasMailToken()) {
            throw new RuntimeException('MAIL_ASSISTANT_TOOLS_MAIL_TOKEN is not configured.');
        }

        return $this->request('POST', '/mail-support-assistant/send-reply', $payload, $this->mailToken);
    }

    public function generateAiReply(array $mailbox, array $rule, array $message): array
    {
        $primaryModel = trim((string) (($rule['reply']['ai_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_MODEL', 'gpt-5.4')));
        $fallbackModel = trim((string) Env::get('MAIL_ASSISTANT_AI_FALLBACK_MODEL', 'gpt-4o-mini'));
        $reasoningEffort = $this->normalizeReasoningEffort(($rule['reply']['ai_reasoning_effort'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_REASONING_EFFORT', 'medium'));
        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        $cleanBody = $this->sanitizeSummaryText((string) (($message['body_text_reply_aware'] ?? null) ?: ($message['body_text'] ?? '')), 2400);

        $contextLines = [
            'Mailbox: ' . (string) ($mailbox['name'] ?? ''),
            'From: ' . (string) ($message['from'] ?? ''),
            'To: ' . (string) ($message['to'] ?? ''),
            'Subject: ' . (string) ($message['subject'] ?? ''),
            'Subject (normalized): ' . (string) (($message['subject_normalized'] ?? null) ?: ($message['subject'] ?? '')),
            'In-Reply-To: ' . (string) ($message['in_reply_to'] ?? ''),
            'References: ' . implode(', ', array_values((array) ($message['references'] ?? []))),
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
        return $this->executeAiRequest($payload, $primaryModel, $fallbackModel, $reasoningEffort);
    }

    public function generateGenericAiReply(array $mailbox, array $message, array $options = []): array
    {
        $primaryModel = trim((string) (($options['ai_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_MODEL', 'gpt-5.4')));
        $fallbackModel = trim((string) (($options['ai_fallback_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_FALLBACK_MODEL', 'gpt-4o-mini')));
        $reasoningEffort = $this->normalizeReasoningEffort(($options['ai_reasoning_effort'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_REASONING_EFFORT', 'medium'));

        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        $cleanBody = $this->sanitizeSummaryText((string) (($message['body_text_reply_aware'] ?? null) ?: ($message['body_text'] ?? '')), 2600);
        $assistantHint = trim((string) ($options['custom_instruction'] ?? ''));
        $userPrompt = 'A support email did not match any explicit mailbox rule. Reply briefly and helpfully only if the request seems answerable from the provided context. If key details are missing, ask a concise follow-up question.';
        if ($assistantHint !== '') {
            $userPrompt .= ' ' . $assistantHint;
        }

        $contextLines = [
            'Mailbox: ' . (string) ($mailbox['name'] ?? ''),
            'From: ' . (string) ($message['from'] ?? ''),
            'To: ' . (string) ($message['to'] ?? ''),
            'Subject: ' . (string) ($message['subject'] ?? ''),
            'Subject (normalized): ' . (string) (($message['subject_normalized'] ?? null) ?: ($message['subject'] ?? '')),
            'In-Reply-To: ' . (string) ($message['in_reply_to'] ?? ''),
            'References: ' . implode(', ', array_values((array) ($message['references'] ?? []))),
            'SpamAssassin: present=' . (!empty($spam['present']) ? 'yes' : 'no')
                . ', flagged=' . (!empty($spam['flagged']) ? 'yes' : 'no')
                . ', score=' . (($spam['score'] ?? null) !== null ? (string) $spam['score'] : 'n/a')
                . ', tests=' . implode(',', array_values((array) ($spam['tests'] ?? []))),
            '',
            'Request summary (sanitized):',
            $cleanBody,
        ];

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

        return $this->executeAiRequest($payload, $primaryModel, $fallbackModel, $reasoningEffort);
    }

    private function executeAiRequest(array $payload, string $primaryModel, string $fallbackModel, ?string $reasoningEffort): array
    {
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

    public function request(string $method, string $path, ?array $payload = null, ?string $tokenOverride = null): array
    {
        $token = trim((string) ($tokenOverride ?? $this->token));
        if ($token === '') {
            throw new RuntimeException('MAIL_ASSISTANT_TOOLS_TOKEN is not configured.');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
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

        if ($status >= 400 || (!empty($decoded['ok']) === false && (isset($decoded['message']) || isset($decoded['error'])))) {
            $message = $this->stringifyApiValue($decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $status));
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function stringifyApiValue($value): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : 'Unknown API error.';
        }

        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, static function ($entry) use (&$flat): void {
                if (is_scalar($entry)) {
                    $entry = trim((string) $entry);
                    if ($entry !== '') {
                        $flat[] = $entry;
                    }
                }
            });

            if (count($flat)) {
                return implode('; ', array_values(array_unique($flat)));
            }

            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json) && $json !== '') {
                return $json;
            }
        }

        return 'Unknown API error.';
    }
}

