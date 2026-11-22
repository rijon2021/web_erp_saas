/**
 * POS Devices (card terminals) — browser side, shared by the till payment
 * modal and Sales > POS Devices.
 *
 * Every terminal endpoint answers { transaction, bridge }. `bridge` is set
 * when the device sits behind a local bridge program on this computer: the
 * server cannot reach it, so this browser forwards the signed request
 * (envelope) to the bridge and posts the bridge's raw, signed answer back.
 * followTransaction() repeats that / polls the server until the payment is
 * approved, declined, cancelled, failed or timed out.
 */
import http from './http';

export const FINAL_STATUSES = ['approved', 'declined', 'cancelled', 'failed', 'timeout'];
export const isFinal = status => FINAL_STATUSES.includes(status);

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

/**
 * Sends one envelope to the bridge.
 * Resolves { raw, http_status, signature } — http_status 0 with `error`
 * when no HTTP answer came back ('unreachable' = never got there,
 * 'timeout' = sent, but no answer in time).
 */
export async function callBridge(envelope) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), envelope.timeout_ms || 30000);
    try {
        const res = await fetch(envelope.url, {
            method: envelope.method || 'POST',
            headers: envelope.headers || {},
            body: envelope.body ?? undefined,
            signal: controller.signal,
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
        });
        const raw = await res.text();
        return { raw, http_status: res.status, signature: res.headers.get('X-Bridge-Signature') };
    } catch (e) {
        return { raw: '', http_status: 0, signature: null, error: e && e.name === 'AbortError' ? 'timeout' : 'unreachable' };
    } finally {
        clearTimeout(timer);
    }
}

/** Forwards a bridge envelope for transaction `id` and returns the server's new answer. */
async function forward(id, envelope) {
    const r = await callBridge(envelope);
    if (r.http_status === 0 && r.error === 'timeout') {
        // It reached the bridge; the device may still finish. Keep asking.
        return http.get(`pos-terminals/transactions/${id}`);
    }
    return http.post(`pos-terminals/transactions/${id}/bridge-result`, { op: envelope.op, ...r });
}

/**
 * Follows a terminal transaction to its end.
 *   answer      the first server answer ({ transaction, bridge, dispatch })
 *   onUpdate    called with every new transaction state
 *   stopped     () => true to stop following (modal closed); returns the last state
 *   deadlineMs  give up after this long (throws { code: 'deadline', transaction })
 * Server refusals (422: bad signature, device busy…) and network errors are
 * thrown; the caller keeps the transaction id it saw through onUpdate.
 */
export async function followTransaction(answer, { onUpdate, stopped, pollMs = 2000, deadlineMs = 0 } = {}) {
    let current = answer;
    const until = deadlineMs > 0 ? Date.now() + deadlineMs : 0;
    let dispatched = false;
    for (;;) {
        const txn = current && current.transaction;
        if (!txn) throw new Error('No transaction');
        if (onUpdate) onUpdate(txn);
        if (isFinal(txn.status) || (stopped && stopped())) return txn;
        if (until && Date.now() > until) {
            throw Object.assign(new Error('deadline'), { code: 'deadline', transaction: txn });
        }

        // Cloud device: the server makes the long provider call in its own
        // request; we keep polling meanwhile (a proxy timeout on that request
        // loses nothing — the server carries on and the row tells the outcome).
        if (current.dispatch && !dispatched) {
            dispatched = true;
            http.post(`pos-terminals/transactions/${txn.id}/dispatch`).catch(() => { /* polled below */ });
        }

        if (current.bridge) {
            current = await forward(txn.id, current.bridge);
            if (current.transaction && !isFinal(current.transaction.status) && !current.bridge) {
                await sleep(pollMs);
                current = await http.get(`pos-terminals/transactions/${txn.id}`);
            }
            continue;
        }

        await sleep(pollMs);
        current = await http.get(`pos-terminals/transactions/${txn.id}`);
    }
}

/** Picks a transaction up again (after a lost connection) and follows it. */
export async function resumeTransaction(id, opts) {
    const answer = await http.get(`pos-terminals/transactions/${id}`);
    return followTransaction(answer, opts);
}

/**
 * "Check again": asks the device once more about a payment that ended
 * timed-out / cancelled on our side (a late approval is recorded), or
 * follows it if it is still pending. Resolves the transaction.
 */
export async function recheckTransaction(id, opts) {
    let answer = await http.get(`pos-terminals/transactions/${id}`, { recheck: 1 });
    if (answer && answer.transaction && isFinal(answer.transaction.status)) {
        if (answer.bridge) answer = await forward(id, answer.bridge);
        return answer.transaction;
    }
    return followTransaction(answer, opts);
}

/** Current state of one transaction, without asking the device. */
export async function fetchTransaction(id) {
    const answer = await http.get(`pos-terminals/transactions/${id}`);
    return answer && answer.transaction;
}

/** Asks the device to stop a pending payment (the follow loop sees the outcome). */
export async function cancelTransaction(id) {
    const answer = await http.post(`pos-terminals/transactions/${id}/cancel`);
    if (answer && answer.bridge) return forward(id, answer.bridge);
    return answer;
}

/** Starts a charge on device `terminalId` and follows it. */
export async function chargeOnTerminal(terminalId, payload, opts) {
    const answer = await http.post(`pos-terminals/${terminalId}/charge`, payload);
    return followTransaction(answer, opts);
}

/** Void (whole amount) and follow it; resolves the void transaction. */
export async function voidTransaction(id, opts) {
    const answer = await http.post(`pos-terminals/transactions/${id}/void`);
    return followTransaction(answer, opts);
}

/** Refund part / all of an approved payment and follow it. */
export async function refundTransaction(id, amount, opts) {
    const answer = await http.post(`pos-terminals/transactions/${id}/refund`, { amount });
    return followTransaction(answer, opts);
}

/** Device test: { ok, message }. Bridge devices are pinged from this computer. */
export async function testTerminal(terminalId) {
    const answer = await http.post(`pos-terminals/${terminalId}/test`);
    if (!answer || !answer.bridge) return answer || { ok: false };
    const r = await callBridge(answer.bridge);
    if (r.http_status === 0) {
        return { ok: false, message: r.error === 'timeout' ? 'bridge_timeout' : 'bridge_unreachable', url: answer.bridge.url };
    }
    return http.post(`pos-terminals/${terminalId}/test-result`, r);
}
