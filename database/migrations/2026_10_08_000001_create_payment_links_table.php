<?php

declare(strict_types=1);

use App\Models\Central\EmailTemplate;
use Database\Seeders\Central\EmailTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin > Payments > Payment Links: a custom-amount payment request for
 * one tenant, paid on the public /pay/{token} page. Each attempt is a normal
 * tenant_billing_payments row tagged with payment_link_id, so webhooks, the
 * return-leg verification and the stale-payment cron all settle it unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('payment_links')) {
            $schema->create('payment_links', function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->string('tenant_id');
                $table->string('title', 190);
                $table->text('description')->nullable();
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3);
                $table->string('status', 20)->default('active'); // active | paid | cancelled
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('tenant_billing_payment_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->json('send_log')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            });
        }

        if (! $schema->hasColumn('tenant_billing_payments', 'payment_link_id')) {
            $schema->table('tenant_billing_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_link_id')->nullable()->index()->after('plan_id');
            });
        }

        // Installs seeded before this feature have no template row. Insert it
        // only when missing so an admin-edited template is never overwritten.
        if ($schema->hasTable('email_templates')
            && ! EmailTemplate::where('trigger_key', EmailTemplate::TRIGGER_PAYMENT_LINK)->exists()) {
            EmailTemplate::create(array_merge(
                (new EmailTemplatesSeeder())->paymentLinkTemplateRow(),
                ['is_active' => true]
            ));
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasColumn('tenant_billing_payments', 'payment_link_id')) {
            $schema->table('tenant_billing_payments', function (Blueprint $table) {
                $table->dropIndex(['payment_link_id']);
                $table->dropColumn('payment_link_id');
            });
        }

        $schema->dropIfExists('payment_links');

        EmailTemplate::where('trigger_key', EmailTemplate::TRIGGER_PAYMENT_LINK)->delete();
    }
};
