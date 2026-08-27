<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Convenient Type Helpers to construct Telegram MTProto InputFile structures.
 */
class InputFile
{
    /**
     * Constructs a standard InputFile structure (<= 10MB).
     *
     * @param int $id Random file identifier
     * @param int $parts Number of file parts
     * @param string $name Original file name
     * @param string $md5Checksum MD5 hash of file content
     * @return array{_: 'inputFile', id: int, parts: int, name: string, md5_checksum: string}
     */
    public static function file(int $id, int $parts, string $name, string $md5Checksum = ''): array
    {
        return [
            '_' => 'inputFile',
            'id' => $id,
            'parts' => $parts,
            'name' => $name,
            'md5_checksum' => $md5Checksum,
        ];
    }

    /**
     * Constructs an InputFileBig structure for large media files (> 10MB, up to 4GB).
     *
     * @param int $id Random file identifier
     * @param int $parts Number of 512KB parts
     * @param string $name File name
     * @return array{_: 'inputFileBig', id: int, parts: int, name: string}
     */
    public static function big(int $id, int $parts, string $name): array
    {
        return [
            '_' => 'inputFileBig',
            'id' => $id,
            'parts' => $parts,
            'name' => $name,
        ];
    }
}
