<?php

namespace App\Console\Commands;

use App\Models\Central\GeneralSetting;
use App\Models\Central\Plan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds (or removes) a realistic set of demo rows in the CENTRAL database so
 * the super-admin dashboard, lists and search can be reviewed with data:
 * tenants with domains, subscriptions (trial / active / expired / cancelled),
 * a year of billing payments, support tickets and payment links.
 *
 * Rows are inserted with the query builder, so no tenant database is created
 * or migrated; every demo row is tagged (tenant ids start with "demo-",
 * invoices and tickets with "DEMO-") and `--remove` deletes exactly those.
 *
 *   php artisan super:demo-data            # add (skips if demo rows exist)
 *   php artisan super:demo-data --fresh    # remove, then add again
 *   php artisan super:demo-data --remove   # delete every demo row
 */
class SuperDemoData extends Command
{
    protected $signature = 'super:demo-data {--remove : Delete the demo rows} {--fresh : Remove existing demo rows first}';

    protected $description = 'Seed or remove demo tenants, subscriptions, payments, tickets and payment links for the super-admin area';

    private const COMPANIES = [
        'Nordic Mart', 'Kifani Pharma', 'Atlas Tools', 'Bloom Florals', 'Casa Rentals', 'Green Harvest', 'Orbit Motors',
        'Lagos Bites', 'Sunset Spa', 'Pixel Bakery', 'Mesa Wholesale', 'Harbor Fashion', 'Delta Rentals', 'Vista Autos',
        'Maple Books', 'Cedar Hardware', 'Lumen Optics', 'Prairie Dairy', 'Summit Sports', 'Coral Cosmetics', 'Juniper Pets',
        'Tidal Seafood', 'Quartz Jewelry', 'Ember Grill', 'Saffron Kitchen', 'Willow Kids', 'Granite Fitness', 'Aurora Electronics',
        'Olive Deli', 'Basil Garden', 'Copper Coffee', 'Marina Yachts', 'Pine Furniture', 'Sol Pharmacy', 'Zen Studio',
        'Nova Mobile', 'Terra Nursery', 'Indigo Textiles', 'Falcon Logistics', 'Riverside Dental', 'Oak Stationery', 'Velvet Decor',
        'Comet Toys', 'Breeze Travel', 'Crescent Bakery', 'Amber Lighting', 'Meadow Grocers', 'Rustic Tiles',
    ];

    private const FIRST_NAMES = ['Amina', 'Youssef', 'Sara', 'Omar', 'Lina', 'Karim', 'Nour', 'Hamza', 'Maya', 'Adam', 'Zara', 'Idris', 'Leila', 'Rami', 'Dina', 'Tariq'];

    private const LAST_NAMES = ['Haddad', 'Mansour', 'Okafor', 'Novak', 'Berger', 'Silva', 'Khan', 'Petrov', 'Rossi', 'Ahmed', 'Diallo', 'Moreau'];

    /** Gateway mix, weighted. */
    private const GATEWAYS = ['stripe', 'stripe', 'stripe', 'stripe', 'stripe', 'flutterwave', 'flutterwave', 'paystack', 'offline', 'paypal'];

    private string $currency = 'USD';

    private string $host = 'stocky.test';

    private int $invoiceSeq = 1;

    public function handle(): int
    {
        if ($this->option('remove')) {
            $this->remove();

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $this->remove();
        } elseif ($this->db()->table('tenants')->where('id', 'like', 'demo-%')->exists()) {
            $this->warn('Demo rows already exist. Use --fresh to recreate them or --remove to delete them.');

            return self::SUCCESS;
        }

        $plans = Plan::orderBy('id')->get()->keyBy('id');
        if ($plans->count() < 2) {
            $this->error('At least two plans are needed (a free one and a paid one). Create plans first.');

            return self::FAILURE;
        }

        $this->currency = (string) (GeneralSetting::instance()->currency_code ?: 'USD');
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $this->host = $appHost && ! in_array($appHost, ['localhost', '127.0.0.1'], true) ? preg_replace('/^www\./', '', $appHost) : 'stocky.test';

        mt_srand(20261101); // deterministic: the same demo every time

        $this->db()->transaction(fn () => $this->seed($plans));

        $this->info('Demo data added: ' . $this->db()->table('tenants')->where('id', 'like', 'demo-%')->count() . ' tenants, '
            . $this->db()->table('tenant_billing_payments')->where('invoice_number', 'like', 'DEMO-%')->count() . ' payments, '
            . $this->db()->table('support_tickets')->where('ticket_number', 'like', 'DEMO-%')->count() . ' tickets, '
            . $this->db()->table('payment_links')->where('tenant_id', 'like', 'demo-%')->count() . ' payment links.');
        $this->line('Remove them later with: php artisan super:demo-data --remove');

        return self::SUCCESS;
    }

    private function db()
    {
        return DB::connection('central');
    }

    private function remove(): void
    {
        $db = $this->db();
        $db->transaction(function () use ($db) {
            $db->table('tenant_billing_payments')->where('invoice_number', 'like', 'DEMO-%')->orWhere('tenant_id', 'like', 'demo-%')->delete();
            $db->table('payment_links')->where('tenant_id', 'like', 'demo-%')->delete();
            $db->table('support_tickets')->where('ticket_number', 'like', 'DEMO-%')->orWhere('tenant_id', 'like', 'demo-%')->delete();
            if ($db->getSchemaBuilder()->hasTable('subscription_events')) {
                $db->table('subscription_events')->where('tenant_id', 'like', 'demo-%')->delete();
            }
            $db->table('tenant_subscriptions')->where('tenant_id', 'like', 'demo-%')->delete();
            $db->table('domains')->where('tenant_id', 'like', 'demo-%')->delete();
            $db->table('tenants')->where('id', 'like', 'demo-%')->delete();
        });
        $this->info('Demo rows removed.');
    }

    private function seed($plans): void
    {
        $now = now();
        $paid = $plans->filter(fn (Plan $p) => (float) $p->price > 0)->values();
        $free = $plans->first(fn (Plan $p) => (float) $p->price <= 0);
        $db = $this->db();

        $tenants = [];
        foreach (self::COMPANIES as $i => $company) {
            // Sign-ups spread over the last 12 months, denser recently.
            $ageDays = (int) round(365 * pow(mt_rand(0, 1000) / 1000, 1.6));
            $created = $now->copy()->subDays($ageDays)->subMinutes(mt_rand(0, 1440));
            $slug = Str::slug($company);
            $id = 'demo-' . $slug;

            // Lifecycle mix.
            $roll = mt_rand(1, 100);
            if ($ageDays <= 20 && $roll <= 45) {
                $kind = 'trial';
            } elseif ($ageDays <= 3 && $roll > 45 && $roll <= 75) {
                $kind = 'pending';
            } elseif ($roll <= 6) {
                $kind = 'cancelled';
            } elseif ($roll <= 12) {
                $kind = 'expired';
            } elseif ($roll <= 18) {
                $kind = 'suspended';
            } elseif ($roll <= 30) {
                $kind = 'free';
            } else {
                $kind = 'paid';
            }

            $status = match ($kind) {
                'pending'   => 'pending',
                'suspended' => 'suspended',
                'cancelled' => 'cancelled',
                default     => 'active',
            };
            $lastActivity = match ($kind) {
                'pending'   => null,
                'cancelled', 'expired' => $created->copy()->addDays(mt_rand(10, 60)),
                default     => mt_rand(1, 100) <= 12 ? $now->copy()->subDays(mt_rand(31, 90)) : $now->copy()->subHours(mt_rand(1, 240)),
            };
            $owner = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $email = 'owner@' . $slug . '.com';

            $db->table('tenants')->insert([
                'id'               => $id,
                'status'           => $status,
                'last_activity_at' => $lastActivity,
                'created_at'       => $created,
                'updated_at'       => $created,
                'data'             => json_encode([
                    'company_name' => $company,
                    'admin_name'   => $owner,
                    'admin_email'  => $email,
                    'demo'         => true,
                ]),
            ]);
            $db->table('domains')->insert([
                'domain' => $slug . '.' . $this->host, 'tenant_id' => $id, 'created_at' => $created, 'updated_at' => $created,
            ]);

            $tenants[] = compact('id', 'company', 'owner', 'email', 'created', 'kind');

            if ($kind === 'pending') {
                continue;
            }

            $plan = $kind === 'free' ? $free : $paid[mt_rand(0, $paid->count() - 1)];
            $cycle = mt_rand(1, 100) <= 70 ? 'monthly' : 'yearly';
            $amount = $plan ? (float) ($cycle === 'yearly' ? $plan->yearly_price : $plan->price) : 0.0;
            if ($kind === 'free' || ! $plan) {
                $amount = 0.0;
                $cycle = 'monthly';
            }

            $sub = [
                'tenant_id'     => $id,
                'plan_id'       => $plan?->id,
                'billing_cycle' => $cycle,
                'amount'        => $amount,
                'currency'      => $this->currency,
                'status'        => 'active',
                'starts_at'     => $created,
                'trial_ends_at' => null,
                'ends_at'       => null,
                'cancelled_at'  => null,
                'created_at'    => $created,
                'updated_at'    => $created,
            ];

            switch ($kind) {
                case 'trial':
                    $sub['status'] = 'trial';
                    $sub['amount'] = 0.0;
                    $sub['trial_ends_at'] = $created->copy()->addDays(14 + mt_rand(0, 7));
                    $sub['ends_at'] = $sub['trial_ends_at'];
                    break;
                case 'cancelled':
                    $sub['status'] = 'cancelled';
                    $sub['cancelled_at'] = $now->copy()->subDays(mt_rand(3, 45));
                    $sub['ends_at'] = $sub['cancelled_at'];
                    break;
                case 'expired':
                    $sub['status'] = 'expired';
                    $sub['ends_at'] = $now->copy()->subDays(mt_rand(2, 40));
                    break;
                case 'suspended':
                    $sub['status'] = 'suspended';
                    $sub['ends_at'] = $now->copy()->subDays(mt_rand(1, 30));
                    break;
                default:
                    // Running period: a handful end within the next two weeks so the
                    // "ending soon" widgets have rows; the rest renew later.
                    $soon = mt_rand(1, 100) <= 18;
                    $sub['ends_at'] = $cycle === 'yearly'
                        ? ($soon ? $now->copy()->addDays(mt_rand(1, 14)) : $created->copy()->addYear())
                        : ($soon ? $now->copy()->addDays(mt_rand(1, 14)) : $now->copy()->addDays(mt_rand(15, 30)));
                    if ($sub['ends_at']->lt($now)) {
                        $sub['ends_at'] = $now->copy()->addDays(mt_rand(15, 300));
                    }
            }
            $subId = $db->table('tenant_subscriptions')->insertGetId($sub);

            // Billing history for paid plans: one paid charge per cycle since sign-up.
            if ($amount > 0 && in_array($kind, ['paid', 'cancelled', 'expired', 'suspended'], true)) {
                $until = match ($kind) {
                    'cancelled' => $sub['cancelled_at'],
                    'expired', 'suspended' => $sub['ends_at'],
                    default => $now,
                };
                $cursor = $created->copy();
                $guard = 0;
                while ($cursor->lte($until) && $guard++ < 40) {
                    $this->payment($id, $subId, $plan->id, $amount, $cycle, 'paid', $cursor->copy()->addMinutes(mt_rand(1, 120)));
                    $cursor = $cycle === 'yearly' ? $cursor->addYear() : $cursor->addMonthNoOverflow();
                }
            }
        }

        $paidTenants = array_values(array_filter($tenants, fn ($t) => $t['kind'] === 'paid'));
        $activeTenants = array_values(array_filter($tenants, fn ($t) => in_array($t['kind'], ['paid', 'free', 'trial'], true)));
        $pick = fn (array $pool) => $pool[mt_rand(0, count($pool) - 1)];
        $paidPlan = $paid->first();

        // Failed card charges this week, bank transfers waiting for review, a couple of refunds.
        for ($i = 0; $i < 4; $i++) {
            $t = $pick($paidTenants);
            $this->payment($t['id'], null, $paidPlan->id, (float) $paidPlan->price, 'monthly', 'failed', $now->copy()->subHours(mt_rand(2, 150)), 'stripe');
        }
        for ($i = 0; $i < 3; $i++) {
            $t = $pick($paidTenants);
            $this->payment($t['id'], null, $paidPlan->id, (float) $paidPlan->yearly_price, 'yearly', 'pending', $now->copy()->subDays(mt_rand(0, 4)), 'offline');
        }
        for ($i = 0; $i < 2; $i++) {
            $t = $pick($paidTenants);
            $this->payment($t['id'], null, $paidPlan->id, (float) $paidPlan->price, 'monthly', 'refunded', $now->copy()->subDays(mt_rand(2, 20)), 'stripe');
        }

        // Support tickets.
        $tickets = [
            ['Cannot print receipts from the POS', 'technical', 'high', 'open', true],
            ['Invoice shows the wrong tax rate', 'billing', 'medium', 'open', true],
            ['Request: export sales to Excel', 'feature_request', 'low', 'open', false],
            ['Barcode scanner not detected', 'bug', 'urgent', 'open', false],
            ['How do I add a second warehouse?', 'account', 'low', 'pending', false],
            ['Payment failed but card was charged', 'billing', 'high', 'pending', true],
            ['Stock levels do not sync with WooCommerce', 'bug', 'medium', 'resolved', false],
            ['Change company email on invoices', 'account', 'low', 'resolved', false],
            ['Login page shows a blank screen', 'technical', 'urgent', 'closed', false],
        ];
        foreach ($tickets as $n => [$subject, $category, $priority, $status, $unread]) {
            $t = $pick($activeTenants);
            $opened = $now->copy()->subDays(mt_rand(0, 25))->subHours(mt_rand(1, 20));
            $db->table('support_tickets')->insert([
                'ticket_number'     => 'DEMO-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                'tenant_id'         => $t['id'],
                'opened_by_user_id' => null,
                'opened_by_name'    => $t['owner'],
                'opened_by_email'   => $t['email'],
                'subject'           => $subject,
                'category'          => $category,
                'priority'          => $priority,
                'status'            => $status,
                'assigned_to'       => null,
                'last_reply_at'     => $opened->copy()->addHours(mt_rand(1, 48)),
                'last_reply_by'     => $unread ? 'tenant' : 'admin',
                'tenant_unread'     => ! $unread,
                'admin_unread'      => $unread,
                'resolved_at'       => in_array($status, ['resolved', 'closed'], true) ? $opened->copy()->addDays(2) : null,
                'closed_at'         => $status === 'closed' ? $opened->copy()->addDays(3) : null,
                'created_at'        => $opened,
                'updated_at'        => $opened,
            ]);
        }

        // Payment links: a few unpaid, two settled.
        $links = [
            ['Onboarding & data import', 250, 'active'], ['Custom report pack', 120, 'active'], ['Extra 5 user seats (one-off)', 75, 'active'],
            ['Hardware setup visit', 400, 'active'], ['Annual plan upgrade difference', 180, 'active'], ['Training session', 150, 'paid'], ['Barcode printer', 320, 'paid'],
        ];
        foreach ($links as [$title, $amount, $status]) {
            $t = $pick($activeTenants);
            $createdAt = $now->copy()->subDays(mt_rand(1, 20));
            $db->table('payment_links')->insert([
                'token'                     => Str::random(40),
                'tenant_id'                 => $t['id'],
                'title'                     => $title,
                'description'               => null,
                'amount'                    => $amount,
                'currency'                  => $this->currency,
                'status'                    => $status,
                'expires_at'                => $now->copy()->addDays(mt_rand(5, 40)),
                'paid_at'                   => $status === 'paid' ? $createdAt->copy()->addDays(1) : null,
                'tenant_billing_payment_id' => null,
                'created_by'                => null,
                'last_sent_at'              => $createdAt,
                'send_log'                  => null,
                'created_at'                => $createdAt,
                'updated_at'                => $createdAt,
            ]);
        }
    }

    private function payment(string $tenantId, ?int $subscriptionId, int $planId, float $amount, string $cycle, string $status, Carbon $at, ?string $gateway = null): void
    {
        $gateway ??= self::GATEWAYS[mt_rand(0, count(self::GATEWAYS) - 1)];
        $this->db()->table('tenant_billing_payments')->insert([
            'tenant_id'              => $tenantId,
            'tenant_subscription_id' => $subscriptionId,
            'plan_id'                => $planId,
            'payment_link_id'        => null,
            'amount'                 => $amount,
            'tax'                    => 0,
            'currency'               => $this->currency,
            'gateway_currency'       => $this->currency,
            'gateway_amount'         => $amount,
            'exchange_rate'          => 1,
            'conversion_applied'     => false,
            'status'                 => $status,
            'billing_cycle'          => $cycle,
            'invoice_number'         => 'DEMO-INV-' . str_pad((string) $this->invoiceSeq++, 5, '0', STR_PAD_LEFT),
            'gateway'                => $gateway,
            'gateway_payment_id'     => $gateway === 'offline' ? null : strtoupper(substr($gateway, 0, 2)) . '_' . Str::upper(Str::random(14)),
            'transaction_id'         => $gateway === 'offline' ? null : 'txn_' . Str::lower(Str::random(16)),
            'payment_proof_path'     => null,
            'paid_at'                => $status === 'paid' || $status === 'refunded' ? $at : null,
            'metadata'               => json_encode(['demo' => true]),
            'notes'                  => $status === 'pending' ? 'Bank transfer reference sent by the tenant' : null,
            'created_at'             => $at,
            'updated_at'             => $at,
        ]);
    }
}
