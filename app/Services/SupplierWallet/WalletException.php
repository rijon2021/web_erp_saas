<?php

namespace App\Services\SupplierWallet;

use RuntimeException;

/**
 * Business refusal (frozen wallet, insufficient balance, bad amount, feature
 * off). `code()` is a stable slug the Vue pages map to a translation key;
 * the message is the English fallback.
 */
class WalletException extends RuntimeException
{
    public function __construct(string $message, protected string $slug = 'wallet_error', protected array $context = [])
    {
        parent::__construct($message, 422);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function toResponse()
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->slug,
        ] + $this->context, 422);
    }
}
