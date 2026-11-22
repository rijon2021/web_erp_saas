<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProductWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AI product writer: drafts descriptions, tags and FAQs.
 *  - generate: returns a draft only (product form applies it into the form;
 *    the user saves the product as usual);
 *  - apply: bulk-saves the drafts the user approved (products_edit), through
 *    the model so the audit log records each change.
 * Needs the ai_assistant permission plus product add/edit rights.
 */
class AiProductWriterController extends Controller
{
    public function options(Request $request)
    {
        $this->authorizeWriter($request);

        $default = DB::table('settings')->whereNull('deleted_at')->value('default_language') ?: 'en';

        return response()->json([
            'ready' => AiSetting::current()->isReady(),
            'languages' => DB::table('languages')->where('is_active', 1)->orderByDesc('is_default')->orderBy('name')->get(['locale', 'name']),
            'default_language' => $default,
            'tones' => AiProductWriter::TONES,
            'lengths' => array_keys(AiProductWriter::LENGTHS),
            'parts' => AiProductWriter::availableParts(),
        ]);
    }

    public function generate(Request $request)
    {
        $user = $this->authorizeWriter($request);

        $data = $request->validate([
            'product_id' => 'nullable|integer',
            'facts' => 'nullable|array',
            'language' => 'nullable|string|max:20',
            'tone' => 'nullable|in:'.implode(',', AiProductWriter::TONES),
            'length' => 'nullable|in:'.implode(',', array_keys(AiProductWriter::LENGTHS)),
            'parts' => 'nullable|array',
            'parts.*' => 'in:'.implode(',', AiProductWriter::availableParts()),
            'instructions' => 'nullable|string|max:500',
            'use_image' => 'nullable|boolean',
            'skip_described' => 'nullable|boolean',
        ]);

        if (! AiSetting::current()->isReady()) {
            return response()->json(['message' => __('messages.ai_not_configured')], 422);
        }

        $product = ! empty($data['product_id']) ? Product::whereNull('deleted_at')->find($data['product_id']) : null;
        if (! empty($data['product_id']) && ! $product) {
            return response()->json(['message' => __('messages.ai_product_not_found')], 404);
        }

        // Bulk "only products without a description": no API call at all.
        if ($product && ! empty($data['skip_described']) && trim((string) $product->note) !== '') {
            return response()->json(['skipped' => true, 'product_id' => $product->id]);
        }

        $writer = new AiProductWriter(AiClient::make());
        // Facts from the form win (they include unsaved edits); bulk sends only the id.
        $facts = ! empty($data['facts']) ? $data['facts'] : ($product ? $writer->factsFromProduct($product) : null);
        if (! $facts || trim((string) ($facts['name'] ?? '')) === '') {
            return response()->json(['message' => __('messages.ai_product_name_required')], 422);
        }
        if (strlen(json_encode($facts)) > 20000) {
            return response()->json(['message' => __('messages.ai_product_facts_too_long')], 422);
        }

        @set_time_limit(120);

        try {
            $draft = $writer->write($facts, [
                'language' => AiProductWriter::languageName($data['language'] ?? null),
                'tone' => $data['tone'] ?? 'professional',
                'length' => $data['length'] ?? 'medium',
                'parts' => $data['parts'] ?? AiProductWriter::availableParts(),
                'instructions' => $data['instructions'] ?? '',
                'image' => ($data['use_image'] ?? true) ? $writer->imageBlock($product) : null,
            ], $user->id);
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['draft' => $draft, 'product_id' => $product?->id]);
    }

    /** Save approved drafts: [{id, description?, tags?[], faqs?[]}], tags/FAQs merged into existing ones. */
    public function apply(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);

        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.id' => 'required|integer',
            'items.*.description' => 'nullable|string|max:10000',
            'items.*.tags' => 'nullable|array|max:30',
            'items.*.tags.*' => 'string|max:60',
            'items.*.faqs' => 'nullable|array|max:10',
            'items.*.faqs.*.question' => 'required_with:items.*.faqs|string|max:300',
            'items.*.faqs.*.answer' => 'required_with:items.*.faqs|string|max:1000',
        ]);

        $parts = AiProductWriter::availableParts();
        $saved = 0;
        DB::transaction(function () use ($data, $parts, &$saved) {
            foreach ($data['items'] as $item) {
                $product = Product::whereNull('deleted_at')->find($item['id']);
                if (! $product) {
                    continue;
                }
                if (! empty($item['description'])) {
                    $product->note = $item['description'];
                }
                if (! empty($item['tags']) && in_array('tags', $parts, true)) {
                    $product->tags = array_values(array_unique(array_merge((array) ($product->tags ?? []), $item['tags'])));
                }
                if (! empty($item['faqs']) && in_array('faqs', $parts, true)) {
                    $product->faqs = array_values(array_merge((array) ($product->faqs ?? []), $item['faqs']));
                }
                if ($product->isDirty()) {
                    $product->save();
                    $saved++;
                }
            }
        });

        return response()->json(['success' => true, 'saved' => $saved]);
    }

    private function authorizeWriter(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);
        if (! $user->can('create', Product::class) && ! $user->can('update', Product::class)) {
            abort(403);
        }

        return $user;
    }
}
