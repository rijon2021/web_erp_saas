<?php

namespace App\Services\PosTerminals\Drivers;

use App\Models\PosTerminal;
use App\Models\PosTerminalTransaction as Txn;
use App\Services\PosTerminals\Contracts\TerminalDriver;
use App\Services\PosTerminals\TerminalHttpRequest;
use App\Services\PosTerminals\TerminalOperation as Op;
use App\Services\PosTerminals\TerminalResult;

/**
 * Shared JSON protocol for devices reached through a REST API or a local
 * bridge: one endpoint per operation (paths editable per device under
 * settings.endpoints), a JSON body carrying the amount / references, and a
 * tolerant reader for the answer (status words, ISO-8583 style response
 * codes, success booleans, fields at the top level or under `data`).
 *
 * A device model with a different wire format overrides body() and/or
 * parseResponse(); one that only differs in defaults overrides meta().
 */
abstract class JsonTerminalDriver implements TerminalDriver
{
    public const DEFAULT_ENDPOINTS = [
        Op::PING => 'GET /ping',
        Op::SALE => 'POST /sale',
        Op::REFUND => 'POST /refund',
        Op::VOID => 'POST /void',
        Op::STATUS => 'GET /transactions/{reference}',
        Op::CANCEL => 'POST /cancel',
    ];

    protected const APPROVED = ['approved', 'success', 'successful', 'succeeded', 'ok', 'completed', 'complete', 'paid', 'accepted', 'captured', 'done', 'onay', 'onaylandi'];
    protected const DECLINED = ['declined', 'rejected', 'denied', 'refused', 'not_approved', 'red', 'reddedildi'];
    protected const CANCELLED = ['cancelled', 'canceled', 'aborted', 'user_cancel', 'user_cancelled', 'voided', 'iptal'];
    protected const PENDING = ['pending', 'processing', 'waiting', 'in_progress', 'inprogress', 'started', 'queued', 'sent', 'busy', 'awaiting_card'];
    protected const TIMEOUT = ['timeout', 'timed_out', 'expired'];
    protected const FAILED = ['error', 'failed', 'failure', 'fail'];
    protected const VOID_DONE = ['voided', 'void', 'cancelled', 'canceled', 'iptal', 'iptal_edildi', 'reversed'];
    protected const REFUND_DONE = ['refunded', 'refund', 'iade', 'iade_edildi', 'reversed'];

    /** Minor-unit exponent where it is not 2. */
    protected const CURRENCY_DECIMALS = [
        'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3, 'LYD' => 3, 'IQD' => 3,
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'XOF' => 0, 'XAF' => 0,
    ];

    /** ISO 4217 numeric codes devices often want next to the alpha code. */
    protected const NUMERIC_CURRENCIES = [
        'TRY' => '949', 'USD' => '840', 'EUR' => '978', 'GBP' => '826', 'SAR' => '682',
        'AED' => '784', 'AZN' => '944', 'GEL' => '981', 'KZT' => '398', 'RUB' => '643',
    ];

    public function buildRequest(PosTerminal $terminal, Op $op): TerminalHttpRequest
    {
        [$method, $path] = $this->endpoint($terminal, $op);

        return new TerminalHttpRequest(
            $method,
            $this->fillPath($path, $terminal, $op),
            $method === 'GET' ? null : $this->body($terminal, $op),
            ['Accept' => 'application/json'],
        );
    }

    public function parseResponse(PosTerminal $terminal, Op $op, int $httpStatus, array $body): TerminalResult
    {
        $message = $this->str($body, ['message', 'description', 'error_message', 'error.message', 'error', 'detail', 'result_message', 'response_message', 'mesaj']);

        if ($op->name === Op::PING) {
            $ok = $httpStatus >= 200 && $httpStatus < 300 && $this->status($body, $httpStatus, true) !== Txn::STATUS_FAILED;

            return new TerminalResult($ok ? Txn::STATUS_APPROVED : Txn::STATUS_FAILED, $message ?: ($ok ? 'Device reachable' : "HTTP {$httpStatus}"), raw: $body, ok: $ok);
        }

        if ($httpStatus >= 500) {
            // A gateway / proxy error while the device may still be working:
            // for money operations and status queries the outcome is unknown.
            $result = in_array($op->name, [Op::SALE, Op::REFUND, Op::VOID, Op::STATUS], true)
                ? TerminalResult::unknown($message ?: "Device / provider error (HTTP {$httpStatus})")
                : TerminalResult::failed($message ?: "Device / provider error (HTTP {$httpStatus})", $body);
            $result->raw = $body;

            return $result;
        }

        // A status query only settles the payment on an explicit status word
        // (or an approval code): "not found" / success:false right after the
        // sale may just mean the provider has not registered it yet.
        $status = $this->status($body, $httpStatus, false, $op->name === Op::STATUS);
        $word = $this->rawStatus($body);

        // Cancel answers describe the cancel itself: "success" = cancelled.
        if ($op->name === Op::CANCEL && in_array($status, [Txn::STATUS_APPROVED, Txn::STATUS_CANCELLED], true)) {
            $status = $word !== null && in_array($word, self::APPROVED, true) && $this->looksLikePayment($body)
                ? Txn::STATUS_APPROVED   // the card went through before the cancel landed
                : Txn::STATUS_CANCELLED;
        }

        // Refund / void answers in their own words: "voided", "iptal",
        // "refunded", "iade"… mean the reversal went through.
        $reversalOp = $op->name === Op::STATUS ? $op->transaction?->type : $op->name;
        if ($word !== null && $reversalOp === Op::VOID && in_array($word, self::VOID_DONE, true)) {
            $status = Txn::STATUS_APPROVED;
        }
        if ($word !== null && $reversalOp === Op::REFUND && in_array($word, self::REFUND_DONE, true)) {
            $status = Txn::STATUS_APPROVED;
        }

        return new TerminalResult(
            $status,
            $message,
            $this->str($body, ['rrn', 'provider_reference', 'transaction_id', 'transactionId', 'reference_number', 'host_reference', 'batch_reference', 'id']),
            $this->str($body, ['auth_code', 'authCode', 'approval_code', 'approvalCode', 'provision_number', 'provizyon']),
            $this->mask($this->str($body, ['card_mask', 'masked_pan', 'maskedPan', 'pan', 'card_number', 'card.mask', 'card.pan'])),
            $this->str($body, ['card_brand', 'brand', 'card_type', 'cardType', 'scheme', 'card.brand']),
            $this->str($body, ['response_code', 'responseCode', 'result_code', 'resultCode', 'host_response_code', 'code']),
            $body,
            $status === Txn::STATUS_APPROVED,
            $this->str($body, ['reference', 'external_reference', 'order_id', 'orderId', 'request_reference']),
            $this->str($body, ['operation', 'op']),
        );
    }

    // ---------------------------------------------------------------- request

    /** [METHOD, path] for an operation, from settings.endpoints or the defaults. */
    protected function endpoint(PosTerminal $terminal, Op $op): array
    {
        $spec = trim((string) ($terminal->setting('endpoints.'.$op->name) ?: (static::meta()['defaults']['endpoints'][$op->name] ?? self::DEFAULT_ENDPOINTS[$op->name])));
        if (preg_match('#^(GET|POST|PUT|PATCH|DELETE)\s+(\S+)$#i', $spec, $m)) {
            return [strtoupper($m[1]), $m[2]];
        }

        return [$op->name === Op::PING || $op->name === Op::STATUS ? 'GET' : 'POST', $spec];
    }

    protected function fillPath(string $path, PosTerminal $terminal, Op $op): string
    {
        $txn = $op->transaction;

        return strtr($path, [
            '{reference}' => rawurlencode((string) ($txn->reference ?? '')),
            '{provider_reference}' => rawurlencode((string) ($txn->provider_reference ?? $op->original?->provider_reference ?? '')),
            '{terminal_id}' => rawurlencode((string) $terminal->terminal_id),
            '{merchant_id}' => rawurlencode((string) $terminal->merchant_id),
            '{serial_number}' => rawurlencode((string) $terminal->serial_number),
        ]);
    }

    protected function body(PosTerminal $terminal, Op $op): array
    {
        $txn = $op->transaction;
        $meta = static::meta();

        $body = [
            'operation' => $op->name,
            'reference' => $txn?->reference,
            'terminal_id' => $terminal->terminal_id,
            'merchant_id' => $terminal->merchant_id,
            'serial_number' => $terminal->serial_number,
            'device' => array_filter([
                'brand' => $meta['brand'] ?? null,
                'model' => $meta['model'] ?? null,
                'ip' => $terminal->ip_address,
                'port' => $terminal->port,
            ], fn ($v) => $v !== null && $v !== ''),
            'test_mode' => (bool) $terminal->is_test,
        ];

        if ($txn && in_array($op->name, [Op::SALE, Op::REFUND, Op::VOID], true)) {
            $currency = strtoupper((string) ($txn->currency ?: 'TRY'));
            $exponent = self::CURRENCY_DECIMALS[$currency] ?? 2;
            $body += [
                'amount' => round((float) $txn->amount, max(2, $exponent)),
                'amount_minor' => (int) round((float) $txn->amount * (10 ** $exponent)),
                'currency' => $currency,
                'currency_numeric' => self::NUMERIC_CURRENCIES[$currency] ?? null,
                'installments' => max(1, (int) $txn->installments),
            ];
        }

        if ($op->original) {
            $body['original'] = [
                'reference' => $op->original->reference,
                'provider_reference' => $op->original->provider_reference,
                'auth_code' => $op->original->auth_code,
                'amount' => round((float) $op->original->amount, 2),
                'date' => optional($op->original->completed_at ?? $op->original->created_at)->toIso8601String(),
            ];
        }

        if ($op->name === Op::CANCEL && $txn) {
            $body['cancel_reference'] = $txn->reference;
        }

        if ($op->name === Op::SALE && $op->receipt) {
            $body['receipt'] = $op->receipt + ['mode' => PosTerminal::FISCAL_FULL_RECEIPT];
        }

        return $body;
    }

    // --------------------------------------------------------------- response

    /** $strict (status queries): only a status word or an approval settles it; refusals stay pending. */
    protected function status(array $body, int $httpStatus, bool $forPing, bool $strict = false): string
    {
        $word = $this->rawStatus($body);
        if ($word !== null) {
            foreach ([
                Txn::STATUS_APPROVED => self::APPROVED,
                Txn::STATUS_DECLINED => self::DECLINED,
                Txn::STATUS_CANCELLED => self::CANCELLED,
                Txn::STATUS_PENDING => self::PENDING,
                Txn::STATUS_TIMEOUT => self::TIMEOUT,
                Txn::STATUS_FAILED => self::FAILED,
            ] as $status => $words) {
                if (in_array($word, $words, true)) {
                    return $status;
                }
            }
        }

        foreach (['approved', 'success', 'is_approved', 'isApproved', 'ok'] as $flag) {
            $v = data_get($body, $flag) ?? data_get($body, 'data.'.$flag);
            if (is_bool($v)) {
                if ($v) {
                    return Txn::STATUS_APPROVED;
                }

                return $forPing ? Txn::STATUS_FAILED : ($strict ? Txn::STATUS_PENDING : Txn::STATUS_DECLINED);
            }
        }

        $code = $this->str($body, ['response_code', 'responseCode', 'host_response_code']);
        if ($code !== null) {
            if (in_array($code, ['00', '0', '000'], true)) {
                return Txn::STATUS_APPROVED;
            }

            return $strict ? Txn::STATUS_PENDING : Txn::STATUS_DECLINED;
        }

        if ($forPing) {
            return $httpStatus >= 200 && $httpStatus < 300 ? Txn::STATUS_APPROVED : Txn::STATUS_FAILED;
        }
        if ($httpStatus >= 400) {
            return Txn::STATUS_FAILED;
        }

        // 2xx with nothing we recognise (e.g. 202 Accepted): the outcome is
        // not known yet, so keep asking rather than guess either way.
        return Txn::STATUS_PENDING;
    }

    protected function rawStatus(array $body): ?string
    {
        $v = $this->str($body, ['status', 'result', 'state', 'transaction_status', 'transactionStatus', 'outcome', 'durum']);

        return $v === null ? null : str_replace([' ', '-'], '_', mb_strtolower($v));
    }

    /** A cancel answer that carries card data means the payment completed first. */
    protected function looksLikePayment(array $body): bool
    {
        return $this->str($body, ['auth_code', 'authCode', 'approval_code', 'rrn']) !== null;
    }

    /** First non-empty scalar among keys, looked up at the top level and under data/result/transaction. */
    protected function str(array $body, array $keys): ?string
    {
        foreach (['', 'data.', 'result.', 'transaction.', 'payload.'] as $prefix) {
            foreach ($keys as $key) {
                $v = data_get($body, $prefix.$key);
                if (is_scalar($v) && ! is_bool($v) && trim((string) $v) !== '') {
                    return mb_substr(trim((string) $v), 0, 491);
                }
            }
        }

        return null;
    }

    /** Never store more than first 6 / last 4 digits of a card number. */
    protected function mask(?string $pan): ?string
    {
        if ($pan === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $pan);
        if (strlen($digits) >= 12 && ! str_contains($pan, '*') && ! str_contains($pan, 'X') && ! str_contains($pan, 'x')) {
            return substr($digits, 0, 6).str_repeat('*', strlen($digits) - 10).substr($digits, -4);
        }

        return mb_substr($pan, 0, 32);
    }
}
