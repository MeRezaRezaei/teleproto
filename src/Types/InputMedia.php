<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Convenient Type Helpers to construct Telegram Bot API & MTProto InputMedia structures.
 */
class InputMedia
{
    /**
     * Constructs an InputMediaPhoto structure.
     *
     * @param string $media File ID, HTTP URL, or attach:// identifier
     * @param string $caption Optional media caption
     * @param array<string, mixed> $options Additional options (e.g. parse_mode, has_spoiler)
     * @return array<string, mixed>
     */
    public static function photo(string $media, string $caption = '', array $options = []): array
    {
        return array_merge([
            'type' => 'photo',
            'media' => $media,
            'caption' => $caption,
        ], $options);
    }

    /**
     * Constructs an InputMediaVideo structure.
     *
     * @param string $media File ID, HTTP URL, or attach:// identifier
     * @param string $caption Optional media caption
     * @param array<string, mixed> $options Additional options (e.g. parse_mode, duration, width, height, supports_streaming)
     * @return array<string, mixed>
     */
    public static function video(string $media, string $caption = '', array $options = []): array
    {
        return array_merge([
            'type' => 'video',
            'media' => $media,
            'caption' => $caption,
        ], $options);
    }

    /**
     * Constructs an InputMediaDocument structure.
     *
     * @param string $media File ID, HTTP URL, or attach:// identifier
     * @param string $caption Optional media caption
     * @param array<string, mixed> $options Additional options (e.g. parse_mode, disable_content_type_detection)
     * @return array<string, mixed>
     */
    public static function document(string $media, string $caption = '', array $options = []): array
    {
        return array_merge([
            'type' => 'document',
            'media' => $media,
            'caption' => $caption,
        ], $options);
    }

    /**
     * Constructs an InputMediaAudio structure.
     *
     * @param string $media File ID, HTTP URL, or attach:// identifier
     * @param string $caption Optional media caption
     * @param array<string, mixed> $options Additional options (e.g. parse_mode, duration, performer, title)
     * @return array<string, mixed>
     */
    public static function audio(string $media, string $caption = '', array $options = []): array
    {
        return array_merge([
            'type' => 'audio',
            'media' => $media,
            'caption' => $caption,
        ], $options);
    }

    /**
     * Constructs an InputMediaAnimation (GIF / H.264 video without sound) structure.
     *
     * @param string $media File ID, HTTP URL, or attach:// identifier
     * @param string $caption Optional media caption
     * @param array<string, mixed> $options Additional options (e.g. parse_mode, duration, width, height)
     * @return array<string, mixed>
     */
    public static function animation(string $media, string $caption = '', array $options = []): array
    {
        return array_merge([
            'type' => 'animation',
            'media' => $media,
            'caption' => $caption,
        ], $options);
    }
}
