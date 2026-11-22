<?php

namespace App\Services\PosTerminals;

use App\Models\PosTerminalTransaction;

/**
 * A device answer in Stocky's terms. `status` is one of the
 * PosTerminalTransaction::STATUS_* values; `ok` is what ping reports.
 */
final class TerminalResult
{
    public function __construct(
        public string $status,
        public ?string $message = null,
        public ?string $providerReference = null,
        public ?string $authCode = null,
        public ?string $cardMask = null,
        public ?string $cardBrand = null,
        public ?string $responseCode = null,
        public array $raw = [],
        public bool $ok = false,
        public ?string $reference = null,
        // The operation the answer says it is about (bridge answers must echo it).
        public ?string $operation = null,
    ) {
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(PosTerminalTransaction::STATUS_FAILED, $message, raw: $raw);
    }

    /** No answer (network / timeout): the device may still be working on it. */
    public static function unknown(string $message): self
    {
        return new self(PosTerminalTransaction::STATUS_PENDING, $message);
    }
}
