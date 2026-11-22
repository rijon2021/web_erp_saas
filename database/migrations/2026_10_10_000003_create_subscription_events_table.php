<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed a tenant's subscription and how: free days granted, plan or
 * cycle changes, manually recorded payments, status changes from the
 * Subscriptions screens. Shown on the super-admin tenant page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('subscription_events')) {
            return;
        }

        Schema::connection('central')->create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('tenant_subscription_id')->nullable()->index();
            $table->unsignedBigInteger('central_user_id')->nullable();
            $table->string('action', 40);
            $table->json('details')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('subscription_events');
    }
};
