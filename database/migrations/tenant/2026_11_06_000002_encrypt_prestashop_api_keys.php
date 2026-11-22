<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PrestaShop webservice keys were stored in plain text. PrestashopSetting
 * encrypts on write and still reads an old plain key, so nothing breaks before
 * this has run; this just moves the keys already saved. Safe to run twice: a
 * value that already decrypts is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prestashop_settings')) {
            return;
        }

        foreach (DB::table('prestashop_settings')->whereNotNull('api_key')->where('api_key', '!=', '')->get(['id', 'api_key']) as $row) {
            try {
                Crypt::decryptString($row->api_key);
            } catch (DecryptException $e) {
                DB::table('prestashop_settings')->where('id', $row->id)->update(['api_key' => Crypt::encryptString($row->api_key)]);
            }
        }
    }

    /** Nothing to undo: the model reads encrypted and plain keys alike. */
    public function down(): void
    {
    }
};
