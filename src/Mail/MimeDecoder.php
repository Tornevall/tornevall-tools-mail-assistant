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

    public static function normalizeReplySubject(?string $subject): string
    {
        $subject = self::decodeHeader((string) ($subject ?? ''));
        if ($subject === '') {
            return '';
        }

        $normalized = trim($subject);
        do {
            $previous = $normalized;
            $normalized = preg_replace('/^(?:(?:re|fw|fwd|sv)\s*:\s*)+/iu', '', $normalized) ?? $normalized;
            $normalized = trim((string) $normalized);
        } while ($normalized !== '' && $normalized !== $previous);

        return $normalized;
    }

    public static function stripQuotedReplyText(string $body): string
    {
        $body = self::normalizeText($body);
        if ($body === '') {
            return '';
        }

        $lines = preg_split('/\n/', $body) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed !== '') {
                if (preg_match('/^>/u', $trimmed) === 1) {
                    break;
                }
                if (preg_match('/^On .+wrote:$/iu', $trimmed) === 1) {
                    break;
                }
                if (preg_match('/^(From|Sent|To|Subject|Date):/iu', $trimmed) === 1 && count($kept) > 0) {
                    break;
                }
                if (preg_match('/^[-_]{2,}\s*Original Message\s*[-_]{2,}$/iu', $trimmed) === 1) {
                    break;
                }
            }

            $kept[] = (string) $line;
        }

        $cleaned = trim(implode("\n", $kept));
        return $cleaned !== '' ? $cleaned : $body;
    }

    public static function extractRequestSummaryText(string $body, int $maxLength = 900): string
    {
        $body = self::normalizeText($body);
        if ($body === '') {
            return '';
        }

        if (function_exists('quoted_printable_decode') && preg_match('/=(?:\r?\n|[A-Fa-f0-9]{2})/', $body) === 1) {
            $decoded = quoted_printable_decode($body);
            if (is_string($decoded) && trim($decoded) !== '') {
                $body = self::normalizeText($decoded);
            }
        }

        $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
        $body = preg_replace('/<\s*br\s*\/?>/iu', "\n", $body) ?? $body;
        $body = preg_replace('/<\s*\/\s*(div|p|li|ul|ol|h[1-6])\s*>/iu', "\n", $body) ?? $body;
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', ' ', $body) ?? $body;
        $body = self::normalizeText($body);

        $wrapper = self::stripSpamAssassinWrapper($body);
        $candidate = (string) ($wrapper['body'] ?? $body);
        $candidate = self::normalizeEmbeddedHeaderRuns($candidate);
        $candidate = self::trimForwardedAttachmentPrefix($candidate);
        $candidate = self::stripLeadingEmbeddedHeaders($candidate);
        $candidate = self::removeSpamAssassinNarration($candidate);
        $bodyMarkerSource = $candidate;
        $candidate = self::stripQuotedReplyText($candidate);
        $candidate = self::normalizeText($candidate);
        if ($candidate === '') {
            return '';
        }

        $lines = preg_split('/\n/', $candidate) ?: [];
        $kept = [];
        $skipPrefixPatterns = [
            '/^Content analysis details:/i',
            '/^Spam detection software,/i',
            '/^has identified this incoming email as possible spam\.?$/i',
            '/^The original message has been attached to this/i',
            '/^The original message was not completely plain text/i',
            '/^If you wish to view it, it may be safer/i',
            '/^Content preview:/i',
            '/^pts\s+rule name/i',
            '/^----\s+----------------------/i',
            '/^[0-9]+(?:\.[0-9]+)?\s+[A-Z0-9_]{3,}\s{2,}/',
            '/^summary of your request:?$/i',
            '/^ForwardedMessage\.eml$/i',
            '/^-----BEGIN PGP SIGNED MESSAGE-----$/i',
            '/^-----BEGIN PGP SIGNATURE-----$/i',
            '/^-----END PGP SIGNATURE-----$/i',
            '/^Hash:\s*SHA1$/i',
            '/^Version:\s*GnuPG/i',
            '/^(?:content-type|content-transfer-encoding|mime-version):/i',
            '/^--[-_=.a-z0-9]+$/i',
        ];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                if (count($kept) && end($kept) !== '') {
                    $kept[] = '';
                }
                continue;
            }

            $skip = false;
            foreach ($skipPrefixPatterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $kept[] = $line;
        }

        $questionLines = array_values(array_filter($kept, static function (string $line): bool {
            return preg_match('/\?$/u', $line) === 1
                || stripos($line, 'question:') === 0
                || stripos($line, 'fråga:') === 0
                || stripos($line, 'fraga:') === 0;
        }));
        if (count($questionLines)) {
            $kept = array_slice($questionLines, 0, 4);
        }

        $kept = self::stripLeadingHeaderishLines($kept);

        $deduplicated = [];
        $seenLines = [];
        foreach ($kept as $line) {
            $normalizedLine = preg_replace('/\s+/u', ' ', trim((string) $line)) ?? trim((string) $line);
            if ($normalizedLine === '') {
                if (count($deduplicated) && end($deduplicated) !== '') {
                    $deduplicated[] = '';
                }
                continue;
            }

            $dedupeKey = function_exists('mb_strtolower')
                ? mb_strtolower($normalizedLine, 'UTF-8')
                : strtolower($normalizedLine);
            if (isset($seenLines[$dedupeKey])) {
                continue;
            }

            $seenLines[$dedupeKey] = true;
            $deduplicated[] = trim((string) $line);
        }

        $excerpt = trim(implode("\n", $deduplicated));
        $excerpt = preg_replace('/\n{3,}/u', "\n\n", $excerpt) ?? $excerpt;
        $excerpt = self::cropToLikelyBodyStart($excerpt);
        $bodyMarkerExcerpt = self::extractFromLikelyBodyMarkers($bodyMarkerSource, $maxLength);
        if ($bodyMarkerExcerpt !== '' && strlen($bodyMarkerExcerpt) > strlen($excerpt)) {
            $excerpt = $bodyMarkerExcerpt;
        }
        if ($excerpt === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($excerpt, 'UTF-8') > $maxLength) {
                $excerpt = rtrim(mb_substr($excerpt, 0, $maxLength, 'UTF-8')) . '…';
            }
        } elseif (strlen($excerpt) > $maxLength) {
            $excerpt = rtrim(substr($excerpt, 0, $maxLength)) . '...';
        }

        return trim($excerpt);
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

    public static function normalizeMessageIdList(?string $value): array
    {
        $value = self::decodeHeader((string) ($value ?? ''));
        if (trim($value) === '') {
            return [];
        }

        preg_match_all('/<([^>]+)>/', $value, $matches);
        $ids = array_values(array_filter(array_map(static function ($candidate): string {
            return self::normalizeMessageId((string) $candidate);
        }, (array) ($matches[1] ?? []))));

        if (count($ids)) {
            return array_values(array_unique($ids));
        }

        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $ids = array_values(array_filter(array_map(static function ($candidate): string {
            return self::normalizeMessageId((string) $candidate);
        }, $parts)));

        return array_values(array_unique($ids));
    }

    public static function analyzeSpamAssassin(array $headers, string $subject = '', string $body = ''): array
    {
        $status = (string) ($headers['x-spam-status'] ?? '');
        $present = isset($headers['x-spam-status'])
            || isset($headers['x-spam-score'])
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

        if ($score === null) {
            $scoreHeader = trim((string) ($headers['x-spam-score'] ?? ''));
            if ($scoreHeader !== '' && preg_match('/([-+]?[0-9]*\.?[0-9]+)/', $scoreHeader, $scoreMatch) === 1) {
                $score = (float) $scoreMatch[1];
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

    private static function normalizeEmbeddedHeaderRuns(string $text): string
    {
        $headerNames = '(Subject|From|Date|To|Cc|Bcc|Reply-To|Message-ID|MIME-Version|Content-Type|Content-Transfer-Encoding|Return-Path|Delivered-To|Authentication-Results|Received|Auto-Submitted|DKIM-Signature|X-[A-Za-z0-9-]+|ARC-[A-Za-z-]+|Ämne|Amne|mne|Från|Fran|Frn|Datum|Till|Kopia|Svar till|Meddelande-ID)';

        $text = preg_replace('/(?<!\n)(' . $headerNames . '):\s*/u', "\n$1: ", $text) ?? $text;

        return self::normalizeText($text);
    }

    private static function trimForwardedAttachmentPrefix(string $text): string
    {
        $text = preg_replace('/^ForwardedMessage\.eml\s*/iu', '', $text, 1) ?? $text;

        return self::normalizeText($text);
    }

    private static function stripLeadingEmbeddedHeaders(string $text): string
    {
        $lines = preg_split('/\n/', self::normalizeText($text)) ?: [];
        $headerPattern = '/^(?:[A-Za-z][A-Za-z0-9-]*|X-[A-Za-z0-9-]+|ARC-[A-Za-z-]+|Ämne|Amne|mne|Från|Fran|Frn|Datum|Till|Kopia|Svar till|Meddelande-ID):/u';
        $headerCount = 0;

        foreach ($lines as $index => $line) {
            $trimmed = trim((string) $line);
            $headerProbe = preg_replace('/^[^\pL\pN]+/u', '', $trimmed) ?? $trimmed;
            if ($trimmed === '') {
                if ($headerCount > 0) {
                    continue;
                }
                break;
            }

            if (preg_match('/^ForwardedMessage\.eml$/iu', $trimmed) === 1) {
                continue;
            }

            if (preg_match($headerPattern, $headerProbe) === 1) {
                $headerCount++;
                continue;
            }

            if ($headerCount > 0 && ($line[0] === ' ' || $line[0] === "\t")) {
                continue;
            }

            if ($headerCount >= 3) {
                $remaining = implode("\n", array_slice($lines, $index));
                return self::normalizeText($remaining);
            }

            break;
        }

        return self::normalizeText($text);
    }

    private static function removeSpamAssassinNarration(string $text): string
    {
        $patterns = [
            '/^Spam detection software, running on the system.*?(?:\n\n|\nContent preview:\n)/is',
            '/^Content analysis details:.*?(?:\n\n|\nForwardedMessage\.eml\n)/is',
            '/^The original message was not completely plain text.*?(?:\n\n|\nForwardedMessage\.eml\n)/is',
        ];

        foreach ($patterns as $pattern) {
            $updated = preg_replace($pattern, '', $text, 1);
            if (is_string($updated) && $updated !== $text) {
                $text = self::normalizeText($updated);
            }
        }

        return self::normalizeText($text);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function stripLeadingHeaderishLines(array $lines): array
    {
        $headerishCount = 0;
        foreach ($lines as $index => $line) {
            $probe = preg_replace('/^[^\pL\pN]+/u', '', trim((string) $line)) ?? trim((string) $line);
            if ($probe === '') {
                if ($headerishCount > 0) {
                    continue;
                }
                break;
            }

            if (preg_match('/^[A-Za-z0-9-]{2,40}:/u', $probe) === 1) {
                $headerishCount++;
                continue;
            }

            if ($headerishCount >= 3) {
                return array_slice($lines, $index);
            }

            break;
        }

        return $lines;
    }

    private static function cropToLikelyBodyStart(string $excerpt): string
    {
        $excerpt = self::normalizeText($excerpt);
        if ($excerpt === '') {
            return '';
        }

        if (preg_match('/(?:^|\n)(Dear\b.*|Hello\b.*|Hej\b.*|Hi\b.*|Notice ID:.*|We\b.*)/u', $excerpt, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $matchText = (string) ($matches[1][0] ?? '');
            $offset = (int) ($matches[1][1] ?? 0);
            if ($matchText !== '' && $offset > 0) {
                $prefix = substr($excerpt, 0, $offset);
                if (preg_match_all('/(^|\n)[A-Za-z0-9-]{2,40}:/u', (string) $prefix) >= 3) {
                    return self::normalizeText(substr($excerpt, $offset));
                }
            }
        }

        return $excerpt;
    }

    private static function extractFromLikelyBodyMarkers(string $text, int $maxLength): string
    {
        $text = self::normalizeText($text);
        if ($text === '') {
            return '';
        }

        if (preg_match('/(?:Notice ID:|Dear\b|Hello\b|Hej\b|Hi\b|We are\b)/u', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $offset = (int) ($match[0][1] ?? 0);
        $excerpt = self::normalizeText(substr($text, $offset));
        $excerpt = preg_replace('/^-----BEGIN PGP SIGNED MESSAGE-----\nHash:\s*SHA1\n*/iu', '', $excerpt) ?? $excerpt;
        $excerpt = preg_replace('/\n{3,}/u', "\n\n", $excerpt) ?? $excerpt;

        if ($excerpt === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($excerpt, 'UTF-8') > $maxLength) {
                $excerpt = rtrim(mb_substr($excerpt, 0, $maxLength, 'UTF-8')) . '…';
            }
        } elseif (strlen($excerpt) > $maxLength) {
            $excerpt = rtrim(substr($excerpt, 0, $maxLength)) . '...';
        }

        return trim($excerpt);
    }
}

