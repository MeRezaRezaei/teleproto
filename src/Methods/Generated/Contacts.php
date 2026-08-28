<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Methods\Generated;

/**
 * mtproto contacts.* curated method builders.
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php from config/curated-methods.json — do not edit by hand.
 */
final class Contacts
{
    public function getContacts(): ContactsGetContactsBuilder
    {
        return new ContactsGetContactsBuilder();
    }

    public function importContacts(): ContactsImportContactsBuilder
    {
        return new ContactsImportContactsBuilder();
    }

    public function search(): ContactsSearchBuilder
    {
        return new ContactsSearchBuilder();
    }
}

/**
 * Fluent builder for contacts.getContacts (mtproto, return: contacts.Contacts).
 * Returns the current user's contact list.
 * Docs: https://core.telegram.org/method/contacts.getContacts
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class ContactsGetContactsBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

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
        foreach (['hash'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('contacts.getContacts: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'contacts.getContacts'], $this->p);
    }
}

/**
 * Fluent builder for contacts.importContacts (mtproto, return: contacts.ImportedContacts).
 * Imports contacts: saves a full list on the server, adds already registered contacts to the contact list, returns added contacts and their info.  Use [contacts.addContact](../methods/contacts.addContact.md) to add Telegram contacts without actually using their phone number.
 * Docs: https://core.telegram.org/method/contacts.importContacts
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class ContactsImportContactsBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function contacts(mixed $contacts): self
    {
        $this->p['contacts'] = $contacts;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['contacts'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('contacts.importContacts: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'contacts.importContacts'], $this->p);
    }
}

/**
 * Fluent builder for contacts.search (mtproto, return: contacts.Found).
 * Returns users found by username substring.
 * Docs: https://core.telegram.org/method/contacts.search
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class ContactsSearchBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function broadcasts(bool $broadcasts): self
    {
        $this->p['broadcasts'] = $broadcasts;

        return $this;
    }

    public function bots(bool $bots): self
    {
        $this->p['bots'] = $bots;

        return $this;
    }

    public function q(string $q): self
    {
        $this->p['q'] = $q;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->p['limit'] = $limit;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['q', 'limit'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('contacts.search: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'contacts.search'], $this->p);
    }
}
