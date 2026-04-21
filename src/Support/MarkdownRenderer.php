<?php

namespace MailSupportAssistant\Support;

final class MarkdownRenderer
{
    public static function toHtml(string $markdown): string
    {
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        if ($markdown === '') {
            return '';
        }

        $lines = preg_split('/\n/', $markdown) ?: [];
        $html = [];
        $paragraph = [];
        $listType = null;
        $listItems = [];
        $quoteLines = [];
        $inCodeFence = false;
        $codeFenceLanguage = '';
        $codeFenceLines = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if (!count($paragraph)) {
                return;
            }

            $text = trim(implode("\n", $paragraph));
            $paragraph = [];
            if ($text === '') {
                return;
            }

            $html[] = '<p style="margin:0 0 16px 0;color:#111827 !important;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;">'
                . self::renderInline($text, true)
                . '</p>';
        };

        $flushList = static function () use (&$listType, &$listItems, &$html): void {
            if ($listType === null || !count($listItems)) {
                $listType = null;
                $listItems = [];
                return;
            }

            $tag = $listType === 'ol' ? 'ol' : 'ul';
            $itemHtml = [];
            foreach ($listItems as $item) {
                $itemHtml[] = '<li style="margin:0 0 8px 0;">' . self::renderInline($item, true) . '</li>';
            }

            $html[] = '<' . $tag . ' style="margin:0 0 16px 24px;padding:0;color:#111827 !important;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;">'
                . implode('', $itemHtml)
                . '</' . $tag . '>';

            $listType = null;
            $listItems = [];
        };

        $flushQuote = static function () use (&$quoteLines, &$html): void {
            if (!count($quoteLines)) {
                return;
            }

            $paragraphs = preg_split('/\n\s*\n/u', trim(implode("\n", $quoteLines))) ?: [];
            $quoteLines = [];
            if (!count($paragraphs)) {
                return;
            }

            $blocks = [];
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim((string) $paragraph);
                if ($paragraph === '') {
                    continue;
                }

                $blocks[] = '<p style="margin:0 0 12px 0;color:#334155 !important;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;">'
                    . self::renderInline($paragraph, true)
                    . '</p>';
            }

            if (!count($blocks)) {
                return;
            }

            $html[] = '<blockquote style="margin:0 0 16px 0;padding:0 0 0 16px;border-left:4px solid #cbd5e1;">'
                . implode('', $blocks)
                . '</blockquote>';
        };

        $flushCodeFence = static function () use (&$inCodeFence, &$codeFenceLanguage, &$codeFenceLines, &$html): void {
            if (!$inCodeFence) {
                return;
            }

            $code = htmlspecialchars(implode("\n", $codeFenceLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $languageClass = $codeFenceLanguage !== ''
                ? ' class="language-' . htmlspecialchars($codeFenceLanguage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                : '';

            $html[] = '<pre style="margin:0 0 16px 0;padding:16px;background:#0f172a;color:#e2e8f0;border-radius:10px;overflow:auto;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.6;"><code'
                . $languageClass
                . '>'
                . $code
                . '</code></pre>';

            $inCodeFence = false;
            $codeFenceLanguage = '';
            $codeFenceLines = [];
        };

        foreach ($lines as $line) {
            $rawLine = (string) $line;
            $trimmed = trim($rawLine);

            if ($inCodeFence) {
                if (preg_match('/^```\s*$/', $trimmed) === 1) {
                    $flushCodeFence();
                    continue;
                }

                $codeFenceLines[] = rtrim($rawLine, "\n");
                continue;
            }

            if (preg_match('/^```\s*([A-Za-z0-9_-]+)?\s*$/', $trimmed, $matches) === 1) {
                $flushParagraph();
                $flushList();
                $flushQuote();
                $inCodeFence = true;
                $codeFenceLanguage = trim((string) ($matches[1] ?? ''));
                $codeFenceLines = [];
                continue;
            }

            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                $flushQuote();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $flushList();
                $flushQuote();
                $level = min(6, max(1, strlen((string) $matches[1])));
                $headingText = trim((string) ($matches[2] ?? ''));
                $fontSizeMap = [1 => '28px', 2 => '24px', 3 => '20px', 4 => '18px', 5 => '16px', 6 => '15px'];
                $marginBottomMap = [1 => '20px', 2 => '18px', 3 => '16px', 4 => '14px', 5 => '12px', 6 => '12px'];
                $html[] = '<h' . $level . ' style="margin:0 0 ' . $marginBottomMap[$level] . ' 0;color:#0f172a !important;font-family:Arial,Helvetica,sans-serif;font-size:' . $fontSizeMap[$level] . ';line-height:1.35;">'
                    . self::renderInline($headingText, false)
                    . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^(?:\*\s*){3,}$|^(?:-\s*){3,}$|^(?:_\s*){3,}$/u', $trimmed) === 1) {
                $flushParagraph();
                $flushList();
                $flushQuote();
                $html[] = '<hr style="margin:0 0 20px 0;border:0;border-top:1px solid #e5e7eb;">';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/u', $rawLine, $matches) === 1) {
                $flushParagraph();
                $flushList();
                $quoteLines[] = (string) ($matches[1] ?? '');
                continue;
            }

            if (preg_match('/^\s*([-*+])\s+(.*)$/u', $rawLine, $matches) === 1) {
                $flushParagraph();
                $flushQuote();
                if ($listType !== null && $listType !== 'ul') {
                    $flushList();
                }
                $listType = 'ul';
                $listItems[] = trim((string) ($matches[2] ?? ''));
                continue;
            }

            if (preg_match('/^\s*\d+\.\s+(.*)$/u', $rawLine, $matches) === 1) {
                $flushParagraph();
                $flushQuote();
                if ($listType !== null && $listType !== 'ol') {
                    $flushList();
                }
                $listType = 'ol';
                $listItems[] = trim((string) ($matches[1] ?? ''));
                continue;
            }

            $flushList();
            $flushQuote();
            $paragraph[] = $rawLine;
        }

        $flushParagraph();
        $flushList();
        $flushQuote();
        $flushCodeFence();

        return implode('', $html);
    }

    private static function renderInline(string $text, bool $allowLineBreaks): string
    {
        $placeholders = [];
        $text = preg_replace_callback('/`([^`]+)`/u', static function (array $matches) use (&$placeholders): string {
            $token = '@@MAILASSISTANTCODE' . count($placeholders) . '@@';
            $placeholders[$token] = '<code style="padding:2px 6px;background:#e2e8f0;color:#0f172a;border-radius:6px;font-family:Consolas,Monaco,monospace;font-size:0.92em;">'
                . htmlspecialchars((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</code>';

            return $token;
        }, $text) ?? $text;

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $escaped = preg_replace_callback('/\[([^]]+)]\(((?:https?:\/\/|mailto:)[^)\s]+)\)/u', static function (array $matches): string {
            $label = trim((string) ($matches[1] ?? ''));
            $url = trim((string) ($matches[2] ?? ''));
            $validatedUrl = filter_var($url, FILTER_VALIDATE_URL);
            if ($validatedUrl === false && stripos($url, 'mailto:') !== 0) {
                return $matches[0];
            }

            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<a href="' . $safeUrl . '" style="color:#2563eb !important;text-decoration:underline;">'
                . $label
                . '</a>';
        }, $escaped) ?? $escaped;

        $escaped = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__([^_]+)__/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/~~([^~]+)~~/u', '<del>$1</del>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!_)_([^_]+)_(?!_)/u', '<em>$1</em>', $escaped) ?? $escaped;

        if ($allowLineBreaks) {
            $escaped = nl2br($escaped, false);
        }

        if (count($placeholders)) {
            $escaped = strtr($escaped, $placeholders);
        }

        return $escaped;
    }
}

