<?php

namespace MailSupportAssistant\Mail;

class MimeDecoder
{
    public static function decodeHeader(?string $value): string
    {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                return trim($decoded);
            }
        }

        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if (is_string($decoded) && $decoded !== '') {
                return trim($decoded);
            }
        }

        return trim($value);
    }

    public static function decodePartBody(string $body, int $encoding): string
    {
        switch ($encoding) {
            case 3:
                $body = base64_decode($body, true) ?: $body;
                break;
            case 4:
                $body = quoted_printable_decode($body);
                break;
        }

        return self::normalizeText($body);
    }

    public static function normalizeText(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return trim($body);
    }

    public static function parseHeaders(string $rawHeaders): array
    {
        $rawHeaders = self::normalizeText($rawHeaders);
        if ($rawHeaders === '') {
            return [];
        }

        $headers = [];
        $currentName = null;
        foreach (preg_split('/\n/', $rawHeaders) ?: [] as $line) {
            $line = rtrim((string) $line);
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
                $headers[$currentName] .= ' ' . trim($line);
                continue;
            }

            if (strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $currentName = strtolower(trim((string) $name));
            $headers[$currentName] = trim(self::decodeHeader($value));
        }

        return $headers;
    }

    public static function normalizeMessageId(?string $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '';
        }

        $value = self::decodeHeader($value);
        $value = trim($value);
        $value = trim($value, "<> \t\n\r\0\x0B");

        return strtolower($value);
    }

    public static function analyzeSpamAssassin(array $headers, string $subject = '', string $body = ''): array
    {
        $status = (string) ($headers['x-spam-status'] ?? '');
        $present = isset($headers['x-spam-status'])
            || isset($headers['x-spam-flag'])
            || isset($headers['x-spam-level'])
            || isset($headers['x-spam-report'])
            || isset($headers['x-spam-checker-version']);

        $score = null;
        $requiredScore = null;
        $tests = [];
        if ($status !== '') {
            if (preg_match('/score=([-+]?[0-9]*\.?[0-9]+)/i', $status, $scoreMatch)) {
                $score = (float) $scoreMatch[1];
            }
            if (preg_match('/required=([-+]?[0-9]*\.?[0-9]+)/i', $status, $requiredMatch)) {
                $requiredScore = (float) $requiredMatch[1];
            }
            if (preg_match('/tests=([^\s]+)/i', $status, $testsMatch)) {
                $tests = array_values(array_filter(array_map('trim', explode(',', (string) $testsMatch[1]))));
            }
        }

        $flagHeader = strtolower(trim((string) ($headers['x-spam-flag'] ?? '')));
        $flagged = in_array($flagHeader, ['yes', 'true', '1'], true);
        if (!$flagged && $requiredScore !== null && $score !== null) {
            $flagged = $score >= $requiredScore;
        }
        if (!$flagged && preg_match('/^\*+$/', trim((string) ($headers['x-spam-level'] ?? ''))) === 1) {
            $flagged = true;
        }

        $body = self::normalizeText($body);
        $subject = self::decodeHeader($subject);
        $isReportWrapper = stripos($body, 'Spam detection software, running on the system') !== false
            || stripos($body, 'Content preview:') !== false
            || stripos($body, 'The original message has been attached to this') !== false
            || stripos($subject, '***SPAM***') !== false;

        return [
            'present' => $present,
            'flagged' => $flagged,
            'score' => $score,
            'required_score' => $requiredScore,
            'tests' => $tests,
            'status' => $status,
            'report' => (string) ($headers['x-spam-report'] ?? ''),
            'is_report_wrapper' => $isReportWrapper,
        ];
    }

    public static function stripSpamAssassinWrapper(string $body): array
    {
        $normalized = self::normalizeText($body);
        if ($normalized === '') {
            return [
                'body' => '',
                'wrapper_removed' => false,
            ];
        }

        $cleaned = $normalized;
        $patterns = [
            '/^Spam detection software, running on the system.*?(?:\n\n|\nContent preview:\n)/is',
            '/^Content preview:\n/is',
            '/^\*{3}SPAM\*{3}\s*/i',
        ];

        foreach ($patterns as $pattern) {
            $updated = preg_replace($pattern, '', $cleaned, 1);
            if (is_string($updated) && $updated !== $cleaned) {
                $cleaned = self::normalizeText($updated);
            }
        }

        return [
            'body' => $cleaned,
            'wrapper_removed' => $cleaned !== $normalized,
        ];
    }
}

