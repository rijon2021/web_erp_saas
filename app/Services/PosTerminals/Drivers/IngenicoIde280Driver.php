<?php

namespace App\Services\PosTerminals\Drivers;

use App\Models\PosTerminal;
use App\Services\PosTerminals\TerminalOperation as Op;

/**
 * Ingenico IDE280 — Turkish new-generation fiscal cash register (YN ÖKC)
 * with a built-in card reader.
 *
 * The device itself is driven by Ingenico's ECR / GMP3 integration SDK
 * (paired with the cashier PC over USB or Ethernet), or by the bank /
 * provider's cloud API where one is offered. Stocky speaks the JSON
 * protocol of JsonTerminalDriver to either:
 *   - bridge: a small program on the cashier PC that wraps the SDK and
 *     listens on localhost (default http://127.0.0.1:8085);
 *   - cloud:  the provider's REST API, called from the server.
 * Amounts go out both as decimal and as kuruş (amount_minor), currency as
 * TRY / 949. In full_receipt mode the items travel with the sale, each with
 * the device department its VAT rate (KDV) is registered under.
 */
class IngenicoIde280Driver extends JsonTerminalDriver
{
    public static function meta(): array
    {
        return [
            'label' => 'Ingenico IDE280',
            'brand' => 'Ingenico',
            'model' => 'IDE280',
            'country' => 'TR',
            'description' => 'Fiscal cash register (YN ÖKC) with card reader — GMP3 / ECR integration',
            'modes' => [PosTerminal::MODE_BRIDGE, PosTerminal::MODE_CLOUD],
            'capabilities' => ['sale', 'refund', 'void', 'status', 'cancel', 'fiscal_receipt', 'installments'],
            'defaults' => [
                'bridge_url' => 'http://127.0.0.1:8085',
                'currency' => 'TRY',
                'timeout_seconds' => 120,
                'max_installments' => 12,
                'endpoints' => self::DEFAULT_ENDPOINTS,
                // KDV rate => device department number (as programmed on the IDE280)
                'departments' => [
                    ['vat_rate' => 20, 'department' => 1],
                    ['vat_rate' => 10, 'department' => 2],
                    ['vat_rate' => 1, 'department' => 3],
                    ['vat_rate' => 0, 'department' => 4],
                ],
            ],
        ];
    }

    protected function body(PosTerminal $terminal, Op $op): array
    {
        $body = parent::body($terminal, $op);
        $body['protocol'] = 'gmp3';

        return $body;
    }
}
