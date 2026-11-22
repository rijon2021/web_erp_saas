<?php

namespace App\Services\Agt;

use App\Models\AgtSetting;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Maps a Stocky sale (FT/FR) or sale return (NC) to the AGT "document"
 * object: lines with net amounts, IVA per line, and the document totals.
 *
 * Amounts are derived algebraically from each line (net = gross / (1+rate)
 * for tax-inclusive prices) because AGT checks net × rate = tax, which the
 * legacy inclusive formula used on screen does not satisfy. The order-level
 * discount (and promotion discount) is spread over the lines pro rata as
 * settlementAmount; shipping becomes its own exempt service line. Totals can
 * therefore differ from the Stocky GrandTotal by rounding cents.
 */
class AgtDocumentBuilder
{
    protected array $unitNames = [];

    public function __construct(protected AgtSetting $settings)
    {
    }

    /**
     * @return array{lines: array, totals: array{taxPayable: int|float, netTotal: int|float, grossTotal: int|float}, customer: array{customerTaxID: string, customerCountry: string, companyName: string}}
     */
    public function build(Model $source, ?string $referenceDocumentNo = null, ?string $reason = null): array
    {
        $isReturn = $source instanceof SaleReturn;
        $source->loadMissing(['client', 'details.product']);

        $orderTaxRate = (float) ($source->tax_rate ?? 0);
        $grossSum = 0.0;
        foreach ($source->details as $detail) {
            $grossSum += (float) $detail->total;
        }

        $orderDiscount = $this->orderDiscount($source, $grossSum);
        $factor = $grossSum > 0 ? min(1, max(0, $orderDiscount / $grossSum)) : 0.0;

        $lines = [];
        $net = 0.0;
        $tax = 0.0;
        foreach ($source->details as $detail) {
            if ((float) $detail->quantity <= 0) {
                continue; // e.g. sale lines kept on a return with nothing returned
            }
            $line = $this->line($detail, count($lines) + 1, $orderTaxRate, $factor, $isReturn);
            if ($referenceDocumentNo !== null) {
                $line['referenceInfo'] = array_filter([
                    'reference' => $referenceDocumentNo,
                    'reason' => $reason ? mb_substr($reason, 0, 60) : null,
                ]);
            }
            $net += $line[$isReturn ? 'debitAmount' : 'creditAmount'];
            $tax += $line['taxes'][0]['taxContribution'];
            $lines[] = $line;
        }

        $shipping = round((float) ($source->shipping ?? 0), 2);
        if ($shipping > 0) {
            $line = $this->shippingLine($shipping, count($lines) + 1, $isReturn);
            if ($referenceDocumentNo !== null) {
                $line['referenceInfo'] = ['reference' => $referenceDocumentNo];
            }
            $net += $shipping;
            $lines[] = $line;
        }

        if (empty($lines)) {
            throw new RuntimeException('The document has no lines to communicate.');
        }

        $net = round($net, 2);
        $tax = round($tax, 2);

        return [
            'lines' => $lines,
            'totals' => [
                'taxPayable' => self::amount($tax),
                'netTotal' => self::amount($net),
                'grossTotal' => self::amount($net + $tax),
            ],
            'customer' => $this->customer($source),
        ];
    }

    protected function line($detail, int $number, float $orderTaxRate, float $discountFactor, bool $isReturn): array
    {
        $product = $detail->product;
        $quantity = (float) $detail->quantity;
        $price = (float) $detail->price;
        $rate = (float) $detail->TaxNet;

        if ($orderTaxRate > 0) {
            if ($rate > 0) {
                throw new RuntimeException('Line "'.($product->name ?? '#'.$detail->product_id).'" has a product tax and the document also has an order tax — AGT needs one IVA rate per line. Use either product taxes or the order tax.');
            }
            // Order tax is charged on top of the (net) line totals.
            $rate = $orderTaxRate;
            $exclusive = true;
        } else {
            $exclusive = (string) $detail->tax_method === '1';
        }

        $discountUnit = (string) $detail->discount_method === '2'
            ? (float) $detail->discount
            : $price * (float) $detail->discount / 100;

        $divisor = 1 + $rate / 100;
        $unitBase = $exclusive ? $price : $price / $divisor;
        $unitNet = $exclusive ? $price - $discountUnit : ($price - $discountUnit) / $divisor;
        $unitNet *= (1 - $discountFactor);

        $amount = round($quantity * $unitNet, 2);
        $baseAmount = round($quantity * $unitBase, 2);
        $taxCode = $this->settings->taxCodeFor($rate);

        $name = $product->name ?? 'Artigo';
        $code = (string) ($product->code ?? '');
        if (! empty($detail->product_variant_id)) {
            $variant = ProductVariant::query()->find($detail->product_variant_id);
            if ($variant) {
                $name .= ' - '.$variant->name;
                $code = (string) ($variant->code ?: $code);
            }
        }
        if (! empty($detail->pack_name)) {
            $name .= ' ['.$detail->pack_name.']';
        }

        return [
            'lineNumber' => $number,
            'operationType' => (($product->type ?? '') === 'is_service') ? 'SG' : 'TB',
            'productCode' => mb_substr($code !== '' ? $code : 'P'.$detail->product_id, 0, 60),
            'productDescription' => mb_substr($name, 0, 200),
            'quantity' => self::amount($quantity, 3),
            'unitOfMeasure' => $this->unitName($detail->sale_unit_id),
            'unitPriceBase' => self::amount($unitBase, 4),
            'unitPrice' => self::amount($unitNet, 4),
            $isReturn ? 'debitAmount' : 'creditAmount' => self::amount($amount),
            'taxes' => [$this->tax($rate, $taxCode, $amount)],
            'settlementAmount' => self::amount(max(0, $baseAmount - $amount)),
        ];
    }

    protected function shippingLine(float $shipping, int $number, bool $isReturn): array
    {
        return [
            'lineNumber' => $number,
            'operationType' => 'SG',
            'productCode' => 'PORTES',
            'productDescription' => 'Portes / Transporte',
            'quantity' => 1,
            'unitOfMeasure' => 'UN',
            'unitPriceBase' => self::amount($shipping),
            'unitPrice' => self::amount($shipping),
            $isReturn ? 'debitAmount' : 'creditAmount' => self::amount($shipping),
            // Stocky charges no tax on shipping.
            'taxes' => [$this->tax(0, 'ISE', $shipping)],
            'settlementAmount' => 0,
        ];
    }

    protected function tax(float $rate, string $taxCode, float $base): array
    {
        $tax = [
            'taxType' => 'IVA',
            'taxCountryRegion' => 'AO',
            'taxCode' => $taxCode,
            'taxPercentage' => self::amount($rate),
            // AGT: rounded UP to the next cent (23,144 → 23,15).
            'taxContribution' => self::amount($rate > 0 ? self::ceilCent($base * $rate / 100) : 0),
        ];

        if ($taxCode === 'ISE') {
            $code = strtoupper(trim((string) $this->settings->default_exemption_code));
            if ($code === '') {
                throw new RuntimeException('A line is exempt (0% IVA) but no default exemption code (taxExemptionCode) is set in AGT settings.');
            }
            $tax['taxExemptionCode'] = $code;
        }

        return $tax;
    }

    protected function orderDiscount(Model $source, float $grossSum): float
    {
        $discount = (float) ($source->discount ?? 0);
        if ($source instanceof Sale) {
            if ((string) $source->discount_Method === '1') {
                $discount = round($grossSum * $discount / 100, 2);
            }
            // Same stack as the sale form (lineCalc computeTotals): manual
            // discount, then loyalty points, then the promotion discount.
            $discount += (float) ($source->discount_from_points ?? 0);
            $discount += (float) ($source->promotion_discount ?? 0);
        }

        return max(0, $discount);
    }

    /** @return array{customerTaxID: string, customerCountry: string, companyName: string} */
    protected function customer(Model $source): array
    {
        $client = $source->client;
        $nif = preg_replace('/\s+/', '', (string) ($client->tax_number ?? ''));
        $country = strtoupper(trim((string) ($client->country ?? '')));
        if ($country === '' || $country === 'ANGOLA' || ! preg_match('/^[A-Z]{2}$/', $country)) {
            $country = 'AO';
        }

        return [
            // AGT: domestic buyers without identification use 999999999.
            'customerTaxID' => mb_substr($nif !== '' ? $nif : '999999999', 0, 50),
            'customerCountry' => $country,
            'companyName' => mb_substr(trim((string) ($client->name ?? '')) ?: 'Consumidor final', 0, 200),
        ];
    }

    protected function unitName($unitId): string
    {
        $fallback = (string) ($this->settings->default_unit ?: 'UN');
        if (! $unitId) {
            return $fallback;
        }
        if (! array_key_exists($unitId, $this->unitNames)) {
            $unit = Unit::query()->find($unitId);
            $this->unitNames[$unitId] = $unit ? (trim((string) $unit->ShortName) ?: trim((string) $unit->name)) : '';
        }

        return mb_substr($this->unitNames[$unitId] ?: $fallback, 0, 20);
    }

    /** Whole numbers as int, otherwise rounded — keeps the signed JSON and the payload identical. */
    public static function amount(float $value, int $decimals = 2): int|float
    {
        $rounded = round($value, $decimals);

        return floor($rounded) == $rounded && abs($rounded) < PHP_INT_MAX ? (int) $rounded : $rounded;
    }

    public static function ceilCent(float $value): float
    {
        // round() first so float noise (5.9999999 vs 6.0000001) cannot add a cent.
        return ceil(round($value * 100, 6)) / 100;
    }
}
