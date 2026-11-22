<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super-admin dashboard layout (Settings > General > Dashboard): widget
 * order, visibility, width and options plus page-wide display settings.
 * NULL means the defaults in App\Support\SuperDashboard\WidgetRegistry.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasTable('general_settings') && ! $schema->hasColumn('general_settings', 'super_dashboard_layout')) {
            $schema->table('general_settings', function (Blueprint $table) {
                $table->json('super_dashboard_layout')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasColumn('general_settings', 'super_dashboard_layout')) {
            $schema->table('general_settings', function (Blueprint $table) {
                $table->dropColumn('super_dashboard_layout');
            });
        }
    }
};
