<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A readable local copy of a media item's image (a conversion when generated, else the original).
 *
 * Media on a remote disk (S3, R2) is downloaded to a temporary file, removed by cleanup().
 */
final class LocalImageFile
{
    private function __construct(
        public readonly string $path,
        public readonly string $mimeType,
        private readonly bool $temporary,
    ) {}

    public static function for(Media $media, ?string $conversion = null): self
    {
        $conversion = $conversion !== null && $media->hasGeneratedConversion($conversion) ? $conversion : '';
        $disk = $conversion === '' ? $media->disk : ($media->conversions_disk ?: $media->disk);
        $relativePath = $media->getPathRelativeToRoot($conversion);
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $mimeType = $conversion === '' ? (string) $media->mime_type : self::mimeTypeForExtension($extension, (string) $media->mime_type);

        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            return new self($media->getPath($conversion), $mimeType, false);
        }

        $stream = Storage::disk($disk)->readStream($relativePath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read [{$relativePath}] from disk [{$disk}].");
        }

        $base = (string) tempnam(sys_get_temp_dir(), 'ai-captioning-');
        $temporaryPath = $extension !== '' ? $base.".{$extension}" : $base;

        if ($temporaryPath !== $base) {
            @unlink($base);
        }
        $target = fopen($temporaryPath, 'wb');

        if ($target === false) {
            throw new RuntimeException("Could not write the temporary file [{$temporaryPath}].");
        }

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return new self($temporaryPath, $mimeType, true);
    }

    public function cleanup(): void
    {
        if ($this->temporary && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private static function mimeTypeForExtension(string $extension, string $fallback): string
    {
        return match (strtolower($extension)) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            default => $fallback,
        };
    }
}
