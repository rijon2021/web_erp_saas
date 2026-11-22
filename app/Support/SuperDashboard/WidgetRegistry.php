<?php

namespace App\Support\SuperDashboard;

/**
 * Every widget the super-admin dashboard can show, in default order.
 *
 * A widget's label and description live at super.dash_custom.widgets.<key>.
 * Its data comes from App\Services\SuperDashboard\WidgetData::<camelKey>(),
 * and it renders through dashboard/widgets/<partial>.blade.php.
 *
 * Keys are stored in general_settings.super_dashboard_layout, so never rename
 * one; a removed key is simply dropped the next time the layout resolves.
 */
class WidgetRegistry
{
    /** Bootstrap column classes for each width a widget can take. */
    public const WIDTHS = [
        'sixth'      => 'col-6 col-md-4 col-xl-2',
        'quarter'    => 'col-6 col-xl-3',
        'third'      => 'col-md-6 col-lg-4',
        'five'       => 'col-lg-5',
        'half'       => 'col-lg-6',
        'seven'      => 'col-lg-7',
        'two_thirds' => 'col-lg-8',
        'full'       => 'col-12',
    ];

    /** Widths each group may use (the first is never forced; the widget's own default is). */
    public const GROUP_WIDTHS = [
        'kpi'   => ['sixth', 'quarter', 'third', 'half'],
        'chart' => ['quarter', 'third', 'half', 'two_thirds', 'full'],
        'table' => ['third', 'five', 'half', 'seven', 'two_thirds', 'full'],
        'panel' => ['quarter', 'third', 'half', 'two_thirds', 'full'],
        'alert' => ['full'],
    ];

    public const GROUPS = ['kpi', 'chart', 'table', 'panel', 'alert'];

    private const MONTHS = ['choices' => [3, 6, 12, 24], 'default' => 6];
    private const RANGE = ['choices' => [30, 90, 365, 0], 'default' => 30];
    private const ROWS = ['choices' => [5, 8, 10, 15], 'default' => 5];

    /**
     * group      kpi | chart | table | panel | alert
     * width      default width (one of GROUP_WIDTHS[group])
     * enabled    shown before the owner customizes anything
     * locked     always shown, cannot be switched off (still reorderable)
     * permission CentralUser section needed to see it (null = dashboard access)
     * options    name => [choices, default]; names map to option labels
     * cacheable  aggregate data that may be cached for the "cache" setting
     */
    public static function all(): array
    {
        return [
            // -- Default dashboard (2026-11 redesign), in display order --
            // Hero KPIs: value, change pill, sparkline.
            'kpi_mrr'                   => ['group' => 'kpi', 'icon' => 'bi-graph-up-arrow', 'width' => 'quarter', 'enabled' => true],
            'kpi_tenants'               => ['group' => 'kpi', 'icon' => 'bi-building', 'width' => 'quarter', 'enabled' => true],
            'kpi_revenue_month'         => ['group' => 'kpi', 'icon' => 'bi-cash-stack', 'width' => 'quarter', 'enabled' => true, 'permission' => 'payments'],
            'kpi_conversion'            => ['group' => 'kpi', 'icon' => 'bi-funnel', 'width' => 'quarter', 'enabled' => true,
                'options' => ['days' => ['choices' => [30, 90, 365], 'default' => 30]]],
            // Operational counters (the "sixth" width renders them as a compact strip).
            'kpi_trials_ending'         => ['group' => 'kpi', 'icon' => 'bi-hourglass-split', 'width' => 'sixth', 'enabled' => true,
                'options' => ['ahead' => ['choices' => [7, 14, 30], 'default' => 7]]],
            'kpi_expiring'              => ['group' => 'kpi', 'icon' => 'bi-alarm', 'width' => 'sixth', 'enabled' => true,
                'options' => ['ahead' => ['choices' => [7, 14, 30], 'default' => 7]]],
            'kpi_failed_payments'       => ['group' => 'kpi', 'icon' => 'bi-x-octagon', 'width' => 'sixth', 'enabled' => true,
                'options' => ['days' => ['choices' => [7, 30, 90], 'default' => 30]]],
            'kpi_open_tickets'          => ['group' => 'kpi', 'icon' => 'bi-life-preserver', 'width' => 'sixth', 'enabled' => true, 'permission' => 'support'],
            'kpi_pending_payments'      => ['group' => 'kpi', 'icon' => 'bi-bank', 'width' => 'sixth', 'enabled' => true, 'permission' => 'payments'],
            'kpi_payment_links'         => ['group' => 'kpi', 'icon' => 'bi-link-45deg', 'width' => 'sixth', 'enabled' => true, 'permission' => 'payments'],
            // Trends.
            'chart_revenue_trend'       => ['group' => 'chart', 'icon' => 'bi-currency-exchange', 'width' => 'two_thirds', 'enabled' => true, 'permission' => 'payments', 'cacheable' => true,
                'options' => ['months' => ['choices' => [3, 6, 12, 24], 'default' => 12], 'chart' => ['choices' => ['area', 'bar', 'line'], 'default' => 'area']]],
            'chart_subscription_status' => ['group' => 'chart', 'icon' => 'bi-pie-chart', 'width' => 'third', 'enabled' => true, 'cacheable' => true,
                'options' => ['chart' => ['choices' => ['segments', 'donut', 'pie', 'bar'], 'default' => 'segments']]],
            // Sign-ups waiting for approval (always shown when there are any).
            'pending_tenants'           => ['group' => 'alert', 'icon' => 'bi-hourglass-split', 'width' => 'full', 'enabled' => true, 'locked' => true],
            // Work queue.
            'needs_attention'           => ['group' => 'panel', 'icon' => 'bi-bell', 'width' => 'third', 'enabled' => true],
            'expiring_table'            => ['group' => 'table', 'icon' => 'bi-alarm', 'width' => 'two_thirds', 'enabled' => true,
                'options' => ['ahead' => ['choices' => [7, 14, 30], 'default' => 14], 'rows' => ['choices' => [5, 8, 15, 25], 'default' => 5]]],
            'chart_net_growth'          => ['group' => 'chart', 'icon' => 'bi-arrow-down-up', 'width' => 'half', 'enabled' => true, 'cacheable' => true,
                'options' => ['months' => self::MONTHS]],
            'chart_revenue_by_plan'     => ['group' => 'chart', 'icon' => 'bi-tags', 'width' => 'quarter', 'enabled' => true, 'permission' => 'payments', 'cacheable' => true,
                'options' => ['range' => self::RANGE, 'chart' => ['choices' => ['bars', 'donut', 'pie', 'bar'], 'default' => 'bars']]],
            'system_health'             => ['group' => 'panel', 'icon' => 'bi-activity', 'width' => 'quarter', 'enabled' => true, 'permission' => 'logs_health'],
            'table_recent_payments'     => ['group' => 'table', 'icon' => 'bi-receipt', 'width' => 'seven', 'enabled' => true, 'permission' => 'payments',
                'options' => ['rows' => self::ROWS]],
            'table_recent_signups'      => ['group' => 'table', 'icon' => 'bi-person-plus', 'width' => 'five', 'enabled' => true, 'permission' => 'tenants',
                'options' => ['rows' => self::ROWS]],

            // -- Available widgets (off until switched on) --
            'kpi_active_subscriptions'  => ['group' => 'kpi', 'icon' => 'bi-check-circle', 'width' => 'quarter', 'enabled' => false],
            'kpi_plans'                 => ['group' => 'kpi', 'icon' => 'bi-tag-fill', 'width' => 'quarter', 'enabled' => false],
            'kpi_total_subscriptions'   => ['group' => 'kpi', 'icon' => 'bi-pie-chart-fill', 'width' => 'quarter', 'enabled' => false],
            'kpi_arpu'                  => ['group' => 'kpi', 'icon' => 'bi-person-badge', 'width' => 'quarter', 'enabled' => false, 'permission' => 'payments'],
            'kpi_renewal_forecast'      => ['group' => 'kpi', 'icon' => 'bi-arrow-repeat', 'width' => 'quarter', 'enabled' => false, 'permission' => 'payments',
                'options' => ['ahead' => ['choices' => [7, 30, 90], 'default' => 30]]],
            'kpi_new_signups'           => ['group' => 'kpi', 'icon' => 'bi-person-plus', 'width' => 'quarter', 'enabled' => false],
            'health_alerts'             => ['group' => 'alert', 'icon' => 'bi-heart-pulse', 'width' => 'full', 'enabled' => false],
            'chart_tenant_growth'       => ['group' => 'chart', 'icon' => 'bi-graph-up', 'width' => 'two_thirds', 'enabled' => false, 'cacheable' => true,
                'options' => ['months' => self::MONTHS, 'chart' => ['choices' => ['area', 'bar', 'line'], 'default' => 'area']]],
            'chart_subs_by_plan'        => ['group' => 'chart', 'icon' => 'bi-bar-chart', 'width' => 'half', 'enabled' => false, 'cacheable' => true,
                'options' => ['top' => ['choices' => [3, 5, 10], 'default' => 5], 'chart' => ['choices' => ['bars', 'bar', 'donut'], 'default' => 'bars']]],
            'chart_revenue_by_gateway'  => ['group' => 'chart', 'icon' => 'bi-credit-card', 'width' => 'third', 'enabled' => false, 'permission' => 'payments', 'cacheable' => true,
                'options' => ['range' => self::RANGE, 'chart' => ['choices' => ['bars', 'donut', 'pie', 'bar'], 'default' => 'bars']]],
            'chart_billing_cycle'       => ['group' => 'chart', 'icon' => 'bi-calendar2-range', 'width' => 'third', 'enabled' => false, 'cacheable' => true,
                'options' => ['chart' => ['choices' => ['bars', 'donut', 'pie', 'bar'], 'default' => 'bars']]],
            'chart_tenant_status'       => ['group' => 'chart', 'icon' => 'bi-buildings', 'width' => 'third', 'enabled' => false, 'cacheable' => true,
                'options' => ['chart' => ['choices' => ['segments', 'donut', 'pie', 'bar'], 'default' => 'segments']]],
            'quick_actions'             => ['group' => 'panel', 'icon' => 'bi-lightning-charge', 'width' => 'half', 'enabled' => false],
            'table_top_tenants'         => ['group' => 'table', 'icon' => 'bi-trophy', 'width' => 'half', 'enabled' => false, 'permission' => 'payments',
                'options' => ['rows' => self::ROWS, 'range' => ['choices' => [30, 90, 365, 0], 'default' => 365]]],
            'table_open_tickets'        => ['group' => 'table', 'icon' => 'bi-life-preserver', 'width' => 'half', 'enabled' => false, 'permission' => 'support',
                'options' => ['rows' => self::ROWS]],
            'table_subscription_events' => ['group' => 'table', 'icon' => 'bi-clock-history', 'width' => 'half', 'enabled' => false, 'permission' => 'subscriptions',
                'options' => ['rows' => self::ROWS]],
            'table_system_errors'       => ['group' => 'table', 'icon' => 'bi-bug', 'width' => 'half', 'enabled' => false, 'permission' => 'logs_health',
                'options' => ['rows' => self::ROWS]],
            'table_impersonations'      => ['group' => 'table', 'icon' => 'bi-incognito', 'width' => 'half', 'enabled' => false, 'permission' => 'logs_health',
                'options' => ['rows' => self::ROWS]],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return string[] */
    public static function widthsFor(array $definition): array
    {
        return self::GROUP_WIDTHS[$definition['group']] ?? ['full'];
    }

    public static function columnClass(string $width): string
    {
        return self::WIDTHS[$width] ?? self::WIDTHS['full'];
    }
}
