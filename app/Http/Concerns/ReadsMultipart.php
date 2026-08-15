<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/*
 * The multipart contract of docs/API.md: the fields travel in one `payload`
 * JSON part, and each file list under its own name with a [] suffix (PHP
 * keeps only the last of repeated bare names).
 */
trait ReadsMultipart
{
    /** @return array<string, mixed> The `payload` part, parsed exactly like a JSON body */
    private function payload(Request $request): array
    {
        $decoded = json_decode((string) $request->input('payload', ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<UploadedFile> */
    private function files(Request $request, string $key): array
    {
        $found = $request->file($key);
        if ($found === null) {
            return [];
        }

        return is_array($found) ? array_values($found) : [$found];
    }

    /**
     * Sniffed content type, not the client's claim. Every stored file is
     * later served inline on the app's own origin (/api/files), so an HTML
     * or SVG body smuggled in as a "photo" would run as the app — this gate
     * guards every photo intake, not just the avatar's.
     */
    private function isPhoto(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif',
        ], true) && $file->getSize() <= 10 * 1024 * 1024;
    }
}
