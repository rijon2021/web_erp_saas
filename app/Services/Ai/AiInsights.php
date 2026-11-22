<?php

namespace App\Services\Ai;

use App\Models\AiInsight;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Daily AI-written dashboard insights. One model call per visibility scope
 * per day (users who see the same warehouses/records share it), generated on
 * the first dashboard view of the day — no cron.
 *
 * The data snapshot is gathered server-side with the same scoped tools the
 * chat uses, so insights never reveal more than the viewer may see.
 */
class AiInsights
{
    public function __construct(private AiClient $client)
    {
    }

    public function today(User $user): ?AiInsight
    {
        return AiInsight::where('scope_key', (new AiScope($user))->key())
            ->whereDate('insight_date', now()->toDateString())
            ->first();
    }

    /** Generate today's insights for the user's scope (or return the existing ones). */
    public function generate(User $user, bool $refresh = false): AiInsight
    {
        $scope = new AiScope($user);
        $key = $scope->key();
        $today = now()->toDateString();

        // One generation per scope at a time: a second tab waits for the first.
        // Scope keys repeat across tenants, so the lock carries the tenant id.
        return Cache::lock('ai-insights-'.(tenant('id') ?? 'central').'-'.$key, 120)->block(100, function () use ($scope, $key, $today, $refresh) {
            $existing = AiInsight::where('scope_key', $key)->whereDate('insight_date', $today)->first();
            if ($existing && ! $refresh) {
                return $existing;
            }

            $snapshot = $this->snapshot($scope);
            // Shared by everyone in the scope, so it follows the store's default language.
            $language = \Illuminate\Support\Facades\DB::table('settings')->whereNull('deleted_at')->value('default_language') ?: 'en';
            $response = $this->client->create([
                'maxTokens' => 4000,
                'system' => [['type' => 'text', 'text' => $this->instructions()]],
                'messages' => [[
                    'role' => 'user',
                    'content' => "Today is {$today}. Write the insights in the language with code \"{$language}\".\n\nBusiness data (JSON, base currency):\n\n".json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                ]],
                'outputConfig' => [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
                ],
            ]);

            if ($response->stopReason === 'refusal') {
                throw new AiException(__('messages.ai_refused'));
            }

            $content = $response->json();
            if (! is_array($content) || empty($content['insights'])) {
                throw new AiException(__('messages.ai_request_failed', ['code' => 'format']));
            }

            $usage = AiClient::usage($response);
            $attributes = [
                'content' => $content,
                'input_tokens' => $usage['input'],
                'output_tokens' => $usage['output'],
                'created_at' => now(),
            ];

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            return AiInsight::create($attributes + ['scope_key' => $key, 'insight_date' => $today]);
        });
    }

    private function snapshot(AiScope $scope): array
    {
        $tools = new AiDataTools($scope);
        $available = $tools->available();
        $call = fn (string $name, array $input) => in_array($name, $available, true) ? json_decode($tools->run($name, $input), true) : null;

        $d = fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString();

        return array_filter([
            'last_7_days' => $call('business_overview', ['from' => $d(6), 'to' => $d(0)]),
            'previous_7_days' => $call('business_overview', ['from' => $d(13), 'to' => $d(7)]),
            'daily_sales_last_14_days' => $call('sales_breakdown', ['from' => $d(13), 'to' => $d(0), 'group_by' => 'day']),
            'sales_by_warehouse_last_7_days' => count($scope->warehouseIds) > 1
                ? $call('sales_breakdown', ['from' => $d(6), 'to' => $d(0), 'group_by' => 'warehouse'])
                : null,
            'top_products_last_7_days' => $call('top_products', ['from' => $d(6), 'to' => $d(0), 'rank_by' => 'revenue', 'limit' => 5]),
            'low_stock' => $call('stock_levels', ['only' => 'low', 'limit' => 10]),
            'largest_customer_balances' => $call('customer_balances', ['limit' => 5]),
        ]);
    }

    private function instructions(): string
    {
        return <<<'TXT'
You write the short "AI insights" panel on a shop owner's dashboard in Stocky (inventory, POS and accounting software). From the business data provided, pick the 3 to 5 most useful observations for today: notable changes between the last 7 days and the previous 7, standout products, stock that needs reordering, money owed, or anything unusual. Prefer concrete, actionable points with the key figure in each.

Rules:
- Use only the figures in the data. Don't speculate about causes you can't see.
- If there is little or no activity, say so plainly in one insight rather than inventing trends.
- Profit figures are estimates; call them "estimated".
- Each title is at most 8 words; each detail is one or two sentences with the numbers.
- tone: "positive" for good news, "warning" for something needing attention, "neutral" otherwise.
- headline: one sentence summing up the week.
TXT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string'],
                'insights' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'tone' => ['type' => 'string', 'enum' => ['positive', 'warning', 'neutral']],
                        ],
                        'required' => ['title', 'detail', 'tone'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['headline', 'insights'],
            'additionalProperties' => false,
        ];
    }
}
