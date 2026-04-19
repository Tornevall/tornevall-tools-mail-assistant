<?php

namespace MailSupportAssistant\Mail;

use RuntimeException;

class ImapMailboxClient
{
    /** @var mixed */
    private $stream = null;

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): void
    {
        if (!function_exists('imap_open')) {
            throw new RuntimeException('ext-imap is not installed, so mailbox polling is unavailable.');
        }

        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 993);
        $user = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $folder = trim((string) ($this->config['folder'] ?? 'INBOX'));
        $flags = trim((string) ($this->config['encryption'] ?? 'ssl'));

        if ($host === '' || $user === '') {
            throw new RuntimeException('Mailbox config is incomplete: host and username are required.');
        }

        $mailboxPath = sprintf('{%s:%d/imap/%s}%s', $host, $port, $flags !== '' ? $flags : 'ssl', $folder !== '' ? $folder : 'INBOX');
        $stream = @imap_open($mailboxPath, $user, $password);
        if (!$stream) {
            throw new RuntimeException('IMAP connection failed: ' . trim((string) imap_last_error()));
        }

        $this->stream = $stream;
    }

    public function fetchUnseenMessages(int $limit = 20): array
    {
        $stream = $this->requireStream();
        $messageNumbers = @imap_search($stream, 'UNSEEN', SE_UID);
        if (!is_array($messageNumbers)) {
            return [];
        }

        $messageNumbers = array_slice($messageNumbers, 0, max(1, $limit));
        $messages = [];
        foreach ($messageNumbers as $uid) {
            $messages[] = $this->fetchMessageByUid((int) $uid);
        }

        return array_filter($messages);
    }

    public function moveMessage(int $uid, string $folder): bool
    {
        $stream = $this->requireStream();
        if ($folder === '') {
            return false;
        }

        $result = @imap_mail_move($stream, (string) $uid, $folder, CP_UID);
        if ($result) {
            @imap_expunge($stream);
        }

        return (bool) $result;
    }

    public function deleteMessage(int $uid): bool
    {
        $stream = $this->requireStream();
        $result = @imap_delete($stream, (string) $uid, FT_UID);
        if ($result) {
            @imap_expunge($stream);
        }

        return (bool) $result;
    }

    public function markSeen(int $uid): bool
    {
        $stream = $this->requireStream();
        return (bool) @imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID);
    }

    public function markUnseen(int $uid): bool
    {
        $stream = $this->requireStream();
        if (!function_exists('imap_clearflag_full')) {
            return false;
        }

        return (bool) @imap_clearflag_full($stream, (string) $uid, '\\Seen', ST_UID);
    }

    public function close(): void
    {
        if ($this->stream) {
            @imap_close($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function fetchMessageByUid(int $uid): array
    {
        $stream = $this->requireStream();
        $overviewList = @imap_fetch_overview($stream, (string) $uid, FT_UID);
        $overview = is_array($overviewList) && isset($overviewList[0]) ? $overviewList[0] : null;
        $messageNo = $overview && isset($overview->msgno) ? (int) $overview->msgno : (int) @imap_msgno($stream, $uid);
        if ($messageNo < 1) {
            return [];
        }

        $headers = @imap_headerinfo($stream, $messageNo);
        $rawHeaders = (string) @imap_fetchheader($stream, $messageNo, FT_PREFETCHTEXT);
        $headerMap = MimeDecoder::parseHeaders($rawHeaders);
        $structure = @imap_fetchstructure($stream, $messageNo);
        [$textBody, $htmlBody] = $this->extractBodies($messageNo, $structure);
        $cleanedBody = MimeDecoder::stripSpamAssassinWrapper($textBody);
        $bodyText = (string) ($cleanedBody['body'] ?? $textBody);
        $bodyTextReplyAware = MimeDecoder::stripQuotedReplyText($bodyText);
        $messageId = MimeDecoder::normalizeMessageId((string) ($headerMap['message-id'] ?? ''));
        $inReplyTo = MimeDecoder::normalizeMessageId((string) ($headerMap['in-reply-to'] ?? ''));
        $references = MimeDecoder::normalizeMessageIdList((string) ($headerMap['references'] ?? ''));
        $subject = MimeDecoder::decodeHeader((string) ($overview->subject ?? ''));
        $subjectNormalized = MimeDecoder::normalizeReplySubject($subject);

        $from = '';
        if ($headers && !empty($headers->from[0])) {
            $fromPart = $headers->from[0];
            $from = trim((string) (($fromPart->mailbox ?? '') . '@' . ($fromPart->host ?? '')), '@');
        }

        $to = '';
        if ($headers && !empty($headers->to[0])) {
            $toPart = $headers->to[0];
            $to = trim((string) (($toPart->mailbox ?? '') . '@' . ($toPart->host ?? '')), '@');
        }

        return [
            'uid' => $uid,
            'message_no' => $messageNo,
            'is_seen' => !empty($overview->seen),
            'message_id' => $messageId,
            'message_key' => $messageId !== '' ? $messageId : strtolower(sha1(implode('|', [
                $uid,
                $subjectNormalized !== '' ? $subjectNormalized : $subject,
                $from,
                $to,
                (string) ($overview->date ?? ''),
            ]))),
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'subject' => $subject,
            'subject_normalized' => $subjectNormalized,
            'from' => MimeDecoder::decodeHeader($from),
            'to' => MimeDecoder::decodeHeader($to),
            'date' => (string) ($overview->date ?? ''),
            'headers_raw' => $rawHeaders,
            'headers_map' => $headerMap,
            'body_text_raw' => $textBody,
            'body_text' => $bodyText,
            'body_text_reply_aware' => $bodyTextReplyAware,
            'body_html' => $htmlBody,
            'spam_assassin' => MimeDecoder::analyzeSpamAssassin(
                $headerMap,
                $subject,
                $textBody
            ),
            'spam_assassin_wrapper_removed' => !empty($cleanedBody['wrapper_removed']),
        ];
    }

    private function extractBodies(int $messageNo, $structure, string $partNumber = ''): array
    {
        $stream = $this->requireStream();
        $textBody = '';
        $htmlBody = '';

        if (!$structure) {
            $body = (string) @imap_body($stream, $messageNo, FT_PEEK);
            return [MimeDecoder::normalizeText($body), ''];
        }

        if (!empty($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                [$partText, $partHtml] = $this->extractBodies($messageNo, $part, $partNumber === '' ? (string) ($index + 1) : $partNumber . '.' . ($index + 1));
                if ($textBody === '' && $partText !== '') {
                    $textBody = $partText;
                }
                if ($htmlBody === '' && $partHtml !== '') {
                    $htmlBody = $partHtml;
                }
            }

            return [$textBody, $htmlBody];
        }

        $section = $partNumber === '' ? '1' : $partNumber;
        $raw = (string) @imap_fetchbody($stream, $messageNo, $section, FT_PEEK);
        $decoded = MimeDecoder::decodePartBody($raw, (int) ($structure->encoding ?? 0));
        $subtype = strtoupper((string) ($structure->subtype ?? 'PLAIN'));

        if ($subtype === 'HTML') {
            $htmlBody = $decoded;
        } else {
            $textBody = strip_tags($decoded);
        }

        return [$textBody, $htmlBody];
    }

    private function requireStream()
    {
        if (!$this->stream) {
            $this->connect();
        }

        return $this->stream;
    }
}

