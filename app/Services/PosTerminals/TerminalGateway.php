<?php

namespace App\Services\PosTerminals;

use App\Models\PosTerminal;
use App\Models\PosTerminalTransaction as Txn;
use App\Models\User;
use App\Services\PosTerminals\TerminalOperation as Op;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Runs POS device operations.
 *
 * Cloud mode: the server calls the provider API. Sales, refunds and voids
 * wait for the customer, so the first answer only creates the row
 * (`dispatch: true`); the browser then fires POST …/dispatch, which makes the
 * long call, while it polls the row — the till keeps the id, can cancel, and
 * a proxy timeout on the long request loses nothing.
 *
 * Bridge mode: the device sits behind a program on the cashier PC that the
 * server cannot reach, so the server answers with a signed request
 * ("bridge envelope") that the browser forwards to the bridge; the browser
 * then posts the bridge's raw answer back (bridgeResult). The bridge signs
 * that answer with the device's shared secret and echoes our reference AND
 * the operation, so a result typed into devtools — or a signed answer
 * replayed for another operation — cannot mark a card payment approved.
 *
 *   request  headers: X-Stocky-Timestamp, X-Stocky-Reference,
 *                     X-Stocky-Signature = hex HMAC-SHA256(timestamp + "." + body, secret)
 *   response header:  X-Bridge-Signature = hex HMAC-SHA256(raw response body, secret)
 *   response body:    must contain "reference" and "operation"
 */
final class TerminalGateway
{
    /** Cloud status polls closer together than this reuse the stored state. */
    private const POLL_GAP_SECONDS = 2;

    /** A cloud row nobody dispatched within this long was never sent. */
    private const UNDISPATCHED_SECONDS = 30;

    /** "Check again" on a timed-out / cancelled charge works for this long. */
    private const RECHECK_HOURS = 24;

    private const MONEY_OPS = [Op::SALE, Op::REFUND, Op::VOID];

    // ------------------------------------------------------------------ ping

    /** @return array{ok: ?bool, message: ?string, bridge: ?array} */
    public function ping(PosTerminal $terminal): array
    {
        $op = Op::make(Op::PING);
        $request = TerminalRegistry::driver($terminal)->buildRequest($terminal, $op);

        if ($terminal->usesBridge()) {
            return ['ok' => null, 'message' => null, 'bridge' => $this->envelope($terminal, $op, $request)];
        }

        $result = $this->send($terminal, $op, $request);
        $this->touch($terminal, $result->ok ? 'ok' : ($result->message ?: 'unreachable'));

        return ['ok' => $result->ok, 'message' => $result->message, 'bridge' => null];
    }

    /** The bridge's answer to a ping envelope. */
    public function pingResult(PosTerminal $terminal, string $raw, int $httpStatus, ?string $signature): array
    {
        $this->assertSigned($terminal, $raw, $signature);
        $result = TerminalRegistry::driver($terminal)->parseResponse($terminal, Op::make(Op::PING), $httpStatus, $this->decode($raw));
        $this->touch($terminal, $result->ok ? 'ok' : ($result->message ?: 'unreachable'));

        return ['ok' => $result->ok, 'message' => $result->message, 'bridge' => null];
    }

    // ------------------------------------------------------------- operations

    /**
     * Starts a card sale. One card flow per device at a time: the device row
     * is locked while we check for a running one and insert ours.
     *
     * @param  array  $data  amount, base_amount, currency, sale_uuid, installments, warehouse_id, receipt
     */
    public function startSale(PosTerminal $terminal, array $data, User $user): array
    {
        $txn = DB::transaction(function () use ($terminal, $data, $user) {
            $locked = PosTerminal::whereKey($terminal->id)->lockForUpdate()->first();
            $this->expireStale($locked);

            if ($locked->transactions()->where('status', Txn::STATUS_PENDING)->exists()) {
                throw new TerminalException(__('messages.Pd_Device_Busy'));
            }

            return Txn::create([
                'pos_terminal_id' => $terminal->id,
                'type' => Txn::TYPE_SALE,
                'status' => Txn::STATUS_PENDING,
                'reference' => Txn::newReference(),
                'amount' => round((float) $data['amount'], 4),
                'base_amount' => round((float) $data['base_amount'], 4),
                'currency' => $data['currency'] ?? null,
                'installments' => max(1, (int) ($data['installments'] ?? 1)),
                'sale_uuid' => $data['sale_uuid'] ?? null,
                'user_id' => $user->id,
                'warehouse_id' => $data['warehouse_id'] ?? $terminal->warehouse_id,
            ]);
        }, 3);

        return $this->runFresh($txn, Op::make(Op::SALE, $txn, null, $data['receipt'] ?? []));
    }

    /**
     * Refund (any amount up to what is left) or void (the whole sale) of an
     * approved sale. $requireUnlinked: the cashier's own-charge path — refused
     * once a sale has claimed the charge (checked under the row lock, so it
     * cannot race the checkout).
     */
    public function reverse(Txn $sale, string $type, float $amount, User $user, bool $requireUnlinked = false): array
    {
        $terminal = $sale->terminal; // withTrashed: a deleted device's charges stay reversible
        if (! $terminal) {
            throw new TerminalException('The device that took this payment no longer exists.');
        }
        if ($sale->type !== Txn::TYPE_SALE || ! $sale->isApproved()) {
            throw new TerminalException('Only approved card sales can be refunded or voided.');
        }
        if (! TerminalRegistry::supports($terminal, $type)) {
            throw new TerminalException('This device model does not support '.$type.'.');
        }

        $amount = round($amount, 2);
        $txn = DB::transaction(function () use ($sale, $type, $amount, $user, $terminal, $requireUnlinked) {
            $sale = Txn::whereKey($sale->id)->lockForUpdate()->first();
            $this->expireStale($terminal);

            if ($requireUnlinked && $sale->sale_id !== null) {
                throw new TerminalException('This card payment now belongs to a saved sale. Ask a manager to refund it.');
            }
            if ($sale->children()->where('status', Txn::STATUS_PENDING)->exists()) {
                throw new TerminalException('A refund or void for this payment is still waiting for the device.');
            }
            if ($sale->isVoided()) {
                throw new TerminalException('This payment has already been voided.');
            }

            $left = round((float) $sale->amount - $sale->reversedAmount(), 2);
            if ($type === Txn::TYPE_VOID) {
                if ($sale->reversedAmount() > 0) {
                    throw new TerminalException('Part of this payment was refunded already — refund the rest instead of voiding.');
                }
                $amount = round((float) $sale->amount, 4);
            }
            if ($amount <= 0 || $amount > $left + 0.0001) {
                throw new TerminalException('The amount must be more than 0 and at most '.number_format($left, 2).'.');
            }

            $rate = (float) $sale->amount > 0 ? (float) $sale->base_amount / (float) $sale->amount : 1;

            return Txn::create([
                'pos_terminal_id' => $terminal->id,
                'type' => $type,
                'status' => Txn::STATUS_PENDING,
                'reference' => Txn::newReference(),
                'amount' => $amount,
                'base_amount' => round($amount * $rate, 4),
                'currency' => $sale->currency,
                'installments' => 1,
                'sale_uuid' => $sale->sale_uuid,
                'sale_id' => $sale->sale_id,
                'parent_id' => $sale->id,
                'user_id' => $user->id,
                'warehouse_id' => $sale->warehouse_id,
            ]);
        }, 3);

        return $this->runFresh($txn, Op::make($type, $txn, $sale->refresh()));
    }

    /**
     * Cloud only: makes the long provider call for a sale / refund / void.
     * Runs once per row (conditional update), and keeps going if the browser
     * gives up on the request — the till learns the outcome by polling.
     */
    public function dispatch(Txn $txn): array
    {
        $terminal = $txn->terminal;
        if (! $terminal || $terminal->usesBridge() || ! in_array($txn->type, self::MONEY_OPS, true)) {
            return $this->answer($txn);
        }

        $claimed = Txn::whereKey($txn->id)
            ->where('status', Txn::STATUS_PENDING)
            ->whereNull('dispatched_at')
            ->update(['dispatched_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $this->answer($txn->refresh());
        }

        @ignore_user_abort(true);
        $txn->refresh();
        $op = Op::make($txn->type, $txn, $txn->parent, (array) data_get($txn->request, 'receipt', []));
        $request = TerminalRegistry::driver($terminal)->buildRequest($terminal, $op);
        $result = $this->send($terminal, $op, $request);
        $this->apply($txn, $op, $result, $result->raw !== []);

        return $this->answer($txn->refresh());
    }

    /**
     * Current state; asks the device (cloud) or hands out a status envelope
     * (bridge) while pending. $recheck also asks about a sale that ended
     * timed-out / cancelled / failed on our side — a late approval then
     * lands on the row (and shows up as charged without a sale).
     */
    public function refresh(Txn $txn, bool $recheck = false): array
    {
        $terminal = $txn->terminal;
        if (! $terminal) {
            return $this->answer($txn);
        }

        if ($txn->isFinal()) {
            $recheckable = $recheck
                && in_array($txn->status, [Txn::STATUS_TIMEOUT, Txn::STATUS_CANCELLED, Txn::STATUS_FAILED], true)
                && $txn->created_at && $txn->created_at->gt(now()->subHours(self::RECHECK_HOURS))
                && TerminalRegistry::supports($terminal, 'status');
            if (! $recheckable) {
                return $this->answer($txn);
            }

            return $this->askStatus($txn, $terminal);
        }

        $expired = $txn->created_at && $txn->created_at->lt(now()->subSeconds($terminal->timeoutSeconds() + 30));

        // Cloud row the till never dispatched: the device never heard of it.
        if (! $terminal->usesBridge() && in_array($txn->type, self::MONEY_OPS, true) && $txn->dispatched_at === null) {
            if ($txn->created_at && $txn->created_at->lt(now()->subSeconds(self::UNDISPATCHED_SECONDS))) {
                $this->apply($txn, Op::make(Op::CANCEL, $txn), new TerminalResult(Txn::STATUS_CANCELLED, 'Never sent to the device.'), false);
            }

            return $this->answer($txn->refresh());
        }

        if (! TerminalRegistry::supports($terminal, 'status')) {
            if ($expired) {
                $this->expire($txn, $terminal);
            }

            return $this->answer($txn->refresh());
        }

        if ($terminal->usesBridge()) {
            if ($expired) {
                $this->expire($txn, $terminal);

                return $this->answer($txn->refresh());
            }

            return $this->askStatus($txn, $terminal);
        }

        if (! $recheck && ! $expired && $txn->last_polled_at && $txn->last_polled_at->gt(now()->subSeconds(self::POLL_GAP_SECONDS))) {
            return $this->answer($txn);
        }

        $answer = $this->askStatus($txn, $terminal);
        $txn->refresh();

        if ($expired && ! $txn->isFinal()) {
            $this->expire($txn, $terminal);

            return $this->answer($txn->refresh());
        }

        return $answer;
    }

    /** Asks the device to abandon a pending operation. */
    public function cancel(Txn $txn): array
    {
        $terminal = $txn->terminal;
        if ($txn->isFinal() || ! $terminal) {
            return $this->answer($txn);
        }

        $notSentYet = ! $terminal->usesBridge() && $txn->dispatched_at === null;
        if ($notSentYet || ! TerminalRegistry::supports($terminal, 'cancel')) {
            // Nothing to tell the device: stop waiting here. A late approval
            // still lands on this row (see apply) so it can be voided.
            $this->apply($txn, Op::make(Op::CANCEL, $txn), new TerminalResult(Txn::STATUS_CANCELLED, 'Cancelled at the till'), false);

            return $this->answer($txn->refresh());
        }

        return $this->run($txn, Op::make(Op::CANCEL, $txn, $txn->parent));
    }

    /**
     * The bridge's raw answer to an envelope, forwarded by the browser.
     * `op` must be one this transaction can be waiting on, and the signed
     * body must name the same operation and reference. The HTTP status the
     * browser reports is not signed, so it is not used: the body decides.
     */
    public function bridgeResult(Txn $txn, string $opName, string $raw, ?string $signature): array
    {
        $terminal = $txn->terminal;
        if (! $terminal || ! $terminal->usesBridge()) {
            throw new TerminalException('This device is not set up for a local bridge.');
        }

        $allowed = [Op::STATUS, Op::CANCEL, $txn->type];
        if (! in_array($opName, $allowed, true)) {
            throw new TerminalException('Unexpected operation for this transaction.');
        }

        $this->assertSigned($terminal, $raw, $signature);
        $body = $this->decode($raw);
        $op = Op::make($opName, $txn, $txn->parent);
        $result = TerminalRegistry::driver($terminal)->parseResponse($terminal, $op, 200, $body);

        // The signed answer has to be about THIS operation on THIS row, or an
        // old signed answer could be replayed (a cancel's "success" as a sale).
        if ($result->reference !== $txn->reference) {
            throw new TerminalException('The bridge answer does not carry this transaction\'s reference.');
        }
        if ($result->operation === null || strtolower($result->operation) !== $opName) {
            throw new TerminalException('The bridge answer does not name the operation it answers.');
        }

        $this->apply($txn, $op, $result, true);

        return $this->answer($txn->refresh());
    }

    /**
     * The browser could not reach the bridge at all for the operation that
     * starts this transaction, so it never got to the device. Recorded as
     * cancelled (not failed) on purpose: this report is unsigned, and a
     * later signed approval from the bridge still overrides it.
     */
    public function bridgeUnreachable(Txn $txn, string $opName, ?string $detail = null): array
    {
        if ($opName === $txn->type && $txn->status === Txn::STATUS_PENDING) {
            $url = $txn->terminal?->bridge_url ?: 'the bridge';
            $this->apply($txn, Op::make(Op::CANCEL, $txn), new TerminalResult(
                Txn::STATUS_CANCELLED,
                mb_substr('Could not reach '.$url.' from this computer'.($detail ? ' ('.$detail.')' : '').'. Is the bridge program running?', 0, 500)
            ), false);
        }

        return $this->answer($txn->refresh());
    }

    /** Pending rows older than the device's wait window become "no answer". */
    public function expireStale(PosTerminal $terminal): void
    {
        $terminal->transactions()
            ->where('status', Txn::STATUS_PENDING)
            ->where('created_at', '<', now()->subSeconds($terminal->timeoutSeconds() + 30))
            ->get()
            ->each(fn (Txn $txn) => $this->expire($txn, $terminal));
    }

    // -------------------------------------------------------------- internals

    /** run() for a row just created: a thrown error marks it failed instead of leaving it pending. */
    private function runFresh(Txn $txn, Op $op): array
    {
        try {
            return $this->run($txn, $op);
        } catch (\Throwable $e) {
            $txn->forceFill(['status' => Txn::STATUS_FAILED, 'message' => mb_substr('Could not send: '.$e->getMessage(), 0, 500), 'completed_at' => now()])->save();
            throw $e;
        }
    }

    /**
     * Bridge: store the request and return the envelope. Cloud: sale / refund
     * / void are only stored (the till dispatches them); the rest are sent now.
     */
    private function run(Txn $txn, Op $op): array
    {
        $terminal = $txn->terminal;
        $request = TerminalRegistry::driver($terminal)->buildRequest($terminal, $op);

        if (in_array($op->name, self::MONEY_OPS, true)) {
            $txn->forceFill(['request' => [
                'method' => $request->method,
                'path' => $request->path,
                'body' => $request->body,
                'receipt' => $op->receipt ?: null,
            ]])->save();

            if (! $terminal->usesBridge()) {
                return $this->answer($txn, null, true);
            }
        }

        if ($terminal->usesBridge()) {
            return $this->answer($txn, $this->envelope($terminal, $op, $request, $txn));
        }

        $result = $this->send($terminal, $op, $request);
        $this->apply($txn, $op, $result, $result->raw !== []);

        return $this->answer($txn->refresh());
    }

    private function askStatus(Txn $txn, PosTerminal $terminal): array
    {
        $op = Op::make(Op::STATUS, $txn, $txn->parent);
        $request = TerminalRegistry::driver($terminal)->buildRequest($terminal, $op);

        if ($terminal->usesBridge()) {
            return $this->answer($txn, $this->envelope($terminal, $op, $request, $txn));
        }

        $txn->forceFill(['last_polled_at' => now()])->save();
        $result = $this->send($terminal, $op, $request);
        $this->apply($txn, $op, $result, $result->raw !== []);

        return $this->answer($txn->refresh());
    }

    private function send(PosTerminal $terminal, Op $op, TerminalHttpRequest $request): TerminalResult
    {
        $url = $request->url($terminal->api_base_url);
        $pin = self::publicHttpsTarget($url);
        if ($pin === null) {
            return TerminalResult::failed('The API URL must be https:// on a public address.');
        }

        $waitsForCard = in_array($op->name, self::MONEY_OPS, true);
        $timeout = $waitsForCard ? $terminal->timeoutSeconds() + 10 : 20;
        if ($waitsForCard) {
            @set_time_limit($timeout + 30);
        }

        $body = $request->bodyJson();
        $headers = $request->headers + $this->authHeaders($terminal, $body);

        try {
            $pending = Http::withHeaders($headers)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$pin]]])
                ->connectTimeout(10)
                ->timeout($timeout);
            if ($request->body !== null) {
                $pending = $pending->withBody($body, 'application/json');
            }
            $response = $pending->send($request->method, $url);
        } catch (ConnectionException $e) {
            // A sale may have reached the device even though we heard nothing
            // back: keep it pending and let the status polls decide.
            return $op->name === Op::PING || $op->name === Op::CANCEL
                ? TerminalResult::failed('No answer from the provider: '.$e->getMessage())
                : TerminalResult::unknown('No answer from the provider yet: '.$e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('POS terminal request failed', ['terminal' => $terminal->id, 'op' => $op->name, 'error' => $e->getMessage()]);

            return $waitsForCard || $op->name === Op::STATUS
                ? TerminalResult::unknown('Request failed: '.$e->getMessage())
                : TerminalResult::failed('Request failed: '.$e->getMessage());
        }

        $json = $response->json();
        if (! is_array($json)) {
            $json = ['_raw' => mb_substr(mb_scrub((string) $response->body(), 'UTF-8'), 0, 2000)];
        }

        return TerminalRegistry::driver($terminal)->parseResponse($terminal, $op, $response->status(), $json);
    }

    private function authHeaders(PosTerminal $terminal, string $body): array
    {
        $key = (string) $terminal->api_key;
        $secret = (string) $terminal->api_secret;

        switch ($terminal->auth_type) {
            case 'none':
                return [];
            case 'basic':
                return ['Authorization' => 'Basic '.base64_encode($key.':'.$secret)];
            case 'header':
                return [($terminal->setting('auth_header') ?: 'X-Api-Key') => $key];
            case 'hmac':
                $ts = (string) now()->timestamp;

                return [
                    ($terminal->setting('auth_header') ?: 'X-Api-Key') => $key,
                    'X-Timestamp' => $ts,
                    'X-Signature' => hash_hmac('sha256', $ts.'.'.$body, $secret),
                ];
            default:
                return $key !== '' ? ['Authorization' => 'Bearer '.$key] : [];
        }
    }

    /** What the browser sends to the bridge for one operation. */
    private function envelope(PosTerminal $terminal, Op $op, TerminalHttpRequest $request, ?Txn $txn = null): array
    {
        $body = $request->bodyJson();
        $ts = (string) now()->timestamp;
        $waitsForCard = in_array($op->name, self::MONEY_OPS, true);

        return [
            'op' => $op->name,
            'url' => $request->url($terminal->bridge_url ?: (TerminalRegistry::meta($terminal->driver)['defaults']['bridge_url'] ?? 'http://127.0.0.1:8085')),
            'method' => $request->method,
            'headers' => $request->headers + array_filter([
                'Content-Type' => $request->body !== null ? 'application/json' : null,
                'X-Stocky-Timestamp' => $ts,
                'X-Stocky-Reference' => $txn?->reference,
                'X-Stocky-Operation' => $op->name,
                'X-Stocky-Signature' => hash_hmac('sha256', 'req.'.$ts.'.'.$body, (string) $terminal->api_secret),
            ]),
            'body' => $request->body !== null ? $body : null,
            'timeout_ms' => ($waitsForCard ? $terminal->timeoutSeconds() + 10 : 15) * 1000,
        ];
    }

    private function assertSigned(PosTerminal $terminal, string $raw, ?string $signature): void
    {
        $secret = (string) $terminal->api_secret;
        if ($secret === '') {
            throw new TerminalException('Set the device shared secret before using the bridge.');
        }
        $expected = hash_hmac('sha256', $raw, $secret);
        if (! is_string($signature) || ! hash_equals($expected, strtolower(trim($signature)))) {
            throw new TerminalException('The bridge answer is not signed with this device\'s shared secret.');
        }
    }

    /**
     * Writes a device answer onto the row. A final row only changes when the
     * device later reports the money actually moved (approved after a
     * cancel / timeout / failure on our side) — that charge then shows up
     * unlinked in Transactions so it can be voided.
     */
    private function apply(Txn $txn, Op $op, TerminalResult $result, bool $reachedDevice): void
    {
        DB::transaction(function () use ($txn, $op, $result) {
            $row = Txn::whereKey($txn->id)->lockForUpdate()->first();

            if ($row->isFinal()) {
                $lateApproval = $result->status === Txn::STATUS_APPROVED
                    && in_array($row->status, [Txn::STATUS_CANCELLED, Txn::STATUS_TIMEOUT, Txn::STATUS_FAILED], true);
                if (! $lateApproval) {
                    return;
                }
            }

            // A cancel the device refused, or a status query that failed, says
            // nothing about the payment itself: keep waiting, note the reason.
            $inconclusive = $result->status === Txn::STATUS_PENDING
                || ($op->name === Op::CANCEL && ! in_array($result->status, [Txn::STATUS_CANCELLED, Txn::STATUS_APPROVED], true))
                || ($op->name === Op::STATUS && $result->status === Txn::STATUS_FAILED);
            if ($inconclusive) {
                $row->forceFill(array_filter([
                    'message' => $result->message ? mb_substr(self::maskPans($result->message), 0, 500) : null,
                    'response' => $result->raw ? self::scrub($result->raw) : null,
                ]) + ['last_polled_at' => now()])->save();

                return;
            }

            $fill = [
                'status' => $result->status,
                'response' => $result->raw ? self::scrub($result->raw) : $row->response,
                'last_polled_at' => now(),
            ];
            foreach ([
                'message' => [$result->message ? self::maskPans($result->message) : null, 500],
                'provider_reference' => [$result->providerReference, 191],
                'auth_code' => [$result->authCode, 64],
                'card_mask' => [$result->cardMask, 32],
                'card_brand' => [$result->cardBrand, 32],
                'response_code' => [$result->responseCode, 32],
            ] as $column => [$value, $max]) {
                if ($value !== null && $value !== '') {
                    $fill[$column] = mb_substr($value, 0, $max);
                }
            }
            if (in_array($result->status, Txn::FINAL_STATUSES, true)) {
                $fill['completed_at'] = now();
            }

            $row->forceFill($fill)->save();
        }, 3);

        if ($reachedDevice && $txn->terminal) {
            $this->touch($txn->terminal, $result->status);
        }
    }

    /**
     * The device answer as stored: card numbers masked (first 6 / last 4),
     * track data, CVV, PIN blocks and EMV blobs dropped — card data must
     * never sit in the database.
     */
    public static function scrub(array $raw, int $depth = 0): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && preg_match('/track|cvv|cvc|cv2|pin_?block|emv|tlv|icc_?data|expir|exp_?date/i', $key)) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $depth < 6 ? self::scrub($value, $depth + 1) : '[…]';
            } elseif (is_string($value)) {
                $out[$key] = self::maskPans(mb_substr(mb_scrub($value, 'UTF-8'), 0, 2000));
            } elseif (is_int($value) && strlen((string) abs($value)) >= 13) {
                $out[$key] = self::maskPans((string) $value);
            } elseif (is_float($value) && abs($value) >= 1e12) {
                $out[$key] = '[number]';
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** 13–19 digits (optionally grouped by spaces / dashes) not glued to letters → first 6 / last 4. */
    public static function maskPans(string $text): string
    {
        return preg_replace_callback('/(?<![\dA-Za-z])\d(?:[ \-]?\d){12,18}(?![\dA-Za-z])/', function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);

            return substr($digits, 0, 6).str_repeat('*', strlen($digits) - 10).substr($digits, -4);
        }, $text) ?? $text;
    }

    private function expire(Txn $txn, PosTerminal $terminal): void
    {
        $this->apply($txn, Op::make(Op::STATUS, $txn), new TerminalResult(
            Txn::STATUS_TIMEOUT,
            'No answer from the device within '.$terminal->timeoutSeconds().' s. Check the device: if the card was charged, use "Check again" or void it from POS Devices > Transactions.'
        ), false);
        $txn->refresh();
    }

    private function touch(PosTerminal $terminal, string $status): void
    {
        $terminal->forceFill(['last_seen_at' => now(), 'last_status' => mb_substr($status, 0, 191)])->saveQuietly();
    }

    private function decode(string $raw): array
    {
        $json = json_decode($raw, true);

        return is_array($json) ? $json : ['_raw' => mb_substr(mb_scrub($raw, 'UTF-8'), 0, 2000)];
    }

    private function answer(Txn $txn, ?array $bridge = null, bool $dispatch = false): array
    {
        return ['transaction' => self::present($txn), 'bridge' => $bridge, 'dispatch' => $dispatch];
    }

    /** The transaction as the till / log pages see it (no raw payloads). */
    public static function present(Txn $txn): array
    {
        $reversed = $txn->type === Txn::TYPE_SALE ? $txn->reversedAmount() : 0.0;

        return [
            'id' => $txn->id,
            'pos_terminal_id' => $txn->pos_terminal_id,
            'type' => $txn->type,
            'status' => $txn->status,
            'final' => $txn->isFinal(),
            'reference' => $txn->reference,
            'amount' => (float) $txn->amount,
            'base_amount' => (float) $txn->base_amount,
            'reversed' => $reversed,
            'currency' => $txn->currency,
            'installments' => $txn->installments,
            'sale_id' => $txn->sale_id,
            'linked' => $txn->sale_id !== null,
            'provider_reference' => $txn->provider_reference,
            'auth_code' => $txn->auth_code,
            'card_mask' => $txn->card_mask,
            'card_brand' => $txn->card_brand,
            'response_code' => $txn->response_code,
            'message' => $txn->message,
            'created_at' => optional($txn->created_at)->toDateTimeString(),
            'completed_at' => optional($txn->completed_at)->toDateTimeString(),
        ];
    }

    /**
     * "host:port:ip" for CURLOPT_RESOLVE when $url is https:// on a public
     * IPv4 address, else null. Cloud URLs are tenant-entered: this keeps the
     * server from being pointed at its own network (private, reserved,
     * carrier-grade NAT, benchmarking… — anything not globally routable).
     */
    public static function publicHttpsTarget(?string $url): ?string
    {
        $url = (string) $url;
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! $host || str_contains($host, ':') || str_contains($host, '[')) {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        return "{$host}:{$port}:{$ips[0]}";
    }
}
