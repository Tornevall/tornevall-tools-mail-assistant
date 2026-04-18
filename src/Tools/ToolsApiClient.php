<?php

namespace MailSupportAssistant\Tools;

use MailSupportAssistant\Mail\MimeDecoder;
use MailSupportAssistant\Support\Env;
use RuntimeException;

class ToolsApiClient
{
    private const CLIENT_VERSION = '0.3.15';

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
        $reply = (array) ($rule['reply'] ?? []);
        $primaryModel = trim((string) (($reply['ai_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_MODEL', 'gpt-5.4')));
        $fallbackModel = trim((string) Env::get('MAIL_ASSISTANT_AI_FALLBACK_MODEL', 'o4'));
        $reasoningEffort = $this->normalizeReasoningEffort(($reply['ai_reasoning_effort'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_REASONING_EFFORT', 'medium'));
        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        $cleanBody = $this->buildIncomingMessageExcerpt($message, 2400);

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

        $customInstruction = trim((string) (($reply['custom_instruction'] ?? null) ?: ''));
        $responderName = trim((string) (($reply['responder_name'] ?? null) ?: ''));
        $personaProfile = trim((string) (($reply['persona_profile'] ?? null) ?: ''));
        $mood = trim((string) (($reply['mood'] ?? null) ?: ''));
        $userPrompt = 'Reply helpfully to this support email. Use the same language as the incoming email unless the sender explicitly asks for another language.';

        $payload = [
            'context' => trim(implode("\n", $contextLines)),
            'user_prompt' => $userPrompt,
            'modifier' => 'short',
            'model' => $primaryModel,
            'request_mode' => 'reply',
            'response_language' => 'auto',
            'client_name' => 'Tornevall Tools Mail Assistant',
            'client_version' => self::CLIENT_VERSION,
            'client_platform' => 'php_standalone',
        ];
        if ($responderName !== '') {
            $payload['responder_name_override'] = $responderName;
        }
        if ($personaProfile !== '') {
            $payload['persona_profile_override'] = $personaProfile;
        }
        if ($customInstruction !== '') {
            $payload['custom_instruction_override'] = $customInstruction;
        }
        if ($mood !== '') {
            $payload['mood'] = $mood;
        }

        return $this->executeAiRequest($payload, $primaryModel, $fallbackModel, $reasoningEffort);
    }

    public function generateGenericAiReply(array $mailbox, array $message, array $options = []): array
    {
        $primaryModel = trim((string) (($options['ai_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_MODEL', 'gpt-5.4')));
        $fallbackModel = trim((string) (($options['ai_fallback_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_FALLBACK_MODEL', 'o4')));
        $reasoningEffort = $this->normalizeReasoningEffort(($options['ai_reasoning_effort'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_REASONING_EFFORT', 'medium'));

        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        $cleanBody = $this->buildIncomingMessageExcerpt($message, 2600);
        $assistantHint = trim((string) ($options['custom_instruction'] ?? ''));
        $userPrompt = 'A support email did not match any explicit mailbox rule. Reply briefly and helpfully only if the request seems answerable from the provided context. Use the same language as the incoming email unless the sender explicitly asks for another language. If key details are missing, ask a concise follow-up question.';

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
            'response_language' => 'auto',
            'client_name' => 'Tornevall Tools Mail Assistant',
            'client_version' => self::CLIENT_VERSION,
            'client_platform' => 'php_standalone',
        ];
        if ($assistantHint !== '') {
            $payload['custom_instruction_override'] = $assistantHint;
        }

        return $this->executeAiRequest($payload, $primaryModel, $fallbackModel, $reasoningEffort);
    }

    private function executeAiRequest(array $payload, string $primaryModel, string $fallbackModel, ?string $reasoningEffort): array
    {
        $payload = $this->applyReasoningEffortToPayload($payload, $reasoningEffort);

        try {
            return $this->performAiRequestWithRetry($payload);
        } catch (RuntimeException $primaryFailure) {
            if ($fallbackModel === '' || strcasecmp($fallbackModel, $primaryModel) === 0) {
                throw new RuntimeException(
                    'AI request failed (models tried: ' . $this->formatModelTrail($primaryModel) . '): ' . $primaryFailure->getMessage(),
                    0,
                    $primaryFailure
                );
            }

            $fallbackPayload = $payload;
            $fallbackPayload['model'] = $fallbackModel;
            $fallbackPayload = $this->applyReasoningEffortToPayload($fallbackPayload, $reasoningEffort);

            try {
                $fallbackResult = $this->performAiRequestWithRetry($fallbackPayload);
            } catch (RuntimeException $fallbackFailure) {
                throw new RuntimeException(
                    'AI request failed (models tried: ' . $this->formatModelTrail($primaryModel, $fallbackModel) . '): ' . $fallbackFailure->getMessage(),
                    0,
                    $fallbackFailure
                );
            }

            if (!array_key_exists('used_fallback_model', $fallbackResult)) {
                $fallbackResult['used_fallback_model'] = true;
            }
            $fallbackResult['fallback_from_model'] = $primaryModel;
            if (!isset($fallbackResult['model']) || trim((string) $fallbackResult['model']) === '') {
                $fallbackResult['model'] = $fallbackModel;
            }

            return $fallbackResult;
        }
    }

    private function applyReasoningEffortToPayload(array $payload, ?string $reasoningEffort): array
    {
        unset($payload['reasoning_effort']);
        if ($reasoningEffort === null) {
            return $payload;
        }

        $model = strtolower(trim((string) ($payload['model'] ?? '')));
        if (!$this->supportsReasoningEffort($model)) {
            return $payload;
        }

        $payload['reasoning_effort'] = $reasoningEffort;

        return $payload;
    }

    private function supportsReasoningEffort(string $model): bool
    {
        if ($model === '') {
            return false;
        }

        return strpos($model, 'gpt-5') === 0
            || strpos($model, 'gpt-4o') === 0
            || strpos($model, 'o1') === 0
            || strpos($model, 'o3') === 0;
    }

    private function formatModelTrail(string $primaryModel, string $fallbackModel = ''): string
    {
        $models = array_values(array_filter([
            trim($primaryModel),
            trim($fallbackModel),
        ], static function (string $model): bool {
            return $model !== '';
        }));

        return implode(' -> ', array_values(array_unique($models)));
    }

    private function performAiRequestWithRetry(array $payload): array
    {
        $maxAttempts = max(1, (int) Env::get('MAIL_ASSISTANT_AI_RETRY_ATTEMPTS', '3'));
        $baseDelayMs = max(0, (int) Env::get('MAIL_ASSISTANT_AI_RETRY_DELAY_MS', '2000'));
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                return $this->request('POST', '/ai/socialgpt/respond', $payload);
            } catch (RuntimeException $e) {
                $lastException = $e;
                if (!$this->shouldRetryAiException($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $delayMs = $this->resolveAiRetryDelayMs($attempt, $baseDelayMs);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw $lastException ?: new RuntimeException('AI request failed after retries.');
    }

    private function shouldRetryAiException(RuntimeException $e): bool
    {
        $message = strtolower(trim($e->getMessage()));
        if ($message === '') {
            return false;
        }

        return preg_match('/(^|\D)429(\D|$)/', $message) === 1
            || strpos($message, 'too many attempts') !== false
            || strpos($message, 'rate limit') !== false
            || strpos($message, 'retry after') !== false;
    }

    private function resolveAiRetryDelayMs(int $attempt, int $baseDelayMs): int
    {
        if ($baseDelayMs <= 0) {
            return 0;
        }

        $multiplier = 1;
        if ($attempt > 1) {
            $multiplier = 1 << ($attempt - 1);
        }

        return min(30000, $baseDelayMs * $multiplier);
    }

    private function sanitizeSummaryText(string $text, int $maxLength = 2400): string
    {
        return MimeDecoder::extractRequestSummaryText($text, $maxLength);
    }

    private function buildIncomingMessageExcerpt(array $message, int $maxLength): string
    {
        $candidates = [
            (string) (($message['body_text_reply_aware'] ?? null) ?: ''),
            (string) (($message['body_text'] ?? null) ?: ''),
            (string) (($message['body_text_raw'] ?? null) ?: ''),
        ];

        foreach ($candidates as $candidate) {
            $excerpt = $this->sanitizeSummaryText($candidate, $maxLength);
            if ($excerpt !== '') {
                return $excerpt;
            }
        }

        return '';
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

