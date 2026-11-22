<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingServiceDetail;
use App\Models\BookingSetting;
use App\Models\BookingStaff;
use App\Services\Booking\BookingCrm;
use App\Services\Booking\BookingDepositService;
use App\Services\Crm\CrmCaptureService;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Support\BookingModule;
use App\Support\BookingTypes;
use App\Support\PortalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management › Guide — GET bookings/guide: the live set-up checklist
 * (what is done, what is missing) for the guide page. Open to anyone with a
 * booking permission; read-only.
 */
class BookingGuideController extends BaseController
{
    public const PERMISSIONS = ['bookings', 'bookings_settings', 'bookings_services', 'bookings_staff', 'bookings_reports'];

    public function index(Request $request)
    {
        $user = $request->user('api');
        $can = collect(self::PERMISSIONS)->mapWithKeys(fn ($p) => [$p => (bool) $user?->can($p === 'bookings' ? 'view' : $p, Booking::class)]);
        if (! $can->contains(true)) {
            abort(403);
        }

        // Each phase's migration, by the table / column it adds.
        $migrations = [
            'foundation' => BookingSetting::tablesReady(),
            'services' => BookingServiceDetail::tablesReady(),
            'staff' => BookingStaff::tablesReady(),
            'workflow' => Booking::workflowReady(),
            'notifications' => Booking::notificationsReady(),
            'deposits' => Booking::depositsReady(),
        ];
        $out = ['module_on' => BookingModule::enabled(), 'migrations' => $migrations, 'can' => $can];
        if (! $migrations['foundation']) {
            return response()->json($out);
        }

        $s = BookingSetting::current();
        $store = DB::table('store_settings')->first();
        $activeStaff = $migrations['staff'] ? BookingStaff::query()->active()->pluck('id') : collect();
        $products = fn () => DB::table('bk_services as s')->join('products as p', 'p.id', '=', 's.product_id')->whereNull('p.deleted_at');

        $deposits = null;
        if ($migrations['deposits']) {
            $svc = app(BookingDepositService::class);
            $deposits = [
                'type' => $s->deposit_type ?: 'none',
                'gateways' => count($svc->gateways()),
                'services_own' => (int) DB::table('bk_services')->whereNotNull('deposit_type')->count(),
            ];
        }

        return response()->json($out + [
            'settings' => [
                'business_types' => BookingTypes::normalize($s->business_types),
                'slot_booking' => (bool) $s->slot_booking,
                'request_booking' => (bool) $s->request_booking,
                'auto_confirm' => (bool) $s->auto_confirm,
                'notify_owner' => (bool) $s->notify_owner,
                'notify_customer' => (bool) $s->notify_customer,
                'reminder_hours' => (int) $s->reminder_hours,
                'whatsapp_notify' => (bool) $s->whatsapp_notify,
                'crm_capture' => $migrations['deposits'] ? (bool) $s->crm_capture : false,
            ],
            'counts' => [
                'services' => $migrations['services'] ? (int) $products()->count() : 0,
                'services_website' => $migrations['services'] ? (int) $products()->where('s.show_on_website', 1)->where('p.is_active', 1)->count() : 0,
                'service_products' => (int) DB::table('products')->whereNull('deleted_at')->where('type', 'is_service')->count(),
                'staff' => $activeStaff->count(),
                'staff_with_hours' => $migrations['staff'] ? (int) DB::table('bk_staff_hours')->whereIn('staff_id', $activeStaff)->distinct()->count('staff_id') : 0,
                'staff_online' => $migrations['staff'] ? (int) BookingStaff::query()->active()->where('bookable_online', true)->count() : 0,
                'bookings' => (int) Booking::whereNull('deleted_at')->count(),
                'website_bookings' => $migrations['workflow'] ? (int) Booking::whereNull('deleted_at')->where('source', 'website')->count() : 0,
                'charged' => $migrations['workflow'] ? (int) Booking::whereNull('deleted_at')->whereNotNull('sale_id')->count() : 0,
            ],
            'deposits' => $deposits,
            'mail_server' => Schema::hasTable('servers') && DB::table('servers')->whereNull('deleted_at')->exists(),
            'whatsapp' => $this->safe(fn () => WhatsAppGateway::isActive()),
            'crm_available' => $migrations['deposits'] && $this->safe(fn () => CrmCaptureService::enabled()),
            'crm_on' => $migrations['deposits'] && BookingCrm::enabled(),
            'portal' => [
                'enabled' => $this->safe(fn () => PortalSettings::enabled()),
                'bookings' => $this->safe(fn () => PortalSettings::allows('bookings')),
            ],
            'store' => [
                'theme' => (string) ($store->theme ?? ''),
                'enabled' => (bool) ($store->enabled ?? false),
            ],
        ]);
    }

    private function safe(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
