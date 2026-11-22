<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Angola AGT electronic invoicing (Decreto Presidencial 71/25, Decreto
 * Executivo 683/25 — API "Facturação Electrónica" v2.0).
 *
 *  agt_settings   taxpayer profile + the AGT-issued signing key (one row)
 *  agt_series     numbering series authorised by AGT (solicitarSerie)
 *  agt_documents  every fiscal document communicated (FT, FR, NC)
 *  agt_logs       raw API exchanges for troubleshooting
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agt_settings')) {
            Schema::create('agt_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('auto_submit')->default(true);
                $table->string('environment', 20)->default('test');        // test | production

                // Taxpayer (emitente)
                $table->string('tax_registration_number', 15)->nullable(); // NIF
                $table->string('company_name', 200)->nullable();           // falls back to settings.CompanyName
                $table->string('establishment_number', 200)->default('SEDE');
                $table->string('eac_code', 5)->nullable();                 // CAE (optional)

                // AGT-issued taxpayer key (signs jwsDocumentSignature + jwsSignature)
                $table->longText('private_key')->nullable();               // encrypted PEM

                // Document rules
                $table->string('paid_sale_type', 2)->default('FR');        // FR | FT for sales paid at creation
                $table->json('tax_code_map')->nullable();                  // {"14":"NOR","7":"INT","5":"RED"}
                $table->string('default_exemption_code', 3)->nullable();   // taxExemptionCode for 0% lines
                $table->string('default_unit', 20)->default('UN');

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agt_series')) {
            Schema::create('agt_series', function (Blueprint $table) {
                $table->id();
                $table->string('environment', 20)->default('test');
                $table->string('document_type', 2);                        // FT | FR | NC ...
                $table->string('series_code', 60);
                $table->unsignedSmallInteger('series_year');
                $table->string('establishment_number', 200)->default('SEDE');
                $table->string('contingency', 1)->default('N');            // N normal | C contingency
                $table->unsignedBigInteger('first_number')->default(1);
                $table->unsignedBigInteger('last_number')->default(999999999999);
                $table->unsignedBigInteger('next_number')->default(1);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['environment', 'series_code'], 'agt_series_env_code_unique');
                $table->index(['environment', 'document_type', 'series_year', 'active'], 'agt_series_pick_index');
            });
        }

        if (! Schema::hasTable('agt_documents')) {
            Schema::create('agt_documents', function (Blueprint $table) {
                $table->id();
                $table->string('source_type');                             // App\Models\Sale | App\Models\SaleReturn
                $table->unsignedBigInteger('source_id');
                $table->string('invoice_ref')->nullable();                 // Stocky Ref

                $table->string('environment', 20)->default('test');
                $table->string('document_type', 2);
                $table->unsignedBigInteger('agt_series_id')->nullable();
                $table->unsignedBigInteger('sequence')->nullable();
                $table->string('document_no', 60);                         // "FT FT6325S2C/10006"
                $table->date('document_date');
                $table->dateTime('system_entry_date');
                $table->string('rejected_document_no', 60)->nullable();    // set on corrections (documentStatus C)

                $table->decimal('net_total', 18, 2)->default(0);
                $table->decimal('tax_payable', 18, 2)->default(0);
                $table->decimal('gross_total', 18, 2)->default(0);

                $table->text('jws_document_signature')->nullable();
                $table->string('hash_extract', 8)->nullable();             // 4 chars printed on the document
                $table->text('qr_payload')->nullable();

                // pending | submitted | valid | invalid | error | superseded
                $table->string('status', 20)->default('pending');
                $table->string('request_id', 20)->nullable();
                $table->string('validation_status', 1)->nullable();        // V valid | P valid with penalty
                $table->longText('errors')->nullable();
                $table->json('payload')->nullable();
                $table->json('response')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('validated_at')->nullable();
                $table->timestamps();

                $table->unique(['environment', 'document_no'], 'agt_documents_env_no_unique');
                $table->index(['source_type', 'source_id'], 'agt_documents_source_index');
                $table->index(['status'], 'agt_documents_status_index');
            });
        }

        if (! Schema::hasTable('agt_logs')) {
            Schema::create('agt_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agt_document_id')->nullable()->index();
                $table->string('action', 50);                              // registarFactura, obterEstado, solicitarSerie ...
                $table->string('endpoint')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->json('request_meta')->nullable();                  // never credentials or keys
                $table->longText('response_body')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agt_logs');
        Schema::dropIfExists('agt_documents');
        Schema::dropIfExists('agt_series');
        Schema::dropIfExists('agt_settings');
    }
};
