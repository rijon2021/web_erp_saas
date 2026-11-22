<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 8: customer reminders.
     *
     * rental_reminders logs every reminder attempt (sent / failed / skipped).
     * The log doubles as the de-duplication record: "rent due" goes out once
     * per invoice and channel, "overdue" at most every N days, "expiring" and
     * "return due" once per contract.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rental_reminders')) {
            Schema::create('rental_reminders', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->enum('type', ['rent_due', 'rent_overdue', 'contract_expiring', 'return_due'])->index();
                $table->enum('channel', ['email', 'sms', 'whatsapp'])->index();
                $table->unsignedBigInteger('rental_contract_id')->nullable()->index();
                $table->unsignedBigInteger('rental_invoice_id')->nullable()->index();
                $table->unsignedBigInteger('rental_customer_id')->nullable()->index();
                $table->string('recipient', 191)->nullable();
                $table->enum('status', ['sent', 'failed', 'skipped'])->default('sent')->index();
                $table->text('message')->nullable();
                $table->text('error')->nullable();
                $table->boolean('manual')->default(false);
                $table->integer('user_id')->nullable();
                $table->timestamps(6);
            });
        }

        Schema::table('rental_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_settings', 'reminders_enabled')) {
                $table->boolean('reminders_enabled')->default(false);
            }
            if (! Schema::hasColumn('rental_settings', 'reminder_channels')) {
                $table->json('reminder_channels')->nullable();          // ["email","sms","whatsapp"]
            }
            if (! Schema::hasColumn('rental_settings', 'overdue_reminder_every_days')) {
                $table->unsignedSmallInteger('overdue_reminder_every_days')->default(3);
            }
            if (! Schema::hasColumn('rental_settings', 'expiry_reminder_days')) {
                $table->unsignedSmallInteger('expiry_reminder_days')->default(14);
            }
            if (! Schema::hasColumn('rental_settings', 'reminder_templates')) {
                $table->json('reminder_templates')->nullable();         // {type: {subject, body}}
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_reminders');
        foreach (['reminders_enabled', 'reminder_channels', 'overdue_reminder_every_days', 'expiry_reminder_days', 'reminder_templates'] as $col) {
            if (Schema::hasColumn('rental_settings', $col)) {
                Schema::table('rental_settings', fn (Blueprint $table) => $table->dropColumn($col));
            }
        }
    }
};
