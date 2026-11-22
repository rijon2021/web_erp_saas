<?php

namespace App\Services\PosTerminals;

/**
 * One HTTP call a driver wants made. `path` is appended to the cloud API
 * base URL or to the bridge URL; `body` is the JSON payload (null for GET).
 * Headers here are the driver's own — authentication is added by the
 * gateway so secrets never land in the stored request.
 */
final class TerminalHttpRequest
{
    public function __construct(
        public string $method,
        public string $path,
        public ?array $body = null,
        public array $headers = [],
    ) {
    }

    public function bodyJson(): string
    {
        return $this->body === null
            ? ''
            : json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function url(?string $base): string
    {
        return rtrim((string) $base, '/').'/'.ltrim($this->path, '/');
    }
}
