<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\AgtProducerSetting;
use App\Models\Central\EInvoicingProviderSetting;
use App\Services\Agt\Jws;
use App\Services\EInvoicing\ProviderOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > E-Invoicing. One tab per tax authority plus a fleet overview:
 *
 *  - Overview : plans offering each provider, tenants enabled / ready,
 *               document backlog (cached scan of every active tenant DB).
 *  - ZATCA    : platform kill-switch + defaults for new tenants (environment,
 *               CSR solution name/version). Taxpayer onboarding is per tenant.
 *  - FBR      : platform kill-switch + default environment. The IRIS bearer
 *               token and seller profile are per tenant.
 *  - AGT      : the Angola software-producer profile (credentials, signing
 *               key, validation number, printed mentions) + kill-switch.
 *  - DGI      : kill-switch; the e-MCF client is pending the DGI spec.
 */
class EInvoicingSettingsController extends Controller
{
    public const TABS = ['overview', 'zatca', 'fbr', 'agt', 'dgi'];

    /** The standalone page moved into Settings > General > E-Invoicing. */
    public function index()
    {
        return $this->redirectToTab('overview');
    }

    /** Data for the E-Invoicing tab of Settings > General. */
    public function viewData(): array
    {
        $producer = $this->agtMigrated() ? AgtProducerSetting::current() : null;

        $publicKey = null;
        if ($producer && ! empty($producer->software_private_key)) {
            try {
                $publicKey = Jws::publicKey((string) $producer->software_private_key);
            } catch (\Throwable $e) {
                $publicKey = null;
            }
        }

        $providersMigrated = EInvoicingProviderSetting::tableExists();
        $providers = [];
        foreach (EInvoicingProviderSetting::PROVIDERS as $key) {
            $providers[$key] = $providersMigrated ? EInvoicingProviderSetting::for($key) : null;
        }

        return [
            'migrated' => $producer !== null,
            'providersMigrated' => $providersMigrated,
            'producer' => $producer,
            'providers' => $providers,
            'publicKey' => $publicKey,
            'environments' => config('agt.environments'),
            'zatcaEnvironments' => config('zatca.environments'),
            'fbrEnvironments' => config('fbr.environments'),
            'fbrBaseUrl' => config('fbr.base_url'),
            'placeholders' => AgtProducerSetting::QR_PLACEHOLDERS,
            // Rendered from cache only: the fleet scan runs on Refresh, never on page load.
            'overview' => ProviderOverview::cachedIfAny(),
            'overviewTtl' => ProviderOverview::TTL_MINUTES,
        ];
    }

    /** Clear the cached fleet scan and rebuild it. */
    public function refreshOverview()
    {
        ProviderOverview::forget();
        ProviderOverview::cached();

        return $this->redirectToTab('overview')->with('success', __('super.einvoicing.overview_refreshed'));
    }

    /** ZATCA / FBR / DGI tab: kill-switch + defaults. */
    public function updateProvider(Request $request, string $provider)
    {
        if (! in_array($provider, ['zatca', 'fbr', 'dgi'], true)) {
            abort(404);
        }

        $row = EInvoicingProviderSetting::for($provider);
        if (! $row) {
            return $this->redirectToTab($provider)->withErrors(['provider' => __('super.einvoicing.providers_not_migrated')]);
        }

        $rules = ['platform_enabled' => ['nullable', 'boolean']];
        if ($provider === 'zatca') {
            $rules += [
                'default_environment' => ['required', 'string', 'in:'.implode(',', array_keys(config('zatca.environments', [])))],
                'solution_name' => ['nullable', 'string', 'max:190'],
                'solution_version' => ['nullable', 'string', 'max:50'],
            ];
        } elseif ($provider === 'fbr') {
            $rules += [
                'default_environment' => ['required', 'string', 'in:'.implode(',', array_keys(config('fbr.environments', [])))],
            ];
        }

        try {
        $data = $request->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e->redirectTo(route('super.settings.general').'#tab=einvoicing&einv='.$provider);
        }

        $row->enabled = $request->boolean('platform_enabled');
        $settings = $data;
        unset($settings['platform_enabled']);
        foreach ($settings as $k => $v) {
            $settings[$k] = is_string($v) ? trim($v) : $v;
        }
        $row->putSettings($settings);

        return $this->redirectToTab($provider)->with('success', __('super.einvoicing.saved'));
    }

    /** AGT tab: software-producer profile (+ the AGT kill-switch). */
    public function update(Request $request)
    {
        $producer = $this->agtMigrated() ? AgtProducerSetting::current() : null;
        if (! $producer) {
            return $this->redirectToTab('agt')->withErrors(['agt' => __('super.einvoicing.not_migrated')]);
        }

        try {
        $data = $request->validate([
            'product_id' => ['nullable', 'string', 'max:120'],
            'product_version' => ['nullable', 'string', 'max:40'],
            'software_validation_number' => ['nullable', 'string', 'max:60'],
            'producer_tax_id' => ['nullable', 'string', 'max:20'],
            'signature_version' => ['nullable', 'integer', 'min:1', 'max:999'],
            'software_private_key' => ['nullable', 'string', 'max:20000'],
            'test_username' => ['nullable', 'string', 'max:120'],
            'test_password' => ['nullable', 'string', 'max:500'],
            'production_username' => ['nullable', 'string', 'max:120'],
            'production_password' => ['nullable', 'string', 'max:500'],
            'qr_template' => ['nullable', 'string', 'max:500'],
            'certification_text' => ['nullable', 'string', 'max:200'],
            'platform_enabled' => ['nullable', 'boolean'],
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e->redirectTo(route('super.settings.general').'#tab=einvoicing&einv=agt');
        }
        unset($data['platform_enabled']);

        // Secrets are write-only: an empty field keeps the stored value.
        foreach (['software_private_key', 'test_password', 'production_password'] as $secret) {
            if (trim((string) ($data[$secret] ?? '')) === '') {
                unset($data[$secret]);
            }
        }

        if (isset($data['software_private_key'])) {
            $data['software_private_key'] = trim($data['software_private_key']);
            try {
                $bits = Jws::inspect($data['software_private_key']);
            } catch (\Throwable $e) {
                return $this->redirectToTab('agt')
                    ->withInput($request->except(['software_private_key', 'test_password', 'production_password']))
                    ->withErrors(['software_private_key' => $e->getMessage()]);
            }
            if ($bits < 2048) {
                return $this->redirectToTab('agt')->withErrors(['software_private_key' => __('super.einvoicing.key_too_small', ['bits' => $bits])]);
            }
        }

        $producer->fill($data)->save();

        if ($row = EInvoicingProviderSetting::for('agt')) {
            $row->enabled = $request->boolean('platform_enabled');
            $row->save();
        }

        return $this->redirectToTab('agt')->with('success', __('super.einvoicing.saved'));
    }

    /** Generate a new RSA key pair; the public key is then registered on the AGT partner portal. */
    public function generateKey(Request $request)
    {
        $producer = $this->agtMigrated() ? AgtProducerSetting::current() : null;
        if (! $producer) {
            return $this->redirectToTab('agt')->withErrors(['agt' => __('super.einvoicing.not_migrated')]);
        }
        if (! empty($producer->software_private_key) && ! $request->boolean('confirm_replace')) {
            return $this->redirectToTab('agt')->withErrors(['software_private_key' => __('super.einvoicing.replace_confirm_required')]);
        }

        $bits = in_array((int) $request->input('bits'), [2048, 3072, 4096], true) ? (int) $request->input('bits') : 4096;
        try {
            $pem = Jws::generatePrivateKey($bits);
        } catch (\Throwable $e) {
            return $this->redirectToTab('agt')->withErrors(['software_private_key' => __('super.einvoicing.generate_failed', ['error' => $e->getMessage()])]);
        }

        $producer->software_private_key = $pem;
        $producer->save();

        return $this->redirectToTab('agt')->with('success', __('super.einvoicing.key_generated'));
    }

    private function redirectToTab(string $tab)
    {
        return redirect()->to(route('super.settings.general').'#tab=einvoicing&einv='.$tab);
    }

    private function agtMigrated(): bool
    {
        try {
            return Schema::connection('central')->hasTable('agt_producer_settings');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
