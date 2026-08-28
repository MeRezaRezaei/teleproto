<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Entities;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Standard DOM-based HTML & MarkdownV2 Telegram MessageEntity Parser.
 * Uses PHP's native DOMDocument parser to handle nested and malformed tags cleanly.
 */
class EntityParser
{
    /**
     * Converts HTML to plain text and Telegram MessageEntity structures using native DOMDocument.
     *
     * @return array{text: string, entities: list<array<string, mixed>>}
     */
    public static function htmlToEntities(string $html): array
    {
        if (trim($html) === '') {
            return ['text' => '', 'entities' => []];
        }

        // Wrap in HTML5 UTF-8 wrapper for DOMDocument
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return ['text' => strip_tags($html), 'entities' => []];
        }

        $plainText = '';
        $entities = [];
        $utf16Offset = 0;

        self::traverseDomNode($body, $plainText, $utf16Offset, $entities);

        return [
            'text' => $plainText,
            'entities' => $entities,
        ];
    }

    /**
     * Converts Telegram Markdown / MarkdownV2 to plain text and MessageEntity structures using a deterministic token stream scanner.
     * Handles escaping, nesting, UTF-16 code units, pre blocks, custom emojis, and inline links without regex.
     *
     * @return array{text: string, entities: list<array<string, mixed>>}
     */
    public static function markdownToEntities(string $markdown): array
    {
        if (trim($markdown) === '') {
            return ['text' => '', 'entities' => []];
        }

        $chars = mb_str_split($markdown);
        $len = count($chars);
        $plainText = '';
        $utf16Offset = 0;
        $entities = [];
        $stack = [];

        $i = 0;
        while ($i < $len) {
            $c = $chars[$i];

            // 1. Escaped character (\x)
            if ($c === '\\' && $i + 1 < $len) {
                $nextChar = $chars[++$i];
                $plainText .= $nextChar;
                $utf16Offset += self::getUtf16Length($nextChar);
                $i++;
                continue;
            }

            // 2. Pre-formatted code block (```)
            if ($c === '`' && $i + 2 < $len && $chars[$i + 1] === '`' && $chars[$i + 2] === '`') {
                $i += 3;
                $lang = '';
                $closePos = null;
                for ($j = $i; $j + 2 < $len; $j++) {
                    if ($chars[$j] === '`' && $chars[$j + 1] === '`' && $chars[$j + 2] === '`') {
                        $closePos = $j;
                        break;
                    }
                }
                if ($closePos !== null) {
                    $blockContent = implode('', array_slice($chars, $i, $closePos - $i));
                    if (str_contains($blockContent, "\n")) {
                        $firstLine = strstr($blockContent, "\n", true);
                        $ok = $firstLine !== false && $firstLine !== '' && strspn($firstLine, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-') === strlen($firstLine);
                        if ($ok) {
                            $lang = $firstLine;
                            $blockContent = substr($blockContent, strlen($firstLine) + 1);
                        }
                    }
                    $startOffset = $utf16Offset;
                    $plainText .= $blockContent;
                    $blockLen = self::getUtf16Length($blockContent);
                    $utf16Offset += $blockLen;
                    $entity = [
                        '_' => 'messageEntityPre',
                        'offset' => $startOffset,
                        'length' => $blockLen,
                    ];
                    if ($lang !== '') {
                        $entity['language'] = $lang;
                    }
                    $entities[] = $entity;
                    $i = $closePos + 3;
                    continue;
                }
            }

            // 3. Inline fixed-width code (`)
            if ($c === '`') {
                $closePos = null;
                for ($j = $i + 1; $j < $len; $j++) {
                    if ($chars[$j] === '`') {
                        $closePos = $j;
                        break;
                    }
                }
                if ($closePos !== null) {
                    $codeContent = implode('', array_slice($chars, $i + 1, $closePos - ($i + 1)));
                    $startOffset = $utf16Offset;
                    $plainText .= $codeContent;
                    $codeLen = self::getUtf16Length($codeContent);
                    $utf16Offset += $codeLen;
                    $entities[] = [
                        '_' => 'messageEntityCode',
                        'offset' => $startOffset,
                        'length' => $codeLen,
                    ];
                    $i = $closePos + 1;
                    continue;
                }
            }

            // 4. Custom emoji (![alt](tg://emoji?id=123))
            if ($c === '!' && $i + 1 < $len && $chars[$i + 1] === '[') {
                $closeBracket = null;
                for ($j = $i + 2; $j < $len; $j++) {
                    if ($chars[$j] === ']') {
                        $closeBracket = $j;
                        break;
                    }
                }
                if ($closeBracket !== null && $closeBracket + 1 < $len && $chars[$closeBracket + 1] === '(') {
                    $closeParen = null;
                    for ($k = $closeBracket + 2; $k < $len; $k++) {
                        if ($chars[$k] === ')') {
                            $closeParen = $k;
                            break;
                        }
                    }
                    if ($closeParen !== null) {
                        $altText = implode('', array_slice($chars, $i + 2, $closeBracket - ($i + 2)));
                        $url = implode('', array_slice($chars, $closeBracket + 2, $closeParen - ($closeBracket + 2)));
                        if (str_starts_with($url, 'tg://emoji?id=')) {
                            $emojiId = substr($url, 14);
                            $startOffset = $utf16Offset;
                            $plainText .= $altText;
                            $altLen = self::getUtf16Length($altText);
                            $utf16Offset += $altLen;
                            $entities[] = [
                                '_' => 'messageEntityCustomEmoji',
                                'offset' => $startOffset,
                                'length' => $altLen,
                                'document_id' => (int)$emojiId,
                            ];
                            $i = $closeParen + 1;
                            continue;
                        }
                    }
                }
            }

            // 5. Inline links ([text](url))
            if ($c === '[') {
                $closeBracket = null;
                for ($j = $i + 1; $j < $len; $j++) {
                    if ($chars[$j] === '\\') {
                        $j++;
                        continue;
                    }
                    if ($chars[$j] === ']') {
                        $closeBracket = $j;
                        break;
                    }
                }
                if ($closeBracket !== null && $closeBracket + 1 < $len && $chars[$closeBracket + 1] === '(') {
                    $closeParen = null;
                    for ($k = $closeBracket + 2; $k < $len; $k++) {
                        if ($chars[$k] === '\\') {
                            $k++;
                            continue;
                        }
                        if ($chars[$k] === ')') {
                            $closeParen = $k;
                            break;
                        }
                    }
                    if ($closeParen !== null) {
                        $linkText = implode('', array_slice($chars, $i + 1, $closeBracket - ($i + 1)));
                        $url = implode('', array_slice($chars, $closeBracket + 2, $closeParen - ($closeBracket + 2)));
                        $startOffset = $utf16Offset;
                        $plainText .= $linkText;
                        $linkLen = self::getUtf16Length($linkText);
                        $utf16Offset += $linkLen;
                        $entities[] = [
                            '_' => 'messageEntityTextUrl',
                            'offset' => $startOffset,
                            'length' => $linkLen,
                            'url' => $url,
                        ];
                        $i = $closeParen + 1;
                        continue;
                    }
                }
            }

            // 6. Two-char delimiters: || (spoiler), __ (underline), ** (bold)
            $twoChar = ($i + 1 < $len) ? ($c . $chars[$i + 1]) : '';
            if ($twoChar === '||' || $twoChar === '__' || $twoChar === '**') {
                $type = match ($twoChar) {
                    '||' => 'messageEntitySpoiler',
                    '__' => 'messageEntityUnderline',
                    '**' => 'messageEntityBold',
                };
                $matchedIdx = null;
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($stack[$k]['delim'] === $twoChar) {
                        $matchedIdx = $k;
                        break;
                    }
                }
                if ($matchedIdx !== null) {
                    $entry = $stack[$matchedIdx];
                    array_splice($stack, $matchedIdx, 1);
                    $lenUnits = $utf16Offset - $entry['start_offset'];
                    if ($lenUnits > 0) {
                        $entities[] = [
                            '_' => $entry['type'],
                            'offset' => $entry['start_offset'],
                            'length' => $lenUnits,
                        ];
                    }
                } else {
                    $stack[] = [
                        'delim' => $twoChar,
                        'type' => $type,
                        'start_offset' => $utf16Offset,
                    ];
                }
                $i += 2;
                continue;
            }

            // 7. Single-char delimiters: *, _, ~
            if ($c === '*' || $c === '_' || $c === '~') {
                $type = match ($c) {
                    '*' => 'messageEntityBold',
                    '_' => 'messageEntityItalic',
                    '~' => 'messageEntityStrike',
                };
                $matchedIdx = null;
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($stack[$k]['delim'] === $c) {
                        $matchedIdx = $k;
                        break;
                    }
                }
                if ($matchedIdx !== null) {
                    $entry = $stack[$matchedIdx];
                    array_splice($stack, $matchedIdx, 1);
                    $lenUnits = $utf16Offset - $entry['start_offset'];
                    if ($lenUnits > 0) {
                        $entities[] = [
                            '_' => $entry['type'],
                            'offset' => $entry['start_offset'],
                            'length' => $lenUnits,
                        ];
                    }
                } else {
                    $stack[] = [
                        'delim' => $c,
                        'type' => $type,
                        'start_offset' => $utf16Offset,
                    ];
                }
                $i++;
                continue;
            }

            // 8. Plain text character
            $plainText .= $c;
            $utf16Offset += self::getUtf16Length($c);
            $i++;
        }

        // Sort entities by start offset
        usort($entities, fn($a, $b) => $a['offset'] <=> $b['offset']);

        return [
            'text' => $plainText,
            'entities' => $entities,
        ];
    }

    protected static function traverseDomNode(DOMNode $node, string &$plainText, int &$utf16Offset, array &$entities): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $child->nodeValue ?? '';
                $plainText .= $text;
                $utf16Offset += self::getUtf16Length($text);
            } elseif ($child instanceof DOMElement) {
                $startOffset = $utf16Offset;
                $tagName = strtolower($child->tagName);

                // Recursively parse child nodes
                self::traverseDomNode($child, $plainText, $utf16Offset, $entities);
                $length = $utf16Offset - $startOffset;

                if ($length > 0) {
                    $entityType = self::mapTagToEntityType($tagName);
                    if ($entityType) {
                        $entity = [
                            '_' => $entityType,
                            'offset' => $startOffset,
                            'length' => $length,
                        ];

                        if ($tagName === 'a' && $child->hasAttribute('href')) {
                            $entity['url'] = $child->getAttribute('href');
                        } elseif ($tagName === 'tg-emoji' && $child->hasAttribute('emoji-id')) {
                            $entity['document_id'] = (int)$child->getAttribute('emoji-id');
                        }

                        $entities[] = $entity;
                    }
                }
            }
        }
    }

    protected static function mapTagToEntityType(string $tagName): ?string
    {
        return match ($tagName) {
            'b', 'strong' => 'messageEntityBold',
            'i', 'em' => 'messageEntityItalic',
            'u', 'ins' => 'messageEntityUnderline',
            's', 'strike', 'del' => 'messageEntityStrike',
            'code' => 'messageEntityCode',
            'pre' => 'messageEntityPre',
            'tg-spoiler' => 'messageEntitySpoiler',
            'blockquote' => 'messageEntityBlockquote',
            'a' => 'messageEntityTextUrl',
            'tg-emoji' => 'messageEntityCustomEmoji',
            default => null,
        };
    }

    /**
     * Calculates string length in UTF-16 code units (surrogate pair precision).
     */
    public static function getUtf16Length(string $text): int
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        return strlen($utf16) / 2;
    }
}
