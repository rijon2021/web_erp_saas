<?php

namespace App\Http\Controllers\Api\Super;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs an action of the super-admin web panel (suspend, approve, mark paid…)
 * so the app gets exactly the same business rules and messages, and turns
 * its flash message or JSON reply into {ok, message}.
 *
 * The web actions answer with back()->with('success'|'error', …). The API has
 * no started session, so an in-memory one is attached to the request: the
 * flash lands there and is never written anywhere.
 */
trait RunsWebActions
{
    protected function runWebAction(Request $request, callable $action): JsonResponse
    {
        $session = app('session.store');
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
        foreach (['success', 'error', 'info', 'errors'] as $key) {
            $session->forget($key);
        }

        $response = $action();

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $ok = (bool) ($data['ok'] ?? $data['success'] ?? $response->getStatusCode() < 400);

            return response()->json([
                'ok'      => $ok,
                'message' => $data['message'] ?? null,
            ], $response->getStatusCode());
        }

        // back()->withErrors([...]) lands in the "errors" bag instead of "error".
        $bag = $session->pull('errors');
        $error = $session->pull('error')
            ?? ($bag instanceof \Illuminate\Support\ViewErrorBag && $bag->any() ? $bag->first() : null);
        $message = $session->pull('success') ?? $session->pull('info');

        return response()->json([
            'ok'      => $error === null,
            'message' => $error ?? $message,
        ], $error === null ? 200 : 422);
    }
}
