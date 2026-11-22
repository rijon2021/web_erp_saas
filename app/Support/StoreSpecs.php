<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Product specifications (products.specs JSON) for the Electronics Premium
 * theme: spec table, highlight tiles, card chips, shop filters, compare rows.
 *
 * A row is {group, label, value, highlight}. Labels are matched exactly
 * (trimmed) — the filters and the compare table group by label.
 */
class StoreSpecs
{
    public const MAX_ROWS = 60;

    protected static ?bool $ready = null;

    protected static ?bool $videoReady = null;

    /** products.specs exists (2026_11_09_000006 ran). */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasColumn('products', 'specs');
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    public static function videoReady(): bool
    {
        if (self::$videoReady === null) {
            try {
                self::$videoReady = Schema::hasColumn('products', 'video_url');
            } catch (\Throwable $e) {
                self::$videoReady = false;
            }
        }

        return self::$videoReady;
    }

    public static function forget(): void
    {
        self::$ready = null;
        self::$videoReady = null;
    }

    /**
     * Clean rows from the product form (JSON string or array). Rows without a
     * label or a value are dropped; labels/values are trimmed and capped.
     *
     * @return array<int, array{group: string, label: string, value: string, highlight: bool}>
     */
    public static function clean($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }

        // Brackets become parentheses: labels are used as query keys
        // (?spec[<label>][]=…), where "[" / "]" would split the key.
        $str = fn ($v, $max) => Str::limit(trim(preg_replace('/\s+/u', ' ', strtr((string) (is_scalar($v) ? $v : ''), ['[' => '(', ']' => ')']))), $max, '');
        $out = [];
        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = $str($row['label'] ?? '', 60);
            $value = $str($row['value'] ?? '', 160);
            if ($label === '' || $value === '') {
                continue;
            }
            $out[] = [
                'group' => $str($row['group'] ?? '', 60),
                'label' => $label,
                'value' => $value,
                'highlight' => filter_var($row['highlight'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
            if (count($out) >= self::MAX_ROWS) {
                break;
            }
        }

        return $out;
    }

    /** The product's rows (empty before the migration or when none were entered). */
    public static function rows($product): array
    {
        if (! $product) {
            return [];
        }
        $raw = $product->getAttributes()['specs'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }

        return self::clean(is_array($raw) ? $raw : json_decode((string) $raw, true));
    }

    /**
     * Highlight rows: the ones ticked "highlight", else the first rows.
     *
     * @return array<int, array{group: string, label: string, value: string, highlight: bool}>
     */
    public static function highlights($product, int $n = 4): array
    {
        $rows = self::rows($product);
        $marked = array_values(array_filter($rows, fn ($r) => $r['highlight']));

        return array_slice($marked ?: $rows, 0, $n);
    }

    /** Rows grouped for the spec table: [group title => rows]; blank group = "General". */
    public static function grouped($product): array
    {
        $groups = [];
        foreach (self::rows($product) as $r) {
            $g = $r['group'] !== '' ? $r['group'] : __('messages.PxSpecGeneral');
            $groups[$g][] = $r;
        }

        return $groups;
    }

    /** label => value (first value wins) — compare tables and filters. */
    public static function map($product): array
    {
        $out = [];
        foreach (self::rows($product) as $r) {
            $out[$r['label']] ??= $r['value'];
        }

        return $out;
    }

    /**
     * Spec labels offered as shop filters / compare rows: the owner's list when
     * set, otherwise the labels most products share (cached 10 minutes).
     */
    public static function filterLabels(array $opts): array
    {
        if (! self::ready()) {
            return [];
        }
        if (! empty($opts['filter_specs'])) {
            return array_values($opts['filter_specs']);
        }

        $tenant = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : 'central';

        return Cache::remember('px_spec_labels:'.$tenant, 600, function () {
            $counts = [];
            DB::table('products')
                ->whereNull('deleted_at')->where('is_active', 1)->where('hide_from_online_store', 0)
                ->whereNotNull('specs')
                ->orderByDesc('id')->limit(2000)
                ->pluck('specs')
                ->each(function ($json) use (&$counts) {
                    $seen = [];
                    foreach (self::clean(json_decode((string) $json, true)) as $r) {
                        if (! isset($seen[$r['label']])) {
                            $seen[$r['label']] = true;
                            $counts[$r['label']] = ($counts[$r['label']] ?? 0) + 1;
                        }
                    }
                });
            // A label only filters something when at least two products use it.
            $counts = array_filter($counts, fn ($c) => $c >= 2);
            arsort($counts);

            return array_slice(array_keys($counts), 0, PremiumElectronicsTheme::MAX_FILTER_SPECS);
        });
    }

    /** Drop the cached filter labels (after a product's specs change). */
    public static function forgetLabels(): void
    {
        $tenant = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : 'central';
        Cache::forget('px_spec_labels:'.$tenant);
    }

    /**
     * Selected spec filters from the query string: ?spec[<label>][]=<value>.
     * Only labels in $allowed are kept.
     *
     * @return array<string, string[]>
     */
    public static function selected($raw, array $allowed): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $allowedMap = array_flip($allowed);
        $out = [];
        foreach ($raw as $label => $values) {
            $label = trim((string) $label);
            if (! mb_check_encoding($label, 'UTF-8')) {
                continue;
            }
            if (! isset($allowedMap[$label])) {
                continue;
            }
            // Invalid UTF-8 (?spec[RAM][]=%FF) would make json_encode() fail in apply().
            $vals = array_values(array_unique(array_filter(array_map(fn ($v) => Str::limit(trim((string) (is_scalar($v) ? $v : '')), 160, ''), (array) $values), fn ($v) => $v !== '' && mb_check_encoding($v, 'UTF-8'))));
            if ($vals) {
                $out[$label] = array_slice($vals, 0, 20);
            }
        }

        return $out;
    }

    /**
     * Restrict a products query to the selected spec values: within a label
     * any value matches (OR); across labels all must match (AND).
     */
    public static function apply($query, array $selected): void
    {
        if (! $selected || ! self::ready()) {
            return;
        }
        foreach ($selected as $label => $values) {
            $needles = array_values(array_filter(array_map(
                fn ($v) => json_encode(['label' => (string) $label, 'value' => (string) $v], JSON_UNESCAPED_UNICODE),
                $values
            )));
            if (! $needles) {
                $query->whereRaw('1 = 0'); // nothing valid selected for this label

                continue;
            }
            $query->where(function ($q) use ($needles) {
                foreach ($needles as $needle) {
                    $q->orWhereRaw('JSON_CONTAINS(products.specs, ?)', [$needle]);
                }
            });
        }
    }

    /**
     * Facet values for each label among the given product ids:
     * [label => [value => count]], values in natural order.
     */
    public static function facets(array $productIds, array $labels): array
    {
        if (! self::ready() || ! $labels || ! $productIds) {
            return [];
        }
        $want = array_flip($labels);
        $out = array_fill_keys($labels, []);
        foreach (array_chunk($productIds, 1000) as $chunk) {
            DB::table('products')->whereIn('id', $chunk)->whereNotNull('specs')->pluck('specs')
                ->each(function ($json) use (&$out, $want) {
                    $seen = [];
                    foreach (self::clean(json_decode((string) $json, true)) as $r) {
                        if (isset($want[$r['label']]) && ! isset($seen[$r['label'].'|'.$r['value']])) {
                            $seen[$r['label'].'|'.$r['value']] = true;
                            $out[$r['label']][$r['value']] = ($out[$r['label']][$r['value']] ?? 0) + 1;
                        }
                    }
                });
        }
        foreach ($out as $label => $vals) {
            uksort($vals, 'strnatcasecmp');
            $out[$label] = $vals;
        }

        return array_filter($out);
    }
}
