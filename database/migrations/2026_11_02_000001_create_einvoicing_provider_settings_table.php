<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level switches and defaults for every e-invoicing provider
 * (Super Admin > Settings > E-Invoicing tabs). One row per provider:
 * a kill-switch the operator can flip during a gateway outage and a JSON
 * bag of defaults handed to new tenants (environment, CSR solution name…).
 * Taxpayer credentials stay in each tenant's own *_settings table; the
 * Angola AGT producer profile keeps its dedicated agt_producer_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('einvoicing_provider_settings')) {
            $schema->create('einvoicing_provider_settings', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 20)->unique();        // zatca | fbr | agt | dgi
                $table->boolean('enabled')->default(true);       // platform kill-switch
                $table->json('settings')->nullable();            // provider defaults
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('einvoicing_provider_settings');
    }
};
