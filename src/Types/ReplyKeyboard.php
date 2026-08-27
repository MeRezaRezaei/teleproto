<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Fluent builder for Telegram Custom Reply Keyboard Markup (`reply_markup`).
 */
class ReplyKeyboard
{
    /**
     * @var list<list<array<string, mixed>>>
     */
    protected array $keyboard = [];
    protected bool $resizeKeyboard = true;
    protected bool $oneTimeKeyboard = false;
    protected bool $isPersistent = false;
    protected ?string $inputFieldPlaceholder = null;
    protected bool $selective = false;

    public function __construct(array $keyboard = [])
    {
        $this->keyboard = $keyboard;
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Adds a row of reply buttons.
     *
     * @param list<array<string, mixed>|string> $buttons
     */
    public function row(array $buttons): self
    {
        $normalizedRow = [];
        foreach ($buttons as $btn) {
            $normalizedRow[] = is_string($btn) ? ['text' => $btn] : $btn;
        }
        $this->keyboard[] = $normalizedRow;
        return $this;
    }

    /**
     * Adds a button to the current row or a new row.
     *
     * @param array<string, mixed>|string $button
     */
    public function button(array|string $button, bool $newRow = false): self
    {
        $btn = is_string($button) ? ['text' => $button] : $button;
        if ($newRow || empty($this->keyboard)) {
            $this->keyboard[] = [$btn];
        } else {
            $this->keyboard[count($this->keyboard) - 1][] = $btn;
        }
        return $this;
    }

    /**
     * Requests user's phone number.
     */
    public static function requestContact(string $text = 'Share Contact'): array
    {
        return ['text' => $text, 'request_contact' => true];
    }

    /**
     * Requests user's geolocation.
     */
    public static function requestLocation(string $text = 'Share Location'): array
    {
        return ['text' => $text, 'request_location' => true];
    }

    /**
     * Requests user to create a poll.
     */
    public static function requestPoll(string $text = 'Create Poll', ?string $type = null): array
    {
        $btn = ['text' => $text, 'request_poll' => []];
        if ($type !== null) {
            $btn['request_poll']['type'] = $type;
        }
        return $btn;
    }

    /**
     * Opens a Telegram Mini App from Reply Keyboard.
     */
    public static function webApp(string $text, string $url): array
    {
        return ['text' => $text, 'web_app' => ['url' => $url]];
    }

    public function resize(bool $resize = true): self
    {
        $this->resizeKeyboard = $resize;
        return $this;
    }

    public function oneTime(bool $oneTime = true): self
    {
        $this->oneTimeKeyboard = $oneTime;
        return $this;
    }

    public function persistent(bool $persistent = true): self
    {
        $this->isPersistent = $persistent;
        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->inputFieldPlaceholder = $placeholder;
        return $this;
    }

    public function selective(bool $selective = true): self
    {
        $this->selective = $selective;
        return $this;
    }

    /**
     * Creates instructions to remove the custom keyboard.
     *
     * @param bool $selective
     * @return array{remove_keyboard: true, selective: bool}
     */
    public static function remove(bool $selective = false): array
    {
        return [
            'remove_keyboard' => true,
            'selective' => $selective,
        ];
    }

    /**
     * Creates a ForceReply structure.
     *
     * @param bool $selective
     * @param string|null $placeholder
     * @return array{force_reply: true, selective: bool, input_field_placeholder?: string}
     */
    public static function forceReply(bool $selective = false, ?string $placeholder = null): array
    {
        $res = [
            'force_reply' => true,
            'selective' => $selective,
        ];
        if ($placeholder !== null) {
            $res['input_field_placeholder'] = $placeholder;
        }
        return $res;
    }

    /**
     * Converts to Telegram `ReplyKeyboardMarkup` array structure.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $markup = [
            'keyboard' => $this->keyboard,
            'resize_keyboard' => $this->resizeKeyboard,
            'one_time_keyboard' => $this->oneTimeKeyboard,
            'is_persistent' => $this->isPersistent,
            'selective' => $this->selective,
        ];

        if ($this->inputFieldPlaceholder !== null) {
            $markup['input_field_placeholder'] = $this->inputFieldPlaceholder;
        }

        return $markup;
    }

    public function toJson(): string
    {
        return (string)json_encode($this->toArray());
    }
}
