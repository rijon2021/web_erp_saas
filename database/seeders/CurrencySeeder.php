<?php

namespace Database\Seeders;

use App\Models\Central\GeneralSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        [$code, $symbol] = $this->tenantCurrency();

        DB::table('currencies')->insertOrIgnore(
            [
                'id'     => 1,
                'code'   => $code,
                'name'   => $this->currencyName($code),
                'symbol' => $symbol,
            ]
        );
    }

    /**
     * Resolve the currency code + symbol seeded into new tenants. Pulls
     * from the super admin Tenant Settings tab; falls back to USD/$.
     */
    protected function tenantCurrency(): array
    {
        // A workspace that signed up in another currency (multi-currency
        // pricing) starts its shop in that currency.
        try {
            $billing = function_exists('tenant') && tenant() ? strtoupper((string) (tenant('billing_currency') ?? '')) : '';
            if (preg_match('/^[A-Z]{3}$/', $billing) && $billing !== strtoupper((string) GeneralSetting::currencyCode())) {
                return [$billing, \App\Support\SellingCurrencies::symbol($billing)];
            }
        } catch (\Throwable) {
            // fall back to the Tenant Settings default
        }

        try {
            $defaults = GeneralSetting::instance()->getTenantDefaults();
            return [$defaults['currency_code'] ?: 'USD', $defaults['currency_symbol'] ?: '$'];
        } catch (\Throwable) {
            return ['USD', '$'];
        }
    }

    /**
     * Best-effort full name lookup for common ISO 4217 codes.
     * Unknown codes fall back to the code itself.
     */
    protected function currencyName(string $code): string
    {
        $map = [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'NGN' => 'Nigerian Naira',
            'GHS' => 'Ghanaian Cedi',
            'ZAR' => 'South African Rand',
            'KES' => 'Kenyan Shilling',
            'XOF' => 'West African CFA Franc',
            'XAF' => 'Central African CFA Franc',
            'UGX' => 'Ugandan Shilling',
            'TZS' => 'Tanzanian Shilling',
            'RWF' => 'Rwandan Franc',
            'ZMW' => 'Zambian Kwacha',
            'ETB' => 'Ethiopian Birr',
            'INR' => 'Indian Rupee',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'JPY' => 'Japanese Yen',
            'CNY' => 'Chinese Yuan',
            'BRL' => 'Brazilian Real',
            'MAD' => 'Moroccan Dirham',
            'AED' => 'UAE Dirham',
            'SAR' => 'Saudi Riyal',
            'EGP' => 'Egyptian Pound',
            'TRY' => 'Turkish Lira',
            'PKR' => 'Pakistani Rupee',
            'BDT' => 'Bangladeshi Taka',
            'MXN' => 'Mexican Peso',
            'CHF' => 'Swiss Franc',
            'SEK' => 'Swedish Krona',
            'NOK' => 'Norwegian Krone',
            'DKK' => 'Danish Krone',
            'PLN' => 'Polish Złoty',
            'RUB' => 'Russian Ruble',
            'SGD' => 'Singapore Dollar',
            'MYR' => 'Malaysian Ringgit',
            'IDR' => 'Indonesian Rupiah',
            'PHP' => 'Philippine Peso',
            'THB' => 'Thai Baht',
            'VND' => 'Vietnamese Đồng',
        ];

        return $map[strtoupper($code)] ?? $code;
    }
}
