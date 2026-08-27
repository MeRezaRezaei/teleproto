<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Media;

use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Native Laravel Storage & Flysystem Stream Media Helper.
 * Handles streaming uploads/downloads directly to/from S3, MinIO, or local disks.
 */
class StorageMedia
{
    public const CHUNK_SIZE = 524288; // 512 KB standard MTProto part size

    /**
     * Reads a file directly from a Laravel Storage disk in streaming chunks.
     *
     * @param string $path File path on the storage disk
     * @param string|null $disk Laravel storage disk (e.g. 'local', 's3', 'minio')
     * @param int $chunkSize Chunk size in bytes (default 512KB)
     * @return Generator<int, array{part_index: int, bytes: string, is_big: bool, total_parts: int}>
     */
    public static function readFromDisk(string $path, ?string $disk = null, int $chunkSize = self::CHUNK_SIZE): Generator
    {
        $storage = Storage::disk($disk);
        if (!$storage->exists($path)) {
            throw new InvalidArgumentException("File not found on storage disk: {$path}");
        }

        $size = $storage->size($path);
        $stream = $storage->readStream($path);

        if (!$stream) {
            throw new RuntimeException("Failed to open read stream for storage path: {$path}");
        }

        try {
            $totalParts = (int)ceil($size / $chunkSize);
            $isBig = $size > 10485760; // > 10 MB
            $partIndex = 0;

            while (!feof($stream) && $partIndex < $totalParts) {
                $bytes = fread($stream, $chunkSize);
                if ($bytes === false || $bytes === '') {
                    break;
                }

                yield $partIndex => [
                    'part_index' => $partIndex,
                    'bytes' => $bytes,
                    'is_big' => $isBig,
                    'total_parts' => $totalParts,
                ];

                $partIndex++;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
