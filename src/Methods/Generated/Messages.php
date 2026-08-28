<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Methods\Generated;

/**
 * mtproto messages.* curated method builders.
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php from config/curated-methods.json — do not edit by hand.
 */
final class Messages
{
    public function sendMessage(): MessagesSendMessageBuilder
    {
        return new MessagesSendMessageBuilder();
    }

    public function getHistory(): MessagesGetHistoryBuilder
    {
        return new MessagesGetHistoryBuilder();
    }

    public function search(): MessagesSearchBuilder
    {
        return new MessagesSearchBuilder();
    }

    public function readHistory(): MessagesReadHistoryBuilder
    {
        return new MessagesReadHistoryBuilder();
    }

    public function sendReaction(): MessagesSendReactionBuilder
    {
        return new MessagesSendReactionBuilder();
    }

    public function getDialogs(): MessagesGetDialogsBuilder
    {
        return new MessagesGetDialogsBuilder();
    }

    public function forwardMessages(): MessagesForwardMessagesBuilder
    {
        return new MessagesForwardMessagesBuilder();
    }

    public function deleteMessages(): MessagesDeleteMessagesBuilder
    {
        return new MessagesDeleteMessagesBuilder();
    }
}

/**
 * Fluent builder for messages.sendMessage (mtproto, return: Updates).
 * Sends a message to a chat
 * Docs: https://core.telegram.org/method/messages.sendMessage
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesSendMessageBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function noWebpage(bool $no_webpage): self
    {
        $this->p['no_webpage'] = $no_webpage;

        return $this;
    }

    public function silent(bool $silent): self
    {
        $this->p['silent'] = $silent;

        return $this;
    }

    public function background(bool $background): self
    {
        $this->p['background'] = $background;

        return $this;
    }

    public function clearDraft(bool $clear_draft): self
    {
        $this->p['clear_draft'] = $clear_draft;

        return $this;
    }

    public function noforwards(bool $noforwards): self
    {
        $this->p['noforwards'] = $noforwards;

        return $this;
    }

    public function updateStickersetsOrder(bool $update_stickersets_order): self
    {
        $this->p['update_stickersets_order'] = $update_stickersets_order;

        return $this;
    }

    public function invertMedia(bool $invert_media): self
    {
        $this->p['invert_media'] = $invert_media;

        return $this;
    }

    public function allowPaidFloodskip(bool $allow_paid_floodskip): self
    {
        $this->p['allow_paid_floodskip'] = $allow_paid_floodskip;

        return $this;
    }

    public function peer(mixed $peer): self
    {
        $this->p['peer'] = $peer;

        return $this;
    }

    public function replyTo(mixed $reply_to): self
    {
        $this->p['reply_to'] = $reply_to;

        return $this;
    }

    public function message(string $message): self
    {
        $this->p['message'] = $message;

        return $this;
    }

    public function randomId(int $random_id): self
    {
        $this->p['random_id'] = $random_id;

        return $this;
    }

    public function replyMarkup(mixed $reply_markup): self
    {
        $this->p['reply_markup'] = $reply_markup;

        return $this;
    }

    public function entities(mixed $entities): self
    {
        $this->p['entities'] = $entities;

        return $this;
    }

    public function scheduleDate(int $schedule_date): self
    {
        $this->p['schedule_date'] = $schedule_date;

        return $this;
    }

    public function scheduleRepeatPeriod(int $schedule_repeat_period): self
    {
        $this->p['schedule_repeat_period'] = $schedule_repeat_period;

        return $this;
    }

    public function sendAs(mixed $send_as): self
    {
        $this->p['send_as'] = $send_as;

        return $this;
    }

    public function quickReplyShortcut(mixed $quick_reply_shortcut): self
    {
        $this->p['quick_reply_shortcut'] = $quick_reply_shortcut;

        return $this;
    }

    public function effect(int $effect): self
    {
        $this->p['effect'] = $effect;

        return $this;
    }

    public function allowPaidStars(int $allow_paid_stars): self
    {
        $this->p['allow_paid_stars'] = $allow_paid_stars;

        return $this;
    }

    public function suggestedPost(mixed $suggested_post): self
    {
        $this->p['suggested_post'] = $suggested_post;

        return $this;
    }

    public function richMessage(mixed $rich_message): self
    {
        $this->p['rich_message'] = $rich_message;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['peer', 'message', 'random_id'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.sendMessage: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.sendMessage'], $this->p);
    }
}

/**
 * Fluent builder for messages.getHistory (mtproto, return: messages.Messages).
 * Returns the conversation history with one interlocutor / within a chat
 * Docs: https://core.telegram.org/method/messages.getHistory
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesGetHistoryBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function peer(mixed $peer): self
    {
        $this->p['peer'] = $peer;

        return $this;
    }

    public function offsetId(int $offset_id): self
    {
        $this->p['offset_id'] = $offset_id;

        return $this;
    }

    public function offsetDate(int $offset_date): self
    {
        $this->p['offset_date'] = $offset_date;

        return $this;
    }

    public function addOffset(int $add_offset): self
    {
        $this->p['add_offset'] = $add_offset;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->p['limit'] = $limit;

        return $this;
    }

    public function maxId(int $max_id): self
    {
        $this->p['max_id'] = $max_id;

        return $this;
    }

    public function minId(int $min_id): self
    {
        $this->p['min_id'] = $min_id;

        return $this;
    }

    public function hash(int $hash): self
    {
        $this->p['hash'] = $hash;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['peer', 'offset_id', 'offset_date', 'add_offset', 'limit', 'max_id', 'min_id', 'hash'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.getHistory: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.getHistory'], $this->p);
    }
}

/**
 * Fluent builder for messages.search (mtproto, return: messages.Messages).
 * Search for messages.
 * Docs: https://core.telegram.org/method/messages.search
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesSearchBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function peer(mixed $peer): self
    {
        $this->p['peer'] = $peer;

        return $this;
    }

    public function q(string $q): self
    {
        $this->p['q'] = $q;

        return $this;
    }

    public function fromId(mixed $from_id): self
    {
        $this->p['from_id'] = $from_id;

        return $this;
    }

    public function savedPeerId(mixed $saved_peer_id): self
    {
        $this->p['saved_peer_id'] = $saved_peer_id;

        return $this;
    }

    public function savedReaction(mixed $saved_reaction): self
    {
        $this->p['saved_reaction'] = $saved_reaction;

        return $this;
    }

    public function topMsgId(int $top_msg_id): self
    {
        $this->p['top_msg_id'] = $top_msg_id;

        return $this;
    }

    public function filter(mixed $filter): self
    {
        $this->p['filter'] = $filter;

        return $this;
    }

    public function minDate(int $min_date): self
    {
        $this->p['min_date'] = $min_date;

        return $this;
    }

    public function maxDate(int $max_date): self
    {
        $this->p['max_date'] = $max_date;

        return $this;
    }

    public function offsetId(int $offset_id): self
    {
        $this->p['offset_id'] = $offset_id;

        return $this;
    }

    public function addOffset(int $add_offset): self
    {
        $this->p['add_offset'] = $add_offset;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->p['limit'] = $limit;

        return $this;
    }

    public function maxId(int $max_id): self
    {
        $this->p['max_id'] = $max_id;

        return $this;
    }

    public function minId(int $min_id): self
    {
        $this->p['min_id'] = $min_id;

        return $this;
    }

    public function hash(int $hash): self
    {
        $this->p['hash'] = $hash;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['peer', 'q', 'filter', 'min_date', 'max_date', 'offset_id', 'add_offset', 'limit', 'max_id', 'min_id', 'hash'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.search: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.search'], $this->p);
    }
}

/**
 * Fluent builder for messages.readHistory (mtproto, return: messages.AffectedMessages).
 * Marks message history as read.
 * Docs: https://core.telegram.org/method/messages.readHistory
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesReadHistoryBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function peer(mixed $peer): self
    {
        $this->p['peer'] = $peer;

        return $this;
    }

    public function maxId(int $max_id): self
    {
        $this->p['max_id'] = $max_id;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['peer', 'max_id'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.readHistory: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.readHistory'], $this->p);
    }
}

/**
 * Fluent builder for messages.sendReaction (mtproto, return: Updates).
 * React to message.  Starting from layer 159, the reaction will be sent from the peer specified using [messages.saveDefaultSendAs](../methods/messages.saveDefaultSendAs.md).
 * Docs: https://core.telegram.org/method/messages.sendReaction
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesSendReactionBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function big(bool $big): self
    {
        $this->p['big'] = $big;

        return $this;
    }

    public function addToRecent(bool $add_to_recent): self
    {
        $this->p['add_to_recent'] = $add_to_recent;

        return $this;
    }

    public function peer(mixed $peer): self
    {
        $this->p['peer'] = $peer;

        return $this;
    }

    public function msgId(int $msg_id): self
    {
        $this->p['msg_id'] = $msg_id;

        return $this;
    }

    public function reaction(mixed $reaction): self
    {
        $this->p['reaction'] = $reaction;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['peer', 'msg_id'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.sendReaction: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.sendReaction'], $this->p);
    }
}

/**
 * Fluent builder for messages.getDialogs (mtproto, return: messages.Dialogs).
 * Returns the current user dialog list.
 * Docs: https://core.telegram.org/method/messages.getDialogs
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesGetDialogsBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function excludePinned(bool $exclude_pinned): self
    {
        $this->p['exclude_pinned'] = $exclude_pinned;

        return $this;
    }

    public function folderId(int $folder_id): self
    {
        $this->p['folder_id'] = $folder_id;

        return $this;
    }

    public function offsetDate(int $offset_date): self
    {
        $this->p['offset_date'] = $offset_date;

        return $this;
    }

    public function offsetId(int $offset_id): self
    {
        $this->p['offset_id'] = $offset_id;

        return $this;
    }

    public function offsetPeer(mixed $offset_peer): self
    {
        $this->p['offset_peer'] = $offset_peer;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->p['limit'] = $limit;

        return $this;
    }

    public function hash(int $hash): self
    {
        $this->p['hash'] = $hash;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['offset_date', 'offset_id', 'offset_peer', 'limit', 'hash'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.getDialogs: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.getDialogs'], $this->p);
    }
}

/**
 * Fluent builder for messages.forwardMessages (mtproto, return: Updates).
 * Forwards messages by their IDs.
 * Docs: https://core.telegram.org/method/messages.forwardMessages
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesForwardMessagesBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function silent(bool $silent): self
    {
        $this->p['silent'] = $silent;

        return $this;
    }

    public function background(bool $background): self
    {
        $this->p['background'] = $background;

        return $this;
    }

    public function withMyScore(bool $with_my_score): self
    {
        $this->p['with_my_score'] = $with_my_score;

        return $this;
    }

    public function dropAuthor(bool $drop_author): self
    {
        $this->p['drop_author'] = $drop_author;

        return $this;
    }

    public function dropMediaCaptions(bool $drop_media_captions): self
    {
        $this->p['drop_media_captions'] = $drop_media_captions;

        return $this;
    }

    public function noforwards(bool $noforwards): self
    {
        $this->p['noforwards'] = $noforwards;

        return $this;
    }

    public function allowPaidFloodskip(bool $allow_paid_floodskip): self
    {
        $this->p['allow_paid_floodskip'] = $allow_paid_floodskip;

        return $this;
    }

    public function fromEphemeral(bool $from_ephemeral): self
    {
        $this->p['from_ephemeral'] = $from_ephemeral;

        return $this;
    }

    public function fromPeer(mixed $from_peer): self
    {
        $this->p['from_peer'] = $from_peer;

        return $this;
    }

    public function id(array $id): self
    {
        $this->p['id'] = $id;

        return $this;
    }

    public function randomId(array $random_id): self
    {
        $this->p['random_id'] = $random_id;

        return $this;
    }

    public function toPeer(mixed $to_peer): self
    {
        $this->p['to_peer'] = $to_peer;

        return $this;
    }

    public function topMsgId(int $top_msg_id): self
    {
        $this->p['top_msg_id'] = $top_msg_id;

        return $this;
    }

    public function replyTo(mixed $reply_to): self
    {
        $this->p['reply_to'] = $reply_to;

        return $this;
    }

    public function scheduleDate(int $schedule_date): self
    {
        $this->p['schedule_date'] = $schedule_date;

        return $this;
    }

    public function scheduleRepeatPeriod(int $schedule_repeat_period): self
    {
        $this->p['schedule_repeat_period'] = $schedule_repeat_period;

        return $this;
    }

    public function sendAs(mixed $send_as): self
    {
        $this->p['send_as'] = $send_as;

        return $this;
    }

    public function quickReplyShortcut(mixed $quick_reply_shortcut): self
    {
        $this->p['quick_reply_shortcut'] = $quick_reply_shortcut;

        return $this;
    }

    public function effect(int $effect): self
    {
        $this->p['effect'] = $effect;

        return $this;
    }

    public function videoTimestamp(int $video_timestamp): self
    {
        $this->p['video_timestamp'] = $video_timestamp;

        return $this;
    }

    public function allowPaidStars(int $allow_paid_stars): self
    {
        $this->p['allow_paid_stars'] = $allow_paid_stars;

        return $this;
    }

    public function suggestedPost(mixed $suggested_post): self
    {
        $this->p['suggested_post'] = $suggested_post;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['from_peer', 'id', 'random_id', 'to_peer'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.forwardMessages: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.forwardMessages'], $this->p);
    }
}

/**
 * Fluent builder for messages.deleteMessages (mtproto, return: messages.AffectedMessages).
 * Deletes messages by their identifiers.
 * Docs: https://core.telegram.org/method/messages.deleteMessages
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class MessagesDeleteMessagesBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function revoke(bool $revoke): self
    {
        $this->p['revoke'] = $revoke;

        return $this;
    }

    public function id(array $id): self
    {
        $this->p['id'] = $id;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['id'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.deleteMessages: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'messages.deleteMessages'], $this->p);
    }
}
