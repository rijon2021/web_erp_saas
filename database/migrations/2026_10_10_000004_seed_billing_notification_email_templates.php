<?php

declare(strict_types=1);

use App\Models\Central\EmailTemplate;
use Database\Seeders\Central\EmailTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Installs seeded before 2026-10-10 lack the welcome, bank-proof and
 * admin-alert templates. Insert each only when missing so an admin-edited
 * template is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('email_templates')) {
            return;
        }

        foreach ((new EmailTemplatesSeeder())->billingNotificationTemplateRows() as $row) {
            if (! EmailTemplate::where('trigger_key', $row['trigger_key'])->exists()) {
                EmailTemplate::create(array_merge($row, ['is_active' => true]));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::connection('central')->hasTable('email_templates')) {
            return;
        }

        EmailTemplate::whereIn('trigger_key', [
            EmailTemplate::TRIGGER_WORKSPACE_READY,
            EmailTemplate::TRIGGER_PAYMENT_PROOF_RECEIVED,
            EmailTemplate::TRIGGER_PAYMENT_PROOF_REJECTED,
            EmailTemplate::TRIGGER_ADMIN_PAYMENT_PROOF,
            EmailTemplate::TRIGGER_ADMIN_PROVISIONING_FAILED,
        ])->delete();
    }
};
