<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Schema;

/**
 * Compares two canonical schema artifacts (the decoded methods-*.json
 * envelopes) method-by-method and renders the markdown audit section.
 *
 * Pure logic, no I/O: commands orchestrate regeneration and feed the
 * envelopes in. Zero regex by repo spec.
 */
final class SchemaDiffer
{
    private const NAMES_CAP = 20;

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array{added: list<string>, removed: list<string>, changed: list<string>, layer: int|null}
     */
    public static function diff(array $old, array $new): array
    {
        /** @var array<string, mixed> $oldMethods */
        $oldMethods = $old['methods'] ?? [];
        /** @var array<string, mixed> $newMethods */
        $newMethods = $new['methods'] ?? [];

        $added = array_keys(array_diff_key($newMethods, $oldMethods));
        $removed = array_keys(array_diff_key($oldMethods, $newMethods));

        $changed = [];
        foreach (array_intersect_key($oldMethods, $newMethods) as $name => $oldMethod) {
            if (json_encode($oldMethod) !== json_encode($newMethods[$name])) {
                $changed[] = (string) $name;
            }
        }

        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        sort($changed, SORT_STRING);

        $oldLayer = $old['layer'] ?? null;
        $newLayer = $new['layer'] ?? null;

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            // null when equal (including both absent, e.g. bot-http), else the NEW layer
            'layer' => $oldLayer === $newLayer ? null : (int) $newLayer,
        ];
    }

    /**
     * Markdown section for one artifact. Surfaces BOTH provenance layer
     * numbers (schema vs errors/wire — they legitimately diverge) whenever
     * the artifact carries them, plus the envelope layer.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function reportSection(string $api, array $old, array $new): string
    {
        $d = self::diff($old, $new);
        $lines = ["## {$api}"];

        $layersLine = self::layersLine($old, $new);
        if ($layersLine !== null) {
            $lines[] = '';
            $lines[] = "Layers: {$layersLine}";
        }

        if ($d['added'] === [] && $d['removed'] === [] && $d['changed'] === [] && $d['layer'] === null) {
            $lines[] = '';
            $lines[] = 'No differences (regenerated output matches the committed artifact).';
            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = '- Added: ' . count($d['added']);
        $lines = [...$lines, ...self::nameLines($d['added'])];
        $lines[] = '- Removed: ' . count($d['removed']);
        $lines = [...$lines, ...self::nameLines($d['removed'])];
        $lines[] = '- Changed: ' . count($d['changed']);
        $lines = [...$lines, ...self::nameLines($d['changed'])];

        return implode("\n", $lines);
    }

    /**
     * One-line layer summary covering both provenance sources, e.g.
     * `layer 229 → 230 (schema 229 → 230, errors/wire 227 → 227)`.
     * Null when neither artifact carries any layer information.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function layersLine(array $old, array $new): ?string
    {
        $oldLayer = $old['layer'] ?? null;
        $newLayer = $new['layer'] ?? null;
        $oldLayers = $old['layers'] ?? null;
        $newLayers = $new['layers'] ?? null;

        if ($oldLayer === null && $newLayer === null && $oldLayers === null && $newLayers === null) {
            return null;
        }

        $parts = [];
        if ($oldLayer !== null || $newLayer !== null) {
            $parts[] = 'layer ' . self::pair($oldLayer, $newLayer);
        }
        foreach (['schema', 'errors'] as $key) {
            $from = is_array($oldLayers) ? ($oldLayers[$key] ?? null) : null;
            $to = is_array($newLayers) ? ($newLayers[$key] ?? null) : null;
            if ($from !== null || $to !== null) {
                $label = $key === 'errors' ? 'errors/wire' : $key;
                $parts[] = "{$label} " . self::pair($from, $to);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function nameLines(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $lines = [];
        foreach (array_slice($names, 0, self::NAMES_CAP) as $name) {
            $lines[] = "  - {$name}";
        }
        $rest = count($names) - self::NAMES_CAP;
        if ($rest > 0) {
            $lines[] = "  - … and {$rest} more";
        }
        return $lines;
    }

    private static function pair(mixed $from, mixed $to): string
    {
        $fromText = $from === null ? 'none' : (string) $from;
        $toText = $to === null ? 'none' : (string) $to;
        return "{$fromText} → {$toText}";
    }
}
