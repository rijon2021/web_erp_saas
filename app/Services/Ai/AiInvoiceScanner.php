<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Reads a supplier invoice (photo or PDF) with the AI model and matches what it
 * finds to Stocky's suppliers and products. The result only pre-fills the
 * purchase form — the user reviews every line before saving.
 *
 * Matching is done here, deterministically, not by the model: exact codes
 * (product / variant code, GTIN) first, then a name similarity suggestion
 * that the review screen marks as "check this".
 */
class AiInvoiceScanner
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    // 5 MB per image at the API, counted on the base64 payload (≈ 4/3 of the file).
    private const MAX_IMAGE_BYTES = 3_750_000;

    // The models read up to 2576 px on the long edge; larger photos only cost bytes.
    private const MAX_IMAGE_EDGE = 2576;

    private const NAME_MATCH_MIN = 55;          // % similarity for a suggestion

    public function __construct(private AiClient $client)
    {
    }

    public function scan(UploadedFile $file, ?int $userId = null): array
    {
        $raw = $this->extract($file, $userId);

        $lines = [];
        foreach ($raw['lines'] ?? [] as $line) {
            $quantity = self::number($line['quantity'] ?? '');
            $unitPrice = self::number($line['unit_price'] ?? '');
            $lineTotal = self::number($line['line_total'] ?? '');
            // Fill a missing unit price from the line total (and vice versa).
            if ($unitPrice === null && $lineTotal !== null && $quantity) {
                $unitPrice = round($lineTotal / $quantity, 4);
            }
            $description = trim((string) ($line['description'] ?? ''));
            $code = trim((string) ($line['code'] ?? ''));
            if ($description === '' && $code === '') {
                continue;
            }
            $lines[] = [
                'description' => $description,
                'code' => $code,
                'quantity' => $quantity ?? 1,
                'unit_cost' => $unitPrice ?? 0,
                'discount' => self::number($line['discount'] ?? ''),
                'line_total' => $lineTotal,
                'match' => $this->matchProduct($code, $description),
            ];
        }

        $supplier = $raw['supplier'] ?? [];
        $currency = strtoupper(trim((string) ($raw['currency'] ?? '')));

        return [
            'is_invoice' => (bool) ($raw['is_invoice'] ?? true),
            'supplier' => [
                'name' => trim((string) ($supplier['name'] ?? '')),
                'tax_number' => trim((string) ($supplier['tax_number'] ?? '')),
                'email' => trim((string) ($supplier['email'] ?? '')),
                'phone' => trim((string) ($supplier['phone'] ?? '')),
                'address' => trim((string) ($supplier['address'] ?? '')),
            ],
            'supplier_match' => $this->matchSupplier($supplier),
            'invoice_number' => trim((string) ($raw['invoice_number'] ?? '')),
            'invoice_date' => self::date($raw['invoice_date'] ?? ''),
            'currency' => $currency,
            'currency_id' => $currency !== '' ? DB::table('currencies')->whereNull('deleted_at')->where('code', $currency)->value('id') : null,
            'subtotal' => self::number($raw['subtotal'] ?? ''),
            'discount' => self::number($raw['discount_total'] ?? ''),
            'tax' => self::number($raw['tax_total'] ?? ''),
            'shipping' => self::number($raw['shipping'] ?? ''),
            'total' => self::number($raw['total'] ?? ''),
            'notes' => trim((string) ($raw['notes'] ?? '')),
            'lines' => $lines,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* AI model                                                            */
    /* ------------------------------------------------------------------ */

    private function extract(UploadedFile $file, ?int $userId): array
    {
        $mime = $file->getMimeType() ?: '';

        if ($mime === 'application/pdf') {
            $data = base64_encode((string) file_get_contents($file->getRealPath()));
            $block = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data]];
        } elseif (in_array($mime, self::IMAGE_TYPES, true)) {
            [$bytes, $mime] = $this->prepareImage($file->getRealPath(), $mime);
            if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
                throw new AiException(__('messages.ai_invoice_image_too_large'));
            }
            $block = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
        } else {
            throw new AiException(__('messages.ai_invoice_bad_file'));
        }

        $response = $this->client->create([
            'maxTokens' => 16000,
            'system' => [['type' => 'text', 'text' => $this->instructions()]],
            'messages' => [[
                'role' => 'user',
                'content' => [$block, ['type' => 'text', 'text' => 'Extract this supplier invoice.']],
            ]],
            'outputConfig' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
        ]);
        AiClient::logUsage('invoice_scan', $response, $userId);

        if ($response->stopReason === 'refusal') {
            throw new AiException(__('messages.ai_refused'));
        }
        if ($response->stopReason === 'max_tokens') {
            throw new AiException(__('messages.ai_invoice_too_long'));
        }

        if (is_array($parsed = $response->json())) {
            return $parsed;
        }

        throw new AiException(__('messages.ai_request_failed', ['code' => 'format']));
    }

    /**
     * Phone photos are often 4000+ px and several MB: rotate per EXIF and
     * shrink to the model's maximum resolution as a JPEG. Small images (and
     * GIFs, which may be animated) are sent untouched.
     *
     * @return array{0: string, 1: string} [bytes, mime]
     */
    private function prepareImage(string $path, string $mime): array
    {
        $bytes = (string) file_get_contents($path);
        $size = @getimagesize($path);
        $tooBig = strlen($bytes) > self::MAX_IMAGE_BYTES
            || ($size && max($size[0], $size[1]) > self::MAX_IMAGE_EDGE);

        if (! $tooBig || $mime === 'image/gif') {
            return [$bytes, $mime];
        }

        try {
            $img = \Intervention\Image\ImageManagerStatic::make($path);
            if (function_exists('exif_read_data')) {
                $img->orientate();
            }
            $img->resize(self::MAX_IMAGE_EDGE, self::MAX_IMAGE_EDGE, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            });

            return [(string) $img->encode('jpg', 85), 'image/jpeg'];
        } catch (\Throwable $e) {
            report($e);

            return [$bytes, $mime];
        }
    }

    private function instructions(): string
    {
        return <<<'TXT'
You read supplier invoices, receipts and delivery notes for a shop's purchasing system. Extract what is printed, exactly as printed.

- Copy names, codes and descriptions as they appear (fix only obvious OCR noise). Don't translate them.
- code: the item's SKU, reference, article number or barcode if the line has one; otherwise "".
- Numbers: plain decimals with "." as the decimal separator and no thousands separators or currency symbols (e.g. "1234.50"). Use "" for anything not on the document — never compute or guess a missing value.
- quantity is the number of units bought on that line; unit_price is the price per unit before tax, as printed; line_total is the line amount as printed.
- invoice_date: YYYY-MM-DD. If the day/month order is ambiguous, use the supplier's country conventions when evident, otherwise day-first.
- currency: ISO 4217 code (e.g. "USD", "EUR", "MAD") if it can be determined, else "".
- supplier: the business that issued the invoice (the seller), not the customer it was billed to.
- Include every product line. Leave out lines that are only subtotals, taxes, shipping or payment info — those go in the totals fields.
- is_invoice: false if the document is not an invoice, receipt, delivery note or similar purchase document.
- notes: one short sentence about anything the reviewer should check (unreadable parts, handwritten changes, multiple pages missing); "" if nothing.
TXT;
    }

    private function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'is_invoice' => ['type' => 'boolean'],
                'supplier' => [
                    'type' => 'object',
                    'properties' => ['name' => $str, 'tax_number' => $str, 'email' => $str, 'phone' => $str, 'address' => $str],
                    'required' => ['name', 'tax_number', 'email', 'phone', 'address'],
                    'additionalProperties' => false,
                ],
                'invoice_number' => $str,
                'invoice_date' => $str,
                'currency' => $str,
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => $str, 'code' => $str, 'quantity' => $str,
                            'unit_price' => $str, 'discount' => $str, 'line_total' => $str,
                        ],
                        'required' => ['description', 'code', 'quantity', 'unit_price', 'discount', 'line_total'],
                        'additionalProperties' => false,
                    ],
                ],
                'subtotal' => $str,
                'discount_total' => $str,
                'tax_total' => $str,
                'shipping' => $str,
                'total' => $str,
                'notes' => $str,
            ],
            'required' => ['is_invoice', 'supplier', 'invoice_number', 'invoice_date', 'currency', 'lines', 'subtotal', 'discount_total', 'tax_total', 'shipping', 'total', 'notes'],
            'additionalProperties' => false,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Matching                                                            */
    /* ------------------------------------------------------------------ */

    /** @return array{product_id:int, product_variant_id:?int, name:string, code:string, confidence:string, score:int}|null */
    public function matchProduct(string $code, string $description): ?array
    {
        $code = trim($code);
        if ($code !== '') {
            $variant = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereNull('product_variants.deleted_at')->whereNull('products.deleted_at')
                ->where(fn ($q) => $q->where('product_variants.code', $code)->orWhere('product_variants.gtin', $code))
                ->first(['products.id', 'product_variants.id as variant_id', 'products.name', 'product_variants.name as variant', 'product_variants.code']);
            if ($variant) {
                return $this->hit($variant->id, $variant->variant_id, $variant->name.' — '.$variant->variant, $variant->code, 'exact', 100);
            }

            $product = DB::table('products')->whereNull('deleted_at')->where('type', '!=', 'is_service')
                ->where(fn ($q) => $q->where('code', $code)->orWhere('gtin', $code))
                ->first(['id', 'name', 'code', 'type']);
            if ($product && $product->type !== 'is_variant') {
                return $this->hit($product->id, null, $product->name, $product->code, 'exact', 100);
            }
        }

        $needle = self::normalize($description);
        if (mb_strlen($needle) < 3) {
            return null;
        }

        // Candidates: products sharing one of the description's longest words.
        $tokens = array_values(array_filter(explode(' ', $needle), fn ($t) => mb_strlen($t) >= 3));
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $tokens = array_slice($tokens, 0, 3);
        if (! $tokens) {
            return null;
        }

        $candidates = DB::table('products')
            ->leftJoin('product_variants', function ($j) {
                $j->on('product_variants.product_id', '=', 'products.id')->whereNull('product_variants.deleted_at');
            })
            ->whereNull('products.deleted_at')
            ->where('products.type', '!=', 'is_service')
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $t) {
                    $q->orWhere('products.name', 'like', '%'.$t.'%')->orWhere('product_variants.name', 'like', '%'.$t.'%');
                }
            })
            ->limit(80)
            ->get(['products.id', 'products.name', 'products.code', 'products.type', 'product_variants.id as variant_id', 'product_variants.name as variant', 'product_variants.code as variant_code']);

        $best = null;
        foreach ($candidates as $c) {
            // A variant product can only be bought as one of its variants.
            if ($c->type === 'is_variant' && ! $c->variant_id) {
                continue;
            }
            $label = $c->variant_id ? $c->name.' '.$c->variant : $c->name;
            similar_text($needle, self::normalize($label), $percent);
            if (! $best || $percent > $best['score']) {
                $best = [
                    'score' => (int) round($percent),
                    'id' => $c->id,
                    'variant_id' => $c->variant_id,
                    'name' => $c->variant_id ? $c->name.' — '.$c->variant : $c->name,
                    'code' => $c->variant_id ? $c->variant_code : $c->code,
                ];
            }
        }

        return $best && $best['score'] >= self::NAME_MATCH_MIN
            ? $this->hit($best['id'], $best['variant_id'], $best['name'], (string) $best['code'], 'suggested', $best['score'])
            : null;
    }

    /** @return array{id:int, name:string, confidence:string}|null */
    public function matchSupplier(array $s): ?array
    {
        $q = DB::table('providers')->whereNull('deleted_at');

        $tax = preg_replace('/\s+/', '', (string) ($s['tax_number'] ?? ''));
        if ($tax !== '' && ($hit = (clone $q)->whereRaw("REPLACE(tax_number, ' ', '') = ?", [$tax])->first(['id', 'name']))) {
            return ['id' => (int) $hit->id, 'name' => $hit->name, 'confidence' => 'exact'];
        }
        $email = strtolower(trim((string) ($s['email'] ?? '')));
        if ($email !== '' && ($hit = (clone $q)->whereRaw('LOWER(email) = ?', [$email])->first(['id', 'name']))) {
            return ['id' => (int) $hit->id, 'name' => $hit->name, 'confidence' => 'exact'];
        }

        $name = self::normalize((string) ($s['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $best = null;
        foreach ((clone $q)->get(['id', 'name', 'phone']) as $p) {
            $candidate = self::normalize($p->name);
            if ($candidate === $name) {
                return ['id' => (int) $p->id, 'name' => $p->name, 'confidence' => 'exact'];
            }
            similar_text($name, $candidate, $percent);
            if (! $best || $percent > $best[0]) {
                $best = [$percent, $p];
            }
        }

        return $best && $best[0] >= 80
            ? ['id' => (int) $best[1]->id, 'name' => $best[1]->name, 'confidence' => 'suggested']
            : null;
    }

    private function hit($productId, $variantId, string $name, string $code, string $confidence, int $score): array
    {
        return [
            'product_id' => (int) $productId,
            'product_variant_id' => $variantId ? (int) $variantId : null,
            'name' => $name,
            'code' => $code,
            'confidence' => $confidence,
            'score' => $score,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Parsing helpers                                                     */
    /* ------------------------------------------------------------------ */

    /** "1,234.50" / "1234.5" / "" → float|null (the model is asked for plain decimals). */
    public static function number($value): ?float
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/[^0-9.,\-]/', '', $s);
        // Both separators present: the last one is the decimal separator.
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif (str_contains($s, ',')) {
            // "1,234" / "12,345,678" = thousands groups; anything else ("12,5") = decimal comma.
            $s = preg_match('/^-?\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private static function date($value): ?string
    {
        $s = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));

        return checkdate($m, $d, $y) ? $s : null;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
