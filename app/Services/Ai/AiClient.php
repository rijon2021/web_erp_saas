<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\AiSetting;
use Illuminate\Support\Facades\DB;

/**
 * The store's AI provider — Claude (official Anthropic PHP SDK), ChatGPT
 * (OpenAiDriver) or Gemini (GeminiDriver) — with its settings (key, model,
 * effort) and the cross-cutting rules:
 *  - server-side refusal fallback ("default" routing) where Claude has one;
 *  - top-level prompt caching (tools + system + history are a stable prefix;
 *    OpenAI and Gemini cache prefixes automatically);
 *  - the optional monthly token cap.
 *
 * Callers always build Claude-shaped requests (the SDK's camelCase named
 * arguments) and get an AiResponse back; the other drivers translate both ways.
 */
class AiClient
{
    public function __construct(private AiSetting $settings)
    {
    }

    public static function make(): self
    {
        return new self(AiSetting::current());
    }

    public function settings(): AiSetting
    {
        return $this->settings;
    }

    /**
     * One model request. $args are the Anthropic SDK's camelCase named
     * arguments; fields the active provider has no use for are ignored.
     *
     * @throws AiException with a message safe to show the user
     */
    public function create(array $args): AiResponse
    {
        if (! $this->settings->apiKey()) {
            throw new AiException(__('messages.ai_not_configured'));
        }
        $this->guardBudget();

        $effort = $this->settings->effort ?: 'medium';

        return match ($this->settings->activeProvider()) {
            'openai' => (new OpenAiDriver($this->settings->apiKey(), $this->settings->activeModel(), $effort))->create($args),
            'gemini' => (new GeminiDriver($this->settings->apiKey(), $this->settings->activeModel(), $effort))->create($args),
            default => $this->anthropic($args),
        };
    }

    /** POST /v1/messages (beta namespace, for the fallbacks parameter). */
    private function anthropic(array $args): AiResponse
    {
        $model = $this->settings->activeModel();
        $args += [
            'model' => $model,
            'maxTokens' => 16000,
            'cacheControl' => ['type' => 'ephemeral'],
        ];
        // Effort: merge into any outputConfig the caller set (e.g. a JSON format).
        $args['outputConfig'] = ($args['outputConfig'] ?? []) + ['effort' => $this->settings->effort ?: 'medium'];
        $args['messages'] = self::anthropicMessages($args['messages'] ?? []);

        // Refusal fallback: Haiku 5.5 has no server-side fallback.
        if ($model !== 'claude-haiku-5-5') {
            $args['fallbacks'] = 'default';
            $args['betas'] = array_values(array_unique(array_merge($args['betas'] ?? [], ['server-side-fallback-2026-07-01'])));
        }

        try {
            $client = new Client(apiKey: $this->settings->apiKey());

            return AiResponse::fromAnthropic($client->beta->messages->create(...$args));
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new AiException(__('messages.ai_invalid_key', ['provider' => 'Claude']), previous: $e);
        } catch (RateLimitException $e) {
            throw new AiException(__('messages.ai_rate_limited'), previous: $e);
        } catch (APIConnectionException $e) {
            throw new AiException(__('messages.ai_unreachable'), previous: $e);
        } catch (APIStatusException $e) {
            report($e);
            // A 400 is not transient (low credit balance, model not enabled
            // for the key, …) — show Anthropic's own reason instead of "try again".
            $detail = is_array($e->body) ? ($e->body['error']['message'] ?? null) : null;
            if ($e->status === 400 && is_string($detail) && $detail !== '') {
                throw new AiException(__('messages.ai_request_rejected', ['code' => 400, 'detail' => mb_substr($detail, 0, 300)]), previous: $e);
            }
            throw new AiException(__('messages.ai_request_failed', ['code' => $e->status ?: 'API']), previous: $e);
        } catch (\Anthropic\Core\Exceptions\AnthropicException|\JsonException $e) {
            // Anything else from the SDK (unexpected response shape, …).
            report($e);
            throw new AiException(__('messages.ai_request_failed', ['code' => 'SDK']), previous: $e);
        }
    }

    /**
     * Messages as Claude accepts them: ChatGPT's reasoning and Gemini's raw
     * parts (from turns answered by those providers) are dropped, as is any message
     * left empty; an empty tool input {} that a JSON round-trip turned into []
     * becomes an object again; ChatGPT's tool-call ids are made to fit
     * Claude's id pattern (the same way on the call and its result).
     */
    private static function anthropicMessages(array $messages): array
    {
        $toolId = fn ($id) => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $id) ?: 'call';

        $out = [];
        foreach ($messages as $message) {
            if (is_array($message['content'] ?? null)) {
                $blocks = [];
                foreach ($message['content'] as $block) {
                    $type = $block['type'] ?? null;
                    if (in_array($type, ['openai_reasoning', 'gemini_parts'], true)) {
                        continue;
                    }
                    if ($type === 'tool_use') {
                        $block['id'] = $toolId($block['id'] ?? '');
                        if (empty($block['input'])) {
                            $block['input'] = new \stdClass;
                        }
                    } elseif ($type === 'tool_result') {
                        $block['tool_use_id'] = $toolId($block['tool_use_id'] ?? '');
                    }
                    $blocks[] = $block;
                }
                if (! $blocks) {
                    continue;
                }
                $message['content'] = $blocks;
            }
            $out[] = $message;
        }

        return $out;
    }

    /** Tokens used this calendar month across chat and insights. */
    public static function monthTokens(): int
    {
        $since = now()->startOfMonth();

        $total = 0;
        foreach (['ai_messages', 'ai_insights', 'ai_usage'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $total += (int) DB::table($table)->where('created_at', '>=', $since)->sum(DB::raw('input_tokens + output_tokens'));
            }
        }

        return $total;
    }

    /** Record a one-off feature's usage (counts toward the monthly limit). */
    public static function logUsage(string $feature, AiResponse $response, ?int $userId = null): void
    {
        DB::table('ai_usage')->insert([
            'user_id' => $userId,
            'feature' => $feature,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'created_at' => now(),
        ]);
    }

    private function guardBudget(): void
    {
        $limit = $this->settings->monthly_token_limit;
        if ($limit && self::monthTokens() >= $limit) {
            throw new AiException(__('messages.ai_budget_reached'));
        }
    }

    /** Total tokens billed for one response. */
    public static function usage(AiResponse $response): array
    {
        return ['input' => $response->inputTokens, 'output' => $response->outputTokens];
    }
}
