<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 2: rental customers.
     *
     * A renter IS an ordinary Stocky client (clients table); `rental_customers`
     * only adds what renting needs on top — identity document, work, emergency
     * contact, guarantor and the blacklist flag. One live profile per client,
     * enforced in the app (soft deletes rule out a plain unique index).
     *
     * Files (ID scans, extra documents) go to public/images/rental_customers
     * under random names; the columns hold the file name only.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rental_customers')) {
            Schema::create('rental_customers', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->integer('client_id')->index();

                // Identity
                $table->enum('id_type', ['national_id', 'passport', 'driving_license', 'residence_permit', 'other'])->nullable();
                $table->string('id_number', 100)->nullable()->index();
                $table->date('id_expiry')->nullable()->index();
                $table->string('id_front', 191)->nullable();
                $table->string('id_back', 191)->nullable();
                $table->date('date_of_birth')->nullable();
                $table->string('nationality', 100)->nullable();

                // Work
                $table->string('occupation', 150)->nullable();
                $table->string('employer', 191)->nullable();
                $table->string('employer_phone', 50)->nullable();
                $table->decimal('monthly_income', 14, 2)->nullable();

                // Emergency contact
                $table->string('emergency_name', 191)->nullable();
                $table->string('emergency_phone', 50)->nullable();
                $table->string('emergency_relation', 100)->nullable();

                // Guarantor
                $table->string('guarantor_name', 191)->nullable();
                $table->string('guarantor_phone', 50)->nullable();
                $table->string('guarantor_id_number', 100)->nullable();
                $table->string('guarantor_relation', 100)->nullable();
                $table->string('guarantor_address', 255)->nullable();

                // Standing
                $table->boolean('is_blacklisted')->default(false)->index();
                $table->text('blacklist_reason')->nullable();
                $table->dateTime('blacklisted_at')->nullable();
                $table->integer('blacklisted_by')->nullable();
                $table->unsignedTinyInteger('rating')->nullable();

                $table->text('notes')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_customer_documents')) {
            Schema::create('rental_customer_documents', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_customer_id')->index();
                $table->string('title', 191);
                $table->string('file_name', 191);
                $table->string('original_name', 191)->nullable();
                $table->string('mime', 100)->nullable();
                $table->unsignedInteger('size')->default(0);
                $table->integer('uploaded_by')->nullable();
                $table->timestamps(6);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_customer_documents');
        Schema::dropIfExists('rental_customers');
    }
};
