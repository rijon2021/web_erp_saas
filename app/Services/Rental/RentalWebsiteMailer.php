<?php

namespace App\Services\Rental;

use App\Models\RentalReservation;
use App\Models\Setting;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Plain-text emails around a website booking, all best-effort:
 *   received   → the customer (with their status link) and the owner
 *   confirmed  → the customer
 *   cancelled  → the customer (staff cancelled) or the owner (customer did)
 *
 * Sent after the response (app()->terminating) so a slow or unreachable
 * mail server never keeps anybody waiting, and only through the tenant's own
 * mail server (Settings › Mail) — never the platform's .env mailer.
 */
class RentalWebsiteMailer
{
    /** Queue the emails of an event to go out once the response is sent. */
    public static function later(string $event, RentalReservation $r): void
    {
        $id = $r->id;
        $tenant = function_exists('tenant') ? tenant() : null;
        $run = function () use ($event, $id) {
            $fresh = RentalReservation::with('items', 'client')->find($id);
            if ($fresh) {
                (new self())->send($event, $fresh);
            }
        };
        app()->terminating(fn () => $tenant ? $tenant->run($run) : $run());
    }

    public function send(string $event, RentalReservation $r): void
    {
        try {
            if (! $this->configure()) {
                return;
            }
            $contact = (array) (($r->request_fields ?? [])['contact'] ?? []);
            $customerEmail = ($contact['email'] ?? null) ?: optional($r->client)->email;
            $store = StoreSetting::query()->first();
            $storeName = ($store->store_name ?? null) ?: (optional(Setting::whereNull('deleted_at')->first())->CompanyName ?: config('app.name'));
            $owner = ($store->contact_email ?? null) ?: optional(Setting::whereNull('deleted_at')->first())->email;

            $items = $r->items->map(fn ($l) => $l->item_name.($l->quantity > 1 ? ' ×'.$l->quantity : ''))->implode(', ');
            $vars = [
                'name' => $contact['name'] ?? optional($r->client)->name ?? '',
                'number' => $r->reservation_number,
                'items' => $items,
                'start' => $r->start_date->format('Y-m-d H:i'),
                'end' => $r->end_date->format('Y-m-d H:i'),
                'total' => number_format((float) $r->total_amount, 2),
                'deposit' => number_format((float) $r->deposit_amount, 2),
                'store' => $storeName,
                'link' => $r->public_token ? store_base_url('rentals/booking/'.$r->public_token) : '',
            ];

            switch ($event) {
                case 'received':
                    if ($customerEmail) {
                        $this->mail($customerEmail, __('messages.RntMailReceivedSubject', $vars), __('messages.RntMailReceivedBody', $vars));
                    }
                    if ($owner) {
                        $lines = [
                            'New website booking request '.$r->reservation_number,
                            'Items: '.$items,
                            'From: '.$vars['start'].'  To: '.$vars['end'],
                            'Customer: '.$vars['name'].' — '.($contact['phone'] ?? '').' — '.($customerEmail ?: '—'),
                            'Estimated rent: '.$vars['total'].'  Deposit: '.$vars['deposit'],
                        ];
                        foreach ((array) (($r->request_fields ?? [])['answers'] ?? []) as $a) {
                            $value = is_bool($a['value'] ?? null) ? (($a['value']) ? 'Yes' : 'No') : (is_array($a['value'] ?? null) ? implode(', ', $a['value']) : (string) ($a['value'] ?? ''));
                            $lines[] = ($a['label'] ?? $a['id']).': '.$value;
                        }
                        if ($r->notes) {
                            $lines[] = 'Notes: '.$r->notes;
                        }
                        $lines[] = '';
                        $lines[] = 'Confirm, convert or cancel it in Rental › Reservations: '.url('/rental/reservations/'.$r->id);
                        $this->mail($owner, 'New booking request '.$r->reservation_number.' — '.$items, implode("\n", $lines));
                    }
                    break;
                case 'confirmed':
                    if ($customerEmail) {
                        $this->mail($customerEmail, __('messages.RntMailConfirmedSubject', $vars), __('messages.RntMailConfirmedBody', $vars));
                    }
                    break;
                case 'cancelled':
                    if ($customerEmail) {
                        $this->mail($customerEmail, __('messages.RntMailCancelledSubject', $vars), __('messages.RntMailCancelledBody', $vars));
                    }
                    break;
                case 'cancelled_by_customer':
                    if ($owner) {
                        $this->mail($owner, 'Booking '.$r->reservation_number.' cancelled by the customer', 'The customer cancelled website booking '.$r->reservation_number.' ('.$items.', '.$vars['start'].' → '.$vars['end'].').');
                    }
                    break;
            }
        } catch (\Throwable $e) {
            Log::warning('Rental website email ('.$event.') failed: '.$e->getMessage());
        }
    }

    private function mail(string $to, string $subject, string $body): void
    {
        Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
    }

    /** Same mail setup as BaseController::Set_config_mail; false when the tenant has no mail server. */
    private function configure(): bool
    {
        $server = DB::table('servers')->whereNull('deleted_at')->first();
        $settings = Setting::whereNull('deleted_at')->first();
        if (! $server || ! $settings) {
            return false;
        }
        Config::set('mail', [
            'driver' => $server->mail_mailer,
            'host' => $server->host,
            'port' => $server->port,
            'from' => ['address' => ($server->sender_email ?? null) ?: $settings->email, 'name' => $server->sender_name],
            'encryption' => $server->encryption,
            'username' => $server->username,
            'password' => $server->password,
            'timeout' => (int) (config('mail.mailers.smtp.timeout') ?: 15),
            'sendmail' => '/usr/sbin/sendmail -bs',
            'pretend' => false,
            'stream' => ['ssl' => ['allow_self_signed' => true, 'verify_peer' => false, 'verify_peer_name' => false]],
        ]);
        Mail::purge();

        return true;
    }
}
