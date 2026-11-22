<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row AI Assistant configuration (per tenant). The provider is Claude
 * (anthropic), ChatGPT (openai) or Gemini (gemini); each keeps its own
 * encrypted key and model (`api_key`/`model` are Claude's,
 * `openai_api_key`/`openai_model` ChatGPT's, `gemini_api_key`/`gemini_model`
 * Gemini's). Each tenant brings its own key — there is no platform fallback,
 * so one tenant's usage never bills the platform's account.
 */
class AiSetting extends Model
{
    public const PROVIDERS = ['anthropic', 'openai', 'gemini'];

    public const MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    /** Suggested ChatGPT models; any other OpenAI model id may be typed in. */
    public const OPENAI_MODELS = ['gpt-6-astra', 'gpt-6.1-sol', 'gpt-6.1-terra', 'gpt-6-luna'];

    public const OPENAI_DEFAULT_MODEL = 'gpt-6.1-sol';

    /** Suggested Gemini models; any other Gemini model id may be typed in. */
    public const GEMINI_MODELS = ['gemini-4-argon', 'gemini-3.8-flash', 'gemini-3.5-flash-lite'];

    public const GEMINI_DEFAULT_MODEL = 'gemini-3.8-flash';

    /** Per provider: key column, model column, default model. */
    private const COLUMNS = [
        'anthropic' => ['api_key', 'model', 'claude-opus-5-5'],
        'openai' => ['openai_api_key', 'openai_model', self::OPENAI_DEFAULT_MODEL],
        'gemini' => ['gemini_api_key', 'gemini_model', self::GEMINI_DEFAULT_MODEL],
    ];

    public const EFFORTS = ['low', 'medium', 'high'];

    protected $fillable = ['enabled', 'provider', 'model', 'openai_model', 'gemini_model', 'effort', 'insights_enabled', 'monthly_token_limit'];

    protected $hidden = ['api_key', 'openai_api_key', 'gemini_api_key'];

    protected $casts = [
        'enabled' => 'boolean',
        'insights_enabled' => 'boolean',
        'monthly_token_limit' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? new static([
            'enabled' => false,
            'provider' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'openai_model' => self::OPENAI_DEFAULT_MODEL,
            'gemini_model' => self::GEMINI_DEFAULT_MODEL,
            'effort' => 'medium',
            'insights_enabled' => true,
        ]);
    }

    /**
     * Providers whose columns exist: Claude always, ChatGPT and Gemini once
     * their migrations have run.
     */
    public static function availableProviders(): array
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'provider')) {
            return ['anthropic'];
        }

        return Schema::hasColumn('ai_settings', 'gemini_api_key') ? self::PROVIDERS : ['anthropic', 'openai'];
    }

    /** The selected provider (anthropic until the provider migration has run). */
    public function activeProvider(): string
    {
        return in_array($this->provider, self::PROVIDERS, true) ? $this->provider : 'anthropic';
    }

    /** The model of the active provider. */
    public function activeModel(): string
    {
        return $this->modelFor($this->activeProvider());
    }

    public function modelFor(string $provider): string
    {
        [, $column, $default] = self::COLUMNS[$provider] ?? self::COLUMNS['anthropic'];

        return $this->{$column} ?: $default;
    }

    /** The active provider's stored key (no environment fallback in the SaaS). */
    public function apiKey(): ?string
    {
        return $this->keyFor($this->activeProvider());
    }

    public function keyFor(string $provider): ?string
    {
        $stored = $this->{self::keyColumn($provider)};
        if ($stored) {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable $e) {
                return null; // APP_KEY changed — the key must be entered again
            }
        }

        return null;
    }

    public function setKeyFor(string $provider, ?string $plain): void
    {
        $this->{self::keyColumn($provider)} = $plain ? Crypt::encryptString($plain) : null;
    }

    /** "sk-ant-…a1b2" for display; null when no key is set. */
    public function maskedKeyFor(string $provider): ?string
    {
        $key = $this->keyFor($provider);

        return $key ? substr($key, 0, 7).'…'.substr($key, -4) : null;
    }

    public function isReady(): bool
    {
        return $this->enabled && (bool) $this->apiKey();
    }

    private static function keyColumn(string $provider): string
    {
        return (self::COLUMNS[$provider] ?? self::COLUMNS['anthropic'])[0];
    }
}
