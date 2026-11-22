<?php

namespace App\Http\Controllers;

use App\Models\Central\EInvoicingProviderSetting;
use App\Models\DgiDocument;
use App\Models\DgiSetting;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Settings > DGI Facture Normalisée (DR Congo). The module switch lives in
 * System Settings > Features (settings.dgi_einvoice_enabled). Seller
 * identity is printed on invoices now; the e-MCF certification connection
 * is stored here and wired once DGI publishes the developer API to us.
 */
class DgiController extends Controller
{
    /** GET dgi/settings */
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'dgi_settings', Sale::class);

        $dgi = DgiSetting::current();
        if (! $dgi) {
            return response()->json(['message' => 'DGI tables are not migrated for this tenant.'], 409);
        }
        $setting = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'settings' => $this->publicSettings($dgi),
            'platform_enabled' => EInvoicingProviderSetting::platformEnabled('dgi'),
            'module_enabled' => (bool) ($setting->dgi_einvoice_enabled ?? false),
            'company' => [
                'name' => $setting->CompanyName ?? null,
                'address' => $setting->CompanyAdress ?? null,
            ],
            // The e-MCF client is not built yet: the DGI developer API
            // (developper.dgirdc.cd) is only available after registration.
            'api_available' => false,
        ]);
    }

    /** POST dgi/settings — an empty api_token keeps the stored token. */
    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'dgi_settings', Sale::class);

        $dgi = DgiSetting::current();
        if (! $dgi) {
            return response()->json(['message' => 'DGI tables are not migrated for this tenant.'], 409);
        }

        $data = $request->validate([
            'environment' => 'in:test,production',
            'nif' => 'nullable|string|max:30',
            'rccm' => 'nullable|string|max:60',
            'id_nat' => 'nullable|string|max:60',
            'company_name' => 'nullable|string|max:200',
            'address' => 'nullable|string|max:190',
            'def_number' => 'nullable|string|max:60',
            'api_url' => 'nullable|url|max:190',
            'api_token' => 'nullable|string|max:5000',
            'tax_group_map' => 'nullable|array',
            'tax_group_map.*' => ['string', 'max:2', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        if (array_key_exists('api_token', $data) && (string) $data['api_token'] === '') {
            unset($data['api_token']);
        }
        if (isset($data['tax_group_map'])) {
            $map = [];
            foreach ($data['tax_group_map'] as $rate => $group) {
                if (is_numeric($rate)) {
                    $map[rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.') ?: '0'] = strtoupper($group);
                }
            }
            $data['tax_group_map'] = $map;
        }

        $dgi->fill($data)->save();

        return response()->json(['success' => true, 'settings' => $this->publicSettings($dgi->fresh())]);
    }

    /** GET dgi/documents */
    public function documents(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'dgi_settings', Sale::class);

        $query = DgiDocument::query()->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate((int) $request->get('limit', 20)));
    }

    protected function publicSettings(DgiSetting $dgi): array
    {
        $token = (string) $dgi->api_token;

        return [
            'environment' => $dgi->environment,
            'nif' => $dgi->nif,
            'rccm' => $dgi->rccm,
            'id_nat' => $dgi->id_nat,
            'company_name' => $dgi->company_name,
            'address' => $dgi->address,
            'def_number' => $dgi->def_number,
            'api_url' => $dgi->api_url,
            'has_token' => $token !== '',
            'token_last4' => $token !== '' ? substr($token, -4) : null,
            'tax_group_map' => $dgi->tax_group_map ?: (object) [],
        ];
    }
}
