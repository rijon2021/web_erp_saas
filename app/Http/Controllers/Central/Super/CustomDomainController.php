<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Super;

use App\Exceptions\CustomDomainException;
use App\Http\Controllers\Controller;
use App\Services\CustomDomainManager;
use App\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Super-admin side of custom domains (tenant page › Custom domain panel).
 * The lifecycle itself lives in CustomDomainManager, shared with the
 * tenant's own Settings › Custom Domain page; this controller only maps
 * outcomes onto super.* flash messages. No plan limit applies here.
 */
class CustomDomainController extends Controller
{
    public function __construct(private readonly CustomDomainManager $domains)
    {
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        try {
            $this->domains->add($tenant, $validated['domain']);
        } catch (CustomDomainException $e) {
            return back()->withErrors(['domain' => $this->message($e->getReason())]);
        }

        return back()->with('success', __('super.custom_domains.flash.added'));
    }

    public function verify(Request $request, Tenant $tenant, int $id): JsonResponse|RedirectResponse
    {
        $record = $tenant->customDomains()->findOrFail($id);

        $result = $this->domains->verify($record);

        if ($result['ok']) {
            $message = __('super.custom_domains.flash.verified');
            if (isset($result['ssl']) && ! $result['ssl']['ok']) {
                $message .= ' ' . __('super.custom_domains.flash.ssl_not_ready', ['reason' => $this->sslMessage($result['ssl'])]);
            }

            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'message' => $message, 'ssl' => $result['ssl'] ?? null]);
            }
            return back()->with('success', $message);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'reason'  => $result['reason'],
                'message' => $this->message($result['reason']),
                'records' => $result['records'] ?? [],
            ], 422);
        }

        return back()->withErrors(['domain' => $this->message($result['reason'])]);
    }

    /** Re-probe HTTPS on a verified domain and refresh its SSL badge. */
    public function checkSsl(Tenant $tenant, int $id): RedirectResponse
    {
        $record = $tenant->customDomains()->where('is_verified', true)->findOrFail($id);

        $result = $this->domains->checkSsl($record);

        if ($result['ok']) {
            return back()->with('success', __('super.custom_domains.flash.ssl_active', ['domain' => $record->domain]));
        }

        return back()->withErrors(['domain' => __('super.custom_domains.flash.ssl_failed', [
            'domain' => $record->domain,
            'reason' => $this->sslMessage($result),
        ])]);
    }

    public function destroy(Tenant $tenant, int $id): RedirectResponse
    {
        $record = $tenant->customDomains()->findOrFail($id);

        $this->domains->remove($record);

        return back()->with('success', __('super.custom_domains.flash.removed'));
    }

    public function makePrimary(Tenant $tenant, int $id): RedirectResponse
    {
        $record = $tenant->customDomains()->findOrFail($id);

        try {
            $this->domains->makePrimary($record);
        } catch (CustomDomainException $e) {
            return back()->withErrors(['domain' => $this->message($e->getReason())]);
        }

        return back()->with('success', __('super.custom_domains.flash.primary_set'));
    }

    /** super.custom_domains.errors.<reason>, falling back to the manager's English text. */
    private function message(string $reason): string
    {
        $key = 'super.custom_domains.errors.' . $reason;
        $translated = __($key);

        return $translated === $key ? $this->domains->reasonMessage($reason) : $translated;
    }

    /** super.custom_domains.ssl_errors.<reason>, falling back to the manager's English text. */
    private function sslMessage(array $result): string
    {
        $key = 'super.custom_domains.ssl_errors.' . ($result['reason'] ?? '');
        $translated = __($key, ['names' => $result['detail'] ?? '']);

        return $translated === $key ? $this->domains->sslReasonMessage($result) : $translated;
    }
}
