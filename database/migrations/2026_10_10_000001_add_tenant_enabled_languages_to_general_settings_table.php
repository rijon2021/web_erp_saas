<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Languages that start enabled in newly provisioned tenants.
 * NULL means all of them (App\Support\TenantLanguages).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasTable('general_settings') && ! $schema->hasColumn('general_settings', 'tenant_enabled_languages')) {
            $schema->table('general_settings', function (Blueprint $table) {
                $table->json('tenant_enabled_languages')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasColumn('general_settings', 'tenant_enabled_languages')) {
            $schema->table('general_settings', function (Blueprint $table) {
                $table->dropColumn('tenant_enabled_languages');
            });
        }
    }
};
