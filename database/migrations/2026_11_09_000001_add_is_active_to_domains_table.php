<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On/off switch for a tenant address (central DB). A switched-off subdomain
 * stays reserved for its tenant but sends browser visitors to the tenant's
 * verified custom domain (see RedirectInactiveDomain). Existing rows stay on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('domains') || $schema->hasColumn('domains', 'is_active')) {
            return;
        }

        $schema->table('domains', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('tenant_id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasTable('domains') && $schema->hasColumn('domains', 'is_active')) {
            $schema->table('domains', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
