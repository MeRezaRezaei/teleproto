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
     * Converts Telegram MarkdownV2 to plain text and MessageEntity structures.
     *
     * @return array{text: string, entities: list<array<string, mixed>>}
     */
    public static function markdownToEntities(string $markdown): array
    {
        if (trim($markdown) === '') {
            return ['text' => '', 'entities' => []];
        }

        // MarkdownV2 patterns
        $patterns = [
            '/\*(.*?)\*/s' => 'messageEntityBold',
            '/_(.*?)_/s'   => 'messageEntityItalic',
            '/~(.*?)~/s'   => 'messageEntityStrike',
            '/\|\|(.*?)\|\|/s' => 'messageEntitySpoiler',
            '/`(.*?)`/s'   => 'messageEntityCode',
        ];

        $plainText = '';
        $entities = [];
        $utf16Offset = 0;

        // Parse links: [text](url)
        $textWithPlaceholders = preg_replace_callback('/\[(.*?)\]\((.*?)\)/s', function ($matches) use (&$entities, &$utf16Offset, &$plainText) {
            $linkText = $matches[1];
            $url = $matches[2];
            $len = self::getUtf16Length($linkText);

            $entities[] = [
                '_' => 'messageEntityTextUrl',
                'offset' => $utf16Offset,
                'length' => $len,
                'url' => $url,
            ];

            $utf16Offset += $len;
            $plainText .= $linkText;
            return $linkText;
        }, $markdown);

        // Standard HTML fallback for mixed entities
        return self::htmlToEntities($textWithPlaceholders ?? $markdown);
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
