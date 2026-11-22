<?php

namespace App\Services\Ai;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes customer-facing product content (description, search tags, FAQs)
 * from the product's own facts and, when available, its main image.
 *
 * Nothing is saved here: the product form shows the draft for the user to
 * apply, and bulk writing saves only the rows the user approves.
 *
 * The storefront prints products.note as escaped plain text with line
 * breaks, so the description is plain text — no Markdown or HTML.
 */
class AiProductWriter
{
    public const TONES = ['professional', 'friendly', 'luxury', 'technical', 'playful'];

    public const LENGTHS = ['short' => '40-70 words', 'medium' => '100-160 words', 'long' => '200-300 words'];

    public const PARTS = ['description', 'tags', 'faqs'];

    private const MAX_IMAGE_BYTES = 3_500_000;

    public function __construct(private AiClient $client)
    {
    }

    /**
     * The parts this install can store. Tags and FAQs need the products.tags /
     * products.faqs columns, which the SaaS schema does not have yet.
     */
    public static function availableParts(): array
    {
        return array_values(array_filter(self::PARTS, fn ($part) => $part === 'description' || Schema::hasColumn('products', $part)));
    }

    /**
     * @param  array  $facts  product facts (see factsFromProduct)
     * @param  array  $options  language (name), tone, length, parts[], instructions, image
     * @return array{description: string, tags: string[], faqs: array<int, array{question: string, answer: string}>}
     */
    public function write(array $facts, array $options, ?int $userId = null): array
    {
        $parts = array_values(array_intersect($options['parts'] ?? self::PARTS, self::availableParts())) ?: ['description'];
        $tone = in_array($options['tone'] ?? '', self::TONES, true) ? $options['tone'] : 'professional';
        $length = self::LENGTHS[$options['length'] ?? 'medium'] ?? self::LENGTHS['medium'];
        $language = trim((string) ($options['language'] ?? '')) ?: 'English';
        $instructions = trim(mb_substr((string) ($options['instructions'] ?? ''), 0, 500));

        $request = "Write content for this product.\n\n"
            ."Parts to write: ".implode(', ', $parts)." (return an empty value for any part not listed).\n"
            ."Language: {$language}.\n"
            ."Tone: {$tone}.\n"
            ."Description length: about {$length}.\n"
            .($instructions !== '' ? "Extra instructions from the shop owner: {$instructions}\n" : '')
            ."\nProduct facts (JSON):\n".json_encode($this->clean($facts), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $content = [];
        if ($image = $options['image'] ?? null) {
            $content[] = $image;
            $request .= "\n\nThe attached image is the product's main photo. You may describe what is clearly visible in it.";
        }
        $content[] = ['type' => 'text', 'text' => $request];

        $response = $this->client->create([
            'maxTokens' => 4000,
            'system' => [['type' => 'text', 'text' => $this->instructions()]],
            'messages' => [['role' => 'user', 'content' => $content]],
            'outputConfig' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
        ]);
        AiClient::logUsage('product_writer', $response, $userId);

        if ($response->stopReason === 'refusal') {
            throw new AiException(__('messages.ai_refused'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new AiException(__('messages.ai_request_failed', ['code' => 'format']));
        }

        return [
            'description' => in_array('description', $parts, true) ? trim((string) ($data['description'] ?? '')) : '',
            'tags' => in_array('tags', $parts, true)
                ? array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 60), (array) ($data['tags'] ?? [])))))
                : [],
            'faqs' => in_array('faqs', $parts, true)
                ? array_values(array_filter(array_map(fn ($f) => [
                    'question' => trim((string) ($f['question'] ?? '')),
                    'answer' => trim((string) ($f['answer'] ?? '')),
                ], (array) ($data['faqs'] ?? [])), fn ($f) => $f['question'] !== '' && $f['answer'] !== ''))
                : [],
        ];
    }

    /** Facts for a saved product (bulk writing). Costs and margins are left out on purpose. */
    public function factsFromProduct(Product $p): array
    {
        $p->loadMissing(['category', 'brand', 'unit', 'variants', 'categories', 'subcategories']);

        $categories = collect([$p->category?->name])
            ->merge($p->categories->pluck('name'))
            ->filter()->unique()->values()->all();

        return [
            'name' => $p->name,
            'code' => $p->code,
            'type' => ['is_single' => 'standard product', 'is_variant' => 'product with variants', 'is_service' => 'service', 'is_combo' => 'bundle'][$p->type] ?? $p->type,
            'categories' => $categories,
            'subcategories' => $p->subcategories->pluck('name')->all(),
            'brand' => $p->brand?->name,
            'unit' => $p->unit?->name,
            'variants' => $p->variants->whereNull('deleted_at')->pluck('name')->filter()->values()->all(),
            'weight' => $p->weight,
            'dimensions' => array_filter(['length' => $p->length, 'width' => $p->width, 'height' => $p->height]),
            'warranty' => $p->warranty_period ? trim($p->warranty_period.' '.$p->warranty_unit) : null,
            'warranty_terms' => $p->warranty_terms,
            'generic_name' => $p->generic_name,
            'strength' => $p->strength,
            'dosage_form' => $p->dosage_form,
            'pack_size' => $p->pack_size,
            'manufacturer' => $p->manufacturer,
            'labels' => $p->labels,
            'existing_tags' => $p->tags,
            'existing_description' => $p->note,
        ];
    }

    /**
     * The product's main image as an image block (Claude's shape; translated
     * for ChatGPT), or null. Local files
     * are sent inline (the store may not be reachable from the internet);
     * remote links are passed as URLs.
     */
    public function imageBlock(?Product $product): ?array
    {
        if (! $product) {
            return null;
        }
        $file = trim((string) ($product->primaryProductImageFilename() ?: $product->image));
        if ($file === '' || $file === 'no-image.png') {
            return null;
        }

        if (product_image_is_remote($file)) {
            $url = str_starts_with($file, '//') ? 'https:'.$file : $file;

            return str_starts_with($url, 'https://') ? ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]] : null;
        }

        // Tenant uploads live under images/tenants/{id}/products.
        $path = upload_public_path('products').'/'.basename($file);
        if (! is_file($path) || filesize($path) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        $mime = mime_content_type($path) ?: '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode(file_get_contents($path))]];
    }

    /** Store default language as a name Claude understands ("Français", not "fr"). */
    public static function languageName(?string $locale): string
    {
        $locale = $locale ?: (DB::table('settings')->whereNull('deleted_at')->value('default_language') ?: 'en');
        $name = DB::table('languages')->where('locale', $locale)->value('name');

        return $name ? "{$name} ({$locale})" : $locale;
    }

    /** Drop empty values and cap long strings so a pasted essay can't blow up the prompt. */
    private function clean(array $facts): array
    {
        $out = [];
        foreach ($facts as $key => $value) {
            if (is_array($value)) {
                $value = $this->clean($value);
                if ($value === []) {
                    continue;
                }
            } elseif ($value === null || $value === '' || $value === 0 || $value === '0') {
                continue;
            } elseif (is_string($value)) {
                $value = mb_substr(trim($value), 0, 2000);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function instructions(): string
    {
        return <<<'TXT'
You write product content for a shop's online store and printed catalog: the product description customers read, search tags, and short FAQs.

Accuracy comes first:
- Use only the facts provided and what is clearly visible in the product photo. Never invent specifications, materials, dimensions, certifications, compatibility, ingredients, origin, warranty terms or awards. If a detail is unknown, leave it out rather than guessing.
- Don't mention price, discounts, stock levels or delivery times; they change.
- If there is an existing description, keep its true facts and improve the writing.
- For medicines or health products, stay factual and don't make medical claims beyond the facts given.

Description format (it is shown as plain text):
- No Markdown, no HTML, no emoji. Separate paragraphs with a blank line.
- Open with what the product is and who it's for, then the useful details. You may add a short list of key features as lines starting with "• ".
- Match the requested tone and length, and write in the requested language.

Tags: 5 to 10 short lowercase search keywords or phrases a shopper might type, in the requested language, no duplicates, no "#".

FAQs: 3 to 5 questions a shopper would plausibly ask, each answered in one or two sentences strictly from the facts. Skip any question the facts can't answer — fewer FAQs is better than an invented answer.
TXT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'faqs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'answer' => ['type' => 'string'],
                        ],
                        'required' => ['question', 'answer'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['description', 'tags', 'faqs'],
            'additionalProperties' => false,
        ];
    }
}
