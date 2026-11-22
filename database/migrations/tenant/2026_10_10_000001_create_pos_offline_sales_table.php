<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales > Offline Sales: one row per POS sale that was rung up offline and
 * later replayed by a till's sync (App\Services\Pos\OfflineSaleRecorder).
 * Keeps the full request payload so a manager can retry a rejected sale from
 * the admin, plus the outcome of the latest attempt. Keyed by the till's
 * sale_uuid (the same idempotency key the sales table carries).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_offline_sales')) {
            return;
        }

        Schema::create('pos_offline_sales', function (Blueprint $table) {
            $table->id();
            $table->char('sale_uuid', 36)->unique();
            $table->string('offline_id', 64)->nullable();
            // Cashier whose till sent it (the sale is recorded under them).
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->longText('payload');
            // synced | failed | discarded
            $table->string('status', 20)->index();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            // When it was rung up on the till (offline_created_at), and the
            // latest time a till or a manager sent it.
            $table->dateTime('offline_created_at')->nullable()->index();
            $table->dateTime('last_attempt_at')->nullable();
            // Manager who retried or discarded it from the admin.
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->text('note')->nullable();
            $table->string('device', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_offline_sales');
    }
};
