<?php

namespace App\Support;

use App\Models\VehicleSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Car Dealership theme: inventory filters (?condition=&make=&model=&year_min=
 * &year_max=&mileage_max=&body[]=&fuel[]=&transmission[]=) and the facet
 * counts the filter panel shows. Counts cover every car on sale (not narrowed
 * by the other filters) so an option never disappears while it is ticked.
 * Sold cars and the reservation-deposit product are never listed.
 */
class VehicleCatalog
{
    public const MULTI = ['body' => 'body_type', 'fuel' => 'fuel', 'transmission' => 'transmission'];

    public const SORTS = ['recommended', 'latest', 'price_asc', 'price_desc', 'year_desc', 'mileage_asc'];

    /** @return array<string, mixed> the normalised filter values from the query string */
    public static function selected(Request $request): array
    {
        $one = fn ($k, $max = 80) => mb_substr(trim((string) (is_scalar($request->query($k)) ? $request->query($k) : '')), 0, $max);
        $num = fn ($k) => is_numeric($request->query($k)) ? (int) $request->query($k) : null;
        $out = [
            'q' => $one('q', 120),
            'condition' => in_array($c = $one('condition'), VehicleSpec::CONDITIONS, true) ? $c : '',
            'make' => $one('make'),
            'model' => $one('model'),
            'year_min' => $num('year_min'),
            'year_max' => $num('year_max'),
            'mileage_max' => $num('mileage_max'),
            'min' => is_numeric($request->query('min')) ? (float) $request->query('min') : null,
            'max' => is_numeric($request->query('max')) ? (float) $request->query('max') : null,
            'sort' => in_array($s = $one('sort'), self::SORTS, true) ? $s : 'recommended',
            'category' => $num('category'),
        ];
        $vocab = ['body' => VehicleSpec::BODY_TYPES, 'fuel' => VehicleSpec::FUELS, 'transmission' => VehicleSpec::TRANSMISSIONS];
        foreach ($vocab as $key => $allowed) {
            $v = $request->query($key);
            $v = is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]);
            $out[$key] = array_values(array_intersect($allowed, array_map(fn ($x) => strtolower((string) $x), array_filter($v, 'is_scalar'))));
        }

        return $out;
    }

    /** Chips for the active filters: [['label' => 'SUV', 'url' => <same search without it>], …] */
    public static function chips(array $f, string $baseUrl, array $query): array
    {
        $chips = [];
        $without = function (string $key, $value = null) use ($query, $baseUrl) {
            $q = $query;
            if ($value === null) {
                unset($q[$key]);
            } else {
                $q[$key] = array_values(array_diff((array) ($q[$key] ?? []), [$value]));
            }
            unset($q['page']);

            return $baseUrl.($q ? '?'.http_build_query($q) : '');
        };
        if ($f['condition'] !== '') {
            $chips[] = ['label' => __('messages.CarCondition_'.$f['condition']), 'url' => $without('condition')];
        }
        if ($f['make'] !== '') {
            // A model only means something within its make: drop both.
            $q = $query;
            unset($q['make'], $q['model'], $q['page']);
            $chips[] = ['label' => $f['make'], 'url' => $baseUrl.($q ? '?'.http_build_query($q) : '')];
        }
        if ($f['model'] !== '') {
            $chips[] = ['label' => $f['model'], 'url' => $without('model')];
        }
        foreach (['body' => 'CarBody_', 'fuel' => 'CarFuel_', 'transmission' => 'CarGear_'] as $k => $prefix) {
            foreach ($f[$k] as $v) {
                $chips[] = ['label' => __('messages.'.$prefix.$v), 'url' => $without($k, $v)];
            }
        }
        if ($f['year_min'] !== null) {
            $chips[] = ['label' => __('messages.CarFromYear', ['year' => $f['year_min']]), 'url' => $without('year_min')];
        }
        if ($f['year_max'] !== null) {
            $chips[] = ['label' => __('messages.CarToYear', ['year' => $f['year_max']]), 'url' => $without('year_max')];
        }
        if ($f['mileage_max'] !== null) {
            $chips[] = ['label' => __('messages.CarUpToMileage', ['mileage' => number_format($f['mileage_max'])]), 'url' => $without('mileage_max')];
        }
        if ($f['min'] !== null) {
            $chips[] = ['label' => __('messages.CarPriceFrom', ['price' => store_money($f['min'])]), 'url' => $without('min')];
        }
        if ($f['max'] !== null) {
            $chips[] = ['label' => __('messages.CarPriceUpTo', ['price' => store_money($f['max'])]), 'url' => $without('max')];
        }
        if ($f['q'] !== '') {
            $chips[] = ['label' => '“'.$f['q'].'”', 'url' => $without('q')];
        }

        return $chips;
    }

    /** Products that are not sold and not the deposit product. */
    public static function onSale($query, ?int $reserveProductId): void
    {
        if ($reserveProductId) {
            $query->where('products.id', '!=', $reserveProductId);
        }
        if (VehicleSpec::tablesReady()) {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('vehicle_specs')
                    ->whereColumn('vehicle_specs.product_id', 'products.id')
                    ->where('vehicle_specs.is_sold', 1);
            });
        }
    }

    /** Apply the vehicle filters and the vehicle sorts to a product query. */
    public static function apply($query, array $f, string $finalExpr): void
    {
        if ($f['q'] !== '') {
            $term = '%'.$f['q'].'%';
            $query->where(function ($w) use ($term) {
                $w->where('products.name', 'like', $term);
                if (VehicleSpec::tablesReady()) {
                    $w->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('vehicle_specs')->whereColumn('vehicle_specs.product_id', 'products.id')
                        ->where(fn ($x) => $x->where('make', 'like', $term)->orWhere('model', 'like', $term)->orWhere('trim', 'like', $term)));
                }
            });
        }
        if ($f['category']) {
            $query->where('products.category_id', $f['category']);
        }
        if ($f['min'] !== null) {
            $query->whereRaw("$finalExpr >= ?", [$f['min']]);
        }
        if ($f['max'] !== null) {
            $query->whereRaw("$finalExpr <= ?", [$f['max']]);
        }

        $specFilters = $f['condition'] !== '' || $f['make'] !== '' || $f['model'] !== '' || $f['year_min'] !== null || $f['year_max'] !== null
            || $f['mileage_max'] !== null || $f['body'] || $f['fuel'] || $f['transmission'];
        if ($specFilters && VehicleSpec::tablesReady()) {
            $query->whereExists(function ($s) use ($f) {
                $s->select(DB::raw(1))->from('vehicle_specs')->whereColumn('vehicle_specs.product_id', 'products.id');
                if ($f['condition'] !== '') {
                    $s->where('condition', $f['condition']);
                }
                if ($f['make'] !== '') {
                    $s->where('make', $f['make']);
                }
                if ($f['model'] !== '') {
                    $s->where('model', $f['model']);
                }
                if ($f['year_min'] !== null) {
                    $s->where('year', '>=', $f['year_min']);
                }
                if ($f['year_max'] !== null) {
                    $s->where('year', '<=', $f['year_max']);
                }
                if ($f['mileage_max'] !== null) {
                    $s->where('mileage', '<=', $f['mileage_max']);
                }
                foreach (self::MULTI as $key => $column) {
                    if ($f[$key]) {
                        $s->whereIn($column, $f[$key]);
                    }
                }
            });
        } elseif ($specFilters) {
            $query->whereRaw('1 = 0');
        }

        $joinSpecs = in_array($f['sort'], ['year_desc', 'mileage_asc'], true) && VehicleSpec::tablesReady();
        if ($joinSpecs) {
            $query->leftJoin('vehicle_specs as vs_sort', 'vs_sort.product_id', '=', 'products.id');
        }
        match ($f['sort']) {
            'price_asc' => $query->orderByRaw("$finalExpr ASC"),
            'price_desc' => $query->orderByRaw("$finalExpr DESC"),
            'year_desc' => $joinSpecs ? $query->orderByRaw('vs_sort.year IS NULL, vs_sort.year DESC') : $query,
            'mileage_asc' => $joinSpecs ? $query->orderByRaw('vs_sort.mileage IS NULL, vs_sort.mileage ASC') : $query,
            'latest' => $query,
            default => $query->orderByDesc('products.is_featured'),
        };
        $query->orderByDesc('products.created_at');
    }

    /**
     * Facet counts over the cars on sale:
     * ['makes' => [['name' => 'BMW', 'count' => 6], …], 'models' => [...] (for $make),
     *  'body' => ['suv' => 4, …], 'fuel' => [...], 'transmission' => [...], 'condition' => [...],
     *  'years' => ['min' => 2015, 'max' => 2025]]
     */
    public static function facets(?int $reserveProductId, string $make = '', ?array $onlyIds = null): array
    {
        $empty = ['makes' => [], 'models' => [], 'body' => [], 'fuel' => [], 'transmission' => [], 'condition' => [], 'years' => ['min' => null, 'max' => null], 'total' => 0];
        if (! VehicleSpec::tablesReady()) {
            return $empty;
        }
        $base = fn () => DB::table('vehicle_specs as vs')
            ->join('products as p', 'p.id', '=', 'vs.product_id')
            ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.hide_from_online_store', 0)->tap(fn ($q) => \App\Models\Product::applyStorefrontWarehouses($q, 'p'))
            ->where('vs.is_sold', 0)
            ->when($reserveProductId, fn ($q) => $q->where('p.id', '!=', $reserveProductId))
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('p.id', $onlyIds ?: [0]));

        $group = fn ($column) => $base()->whereNotNull("vs.$column")->where("vs.$column", '!=', '')
            ->select("vs.$column as v", DB::raw('COUNT(*) as c'))->groupBy("vs.$column")->orderByDesc('c')->orderBy("vs.$column")
            ->pluck('c', 'v')->map(fn ($c) => (int) $c)->all();

        $years = $base()->selectRaw('MIN(vs.year) AS lo, MAX(vs.year) AS hi')->first();
        $models = $make !== ''
            ? $base()->where('vs.make', $make)->whereNotNull('vs.model')->where('vs.model', '!=', '')
                ->select('vs.model as v', DB::raw('COUNT(*) as c'))->groupBy('vs.model')->orderBy('vs.model')->pluck('c', 'v')->all()
            : [];

        return [
            'makes' => collect($group('make'))->map(fn ($c, $name) => ['name' => (string) $name, 'count' => $c])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'models' => collect($models)->map(fn ($c, $name) => ['name' => (string) $name, 'count' => (int) $c])->values()->all(),
            'body' => $group('body_type'),
            'fuel' => $group('fuel'),
            'transmission' => $group('transmission'),
            'condition' => $group('condition'),
            'years' => ['min' => $years->lo ? (int) $years->lo : null, 'max' => $years->hi ? (int) $years->hi : null],
            'total' => (int) $base()->count(),
        ];
    }

    /** make => [models] for the home search form's dependent model select. */
    public static function makeModels(?int $reserveProductId, ?array $onlyIds = null): array
    {
        if (! VehicleSpec::tablesReady()) {
            return [];
        }

        return DB::table('vehicle_specs as vs')
            ->join('products as p', 'p.id', '=', 'vs.product_id')
            ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.hide_from_online_store', 0)->tap(fn ($q) => \App\Models\Product::applyStorefrontWarehouses($q, 'p'))->where('vs.is_sold', 0)
            ->when($reserveProductId, fn ($q) => $q->where('p.id', '!=', $reserveProductId))
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('p.id', $onlyIds ?: [0]))
            ->whereNotNull('vs.make')->where('vs.make', '!=', '')
            ->select('vs.make', 'vs.model')->distinct()->orderBy('vs.make')->orderBy('vs.model')->get()
            ->groupBy('make')
            ->map(fn ($rows) => $rows->pluck('model')->filter()->values()->all())
            ->all();
    }
}
