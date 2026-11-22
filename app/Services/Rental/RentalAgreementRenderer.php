<?php

namespace App\Services\Rental;

use App\Models\Currency;
use App\Models\RentalContract;
use App\Models\Setting;

/**
 * Turns an agreement template (HTML with {placeholders}) into the text of
 * one contract. Used by the PDF and by the template preview.
 *
 * Values are HTML-escaped before they go in; only {items_table} and
 * {signature} insert markup, and they build it themselves.
 */
class RentalAgreementRenderer
{
    private const PERIOD_LABELS = [
        'hour' => ['hour', 'hours', 'Hourly'],
        'day' => ['day', 'days', 'Daily'],
        'week' => ['week', 'weeks', 'Weekly'],
        'month' => ['month', 'months', 'Monthly'],
        'year' => ['year', 'years', 'Yearly'],
    ];

    /** Placeholder => description, for the template editor's insert list. */
    public static function placeholders(): array
    {
        return [
            '{contract_number}' => 'Contract number',
            '{today}' => "Today's date",
            '{company_name}' => 'Your company name',
            '{company_address}' => 'Your company address',
            '{company_phone}' => 'Your company phone',
            '{company_email}' => 'Your company email',
            '{customer_name}' => 'Customer name',
            '{customer_phone}' => 'Customer phone',
            '{customer_email}' => 'Customer email',
            '{customer_address}' => 'Customer address',
            '{customer_id_type}' => 'Customer ID type',
            '{customer_id_number}' => 'Customer ID number',
            '{guarantor_name}' => 'Guarantor name',
            '{guarantor_phone}' => 'Guarantor phone',
            '{start_date}' => 'Start date',
            '{end_date}' => 'End date (or "Until terminated")',
            '{duration}' => 'Duration, e.g. "12 months"',
            '{rate_period}' => 'Billing period, e.g. "Monthly"',
            '{rate_period_unit}' => 'Period unit, e.g. "month"',
            '{billing_type}' => 'Billing: "Every period" or "Paid upfront"',
            '{items_list}' => 'Rented items, comma separated',
            '{items_table}' => 'Table of rented items with rates',
            '{rent_amount}' => 'Rent per period',
            '{discount}' => 'Discount',
            '{tax_amount}' => 'Tax amount',
            '{total_amount}' => 'Amount per bill',
            '{contract_value}' => 'Total contract value',
            '{deposit_amount}' => 'Security deposit',
            '{grace_days}' => 'Grace days for payments',
            '{late_fee}' => 'Late-fee rule in words',
            '{terms}' => 'The contract\'s terms & conditions',
            '{signed_name}' => 'Name of the signer',
            '{signed_at}' => 'Date signed',
            '{signature}' => 'Signature image',
        ];
    }

    /** Built-in wording used when no template exists yet. */
    public static function defaultTemplate(): string
    {
        return '<h2>Rental Agreement {contract_number}</h2>'
            . '<p>This agreement is made on {today} between <strong>{company_name}</strong> ("the Owner") '
            . 'and <strong>{customer_name}</strong> ("the Renter"), ID {customer_id_number}.</p>'
            . '<h3>1. Rented items</h3>{items_table}'
            . '<h3>2. Rental period</h3><p>From <strong>{start_date}</strong> to <strong>{end_date}</strong> ({duration}).</p>'
            . '<h3>3. Rent and payment</h3><p>Rent is {rent_amount} per {rate_period_unit}, billed {billing_type}. '
            . 'Each bill comes to {total_amount}. Total contract value: {contract_value}.</p>'
            . '<p>Payments are due on the due date. {late_fee}</p>'
            . '<h3>4. Security deposit</h3><p>The Renter pays a security deposit of {deposit_amount}, refundable on return of the items in the same condition, less any damage or unpaid amounts.</p>'
            . '<h3>5. Terms and conditions</h3>{terms}';
    }

    public function render(RentalContract $contract, string $template): string
    {
        $contract->loadMissing('items', 'client', 'customer');
        $setting = Setting::whereNull('deleted_at')->first();
        $symbol = optional(Currency::find(optional($setting)->currency_id))->symbol ?? '';
        $money = fn ($v) => $v === null ? '—' : trim($symbol . ' ' . number_format((float) $v, 2));
        $date = fn ($d) => $d ? $d->format('F j, Y') . ($d->format('H:i') !== '00:00' ? ' ' . $d->format('H:i') : '') : '';

        $labels = self::PERIOD_LABELS[$contract->rate_period] ?? ['period', 'periods', 'Periodic'];
        $client = $contract->client;
        $customer = $contract->customer;

        $values = [
            '{contract_number}' => $contract->contract_number,
            '{today}' => now()->format('F j, Y'),
            '{company_name}' => optional($setting)->CompanyName,
            '{company_address}' => optional($setting)->CompanyAdress,
            '{company_phone}' => optional($setting)->CompanyPhone,
            '{company_email}' => optional($setting)->email,
            '{customer_name}' => optional($client)->name,
            '{customer_phone}' => optional($client)->phone,
            '{customer_email}' => optional($client)->email,
            '{customer_address}' => collect([optional($client)->adresse, optional($client)->city, optional($client)->country])->filter()->implode(', '),
            '{customer_id_type}' => $customer && $customer->id_type ? ucwords(str_replace('_', ' ', $customer->id_type)) : '',
            '{customer_id_number}' => optional($customer)->id_number ?: '—',
            '{guarantor_name}' => optional($customer)->guarantor_name,
            '{guarantor_phone}' => optional($customer)->guarantor_phone,
            '{start_date}' => $date($contract->start_date),
            '{end_date}' => $contract->end_date ? $date($contract->end_date) : 'Until terminated',
            '{duration}' => $contract->periods ? $contract->periods . ' ' . ($contract->periods === 1 ? $labels[0] : $labels[1]) : 'Open-ended',
            '{rate_period}' => $labels[2],
            '{rate_period_unit}' => $labels[0],
            '{billing_type}' => $contract->billing_type === 'upfront' ? 'upfront for the whole period' : 'every ' . $labels[0],
            '{items_list}' => $contract->items->map(fn ($l) => $l->item_name . ($l->quantity > 1 ? ' × ' . $l->quantity : ''))->implode(', '),
            '{rent_amount}' => $money($contract->rent_amount),
            '{discount}' => $money($contract->discount),
            '{tax_amount}' => $money($contract->tax_amount),
            '{total_amount}' => $money($contract->total_amount),
            '{contract_value}' => $contract->contract_value === null ? 'Open-ended' : $money($contract->contract_value),
            '{deposit_amount}' => $money($contract->deposit_amount),
            '{grace_days}' => (string) $contract->grace_days,
            '{late_fee}' => $this->lateFeeText($contract, $money),
            '{signed_name}' => $contract->signed_name,
            '{signed_at}' => $contract->signed_at ? $contract->signed_at->format('F j, Y H:i') : '',
        ];

        $html = str_replace(
            array_keys($values),
            array_map(fn ($v) => e((string) $v), array_values($values)),
            $template
        );

        // Markup placeholders — built here, never from user input.
        $terms = $contract->terms ? nl2br(e($contract->terms)) : '';

        return str_replace(
            ['{items_table}', '{signature}', '{terms}'],
            [$this->itemsTable($contract, $money, $labels[0]), $this->signatureImage($contract), $terms],
            $html
        );
    }

    private function lateFeeText(RentalContract $c, callable $money): string
    {
        if ($c->late_fee_type === 'none' || (float) $c->late_fee_value <= 0) {
            return "A payment is late {$c->grace_days} day(s) after its due date. No late fee applies.";
        }
        $fee = $c->late_fee_type === 'percent'
            ? rtrim(rtrim(number_format((float) $c->late_fee_value, 2), '0'), '.') . '% of the overdue amount'
            : $money($c->late_fee_value);
        $when = $c->late_fee_frequency === 'daily' ? 'for every day it remains unpaid' : 'once';

        return "A payment is late {$c->grace_days} day(s) after its due date; a late fee of {$fee} is then charged {$when}.";
    }

    private function itemsTable(RentalContract $c, callable $money, string $unit): string
    {
        $rows = $c->items->map(fn ($l) => '<tr>'
            . '<td>' . e($l->item_name) . ($l->item_code ? ' <span style="color:#6b7280">(' . e($l->item_code) . ')</span>' : '') . '</td>'
            . '<td style="text-align:center">' . (int) $l->quantity . '</td>'
            . '<td style="text-align:right">' . e($money($l->rate)) . ' / ' . e($unit) . '</td>'
            . '<td style="text-align:right">' . e($money($l->amount)) . '</td>'
            . '</tr>')->implode('');

        return '<table style="width:100%;border-collapse:collapse;margin:6px 0 10px" cellpadding="6">'
            . '<thead><tr style="background:#f3f4f6">'
            . '<th style="text-align:left;border:1px solid #e5e7eb">Item</th>'
            . '<th style="border:1px solid #e5e7eb">Qty</th>'
            . '<th style="text-align:right;border:1px solid #e5e7eb">Rate</th>'
            . '<th style="text-align:right;border:1px solid #e5e7eb">Per ' . e($unit) . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** Inline data URI so dompdf never has to fetch a URL. */
    public function signatureImage(RentalContract $c): string
    {
        if (! $c->signature) {
            return '';
        }
        $path = upload_public_path(RentalContract::FILE_DIR . '/' . $c->signature);
        if (! is_file($path)) {
            return '';
        }

        return '<img src="data:image/png;base64,' . base64_encode(file_get_contents($path)) . '" style="max-height:70px;max-width:240px" alt="Signature">';
    }
}
