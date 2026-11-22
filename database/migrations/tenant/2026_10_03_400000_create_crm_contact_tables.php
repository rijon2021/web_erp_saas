<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM module — phase 4: contact people of a customer company, and the
     * quotation a deal produced (accepting = converting it to a sale wins the deal).
     */
    public function up(): void
    {
        if (! Schema::hasTable('crm_contacts')) {
            Schema::create('crm_contacts', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('client_id')->index();
                $table->string('name', 191);
                $table->string('job_title', 120)->nullable();
                $table->string('email', 191)->nullable();
                $table->string('phone', 50)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->string('notes', 1000)->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::table('crm_deals', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_deals', 'quotation_id')) {
                $table->integer('quotation_id')->nullable()->index()->after('client_id');
            }
            if (! Schema::hasColumn('crm_deals', 'crm_contact_id')) {
                $table->integer('crm_contact_id')->nullable()->after('client_id');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
        Schema::table('crm_deals', function (Blueprint $table) {
            foreach (['quotation_id', 'crm_contact_id'] as $col) {
                if (Schema::hasColumn('crm_deals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
