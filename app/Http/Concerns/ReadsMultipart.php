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
}
