<?php

namespace App\Support;

/*
 * A user agent, reduced to what a shop owner can act on: "Chrome on Android",
 * phone or computer. Honest and coarse — no model-name guessing, no user
 * agent database to keep current.
 */
class DeviceName
{
    /** @return array{device: string, platform: string, kind: string} */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $phone = str_contains($ua, 'Android') || str_contains($ua, 'iPhone')
            || str_contains($ua, 'Mobile');

        return [
            'device' => "{$browser} on {$os}",
            'platform' => $os,
            'kind' => $phone ? 'phone' : 'computer',
        ];
    }
}
