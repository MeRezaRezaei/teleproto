<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Methods\Generated;

/**
 * mtproto help.* curated method builders.
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php from config/curated-methods.json — do not edit by hand.
 */
final class Help
{
    public function getNearestDc(): HelpGetNearestDcBuilder
    {
        return new HelpGetNearestDcBuilder();
    }
}

/**
 * Fluent builder for help.getNearestDc (mtproto, return: NearestDc).
 * Returns info on data center nearest to the user.
 * Docs: https://core.telegram.org/method/help.getNearestDc
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class HelpGetNearestDcBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        return array_merge(['_' => 'help.getNearestDc'], $this->p);
    }
}
