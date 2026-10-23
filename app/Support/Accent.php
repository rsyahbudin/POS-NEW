<?php

namespace App\Support;

use App\Domain\Settings\SettingsRepository;

/**
 * The accent colour of the whole app (buttons, links, active menu, charts), chosen in Pengaturan > Operasional.
 * Status colours (green / amber / red for table and order states) never change with it.
 * Written as an inline <style> after the compiled CSS so it wins without a rebuild.
 */
final class Accent
{
    /** @var array<string, array{label: string, light: array<string, string>, dark: array<string, string>}> */
    public const PRESETS = [
        'blue' => [
            'label' => 'Biru',
            'light' => ['primary' => '0.5 0.19 262', 'tint' => '0.955 0.022 262', 'ink' => '0.37 0.13 262', 'ring' => '0.6 0.16 262'],
            'dark' => ['primary' => '0.72 0.14 262', 'tint' => '0.3 0.05 262', 'ink' => '0.92 0.03 262', 'ring' => '0.6 0.14 262'],
        ],
        'green' => [
            'label' => 'Hijau',
            'light' => ['primary' => '0.48 0.12 163', 'tint' => '0.955 0.012 163', 'ink' => '0.34 0.08 163', 'ring' => '0.58 0.12 163'],
            'dark' => ['primary' => '0.74 0.14 163', 'tint' => '0.29 0.025 163', 'ink' => '0.92 0.02 163', 'ring' => '0.6 0.12 163'],
        ],
        'orange' => [
            'label' => 'Oranye',
            'light' => ['primary' => '0.56 0.16 45', 'tint' => '0.96 0.022 55', 'ink' => '0.4 0.11 45', 'ring' => '0.64 0.16 50'],
            'dark' => ['primary' => '0.76 0.15 55', 'tint' => '0.3 0.05 50', 'ink' => '0.93 0.04 60', 'ring' => '0.65 0.15 50'],
        ],
        'violet' => [
            'label' => 'Ungu',
            'light' => ['primary' => '0.5 0.2 292', 'tint' => '0.957 0.024 292', 'ink' => '0.37 0.14 292', 'ring' => '0.6 0.17 292'],
            'dark' => ['primary' => '0.73 0.15 292', 'tint' => '0.3 0.055 292', 'ink' => '0.92 0.035 292', 'ring' => '0.62 0.15 292'],
        ],
    ];

    public static function key(): string
    {
        $key = (string) app(SettingsRepository::class)->get('ui.accent', 'blue');

        return isset(self::PRESETS[$key]) ? $key : 'blue';
    }

    public static function css(): string
    {
        $p = self::PRESETS[self::key()];
        $block = function (array $c, string $fg): string {
            $v = [
                '--primary' => "oklch({$c['primary']})", '--primary-foreground' => $fg, '--ring' => "oklch({$c['ring']})",
                '--accent' => "oklch({$c['tint']})", '--accent-foreground' => "oklch({$c['ink']})",
                '--chart-1' => "oklch({$c['primary']})",
                '--sidebar-primary' => "oklch({$c['primary']})", '--sidebar-primary-foreground' => $fg, '--sidebar-ring' => "oklch({$c['ring']})",
                '--sidebar-accent' => "oklch({$c['tint']})", '--sidebar-accent-foreground' => "oklch({$c['ink']})",
            ];

            return implode('', array_map(fn ($k, $val) => "{$k}:{$val};", array_keys($v), $v));
        };

        return ':root{'.$block($p['light'], 'oklch(0.99 0.004 262)').'}:root.dark{'.$block($p['dark'], 'oklch(0.2 0.03 262)').'}';
    }

    /** @return list<array{0: string, 1: string}> */
    public static function options(): array
    {
        return array_map(fn ($k, $v) => [$k, $v['label']], array_keys(self::PRESETS), self::PRESETS);
    }
}
