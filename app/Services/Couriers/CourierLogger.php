<?php

namespace App\Services\Couriers;

use App\Models\CourierAccount;
use App\Models\CourierLog;
use App\Services\Couriers\Data\ConnectionResult;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Courier → Logs. Every HTTP call a driver makes passes through middleware()
 * (attached in AbstractCourierDriver::http()), so nothing has to be logged by
 * hand; during() tells it what the call is for (book, track, label…) and
 * which shipment, and records driver errors that never reached the courier.
 *
 * Secrets never reach the table: values of the account's own credentials are
 * blanked wherever they appear, and fields named like keys, tokens, secrets,
 * passwords or signatures are masked in JSON, form and query strings. Request
 * headers are not stored. Bodies are cut at 6,000 characters; binary
 * answers (labels) are stored as a size note.
 *
 * Scheduled status checks run "quiet": only failures are kept, so a busy
 * workspace does not fill the table with routine polls. Logging can never
 * break a courier call — every failure here is swallowed.
 */
final class CourierLogger
{
    public const KEEP_DAYS = 30;
    private const MAX_BODY = 6000;
    private const MASK = '••••';
    private const SECRET_KEYS = '/(pass(word|wd)?|secret|token|api[_-]?key|apikey|private[_-]?key|signature|^sign$|authorization|^pin$|account[_-]?pin|credential|client[_-]?secret|refresh)/i';

    /** @var array<int, array{account: CourierAccount, action: string, shipment_id: ?int, quiet: bool}> */
    private static array $stack = [];

    /** @var array<string, bool> */
    private static array $tableReady = [];

    /**
     * Run $fn as one logged courier operation.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public static function during(CourierAccount $account, string $action, ?int $shipmentId, callable $fn, bool $quiet = false)
    {
        self::$stack[] = ['account' => $account, 'action' => $action, 'shipment_id' => $shipmentId, 'quiet' => $quiet];
        try {
            $result = $fn();
            if ($result instanceof ConnectionResult && ! $result->ok) {
                self::write($account, $action, $shipmentId, ['level' => 'error', 'message' => $result->message]);
            }

            return $result;
        } catch (CourierException $e) {
            self::write($account, $action, $shipmentId, ['level' => 'error', 'message' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            // Timeouts, unexpected answers (TypeErrors) and the like become a readable
            // courier error instead of a 500. The message may hold the request URL with
            // query credentials, so it is masked before it is logged or shown.
            $network = $e instanceof \Illuminate\Http\Client\ConnectionException;
            if (! $network) {
                report($e); // a code-level surprise worth a stack trace; timeouts are not
            }
            $message = self::mask(($network ? 'Could not reach the courier: ' : 'Courier error: ') . $e->getMessage(), $account);
            self::write($account, $action, $shipmentId, ['level' => 'error', 'message' => $message]);
            throw new CourierException(mb_substr($message, 0, 400), 0, $e);
        } finally {
            array_pop(self::$stack);
        }
    }

    /** Guzzle middleware for one account's driver. */
    public static function middleware(CourierAccount $account): callable
    {
        return function (callable $handler) use ($account) {
            return function (RequestInterface $request, array $options) use ($handler, $account) {
                $started = microtime(true);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $account, $started) {
                        self::fromHttp($account, $request, $response, null, $started);

                        return $response;
                    },
                    function ($reason) use ($request, $account, $started) {
                        self::fromHttp($account, $request, null, $reason, $started);

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /** A webhook a courier sent (accepted or rejected). */
    public static function incoming(?CourierAccount $account, Request $request, int $status, string $message, ?int $shipmentId = null, string $level = 'info'): void
    {
        if (! $account) {
            return;
        }
        $body = (string) $request->getContent();
        self::write($account, 'webhook', $shipmentId, [
            'direction' => 'in',
            'level' => $level,
            'method' => $request->method(),
            'url' => mb_substr('/' . ltrim(preg_replace('#/couriers/webhook/[^/?]+#', '/couriers/webhook/{token}', $request->path()), '/'), 0, 500),
            'http_status' => $status,
            'message' => $message,
            'request' => self::body($body, (string) $request->header('Content-Type'), $account),
        ]);
    }

    /**
     * Free text (error messages) with secrets removed: query-string values of
     * secret-named keys and every credential value of the account.
     */
    public static function mask(string $text, ?CourierAccount $account = null): string
    {
        $text = preg_replace_callback('/([?&])([^=&\s#]+)=([^&\s#"\']*)/', function ($m) {
            return $m[1] . $m[2] . '=' . (preg_match(self::SECRET_KEYS, urldecode($m[2])) ? self::MASK : $m[3]);
        }, $text);

        return $account ? self::maskValues((string) $text, $account) : (string) $text;
    }

    /** Delete entries older than KEEP_DAYS (called by couriers:sync). */
    public static function prune(): int
    {
        if (! self::ready()) {
            return 0;
        }

        return CourierLog::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    // ------------------------------------------------------------------

    private static function fromHttp(CourierAccount $account, RequestInterface $request, ?ResponseInterface $response, $reason, float $started): void
    {
        try {
            $ctx = self::context($account);
            $status = $response?->getStatusCode();
            $failed = ! $response || $status >= 400;
            if ($ctx['quiet'] && ! $failed) {
                return;
            }

            self::write($account, $ctx['action'], $ctx['shipment_id'], [
                'level' => $failed ? 'error' : 'info',
                'method' => $request->getMethod(),
                'url' => self::url($request, $account),
                'http_status' => $status,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'message' => $response ? self::summary($response) : ('No response: ' . self::reason($reason)),
                'request' => self::body(self::read($request), $request->getHeaderLine('Content-Type'), $account),
                'response' => $response ? self::body(self::read($response), $response->getHeaderLine('Content-Type'), $account) : null,
            ]);
        } catch (\Throwable $e) {
            // Never let logging break a courier call.
        }
    }

    private static function write(CourierAccount $account, string $action, ?int $shipmentId, array $row): void
    {
        try {
            if (! self::ready()) {
                return;
            }
            if (isset($row['message'])) {
                $row['message'] = mb_substr(self::mask((string) $row['message'], $account), 0, 500);
            }
            CourierLog::query()->create($row + [
                'courier_account_id' => $account->id,
                'shipment_id' => $shipmentId,
                'courier' => $account->courier,
                'action' => in_array($action, CourierLog::ACTIONS, true) ? $action : 'other',
                'direction' => 'out',
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let logging break a courier call.
        }
    }

    /** The innermost during() for this account, else a generic entry. */
    private static function context(CourierAccount $account): array
    {
        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if (self::$stack[$i]['account'] === $account || (self::$stack[$i]['account']->id && self::$stack[$i]['account']->id === $account->id)) {
                return self::$stack[$i];
            }
        }

        return ['account' => $account, 'action' => 'other', 'shipment_id' => null, 'quiet' => false];
    }

    /** Only "yes" is remembered, so a tenant that migrates is seen at once. */
    private static function ready(): bool
    {
        $key = function_exists('tenant') && tenant() ? (string) tenant('id') : 'central';
        if (! empty(self::$tableReady[$key])) {
            return true;
        }

        return self::$tableReady[$key] = Schema::hasTable('courier_logs');
    }

    private static function read(MessageInterface $message): string
    {
        $stream = $message->getBody();
        $content = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $content;
    }

    /** Scheme, host and path; query values masked by key and by credential value. */
    private static function url(RequestInterface $request, CourierAccount $account): string
    {
        $uri = $request->getUri();
        $base = $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath();
        $query = $uri->getQuery();
        if ($query !== '') {
            parse_str($query, $params);
            $base .= '?' . urldecode(http_build_query(self::maskArray($params)));
        }

        return mb_substr(self::maskValues($base, $account), 0, 500);
    }

    /** Masked, truncated body text; binary content as a size note. */
    private static function body(string $raw, string $contentType, CourierAccount $account): ?string
    {
        if ($raw === '') {
            return null;
        }
        $type = strtolower($contentType);
        if (str_contains($type, 'pdf') || str_starts_with($type, 'image/') || str_contains($type, 'octet-stream')
            || str_starts_with($raw, '%PDF') || ! mb_check_encoding($raw, 'UTF-8')) {
            return '[binary ' . strlen($raw) . ' bytes' . ($contentType !== '' ? ', ' . $contentType : '') . ']';
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            $text = json_encode(self::maskArray($json), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } elseif (str_contains($type, 'x-www-form-urlencoded') || (preg_match('/^[\w.\[\]%-]+=[^&]*(&[\w.\[\]%-]+=[^&]*)*$/', $raw) && ! str_contains($raw, ' '))) {
            parse_str($raw, $params);
            $text = urldecode(http_build_query(self::maskArray($params)));
        } else {
            // XML / text: mask <Password>…</Password>-style elements.
            $text = preg_replace_callback('#<([\w:]*?(?:pass(?:word)?|secret|token|key|pin))>(.*?)</\1>#is', fn ($m) => "<{$m[1]}>" . self::MASK . "</{$m[1]}>", $raw);
        }

        $text = self::maskValues((string) $text, $account);

        return mb_strlen($text) > self::MAX_BODY ? mb_substr($text, 0, self::MAX_BODY) . "\n… [cut, " . mb_strlen($text) . ' characters]' : $text;
    }

    private static function maskArray(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k) && ! is_array($v) && $v !== null && $v !== '') {
                $data[$k] = self::MASK;
            } elseif (is_array($v)) {
                $data[$k] = self::maskArray($v);
            } elseif (is_string($v) && strlen($v) > 2000 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $v)) {
                $data[$k] = '[base64 ' . strlen($v) . ' characters]'; // embedded labels
            }
        }

        return $data;
    }

    /** Blank every credential value of the account wherever it appears. */
    private static function maskValues(string $text, CourierAccount $account): string
    {
        $values = collect((array) ($account->credentials ?? []))
            ->filter(fn ($v) => is_string($v) && mb_strlen(trim($v)) >= 4)
            ->flatMap(fn ($v) => [$v, rawurlencode($v), urlencode($v), json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ? trim(json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"') : $v])
            ->filter()->unique()->sortByDesc(fn ($v) => strlen($v))->values()->all();

        return $values ? str_replace($values, self::MASK, $text) : $text;
    }

    private static function summary(ResponseInterface $response): string
    {
        $status = $response->getStatusCode();

        return 'HTTP ' . $status . ' ' . $response->getReasonPhrase();
    }

    private static function reason($reason): string
    {
        return $reason instanceof \Throwable ? mb_substr($reason->getMessage(), 0, 300) : 'request failed';
    }
}
