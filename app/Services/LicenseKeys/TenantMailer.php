<?php

namespace App\Services\LicenseKeys;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * License emails go through the tenant's own mail server (Settings › Mail),
 * never the platform mailer — same setup as BaseController::Set_config_mail
 * and BookingNotifier::configureMail.
 */
class TenantMailer
{
    /** Configure the tenant mailer; false when the tenant has no mail server. */
    public static function configure(): bool
    {
        $server = DB::table('servers')->whereNull('deleted_at')->first();
        $settings = Setting::whereNull('deleted_at')->first();
        if (! $server || ! $settings || empty($server->host)) {
            return false;
        }

        $storeName = null;
        try {
            $storeName = DB::table('store_settings')->value('store_name');
        } catch (\Throwable $e) {
            // no store tables
        }

        Config::set('mail', [
            'driver' => $server->mail_mailer,
            'host' => $server->host,
            'port' => $server->port,
            'from' => [
                'address' => ($server->sender_email ?? null) ?: $settings->email,
                'name' => $server->sender_name ?: ($storeName ?: ($settings->CompanyName ?? config('app.name'))),
            ],
            'encryption' => $server->encryption,
            'username' => $server->username,
            'password' => $server->password,
            'timeout' => (int) (config('mail.mailers.smtp.timeout') ?: 15),
            'sendmail' => '/usr/sbin/sendmail -bs',
            'pretend' => false,
            'stream' => ['ssl' => ['allow_self_signed' => true, 'verify_peer' => false, 'verify_peer_name' => false]],
        ]);
        $mailer = Mail::getFacadeRoot();
        if ($mailer && method_exists($mailer, 'purge')) {
            Mail::purge();
        }

        return true;
    }

    /** Plain text safe inside a markdown mail: HTML-escaped and markdown characters neutralised. */
    public static function md(?string $text): string
    {
        $s = e((string) $text);

        return strtr($s, [
            '\\' => '&#92;', '*' => '&#42;', '_' => '&#95;', '`' => '&#96;', '[' => '&#91;', ']' => '&#93;',
            '#' => '&#35;', '~' => '&#126;', '|' => '&#124;', '!' => '&#33;', '(' => '&#40;', ')' => '&#41;',
        ]);
    }
}
