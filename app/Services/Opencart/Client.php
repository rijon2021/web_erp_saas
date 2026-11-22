<?php

namespace App\Services\Opencart;

use App\Models\OpencartSetting;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the connector extension installed on the store.
 *
 * OpenCart's own API has no catalogue or stock calls and limits callers by IP,
 * so everything goes through the connector (resources/connectors/opencart):
 * one POST route, the action in the query string, a JSON body, and an
 * HMAC-SHA256 signature over the action, the time and the body. The webhook
 * the connector sends back is signed the same way, with the action "webhook".
 */
class Client
{
    /** Where the connector answers on OpenCart 4 and on OpenCart 3. */
    private const ROUTES = [
        4 => 'extension/pos_connector/api',
        3 => 'extension/module/pos_connector/api',
    ];

    public function __construct(private OpencartSetting $settings)
    {
    }

    public static function make(?OpencartSetting $settings = null): self
    {
        return new self($settings ?: OpencartSetting::current());
    }

    /** @return array the decoded response, always with ok=true */
    public function call(string $action, array $payload = [], int $timeout = 30): array
    {
        $base = rtrim((string) $this->settings->store_url, '/');
        $secret = (string) $this->settings->secret;
        if ($base === '' || $secret === '') {
            throw new RuntimeException('OpenCart is not configured. Add the store URL and connector secret first.');
        }

        $problem = self::urlProblem($base);
        if ($problem) {
            throw new RuntimeException($problem);
        }

        $body = (string) json_encode($payload ?: new \stdClass, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $timestamp = (string) time();

        // The route differs between OpenCart 4 and 3. The version the store
        // reported last time goes first; a store that does not answer as the
        // connector there is tried on the other route before giving up.
        $json = null;
        $status = 0;
        foreach ($this->routes() as $route) {
            try {
                $response = Http::withHeaders([
                    'X-Connector-Timestamp' => $timestamp,
                    'X-Connector-Signature' => self::signature($secret, $action, $timestamp, $body),
                ])
                    ->acceptJson()
                    ->withBody($body, 'application/json')
                    // A redirect would be followed as a GET without the body;
                    // the right address is reported instead.
                    ->withOptions(['allow_redirects' => false])
                    ->connectTimeout(10)
                    ->timeout($timeout)
                    ->post($base.'/index.php?'.http_build_query(['route' => $route, 'action' => $action]));
            } catch (ConnectionException|TransferException $e) {
                throw new RuntimeException('Could not reach the store: '.$e->getMessage());
            }

            if ($response->redirect()) {
                $target = (string) $response->header('Location');
                $parts = parse_url($target) ?: [];
                $host = isset($parts['scheme'], $parts['host'])
                    ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                    : $target;

                throw new RuntimeException('The store redirects to '.$host.'. Use that address as the store URL.');
            }

            $json = $response->json();
            $status = $response->status();
            if (is_array($json) && array_key_exists('ok', $json)) {
                break;
            }
        }

        // Anything that is not the connector's JSON: wrong address, or the
        // module is not installed and OpenCart answered with a page.
        if (! is_array($json) || ! array_key_exists('ok', $json)) {
            throw new RuntimeException(OpencartSetting::connectorName().' did not answer at this address (HTTP '.$status.'). Check the store URL and that the module is installed.');
        }

        if (empty($json['ok'])) {
            throw new RuntimeException(self::errorMessage($json, $response->status()));
        }

        return $json;
    }

    /** @return string[] the 3.x route first when the store said it was 3.x */
    private function routes(): array
    {
        $routes = self::ROUTES;
        if (str_starts_with((string) $this->settings->opencart_version, '3')) {
            $routes = array_reverse($routes, true);
        }

        return array_values($routes);
    }

    /** The connector's signature; also what an incoming webhook must carry. */
    public static function signature(string $secret, string $action, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', "POST\n{$action}\n{$timestamp}\n".hash('sha256', $body), $secret);
    }

    /**
     * Why this address must not be called, if so. Tenants choose the URL, so a
     * shared server must not be talked into calling its own network. Local
     * development legitimately runs the store on this machine.
     */
    public static function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The store URL must start with http:// or https://';
        }

        if (app()->environment('local', 'testing')) {
            return null;
        }

        $ips = @gethostbynamel($host) ?: [];
        if (! $ips) {
            return 'The store address could not be found: '.$host;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'The store URL points to a private address.';
            }
        }

        return null;
    }

    private static function errorMessage(array $json, int $status): string
    {
        return match ((string) ($json['code'] ?? '')) {
            'signature' => 'The secret does not match. Copy it again from the '.OpencartSetting::connectorName().' module in OpenCart.',
            'clock' => 'The store clock is too far from this server. Check the date and time on the OpenCart server.',
            'disabled' => 'The '.OpencartSetting::connectorName().' module is disabled in OpenCart.',
            default => (string) ($json['error'] ?? 'HTTP '.$status),
        };
    }
}
