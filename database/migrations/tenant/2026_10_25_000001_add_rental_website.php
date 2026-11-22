<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental business type + the Rental store theme (website booking).
 *
 * rental_settings  business_types: which kinds of rentals this tenant runs
 *                  (vehicle, equipment, property, other; NULL = all four, the
 *                  behaviour before this migration). Website booking rules
 *                  and the per-field choices of the booking form.
 * rental_items     published on the website or not, featured, URL slug and
 *                  `specs`: the type's extra properties (transmission, seats,
 *                  power source…) defined in App\Support\RentalTypes.
 * rental_categories  photo, slug, order and website visibility.
 * rental_reservations  where the booking came from (staff | website), the
 *                  answers given on the website form, when staff confirmed
 *                  it, and the secret token of the customer's status page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_settings')) {
            Schema::table('rental_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('rental_settings', 'business_types')) {
                    $table->json('business_types')->nullable();
                }
                if (! Schema::hasColumn('rental_settings', 'website_booking')) {
                    $table->boolean('website_booking')->default(true);
                    // How long an unconfirmed website request keeps the items.
                    $table->unsignedSmallInteger('website_hold_hours')->default(24);
                    $table->unsignedSmallInteger('website_min_notice_hours')->default(2);
                    $table->unsignedSmallInteger('website_max_days_ahead')->default(180);
                    $table->boolean('website_require_login')->default(false);
                    $table->json('website_fields')->nullable();
                    $table->json('pickup_locations')->nullable();
                    $table->text('website_terms')->nullable();
                }
            });
        }

        if (Schema::hasTable('rental_items')) {
            Schema::table('rental_items', function (Blueprint $table) {
                if (! Schema::hasColumn('rental_items', 'show_on_website')) {
                    $table->boolean('show_on_website')->default(true)->index();
                    $table->boolean('featured')->default(false);
                    $table->string('slug', 191)->nullable()->index();
                    $table->json('specs')->nullable();
                }
            });
        }

        if (Schema::hasTable('rental_categories')) {
            Schema::table('rental_categories', function (Blueprint $table) {
                if (! Schema::hasColumn('rental_categories', 'show_on_website')) {
                    $table->boolean('show_on_website')->default(true);
                    $table->string('slug', 191)->nullable()->index();
                    $table->string('image', 191)->nullable();
                    $table->integer('sort_order')->default(0);
                }
            });
        }

        if (Schema::hasTable('rental_reservations')) {
            Schema::table('rental_reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('rental_reservations', 'source')) {
                    $table->string('source', 16)->default('staff')->index();
                    $table->json('request_fields')->nullable();
                    $table->dateTime('confirmed_at')->nullable();
                    $table->string('public_token', 48)->nullable()->unique();
                    $table->integer('ecommerce_client_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        $drop = [
            'rental_settings' => ['business_types', 'website_booking', 'website_hold_hours', 'website_min_notice_hours', 'website_max_days_ahead', 'website_require_login', 'website_fields', 'pickup_locations', 'website_terms'],
            'rental_items' => ['show_on_website', 'featured', 'slug', 'specs'],
            'rental_categories' => ['show_on_website', 'slug', 'image', 'sort_order'],
            'rental_reservations' => ['source', 'request_fields', 'confirmed_at', 'public_token', 'ecommerce_client_id'],
        ];
        $indexes = [
            'rental_items' => ['show_on_website', 'slug'],
            'rental_categories' => ['slug'],
            'rental_reservations' => ['source', 'ecommerce_client_id'],
        ];
        foreach ($drop as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($tableName, $c)));
            if (! $present) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $present, $indexes) {
                if ($tableName === 'rental_reservations' && in_array('public_token', $present, true)) {
                    $table->dropUnique(['public_token']);
                }
                foreach ($indexes[$tableName] ?? [] as $indexed) {
                    if (in_array($indexed, $present, true)) {
                        $table->dropIndex([$indexed]);
                    }
                }
                $table->dropColumn($present);
            });
        }
    }
};
