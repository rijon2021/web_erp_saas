<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons, part 2:
 *  - addons.trial_days      free trial length (null / 0 = no trial), once per tenant
 *  - addons.requires        add-on keys that must be active first (e.g. a theme
 *                           that needs its module)
 *  - addons.pack_size       limit packs ("limit:max_users"): units added per pack
 *  - tenant_addons.quantity packs bought in one purchase (limit packs; 1 otherwise)
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasTable('addons')) {
            $schema->table('addons', function (Blueprint $table) use ($schema) {
                if (! $schema->hasColumn('addons', 'trial_days')) {
                    $table->unsignedSmallInteger('trial_days')->nullable();
                }
                if (! $schema->hasColumn('addons', 'requires')) {
                    $table->json('requires')->nullable();
                }
                if (! $schema->hasColumn('addons', 'pack_size')) {
                    $table->unsignedInteger('pack_size')->nullable();
                }
            });
        }

        if ($schema->hasTable('tenant_addons') && ! $schema->hasColumn('tenant_addons', 'quantity')) {
            $schema->table('tenant_addons', function (Blueprint $table) {
                $table->unsignedInteger('quantity')->default(1);
            });
        }

        // The app caches "columns ready?" — make it see them right away.
        if (class_exists(\App\Models\Central\Addon::class)) {
            \App\Models\Central\Addon::forgetCache();
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        foreach (['trial_days', 'requires', 'pack_size'] as $column) {
            if ($schema->hasColumn('addons', $column)) {
                $schema->table('addons', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if ($schema->hasColumn('tenant_addons', 'quantity')) {
            $schema->table('tenant_addons', fn (Blueprint $table) => $table->dropColumn('quantity'));
        }

        if (class_exists(\App\Models\Central\Addon::class)) {
            \App\Models\Central\Addon::forgetCache();
        }
    }
};
