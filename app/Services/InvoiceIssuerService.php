<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Warehouse;

/**
 * Resolves the "issuer" block printed at the top of a document (PDF invoices,
 * POS receipts, the on-screen invoice previews).
 *
 * A document belongs to a warehouse, and that warehouse is the branch that
 * actually issued it, so the header shows the warehouse's own name, address,
 * phone, email and logo instead of the head-office company settings. Every
 * field falls back to the global `settings` row on its own: a warehouse that
 * has no address yet still prints the company address, so nothing on an
 * existing install goes blank after this change.
 *
 * Documents with no warehouse at all (reports, ledgers, contracts, bookings)
 * pass null and get the pure global block.
 *
 * The whole module is opt-in behind `settings.enable_warehouse_letterhead`
 * (System Settings -> Features). While it is off every caller gets the plain
 * company block, so an install that never opts in prints exactly what it did
 * before the module existed.
 *
 * Nothing here is cached on purpose - queue workers keep static state alive
 * between jobs, and a stale letterhead on a mailed invoice is worse than the
 * one extra query a render costs.
 */
class InvoiceIssuerService
{
    /**
     * Build the issuer block for a document.
     *
     * @param  Warehouse|int|null  $warehouse  Warehouse model or id owning the document.
     * @param  Setting|null  $setting  Already-loaded global settings, when the caller has one.
     */
    public static function resolve($warehouse = null, $setting = null): array
    {
        $setting = $setting ?: Setting::where('deleted_at', '=', null)->first();

        // Feature off: every document keeps the plain company letterhead.
        if (! static::enabled($setting)) {
            return static::build(null, $setting);
        }

        if (! $warehouse instanceof Warehouse && $warehouse) {
            $warehouse = Warehouse::where('id', $warehouse)
                ->where('deleted_at', '=', null)->first();
        }

        return static::build($warehouse instanceof Warehouse ? $warehouse : null, $setting);
    }

    /**
     * The pure global company block, for documents that have no warehouse.
     */
    public static function company($setting = null): array
    {
        return static::resolve(null, $setting);
    }

    protected static function build(?Warehouse $warehouse, $setting): array
    {
        $company = [
            'name' => static::text($setting->CompanyName ?? null),
            'address' => static::text($setting->CompanyAdress ?? null),
            'phone' => static::text($setting->CompanyPhone ?? null),
            'email' => static::text($setting->email ?? null),
            'logo' => static::text($setting->logo ?? null),
        ];

        if (! $warehouse) {
            return $company + [
                'city' => '',
                'zip' => '',
                'country' => '',
                'full_address' => $company['address'],
                'footer' => '',
                'warehouse_name' => '',
                'is_branch' => false,
            ];
        }

        $issuer = [
            // The warehouse name replaces the company name on branch documents.
            'name' => static::text($warehouse->name) ?: $company['name'],
            // `address` is the free-text street line; city/zip/country stay
            // separate so templates can print them on their own line.
            'address' => static::text($warehouse->address) ?: $company['address'],
            'phone' => static::text($warehouse->mobile) ?: $company['phone'],
            'email' => static::text($warehouse->email) ?: $company['email'],
            'logo' => static::text($warehouse->logo) ?: $company['logo'],
            'city' => static::text($warehouse->city),
            'zip' => static::text($warehouse->zip),
            'country' => static::text($warehouse->country),
            'footer' => static::text($warehouse->invoice_footer),
            'warehouse_name' => static::text($warehouse->name),
            'is_branch' => true,
        ];

        // The single address line templates print: street plus the branch
        // locality, so the reader sees which city the document came from.
        $locality = static::localityLine($issuer);
        $issuer['full_address'] = trim(implode(', ', array_filter([$issuer['address'], $locality])));

        return $issuer;
    }

    /**
     * City / zip / country joined into the single line most templates print
     * under the street address. Empty parts are dropped.
     */
    public static function localityLine(array $issuer): string
    {
        $parts = array_filter([
            trim(implode(' ', array_filter([$issuer['zip'] ?? '', $issuer['city'] ?? '']))),
            $issuer['country'] ?? '',
        ], function ($part) {
            return $part !== '';
        });

        return implode(', ', $parts);
    }

    /**
     * Is the Warehouse Letterhead module switched on?
     */
    public static function enabled($setting = null): bool
    {
        $setting = $setting ?: Setting::where('deleted_at', '=', null)->first();

        return (bool) ($setting->enable_warehouse_letterhead ?? false);
    }

    protected static function text($value): string
    {
        return $value === null ? '' : trim((string) $value);
    }
}
