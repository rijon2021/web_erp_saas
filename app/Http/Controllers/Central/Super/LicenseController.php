<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\Request;

/**
 * Super Admin → Settings → Licence (UI Lib licence of the platform).
 * Routes are gated by central.permission:license. Every call to
 * ui-lib.com happens here on the server; the browser never sees the key.
 *
 * GET  settings/license              page; ?refresh=1 re-checks with ui-lib.com first
 * POST settings/license/activate     {license, email?}
 * POST settings/license/check
 * POST settings/license/deactivate   {domain?, license?, email?}  (no domain = this install)
 */
class LicenseController extends Controller
{
    public function __construct(private LicenseService $service)
    {
    }

    public function index(Request $request)
    {
        $offline = false;
        $error = null;

        // Opening the page refreshes the details + token (guide §9); when
        // ui-lib.com is unreachable the stored details are shown instead.
        if ($request->boolean('refresh', true) && ! $request->session()->has('license_limit')) {
            $r = $this->service->check();
            if ($r['network_error']) {
                $offline = true;
            } elseif (! $r['ok'] && $r['code'] && ! in_array($r['code'], ['no_license', 'not_available'], true)) {
                $error = $r['message'];
            }
        }

        return view('central.super.settings.license', [
            'state' => $this->service->state(),
            'offline' => $offline,
            'checkError' => $error,
            'limit' => $request->session()->get('license_limit'),
        ]);
    }

    public function activate(Request $request)
    {
        $data = $request->validate([
            'license' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);

        if (\UiLibLicenseClient::needsEmail($data['license']) && empty($data['email'])) {
            return back()->withInput()->with('error', __('super.license.email_required'));
        }

        $r = $this->service->activate($data['license'], (string) ($data['email'] ?? ''));

        return $this->respond($r, __('super.license.activated'), $data);
    }

    /** "Activate again" with the stored key: no retyping after a lapse. */
    public function reactivate()
    {
        $key = $this->service->storedKey();
        if ($key === '') {
            return redirect()->route('super.settings.license', ['refresh' => 0])->with('error', __('super.license.no_stored_key'));
        }

        $r = $this->service->reactivate();

        // No 'license' in the input: a failed retry must not flash the stored key to the page.
        return $this->respond($r, __('super.license.activated'), ['email' => $this->service->state()['email'] ?? '']);
    }

    public function check()
    {
        $r = $this->service->check();

        if ($r['ok']) {
            return redirect()->route('super.settings.license', ['refresh' => 0])->with('success', __('super.license.refreshed'));
        }
        if ($r['network_error']) {
            return redirect()->route('super.settings.license', ['refresh' => 0])->with('error', __('super.license.network_error'));
        }

        return redirect()->route('super.settings.license', ['refresh' => 0])->with('error', $r['message'] ?: ($r['code'] ?? 'error'));
    }

    public function deactivate(Request $request)
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:191'],
            'license' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);

        $own = empty($data['domain']);
        $r = $this->service->deactivate($data['domain'] ?? null, $data['license'] ?? null, $data['email'] ?? null);

        if ($own) {
            if ($r['ok'] || $r['code'] === 'not_activated') {
                return redirect()->route('super.settings.license', ['refresh' => 0])->with('success', __('super.license.deactivated'));
            }

            return redirect()->route('super.settings.license', ['refresh' => 0])
                ->with('error', $r['network_error'] ? __('super.license.network_error') : ($r['message'] ?: $r['code']));
        }

        // "Deactivate there": the other domain is released, now activate here
        // with the same licence (typed, or the stored one).
        if ($r['ok'] || $r['code'] === 'not_activated') {
            $license = (string) ($data['license'] ?? '');
            $again = $license !== ''
                ? $this->service->activate($license, (string) ($data['email'] ?? ''))
                : $this->service->reactivate();

            return $this->respond($again, __('super.license.activated'), ['license' => $license, 'email' => $data['email'] ?? '']);
        }

        return redirect()->route('super.settings.license', ['refresh' => 0])->withInput()
            ->with('error', $r['network_error'] ? __('super.license.network_error') : ($r['message'] ?: $r['code']));
    }

    private function respond(array $r, string $successMessage, array $input)
    {
        $to = redirect()->route('super.settings.license', ['refresh' => 0]);

        if ($r['ok']) {
            return $to->with('success', $successMessage);
        }
        if ($r['network_error']) {
            return $to->withInput($input)->with('error', __('super.license.network_error'));
        }
        if ($r['code'] === 'activation_limit') {
            return $to->withInput($input)->with('license_limit', [
                'message' => $r['message'],
                'domains' => $r['data']['domains'] ?? [],
                'license' => $input['license'] ?? '',
                'email' => $input['email'] ?? '',
            ]);
        }

        return $to->withInput($input)->with('error', $r['message'] ?: ($r['code'] ?? 'error'));
    }
}
