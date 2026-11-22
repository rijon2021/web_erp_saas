<?php

namespace App\Services\PosTerminals\Contracts;

use App\Models\PosTerminal;
use App\Services\PosTerminals\TerminalHttpRequest;
use App\Services\PosTerminals\TerminalOperation;
use App\Services\PosTerminals\TerminalResult;

/**
 * A POS device model. Drivers only translate: they build the HTTP request
 * for an operation and read the device's answer back. Sending it is the
 * gateway's job — from the server (cloud mode) or from the cashier's
 * browser to the local bridge (bridge mode) — so one driver serves both.
 */
interface TerminalDriver
{
    /**
     * Static description for the device form:
     *   label, brand, model, country, modes (cloud|bridge),
     *   capabilities (sale, refund, void, status, cancel, fiscal_receipt, installments),
     *   defaults (settings JSON a new device starts with).
     */
    public static function meta(): array;

    /** The request for one operation (ping, sale, refund, void, status, cancel). */
    public function buildRequest(PosTerminal $terminal, TerminalOperation $op): TerminalHttpRequest;

    /** The device / provider answer, normalised. Never throws on odd payloads. */
    public function parseResponse(PosTerminal $terminal, TerminalOperation $op, int $httpStatus, array $body): TerminalResult;
}
