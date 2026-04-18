<?php

namespace MailSupportAssistant\Tools;

use MailSupportAssistant\Mail\MimeDecoder;
use MailSupportAssistant\Support\Env;
use RuntimeException;

class ToolsApiClient
{
    private const CLIENT_VERSION = '0.3.17';

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

    public function evaluateGenericNoMatchReply(array $mailbox, array $message, array $options = []): array
    {
        $primaryModel = trim((string) (($options['ai_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_MODEL', 'gpt-5.4')));
        $fallbackModel = trim((string) (($options['ai_fallback_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_FALLBACK_MODEL', 'o4')));
        $reasoningEffort = $this->normalizeReasoningEffort(($options['ai_reasoning_effort'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_REASONING_EFFORT', 'medium'));
        $ifCondition = trim((string) ($options['if_condition'] ?? ''));
        $replyInstruction = trim((string) ($options['reply_instruction'] ?? ''));

        if ($ifCondition === '') {
            return [
                'can_reply' => false,
                'certainty' => 'low',
                'reason' => 'Generic no-match AI condition is not configured.',
                'decision_reason_code' => 'no_matching_rule_generic_ai_unconfigured',
                'reply' => '',
                'response' => '',
                'risk_flags' => [],
                'raw_response' => '',
            ];
        }

        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        $cleanBody = $this->buildIncomingMessageExcerpt($message, 2600);
        $userPrompt = implode("\n", [
            'You are evaluating whether an unmatched support email may be answered safely.',
            'Return ONLY valid JSON with this schema:',
            '{"can_reply":true|false,"certainty":"high|medium|low","reason":"short reason","risk_flags":["..."],"reply":"reply text or empty string"}',
            'Rules:',
            '- Ignore outer SpamAssassin wrapper prose if it only forwards the original mail, but use SpamAssassin flags/tests as risk signals.',
            '- Only allow a reply when the email clearly matches the admin IF condition below.',
            '- If the mail looks like spam, scam, fraud, phishing, unsolicited sales, unsolicited collaboration outreach, vague marketing, or anything outside the IF condition, set can_reply=false.',
            '- If there is any uncertainty, missing context, or safety doubt, set can_reply=false.',
            '- Only set can_reply=true when you are fully confident. In that case certainty must be "high" and reply must already be written in the original sender language from the email itself.',
            '- When can_reply=false, reply must be an empty string.',
            '',
            'Admin IF condition:',
            $ifCondition,
            '',
            'Admin reply instructions (only use if can_reply=true):',
            $replyInstruction !== '' ? $replyInstruction : 'Reply briefly, politely, and clearly.',
        ]);

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
            'responder_name_override' => 'Mail Support Assistant',
            'persona_profile_override' => 'Extremely cautious support triage classifier that only returns strict JSON.',
            'custom_instruction_override' => 'Return JSON only. Never add commentary, markdown, or code fences unless they still contain exactly one valid JSON object.',
            'client_name' => 'Tornevall Tools Mail Assistant',
            'client_version' => self::CLIENT_VERSION,
            'client_platform' => 'php_standalone',
        ];

        $result = $this->executeAiRequest($payload, $primaryModel, $fallbackModel, $reasoningEffort);
        $rawResponse = trim((string) ($result['response'] ?? ''));
        $decision = $this->parseGenericNoMatchDecision($rawResponse);

        $replyText = trim((string) ($decision['reply'] ?? ''));
        $canReply = !empty($decision['can_reply']) && strtolower((string) ($decision['certainty'] ?? '')) === 'high' && $replyText !== '';
        $reasonCode = (string) ($decision['decision_reason_code'] ?? '');
        if ($reasonCode === '') {
            if (empty($decision['valid_json'])) {
                $reasonCode = 'no_matching_rule_generic_ai_invalid_json';
            } elseif (empty($decision['can_reply'])) {
                $reasonCode = 'no_matching_rule_generic_ai_rejected';
            } elseif (strtolower((string) ($decision['certainty'] ?? '')) !== 'high') {
                $reasonCode = 'no_matching_rule_generic_ai_not_certain';
            } elseif ($replyText === '') {
                $reasonCode = 'no_matching_rule_generic_ai_empty_reply';
            } else {
                $reasonCode = 'no_matching_rule_generic_ai_replied';
            }
        }

        return array_merge($result, [
            'can_reply' => $canReply,
            'certainty' => (string) ($decision['certainty'] ?? 'low'),
            'reason' => (string) ($decision['reason'] ?? ''),
            'decision_reason_code' => $reasonCode,
            'reply' => $replyText,
            'response' => $replyText,
            'risk_flags' => (array) ($decision['risk_flags'] ?? []),
            'raw_response' => $rawResponse,
            'parsed_decision' => $decision,
        ]);
    }

    private function parseGenericNoMatchDecision(string $rawResponse): array
    {
        $json = $this->extractJsonObject($rawResponse);
        if ($json === '') {
            return [
                'valid_json' => false,
                'can_reply' => false,
                'certainty' => 'low',
                'reason' => 'AI did not return a JSON object.',
                'risk_flags' => ['invalid_json'],
                'reply' => '',
                'decision_reason_code' => 'no_matching_rule_generic_ai_invalid_json',
            ];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [
                'valid_json' => false,
                'can_reply' => false,
                'certainty' => 'low',
                'reason' => 'AI returned malformed JSON.',
                'risk_flags' => ['invalid_json'],
                'reply' => '',
                'decision_reason_code' => 'no_matching_rule_generic_ai_invalid_json',
            ];
        }

        $canReply = $this->normalizeDecisionBool(
            $decoded['can_reply']
                ?? $decoded['should_reply']
                ?? $decoded['reply_allowed']
                ?? $decoded['answerable']
                ?? null
        );
        $certainty = strtolower(trim((string) ($decoded['certainty'] ?? $decoded['confidence'] ?? 'low')));
        if (!in_array($certainty, ['high', 'medium', 'low'], true)) {
            $certainty = 'low';
        }

        $reason = trim((string) ($decoded['reason'] ?? $decoded['why'] ?? ''));
        $reply = trim((string) ($decoded['reply'] ?? $decoded['response'] ?? ''));
        $riskFlags = $decoded['risk_flags'] ?? $decoded['risks'] ?? [];
        if (!is_array($riskFlags)) {
            $riskFlags = [$riskFlags];
        }
        $riskFlags = array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $riskFlags), static function (string $value): bool {
            return $value !== '';
        }));

        $canReply = $canReply === true;
        if (!$canReply) {
            $reply = '';
        }

        return [
            'valid_json' => true,
            'can_reply' => $canReply,
            'certainty' => $certainty,
            'reason' => $reason,
            'risk_flags' => $riskFlags,
            'reply' => $reply,
        ];
    }

    private function extractJsonObject(string $rawResponse): string
    {
        $candidate = trim($rawResponse);
        if ($candidate === '') {
            return '';
        }

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $candidate, $m) === 1) {
            $candidate = trim((string) $m[1]);
        }

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return trim(substr($candidate, $start, $end - $start + 1));
    }

    private function normalizeDecisionBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        if (in_array($value, ['1', 'true', 'yes', 'y', 'reply', 'allow'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'n', 'skip', 'deny'], true)) {
            return false;
        }

        return null;
    }

    private function executeAiRequest(array $payload, string $primaryModel, string $fallbackModel, ?string $reasoningEffort): array
    {
        $payload = $this->applyReasoningEffortToPayload($payload, $reasoningEffort);
        $primaryResult = null;

        try {
            $primaryResult = $this->performAiRequestWithRetry($payload);
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

        if ($this->hasUsableAiResponse($primaryResult)) {
            return $primaryResult;
        }

        if ($fallbackModel === '' || strcasecmp($fallbackModel, $primaryModel) === 0) {
            throw new RuntimeException(
                'AI request returned an empty response (models tried: ' . $this->formatModelTrail($primaryModel) . ').'
            );
        }

        $fallbackPayload = $payload;
        $fallbackPayload['model'] = $fallbackModel;
        $fallbackPayload = $this->applyReasoningEffortToPayload($fallbackPayload, $reasoningEffort);

        try {
            $fallbackResult = $this->performAiRequestWithRetry($fallbackPayload);
        } catch (RuntimeException $fallbackFailure) {
            throw new RuntimeException(
                'AI request failed after empty primary response (models tried: ' . $this->formatModelTrail($primaryModel, $fallbackModel) . '): ' . $fallbackFailure->getMessage(),
                0,
                $fallbackFailure
            );
        }

        if ($this->hasUsableAiResponse($fallbackResult)) {
            if (!array_key_exists('used_fallback_model', $fallbackResult)) {
                $fallbackResult['used_fallback_model'] = true;
            }
            $fallbackResult['fallback_from_model'] = $primaryModel;
            if (!isset($fallbackResult['model']) || trim((string) $fallbackResult['model']) === '') {
                $fallbackResult['model'] = $fallbackModel;
            }

            return $fallbackResult;
        }

        throw new RuntimeException(
            'AI request returned an empty response (models tried: ' . $this->formatModelTrail($primaryModel, $fallbackModel) . ').'
        );
    }

    private function hasUsableAiResponse(array $result): bool
    {
        $response = trim((string) ($result['response'] ?? ''));
        if ($response !== '') {
            return true;
        }

        $message = trim((string) ($result['message'] ?? ''));
        if ($message === '') {
            return false;
        }

        if (preg_match('/^(ok|success|accepted|request accepted)\.?$/i', $message) === 1) {
            return false;
        }

        return false;
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

