<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoredMedia
{
    /**
     * Delete a file from storage. Accepts either a relative storage path
     * (e.g. communities/{id}/logo-uuid.jpg) or a full CDN URL.
     */
    public static function delete(?string $pathOrUrl, string $disk = 'spaces'): void
    {
        $path = self::resolvePath($pathOrUrl, $disk);

        if (! $path) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function resolvePath(?string $pathOrUrl, string $disk = 'spaces'): ?string
    {
        if (! $pathOrUrl) {
            return null;
        }

        $pathOrUrl = trim($pathOrUrl);

        if (! Str::startsWith($pathOrUrl, ['http://', 'https://'])) {
            return ltrim($pathOrUrl, '/');
        }

        $baseUrl = rtrim((string) config("filesystems.disks.{$disk}.url"), '/');

        if ($baseUrl !== '' && Str::startsWith($pathOrUrl, $baseUrl)) {
            return ltrim(Str::after($pathOrUrl, $baseUrl), '/');
        }

        $parsed = parse_url($pathOrUrl, PHP_URL_PATH);

        return $parsed ? ltrim($parsed, '/') : null;
    }
}
