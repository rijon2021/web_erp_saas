<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Angola AGT e-invoicing — software-producer credentials (Super Admin >
 * Settings > E-Invoicing). The platform operator is the software producer
 * for every tenant: one Basic-auth pair per AGT environment, one software
 * signing key (jwsSoftwareSignature) and the AGT validation number.
 * Each tenant keeps its own AGT-issued taxpayer key in its agt_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('agt_producer_settings')) {
            $schema->create('agt_producer_settings', function (Blueprint $table) {
                $table->id();
                $table->string('product_id', 120)->nullable();                 // softwareInfoDetail.productId
                $table->string('product_version', 40)->nullable();
                $table->string('software_validation_number', 60)->nullable();
                $table->string('producer_tax_id', 20)->nullable();             // NIF of the producer (SAF-T ProductCompanyTaxID)
                $table->unsignedInteger('signature_version')->nullable();      // optional, sent when set
                $table->longText('software_private_key')->nullable();          // encrypted PEM

                $table->string('test_username', 120)->nullable();
                $table->longText('test_password')->nullable();                 // encrypted
                $table->string('production_username', 120)->nullable();
                $table->longText('production_password')->nullable();           // encrypted

                $table->string('qr_template', 500)->nullable();
                $table->string('certification_text', 200)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('agt_producer_settings');
    }
};
