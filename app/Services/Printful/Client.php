<?php

namespace App\Services\Printful;

use App\Models\PrintfulSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Printful API (v1, https://api.printful.com).
 * Auth is a private token as a Bearer header; an account-level token also
 * needs X-PF-Store-Id on store endpoints. Every answer is an envelope
 * {code, result, paging?} — call() returns it decoded, or throws a
 * PrintfulException carrying Printful's own error message.
 *
 * Printful allows about 120 calls a minute. A 429 is waited out (up to a
 * few seconds, twice) unless the caller is a shopper waiting at checkout.
 */
class Client
{
    public const BASE_URL = 'https://api.printful.com';

    private bool $retryRateLimit = true;

    private int $timeout = 45;

    public function __construct(private PrintfulSetting $settings)
    {
    }

    public static function make(?PrintfulSetting $settings = null): self
    {
        return new self($settings ?: PrintfulSetting::current());
    }

    /** Fail fast instead of waiting on rate limits (checkout rate quotes). */
    public function quick(int $timeout = 12): self
    {
        $clone = clone $this;
        $clone->retryRateLimit = false;
        $clone->timeout = $timeout;

        return $clone;
    }

    /** Same token, a specific store (or none) — used while picking a store. */
    public function forStore(?int $storeId): self
    {
        $settings = clone $this->settings;
        $settings->store_id = $storeId;

        return new self($settings);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $path, $query);
    }

    public function post(string $path, array $body = [], array $query = []): array
    {
        return $this->call('POST', $path, $query, $body);
    }

    public function delete(string $path): array
    {
        return $this->call('DELETE', $path);
    }

    /** @return array the decoded envelope: ['code', 'result', 'paging'?] */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = self::BASE_URL.'/'.ltrim($path, '/');
        if ($query) {
            $url .= '?'.http_build_query($query);
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $request = Http::withToken((string) $this->settings->api_token)
                    ->acceptJson()
                    ->timeout($this->timeout);
                if ($this->settings->store_id) {
                    $request = $request->withHeaders(['X-PF-Store-Id' => (string) $this->settings->store_id]);
                }
                $response = $body === null ? $request->send($method, $url) : $request->send($method, $url, ['json' => $body]);
            } catch (ConnectionException $e) {
                throw new PrintfulException('Printful could not be reached: '.$e->getMessage(), 0, true);
            }

            if ($response->status() === 429 && $this->retryRateLimit && $attempt < 2) {
                sleep($this->retryAfter($response));

                continue;
            }

            return $this->decode($response);
        }
    }

    private function decode(Response $response): array
    {
        $json = $response->json();
        $status = $response->status();

        if ($response->successful() && is_array($json)) {
            return $json;
        }

        $message = is_array($json) ? self::errorMessage($json) : '';
        if ($message === '') {
            $message = $status === 401 ? 'The Printful API token was rejected (expired, revoked or missing a scope).' : 'Printful answered HTTP '.$status;
        }

        throw new PrintfulException($message, $status, $status === 429 || $status >= 500);
    }

    /** {result: "text"} or {error: {message}} — whichever Printful filled. */
    public static function errorMessage(array $json): string
    {
        $error = $json['error'] ?? null;
        if (is_array($error) && ! empty($error['message'])) {
            return (string) $error['message'];
        }
        if (is_string($json['result'] ?? null)) {
            return $json['result'];
        }

        return '';
    }

    private function retryAfter(Response $response): int
    {
        $wait = (int) ($response->header('Retry-After') ?: $response->header('X-Ratelimit-Reset') ?: 5);

        return max(1, min(20, $wait));
    }
}
