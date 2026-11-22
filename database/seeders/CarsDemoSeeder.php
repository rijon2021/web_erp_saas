<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StoreSetting;
use App\Models\Unit;
use App\Models\VehicleSpec;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo stock for the Car Dealership theme: 13 cars (category "Cars", one
 * brand per make, Unsplash photo LINKS, vehicle specs, 1 in stock in the
 * first warehouse), a "Reservation deposit" service product, and the theme
 * activated with one hero slide, opening hours and reservations on.
 * Needs the vehicle_specs migration (2026_10_24_000001).
 * Run:  php artisan tenants:run db:seed --option="class=CarsDemoSeeder" (or db:seed inside a tenant)
 * Idempotent: products carry CD- codes and are skipped when present.
 */
class CarsDemoSeeder extends Seeder
{
    private const IMG = [
        'mustang' => '1494976388531-d1058494cdd8', 'panamera' => '1503376780353-7e6692767b70', 'camaro' => '1552519507-da3b142c6e3d',
        'fiat500' => '1549317661-bd32c8ce0db2', 'expedition' => '1533473359331-0135ef1b58bf', 'm4' => '1580273916550-e323be2ae537',
        'bmw4' => '1502877338535-766e1452684a', 'crv' => '1519641471654-76ce0107ad1b', 'rs6' => '1606664515524-ed2f786a0bd6',
        'accent' => '1619767886558-efdc259cde1a', 'm5' => '1555215695-3004980ad54e', 'polo' => '1541899481282-d53bffe3c35d',
        'gtr' => '1568605117036-5fe5e7bab0b7',
    ];

    private static function img(string $key, int $w = 1200): string
    {
        return 'https://images.unsplash.com/photo-'.self::IMG[$key].'?w='.$w.'&q=80&auto=format&fit=crop';
    }

    public function run(): void
    {
        if (! VehicleSpec::tablesReady()) {
            $this->command?->error('Run the tenant migrations first (vehicle_specs / vehicle_leads tables are missing).');

            return;
        }
        if (Product::where('code', 'like', 'CD-%')->exists()) {
            $this->command?->warn('Car demo products already present — activating the theme only. (Delete CD-* products to reseed.)');
            $this->activateTheme();

            return;
        }

        DB::transaction(function () {
            $unit = Unit::firstOrCreate(['ShortName' => 'pc'], ['name' => 'Piece', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1]);
            $warehouse = Warehouse::first() ?: Warehouse::create(['name' => 'Showroom', 'city' => 'Main', 'country' => '', 'mobile' => '', 'email' => '', 'zip' => '']);
            $category = Category::firstOrCreate(['name' => 'Cars'], array_merge(['code' => 'CD-C1', 'icon' => 'truck'], Schema::hasColumn('categories', 'show_in_store') ? ['show_in_store' => 1] : []));

            $features = [
                'comfort' => ['Dual-zone climate control', 'Heated front seats', 'Keyless entry', 'Cruise control'],
                'tech' => ['Navigation', 'Apple CarPlay / Android Auto', 'Reversing camera', 'Parking sensors', 'Bluetooth'],
                'safety' => ['Lane departure warning', 'Autonomous emergency braking', 'Blind-spot monitoring', 'ISOFIX'],
                'sport' => ['Launch control', 'Adaptive suspension', 'Sport exhaust', 'Performance brakes'],
            ];
            $pick = fn (...$groups) => array_values(array_merge(...array_map(fn ($g) => $features[$g], $groups)));

            // [make, model, trim, year, km, body, fuel, gearbox, drive, engine L, hp, colour, interior, doors, seats, owners, economy,
            //  service, condition, cost, price, discount%, featured, img, features, days old, note]
            $rows = [
                ['Ford', 'Mustang', '5.0 V8 GT Fastback', 2021, 32400, 'coupe', 'petrol', 'automatic', 'rwd', 5.0, 450, 'Magnetic Grey', 'Black leather', 2, 4, 1, '12.4 L/100 km', 'full', 'used', 31000, 38900, 0, 1, 'mustang', $pick('comfort', 'tech', 'sport'), 4, 'One owner from new with full Ford service history. 10-speed automatic, active valve exhaust and the B&O sound system.'],
                ['Porsche', 'Panamera', '4 Sport Turismo', 2019, 41000, 'sedan', 'petrol', 'automatic', 'awd', 2.9, 330, 'Jet Black', 'Black leather', 4, 4, 2, '9.6 L/100 km', 'full', 'certified', 58000, 68500, 0, 1, 'panamera', $pick('comfort', 'tech', 'safety'), 10, 'Porsche Approved car with panoramic roof, sport chrono package and adaptive air suspension.'],
                ['Chevrolet', 'Camaro', '2SS 6.2 V8', 2020, 18900, 'coupe', 'petrol', 'manual', 'rwd', 6.2, 455, 'Riverside Blue', 'Jet Black', 2, 4, 1, '13.1 L/100 km', 'full', 'used', 28500, 34750, 5, 0, 'camaro', $pick('tech', 'sport'), 22, 'Six-speed manual with magnetic ride control, head-up display and Brembo brakes.'],
                ['Fiat', '500', '1.0 Hybrid Dolcevita', 2022, 12300, 'hatchback', 'hybrid', 'manual', 'fwd', 1.0, 70, 'Celeste Blue', 'Ivory fabric', 3, 4, 1, '4.9 L/100 km', 'full', 'used', 11200, 14200, 0, 1, 'fiat500', ['Panoramic glass roof', 'Apple CarPlay / Android Auto', 'Parking sensors', 'Cruise control'], 2, 'Ideal first car or city runabout, still under manufacturer warranty.'],
                ['Ford', 'Expedition', 'Limited 3.5 EcoBoost', 2021, 27600, 'suv', 'petrol', 'automatic', '4wd', 3.5, 375, 'Oxford White', 'Sandstone leather', 5, 8, 1, '13.8 L/100 km', 'full', 'used', 44000, 52900, 0, 1, 'expedition', $pick('comfort', 'tech', 'safety'), 7, 'Eight seats, tow package and a power-folding third row. Perfect for big families.'],
                ['BMW', 'M4', 'Competition Coupe', 2020, 29800, 'coupe', 'petrol', 'automatic', 'rwd', 3.0, 503, 'Frozen Grey', 'Silverstone leather', 2, 4, 1, '10.2 L/100 km', 'full', 'used', 52000, 61500, 0, 0, 'm4', $pick('comfort', 'tech', 'sport'), 15, 'Competition package, carbon roof and M carbon bucket seats.'],
                ['BMW', '4 Series', '430i M Sport Coupe', 2019, 45100, 'coupe', 'petrol', 'automatic', 'rwd', 2.0, 252, 'Estoril Blue', 'Black Dakota leather', 2, 4, 2, '6.9 L/100 km', 'partial', 'used', 23000, 27900, 8, 0, 'bmw4', $pick('comfort', 'tech'), 34, 'M Sport package with adaptive LED headlights and Harman Kardon audio.'],
                ['Honda', 'CR-V', '1.5 Turbo EX AWD', 2022, 21300, 'suv', 'petrol', 'cvt', 'awd', 1.5, 190, 'Platinum White', 'Black leather', 5, 5, 1, '7.4 L/100 km', 'full', 'used', 25500, 29990, 0, 1, 'crv', $pick('comfort', 'tech', 'safety'), 5, 'Honda Sensing safety suite, heated seats and a power tailgate.'],
                ['Audi', 'RS6', 'Avant Performance', 2018, 58700, 'wagon', 'petrol', 'automatic', 'awd', 4.0, 597, 'Mythos Black', 'Black Valcona leather', 5, 5, 2, '11.7 L/100 km', 'full', 'used', 51000, 59900, 0, 0, 'rs6', $pick('comfort', 'tech', 'sport'), 40, 'The ultimate family estate: Dynamic package, ceramic brakes and Bang & Olufsen sound.'],
                ['Hyundai', 'Accent', '1.6 SE', 2025, 10, 'sedan', 'petrol', 'automatic', 'fwd', 1.6, 120, 'Phantom Black', 'Grey cloth', 4, 5, 0, '6.0 L/100 km', null, 'new', 16500, 19450, 0, 1, 'accent', $pick('tech', 'safety'), 1, 'Brand new with the full manufacturer warranty. Finance from a low deposit.'],
                ['BMW', 'M5', 'Competition', 2019, 39500, 'sedan', 'petrol', 'automatic', 'awd', 4.4, 617, 'Alpine White', 'Sakhir Orange leather', 4, 5, 1, '10.6 L/100 km', 'full', 'certified', 61000, 72000, 0, 0, 'm5', $pick('comfort', 'tech', 'sport', 'safety'), 18, 'BMW Approved Used with the M Driver\'s package and soft-close doors.'],
                ['Volkswagen', 'Polo', '1.0 TSI Life', 2021, 24600, 'hatchback', 'petrol', 'manual', 'fwd', 1.0, 95, 'Reef Blue', 'Black cloth', 5, 5, 1, '5.2 L/100 km', 'full', 'used', 12900, 15800, 0, 0, 'polo', ['Apple CarPlay / Android Auto', 'Parking sensors', 'Cruise control', 'Autonomous emergency braking'], 12, 'Low running costs and cheap insurance. Two keys and the spare wheel.'],
                ['Nissan', 'GT-R', 'Premium Edition', 2017, 31200, 'coupe', 'petrol', 'automatic', 'awd', 3.8, 565, 'Ultimate Silver', 'Black leather', 2, 4, 2, '11.8 L/100 km', 'full', 'used', 69000, 79500, 0, 0, 'gtr', $pick('comfort', 'tech', 'sport'), 28, 'Hand-built twin-turbo V6. Recently serviced, new tyres all round.'],
            ];

            $brands = [];
            foreach ($rows as $i => $r) {
                [$make, $model, $trim, $year, $km, $body, $fuel, $gear, $drive, $engine, $hp, $colour, $interior, $doors, $seats, $owners, $economy,
                    $service, $condition, $cost, $price, $disc, $featured, $img, $feats, $daysOld, $note] = $r;
                $brands[$make] ??= Brand::firstOrCreate(['name' => $make], ['description' => $make]);
                $code = 'CD-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                $data = [
                    'code' => $code,
                    'Type_barcode' => 'CODE128',
                    'name' => "$year $make $model $trim",
                    'cost' => $cost,
                    'price' => $price,
                    'unit_id' => $unit->id,
                    'unit_sale_id' => $unit->id,
                    'unit_purchase_id' => $unit->id,
                    'stock_alert' => 0,
                    'category_id' => $category->id,
                    'brand_id' => $brands[$make]->id,
                    'is_variant' => 0,
                    'tax_method' => '1',
                    'TaxNet' => 0,
                    'type' => 'is_single',
                    'is_active' => 1,
                    'image' => self::img($img),
                    'note' => $note,
                    'discount' => $disc > 0 ? $disc : null,
                    'discount_method' => '1',
                    'created_at' => Carbon::now()->subDays($daysOld)->subMinutes($i),
                    'updated_at' => Carbon::now(),
                ];
                foreach (['is_featured' => $featured, 'hide_from_online_store' => 0, 'not_selling' => 0] as $col => $val) {
                    if (Schema::hasColumn('products', $col)) {
                        $data[$col] = $val;
                    }
                }
                $product = new Product;
                $product->forceFill($data);
                $product->save();

                if (Schema::hasTable('product_images')) {
                    ProductImage::create(['product_id' => $product->id, 'image_path' => self::img($img), 'is_main' => true, 'sort_order' => 0]);
                }
                foreach (Warehouse::all() as $k => $wh) {
                    product_warehouse::create(['product_id' => $product->id, 'warehouse_id' => $wh->id, 'qte' => $wh->id === $warehouse->id ? 1 : 0, 'manage_stock' => 1]);
                }

                VehicleSpec::create(VehicleSpec::clean([
                    'condition' => $condition, 'make' => $make, 'model' => $model, 'trim' => $trim, 'year' => $year, 'mileage' => $km,
                    'body_type' => $body, 'fuel' => $fuel, 'transmission' => $gear, 'drivetrain' => $drive, 'engine_size' => $engine,
                    'power_hp' => $hp, 'exterior_color' => $colour, 'interior' => $interior, 'doors' => $doors, 'seats' => $seats,
                    'owners' => $owners, 'fuel_economy' => $economy, 'service_history' => $service, 'features' => $feats, 'reservable' => true,
                ]) + ['product_id' => $product->id]);
            }

            // Reservation deposit: a stock-less service product the Reserve button puts in the cart.
            $deposit = new Product;
            $deposit->forceFill(array_merge([
                'code' => 'CD-DEPOSIT', 'Type_barcode' => 'CODE128', 'name' => 'Reservation deposit', 'cost' => 0, 'price' => 500,
                'unit_id' => $unit->id, 'unit_sale_id' => $unit->id, 'unit_purchase_id' => $unit->id, 'stock_alert' => 0,
                'category_id' => $category->id, 'is_variant' => 0, 'tax_method' => '1', 'TaxNet' => 0, 'type' => 'is_service',
                'is_active' => 1, 'image' => 'no-image.png', 'note' => 'Refundable deposit that holds a car for you. Deducted from the price when you buy.',
                'discount_method' => '1', 'created_at' => Carbon::now()->subYear(), 'updated_at' => Carbon::now(),
            ], Schema::hasColumn('products', 'hide_from_online_store') ? ['hide_from_online_store' => 0] : []));
            $deposit->save();
        });

        $this->activateTheme();
        $this->command?->info('Car demo stock seeded and the Car Dealership theme activated.');
    }

    protected function activateTheme(): void
    {
        $s = StoreSetting::first();
        if (! $s) {
            return;
        }
        $opts = is_array($s->theme_options) ? $s->theme_options : [];
        $opts['cars'] = array_merge($opts['cars'] ?? [], [
            'announcement_text' => 'Part-exchange welcome · Finance available on every car',
            'slides' => [
                ['kicker' => 'New & approved used', 'title' => 'Find the car that fits your life.', 'subtitle' => 'Every car inspected and priced up front. Book a test drive online and drive away on finance or cash.', 'image' => self::img('mustang', 1600), 'primary_text' => 'Browse inventory', 'primary_url' => '/shop', 'secondary_text' => 'Used SUVs', 'secondary_url' => '/shop?condition=used&body[]=suv'],
                ['kicker' => 'Performance', 'title' => 'Approved performance cars', 'subtitle' => 'BMW M, Porsche and Audi Sport, each with a full service history.', 'image' => self::img('m4', 1600), 'primary_text' => 'Shop coupes', 'primary_url' => '/shop?body[]=coupe', 'secondary_text' => '', 'secondary_url' => ''],
            ],
            'opening_hours' => "Mon – Fri  9:00 – 19:00\nSaturday  9:00 – 17:00\nSunday  Closed",
            'enable_reserve' => true,
            'reserve_product_id' => (int) Product::where('code', 'CD-DEPOSIT')->value('id') ?: null,
            'reserve_days' => 7,
        ]);

        $s->forceFill([
            'theme' => 'cars',
            'theme_options' => $opts,
        ])->save();
    }
}
