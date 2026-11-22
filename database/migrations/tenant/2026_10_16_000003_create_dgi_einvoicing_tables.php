<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DR Congo DGI "facture normalisée" (Arrêté 033/2023). The seller identity
 * printed on every invoice lives here now; dgi_documents is ready for the
 * e-MCF certification data (DEF number, counters, authentication code, QR)
 * once the DGI developer API specification is available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dgi_settings')) {
            Schema::create('dgi_settings', function (Blueprint $table) {
                $table->id();
                $table->string('environment', 20)->default('test');   // test | production

                // Seller identity (mentions obligatoires)
                $table->string('nif', 30)->nullable();
                $table->string('rccm', 60)->nullable();
                $table->string('id_nat', 60)->nullable();
                $table->string('company_name', 200)->nullable();      // falls back to settings.CompanyName
                $table->string('address')->nullable();                // falls back to settings.CompanyAdress

                // e-MCF connection (filled once DGI issues developer access)
                $table->string('def_number', 60)->nullable();         // DEF / e-MCF identification number
                $table->string('api_url')->nullable();
                $table->longText('api_token')->nullable();            // encrypted
                $table->json('tax_group_map')->nullable();            // {"16":"B","0":"A"}

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dgi_documents')) {
            Schema::create('dgi_documents', function (Blueprint $table) {
                $table->id();
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->string('invoice_ref')->nullable();
                $table->string('environment', 20)->default('test');

                // pending | certified | rejected | error
                $table->string('status', 20)->default('pending');
                $table->string('def_number', 60)->nullable();
                $table->string('counters', 120)->nullable();
                $table->text('auth_code')->nullable();                // signature / code d'authentification
                $table->dateTime('certified_at')->nullable();
                $table->text('qr_payload')->nullable();
                $table->longText('errors')->nullable();
                $table->json('payload')->nullable();
                $table->json('response')->nullable();
                $table->timestamps();

                $table->unique(['source_type', 'source_id']);
                $table->index(['status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dgi_documents');
        Schema::dropIfExists('dgi_settings');
    }
};
