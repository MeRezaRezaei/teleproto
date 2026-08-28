<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Schema;

use InvalidArgumentException;

/**
 * Immutable view of one method entry from schema/methods-mtproto.json
 * or schema/methods-botapi.json.
 *
 * Fields an api does not provide fall back to empty defaults ('' / [] / null)
 * so consumers never need api-specific null checks.
 */
final readonly class TelegramMethod
{
    /**
     * @param 'mtproto'|'bot-http'    $api
     * @param string                  $id          crc id like '0xc4f9186b' (mtproto only, '' otherwise)
     * @param list<array{name: string, type: string, flag_word: string|null, bit: int|null, required: bool|null, description: string}> $params
     *                                            mtproto entries carry flag_word/bit (null when the param is not a flag member),
     *                                            bot-http entries carry required (null for mtproto); description is always present
     * @param string                  $returnType  mtproto 'return' as-is; bot-http 'returns' joined with '|'; '' when absent
     * @param string                  $docs        documentation URL
     * @param string                  $description human-readable summary
     * @param list<string>            $errors      machine-readable error codes
     * @param list<string>            $required    bot-http required param names (mtproto: [])
     * @param list<string>            $returns     bot-http raw returns list (mtproto: [])
     */
    public function __construct(
        public string $name,
        public string $api,
        public string $id,
        public array $params,
        public string $returnType,
        public string $docs,
        public string $description,
        public array $errors,
        public array $required,
        public array $returns,
    ) {
    }

    /**
     * Map a canonical JSON method entry onto the value object.
     *
     * `$raw` is the artifact entry; the registry injects the envelope-level
     * `api` key ('mtproto'|'bot-http') alongside it.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArtifact(string $name, array $raw): self
    {
        $api = (string) ($raw['api'] ?? '');
        if ($api !== 'mtproto' && $api !== 'bot-http') {
            throw new InvalidArgumentException("Method [{$name}] has unknown api [{$api}].");
        }

        $returns = self::stringList($raw['returns'] ?? []);

        $returnType = (string) ($raw['return'] ?? '');
        if ($returnType === '' && $returns !== []) {
            $returnType = implode('|', $returns);
        }

        $params = [];
        foreach ((array) ($raw['params'] ?? []) as $param) {
            $param = (array) $param;
            $params[] = [
                'name' => (string) ($param['name'] ?? ''),
                'type' => (string) ($param['type'] ?? ''),
                'flag_word' => isset($param['flag_word']) ? (string) $param['flag_word'] : null,
                'bit' => isset($param['bit']) ? (int) $param['bit'] : null,
                'required' => isset($param['required']) ? (bool) $param['required'] : null,
                'description' => (string) ($param['description'] ?? ''),
            ];
        }

        return new self(
            name: $name,
            api: $api,
            id: (string) ($raw['id'] ?? ''),
            params: $params,
            returnType: $returnType,
            docs: (string) ($raw['docs'] ?? ''),
            description: (string) ($raw['description'] ?? ''),
            errors: self::stringList($raw['errors'] ?? []),
            required: self::stringList($raw['required'] ?? []),
            returns: $returns,
        );
    }

    /** @return list<string> */
    public function paramNames(): array
    {
        $names = [];
        foreach ($this->params as $param) {
            $names[] = $param['name'];
        }

        return $names;
    }

    /**
     * @param list<mixed>|array<array-key, mixed> $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $strings = [];
        foreach (array_values($values) as $value) {
            $strings[] = (string) $value;
        }

        return $strings;
    }
}
