<?php
declare(strict_types=1);
namespace App\Infrastructure\Net;

final class NetSettings
{
    public const DEFAULTS = [
        'net.alert.mass_min_onus' => 4, 'net.alert.mass_pct' => 50,
        'net.alert.mass_window_min' => 15, 'net.alert.unstable_downs' => 5,
        'net.alert.unstable_hours' => 24, 'net.alert.unreachable_failures' => 3,
        'net.alert.band_cycles' => 2, 'net.alert.mute_default_hours' => 24,
        'net.tick.backoff_failures' => 3, 'net.tick.backoff_min' => 15,
        'net.retention.samples_days' => 60, 'net.retention.events_days' => 180,
    ];

    public static function resolve(array $settings): array
    {
        $out = self::DEFAULTS;
        foreach ($out as $key => $default) {
            if (isset($settings[$key]) && is_numeric($settings[$key])) {
                $out[$key] = max(1, min(65535, (int) $settings[$key]));
            }
        }
        $out['net.alert.mass_pct'] = min(100, $out['net.alert.mass_pct']);
        return $out;
    }
}
