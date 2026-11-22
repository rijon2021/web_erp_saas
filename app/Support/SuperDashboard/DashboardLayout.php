<?php

namespace App\Support\SuperDashboard;

use App\Models\Central\CentralUser;
use App\Models\Central\GeneralSetting;
use Illuminate\Support\Facades\Schema;

/**
 * The super-admin dashboard layout: page-wide display settings plus the
 * order, visibility, width and options of every widget.
 *
 * Stored (platform-wide) as JSON in general_settings.super_dashboard_layout:
 *   ['settings' => [...], 'order' => [key, ...], 'widgets' => [key => [enabled, width, options]]]
 * NULL, or anything unreadable, falls back to WidgetRegistry defaults, so the
 * dashboard looks unchanged until the owner saves the Dashboard tab.
 */
class DashboardLayout
{
    /** Page-wide settings: name => [choices, default]. */
    public const SETTINGS = [
        'show_greeting' => ['choices' => [true, false], 'default' => true],
        'refresh'       => ['choices' => [0, 1, 5, 15, 30], 'default' => 0],
        'density'       => ['choices' => ['comfortable', 'compact'], 'default' => 'comfortable'],
        'cache'         => ['choices' => [0, 5, 15, 60], 'default' => 0],
    ];

    private static ?bool $columnExists = null;

    /** Whether the super_dashboard_layout migration has run. */
    public static function storable(): bool
    {
        return self::$columnExists ??= Schema::connection('central')
            ->hasColumn('general_settings', 'super_dashboard_layout');
    }

    /** The saved layout merged over the defaults. */
    public static function current(): array
    {
        $stored = self::storable() ? GeneralSetting::instance()->super_dashboard_layout : null;

        return self::resolve(is_array($stored) ? $stored : null);
    }

    public static function defaults(): array
    {
        return self::resolve(null);
    }

    /**
     * Merge raw input (stored JSON or the settings form) over the defaults.
     * Unknown widgets and out-of-range values are dropped; widgets missing
     * from the saved order (added by a later update) go to the end.
     *
     * @return array{settings: array, widgets: array<string, array>}
     */
    public static function resolve(?array $raw): array
    {
        $raw ??= [];
        $definitions = WidgetRegistry::all();

        $settings = [];
        $rawSettings = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
        foreach (self::SETTINGS as $name => $spec) {
            $settings[$name] = self::pick($rawSettings[$name] ?? null, $spec['choices'], $spec['default']);
        }

        $order = [];
        foreach ((array) ($raw['order'] ?? []) as $key) {
            if (is_string($key) && isset($definitions[$key]) && ! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        foreach (array_keys($definitions) as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        $rawWidgets = is_array($raw['widgets'] ?? null) ? $raw['widgets'] : [];
        $widgets = [];
        foreach ($order as $key) {
            $definition = $definitions[$key];
            $saved = is_array($rawWidgets[$key] ?? null) ? $rawWidgets[$key] : [];

            $enabled = ! empty($definition['locked'])
                || (array_key_exists('enabled', $saved) ? self::bool($saved['enabled']) : (bool) $definition['enabled']);

            $widths = WidgetRegistry::widthsFor($definition);
            $width = in_array($saved['width'] ?? null, $widths, true) ? $saved['width'] : $definition['width'];

            $options = [];
            $savedOptions = is_array($saved['options'] ?? null) ? $saved['options'] : [];
            foreach ($definition['options'] ?? [] as $name => $spec) {
                $options[$name] = self::pick($savedOptions[$name] ?? null, $spec['choices'], $spec['default']);
            }

            $widgets[$key] = [
                'key'     => $key,
                'enabled' => $enabled,
                'width'   => $width,
                'options' => $options,
            ];
        }

        return ['settings' => $settings, 'widgets' => $widgets];
    }

    /** Shape a resolved layout for storage. */
    public static function toStorage(array $resolved): array
    {
        return [
            'settings' => $resolved['settings'],
            'order'    => array_keys($resolved['widgets']),
            'widgets'  => array_map(fn (array $w) => [
                'enabled' => $w['enabled'],
                'width'   => $w['width'],
                'options' => $w['options'],
            ], $resolved['widgets']),
        ];
    }

    /** Enabled widgets this admin may see, in order. */
    public static function visibleWidgets(array $resolved, ?CentralUser $user): array
    {
        return array_filter($resolved['widgets'], function (array $widget) use ($user) {
            if (! $widget['enabled']) {
                return false;
            }
            $permission = WidgetRegistry::get($widget['key'])['permission'] ?? null;

            return $permission === null || ($user && $user->hasPermission($permission));
        });
    }

    /** The choice matching $value (compared as strings, as form input arrives), else $default. */
    private static function pick(mixed $value, array $choices, mixed $default): mixed
    {
        if ($value === null || ! is_scalar($value)) {
            return $default;
        }
        if (is_bool($default)) {
            return self::bool($value);
        }
        foreach ($choices as $choice) {
            if ((string) $choice === (string) $value) {
                return $choice;
            }
        }

        return $default;
    }

    private static function bool(mixed $value): bool
    {
        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
