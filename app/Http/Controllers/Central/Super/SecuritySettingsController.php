<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Support\Security\Captcha;
use App\Support\Security\SecurityOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > General > Security tab: CAPTCHA and failed-attempt lockout for
 * /super/login. Tenant logins have their own copy under System Settings >
 * Security. The old standalone page redirects to the tab.
 */
class SecuritySettingsController extends Controller
{
    public function index()
    {
        return $this->redirectToTab();
    }

    /** Data for the Security tab of Settings > General. */
    public function viewData(): array
    {
        return [
            'options' => SecurityOptions::forClient(SecurityOptions::get(SecurityOptions::CENTRAL), SecurityOptions::CENTRAL),
            'providers' => Captcha::EXTERNAL,
            'imageCaptcha' => Captcha::hasGd(),
            'migrated' => $this->migrated(),
        ];
    }

    public function update(Request $request)
    {
        if (! $this->migrated()) {
            return $this->redirectToTab()->withErrors(['security' => __('super.security_settings.not_migrated')]);
        }

        try {
        $request->validate([
            'captcha_provider' => ['required', 'in:'.implode(',', SecurityOptions::PROVIDERS)],
            'captcha_site_key' => ['nullable', 'string', 'max:255'],
            'captcha_secret_key' => ['nullable', 'string', 'max:255'],
            'captcha_mode' => ['required', 'in:'.implode(',', SecurityOptions::CAPTCHA_MODES)],
            'captcha_after_failures' => ['required', 'integer', 'min:1', 'max:20'],
            'lockout_max_attempts' => ['required', 'integer', 'min:2', 'max:50'],
            'lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'lockout_scope' => ['required', 'in:'.implode(',', SecurityOptions::LOCKOUT_SCOPES)],
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e->redirectTo(route('super.settings.general').'#tab=security');
        }

        // Unchecked checkboxes are absent from the POST: send explicit booleans.
        $input = $request->only(SecurityOptions::CENTRAL_KEYS);
        foreach (['captcha_enabled', 'lockout_enabled', 'lockout_show_remaining'] as $flag) {
            $input[$flag] = $request->boolean($flag);
        }

        if ($problem = SecurityOptions::captchaKeyProblem(SecurityOptions::CENTRAL, $input, $request->input('captcha_test_token'), $request->ip())) {
            return $this->redirectToTab()->withInput($request->except('captcha_secret_key'))->withErrors(['captcha_test' => $problem]);
        }

        SecurityOptions::save(SecurityOptions::CENTRAL, $input);

        return $this->redirectToTab()->with('success', __('super.flash.security_saved'));
    }

    private function redirectToTab()
    {
        return redirect()->to(route('super.settings.general').'#tab=security');
    }

    private function migrated(): bool
    {
        try {
            return Schema::connection('central')->hasColumn('general_settings', 'security_options');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
