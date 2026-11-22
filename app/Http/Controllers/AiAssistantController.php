<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\Setting;
use App\Services\Ai\AiAssistant;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiInsights;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant: chat over business data (perm ai_assistant), daily dashboard
 * insights (same perm), and the AI provider settings (perm ai_settings).
 * Conversations are private to the user who started them.
 */
class AiAssistantController extends Controller
{
    /* ----------------------------------------------------------- status */

    /** What the UI needs to decide what to show. */
    public function status(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);

        $settings = AiSetting::current();

        return response()->json([
            'ready' => $settings->isReady(),
            'enabled' => (bool) $settings->enabled,
            'has_key' => (bool) $settings->apiKey(),
            'provider' => $settings->activeProvider(),
            'insights_enabled' => $settings->isReady() && $settings->insights_enabled,
            'can_configure' => $user->can('ai_settings', Setting::class),
        ]);
    }

    /* ----------------------------------------------------- conversations */

    public function conversations(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);

        return response()->json([
            'conversations' => AiConversation::where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get(['id', 'title', 'updated_at']),
        ]);
    }

    public function show(Request $request, $id)
    {
        $conversation = $this->own($request, $id);

        return response()->json([
            'conversation' => $conversation->only(['id', 'title', 'updated_at']),
            'messages' => AiMessage::where('conversation_id', $conversation->id)->orderBy('id')
                ->get(['id', 'question', 'answer', 'tools_used', 'error', 'created_at']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $conversation = $this->own($request, $id);
        AiMessage::where('conversation_id', $conversation->id)->delete();
        $conversation->delete();

        return response()->json(['success' => true]);
    }

    /** Ask a question; starts a conversation when conversation_id is empty. */
    public function ask(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);

        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'question' => 'required|string|max:4000',
        ]);

        if (! AiSetting::current()->isReady()) {
            return response()->json(['message' => __('messages.ai_not_configured')], 422);
        }

        $conversation = ! empty($data['conversation_id'])
            ? $this->own($request, $data['conversation_id'])
            : AiConversation::create(['user_id' => $user->id]);

        // A tool loop can take a while; don't let max_execution_time cut it.
        @set_time_limit(300);

        $message = (new AiAssistant(AiClient::make()))->ask($conversation, $user, trim($data['question']));

        return response()->json([
            'conversation' => $conversation->fresh()->only(['id', 'title', 'updated_at']),
            'message' => $message->only(['id', 'question', 'answer', 'tools_used', 'error', 'created_at']),
        ]);
    }

    /* ---------------------------------------------------------- insights */

    public function insights(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);

        $settings = AiSetting::current();
        if (! $settings->isReady() || ! $settings->insights_enabled) {
            return response()->json(['enabled' => false]);
        }

        $insight = (new AiInsights(AiClient::make()))->today($user);

        return response()->json([
            'enabled' => true,
            'insight' => $insight ? $this->insightPayload($insight) : null,
            // A recent failure: the card shows it instead of auto-retrying a paid call.
            'error' => $insight ? null : Cache::get($this->insightFailKey($user)),
        ]);
    }

    private function insightFailKey($user): string
    {
        return 'ai-insights-failed-'.(tenant('id') ?? 'central').'-'.(new \App\Services\Ai\AiScope($user))->key();
    }

    public function generateInsights(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);

        $settings = AiSetting::current();
        if (! $settings->isReady() || ! $settings->insights_enabled) {
            return response()->json(['message' => __('messages.ai_not_configured')], 422);
        }

        @set_time_limit(180);

        try {
            $insight = (new AiInsights(AiClient::make()))->generate($user, $request->boolean('refresh'));
        } catch (AiException $e) {
            Cache::put($this->insightFailKey($user), $e->getMessage(), now()->addMinutes(15));

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json(['message' => __('messages.ai_busy')], 429);
        }
        Cache::forget($this->insightFailKey($user));

        return response()->json(['enabled' => true, 'insight' => $this->insightPayload($insight)]);
    }

    private function insightPayload($insight): array
    {
        return [
            'headline' => $insight->content['headline'] ?? '',
            'insights' => $insight->content['insights'] ?? [],
            'generated_at' => optional($insight->created_at)->toIso8601String(),
        ];
    }

    /* ---------------------------------------------------------- settings */

    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'ai_settings', Setting::class);

        $s = AiSetting::current();
        // ChatGPT and Gemini can only be chosen once their migrations have run.
        $providers = AiSetting::availableProviders();

        return response()->json([
            'enabled' => (bool) $s->enabled,
            'provider' => $s->activeProvider(),
            'providers' => $providers,
            'masked_key' => $s->maskedKeyFor('anthropic'),
            'openai_masked_key' => in_array('openai', $providers, true) ? $s->maskedKeyFor('openai') : null,
            'gemini_masked_key' => in_array('gemini', $providers, true) ? $s->maskedKeyFor('gemini') : null,
            'model' => $s->model,
            'openai_model' => $s->modelFor('openai'),
            'gemini_model' => $s->modelFor('gemini'),
            'effort' => $s->effort,
            'insights_enabled' => (bool) $s->insights_enabled,
            'monthly_token_limit' => $s->monthly_token_limit,
            'month_tokens' => Schema::hasTable('ai_messages') ? AiClient::monthTokens() : 0,
            'models' => AiSetting::MODELS,
            'openai_models' => AiSetting::OPENAI_MODELS,
            'gemini_models' => AiSetting::GEMINI_MODELS,
            'efforts' => AiSetting::EFFORTS,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'ai_settings', Setting::class);

        $modelId = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'];
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'provider' => 'nullable|in:'.implode(',', AiSetting::PROVIDERS),
            'api_key' => 'nullable|string|max:500',
            'clear_key' => 'nullable|boolean',
            'openai_api_key' => 'nullable|string|max:500',
            'clear_openai_key' => 'nullable|boolean',
            'gemini_api_key' => 'nullable|string|max:500',
            'clear_gemini_key' => 'nullable|boolean',
            'model' => 'required|in:'.implode(',', AiSetting::MODELS),
            // Any ChatGPT / Gemini model id, not only the suggested ones. Only
            // checked (and saved) for the selected provider, so a half-typed id
            // on a hidden field can't block saving the others.
            'openai_model' => array_merge(['exclude_unless:provider,openai'], $modelId),
            'gemini_model' => array_merge(['exclude_unless:provider,gemini'], $modelId),
            'effort' => 'required|in:'.implode(',', AiSetting::EFFORTS),
            'insights_enabled' => 'required|boolean',
            'monthly_token_limit' => 'nullable|integer|min:1000',
        ]);

        $provider = $data['provider'] ?? 'anthropic';
        $available = AiSetting::availableProviders();
        if (! in_array($provider, $available, true)) {
            return response()->json(['success' => false, 'message' => __('messages.ai_provider_not_ready')], 422);
        }

        $s = AiSetting::query()->first() ?? new AiSetting;
        $s->fill([
            'enabled' => $data['enabled'],
            'model' => $data['model'],
            'effort' => $data['effort'],
            'insights_enabled' => $data['insights_enabled'],
            'monthly_token_limit' => $data['monthly_token_limit'] ?? null,
        ]);
        if (in_array('openai', $available, true)) {
            $s->provider = $provider;
        }
        if (in_array($provider, ['openai', 'gemini'], true)) {
            $s->{$provider.'_model'} = trim((string) ($data[$provider.'_model'] ?? '')) ?: $s->modelFor($provider);
        }

        // Keys are write-only: blank keeps the stored one. Claude's fields keep
        // their original names (api_key / clear_key).
        foreach ($available as $name) {
            $field = $name === 'anthropic' ? 'api_key' : $name.'_api_key';
            $clear = $name === 'anthropic' ? 'clear_key' : 'clear_'.$name.'_key';
            if (! empty($data[$clear])) {
                $s->setKeyFor($name, null);
            } elseif (! empty($data[$field])) {
                $s->setKeyFor($name, trim($data[$field]));
            }
        }
        $s->save();

        return response()->json(['success' => true]);
    }

    /** One tiny request to confirm the key and model work. */
    public function test(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'ai_settings', Setting::class);

        try {
            $response = AiClient::make()->create([
                'maxTokens' => 1000,
                'outputConfig' => ['effort' => 'low'],
                'messages' => [['role' => 'user', 'content' => 'Reply with the single word: OK']],
            ]);
        } catch (AiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'model' => $response->model]);
    }

    /* ----------------------------------------------------------- helpers */

    private function own(Request $request, $id): AiConversation
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);

        return AiConversation::where('user_id', $user->id)->findOrFail((int) $id);
    }
}
