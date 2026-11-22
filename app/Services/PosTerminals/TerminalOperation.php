<?php

namespace App\Services\PosTerminals;

use App\Models\PosTerminalTransaction;

/**
 * What the gateway asks a device to do. `transaction` is the row being
 * processed (null for ping); `original` is the sale a refund / void reverses.
 */
final class TerminalOperation
{
    public const PING = 'ping';
    public const SALE = 'sale';
    public const REFUND = 'refund';
    public const VOID = 'void';
    public const STATUS = 'status';
    public const CANCEL = 'cancel';

    public const ALL = [self::PING, self::SALE, self::REFUND, self::VOID, self::STATUS, self::CANCEL];

    /**
     * @param  array  $receipt  full_receipt mode only: ['items' => [...], 'total' => float, ...]
     */
    public function __construct(
        public string $name,
        public ?PosTerminalTransaction $transaction = null,
        public ?PosTerminalTransaction $original = null,
        public array $receipt = [],
    ) {
    }

    public static function make(string $name, ?PosTerminalTransaction $txn = null, ?PosTerminalTransaction $original = null, array $receipt = []): self
    {
        return new self($name, $txn, $original, $receipt);
    }
}
