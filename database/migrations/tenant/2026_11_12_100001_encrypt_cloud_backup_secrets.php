<?php

use App\Casts\EncryptedOrPlain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt cloud-backup credentials that were stored in plaintext (tenant
 * settings table; runs per tenant via tenants:migrate).
 * Idempotent: values that are already encrypted are left untouched.
 * Reads keep working either way (Setting casts via EncryptedOrPlain).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'backup_s3_secret_key',
        'backup_gdrive_access_token',
        'backup_gdrive_refresh_token',
        'backup_gdrive_client_secret',
        'backup_dropbox_access_token',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $columns = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('settings', $c)));
        if (! $columns) {
            return;
        }

        foreach (DB::table('settings')->select(array_merge(['id'], $columns))->get() as $row) {
            $changes = [];
            foreach ($columns as $column) {
                $value = $row->{$column};
                if ($value === null || $value === '' || EncryptedOrPlain::looksEncrypted((string) $value)) {
                    continue;
                }
                $changes[$column] = EncryptedOrPlain::encrypt($value);
            }

            if ($changes) {
                DB::table('settings')->where('id', $row->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $columns = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('settings', $c)));

        foreach (DB::table('settings')->select(array_merge(['id'], $columns))->get() as $row) {
            $changes = [];
            foreach ($columns as $column) {
                $value = $row->{$column};
                if ($value !== null && $value !== '' && EncryptedOrPlain::looksEncrypted((string) $value)) {
                    $changes[$column] = EncryptedOrPlain::decrypt($value);
                }
            }

            if ($changes) {
                DB::table('settings')->where('id', $row->id)->update($changes);
            }
        }
    }
};
