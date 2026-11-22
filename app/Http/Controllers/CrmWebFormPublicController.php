<?php

namespace App\Http\Controllers;

use App\Models\CrmWebForm;
use App\Models\Setting;
use App\Services\Crm\CrmCaptureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

/**
 * The public CRM web form: GET /lead-form/{token} renders it (stand-alone or
 * inside an iframe with ?embed=1), POST stores the lead. No login and no CSRF
 * (it lives in other sites' iframes); abuse is held back by the throttle on
 * the route, a hidden honeypot field and a signed "page shown at" stamp that
 * must be a few seconds old.
 */
class CrmWebFormPublicController extends Controller
{
    private const MIN_SECONDS = 3;

    public function show(Request $request, string $token)
    {
        $form = $this->form($token);
        if (! $form) {
            return response()->view('crm.web_form', ['form' => null, 'embed' => $request->boolean('embed')], 404);
        }

        return response()->view('crm.web_form', [
            'form' => $form,
            'fields' => $form->fieldList(),
            'embed' => $request->boolean('embed'),
            'stamp' => Crypt::encryptString((string) time()),
            'company' => optional(Setting::whereNull('deleted_at')->first())->CompanyName,
            'done' => session('crm_form_done') === $form->id,
        ])->header('Cache-Control', 'no-store');
    }

    public function submit(Request $request, string $token)
    {
        $form = $this->form($token);
        if (! $form) {
            return $this->reply($request, null, ['message' => 'This form is no longer available.'], 404);
        }

        // Bots: a filled honeypot or an instant submit gets a fake success.
        if ($request->filled('website_hp') || ! $this->stampOk((string) $request->input('_ts'))) {
            return $this->reply($request, $form, ['success' => true, 'message' => $this->successText($form)]);
        }

        $rules = [];
        foreach ($form->fieldList() as $f) {
            $r = [$f['required'] ? 'required' : 'nullable'];
            $r = array_merge($r, match ($f['type']) {
                'email' => ['email', 'max:191'],
                'tel' => ['string', 'max:50', 'regex:/^[\d\s()+\-.\/]{6,}$/'],
                'number' => ['numeric', 'min:0', 'max:999999999999'],
                'textarea' => ['string', 'max:5000'],
                default => ['string', 'max:191'],
            });
            $rules[$f['key']] = $r;
        }
        if ($form->consent_text) {
            $rules['consent'] = ['accepted'];
        }
        $labels = collect($form->fieldList())->pluck('label', 'key')->all() + ['consent' => 'consent'];
        $v = Validator::make($request->all(), $rules, [
            'required' => 'Please fill in :attribute.',
            'email' => 'Please enter a valid email address.',
            'regex' => 'Please enter a valid phone number.',
            'accepted' => 'Please tick the box to continue.',
        ], $labels);
        if ($v->fails()) {
            return $this->reply($request, $form, ['message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
        }

        $values = collect($v->validated())->except('consent')->all();
        // A lead nobody can contact is no use: ask for at least one way to reply.
        $contactKeys = array_values(array_intersect(['email', 'phone'], array_column($form->fieldList(), 'key')));
        if ($contactKeys && ! array_filter(array_map(fn ($k) => trim((string) ($values[$k] ?? '')), $contactKeys))) {
            return $this->reply($request, $form, [
                'message' => count($contactKeys) > 1 ? 'Please give an email address or a phone number.' : 'Please fill in ' . collect($form->fieldList())->firstWhere('key', $contactKeys[0])['label'] . '.',
                'errors' => [$contactKeys[0] => ['Required.']],
            ], 422);
        }
        $values['extra'] = array_filter([
            'Form' => $form->name,
            'Page' => mb_substr((string) $request->input('page_url'), 0, 300) ?: null,
            'Campaign' => trim(implode(' / ', array_filter([
                $request->input('utm_source'), $request->input('utm_medium'), $request->input('utm_campaign'),
            ], fn ($x) => is_string($x) && $x !== ''))) ?: null,
        ]);
        app(CrmCaptureService::class)->fromWebForm($form, $values);

        return $this->reply($request, $form, ['success' => true, 'message' => $this->successText($form), 'redirect' => $form->redirect_url]);
    }

    private function form(string $token): ?CrmWebForm
    {
        if (! CrmCaptureService::enabled()) {
            return null;
        }

        return CrmWebForm::where('token', $token)->where('is_active', true)->first();
    }

    private function stampOk(string $stamp): bool
    {
        try {
            $age = time() - (int) Crypt::decryptString($stamp);
        } catch (\Throwable $e) {
            return false;
        }

        return $age >= self::MIN_SECONDS && $age <= 86400;
    }

    private function successText(CrmWebForm $form): string
    {
        return $form->success_message ?: 'Thank you! We received your message and will get back to you shortly.';
    }

    /** JSON for the page's fetch(); a plain redirect when JavaScript is off. */
    private function reply(Request $request, ?CrmWebForm $form, array $body, int $status = 200)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($body, $status);
        }
        if ($status === 200 && ! empty($body['redirect'])) {
            return redirect()->away($body['redirect']);
        }
        if ($status !== 200) {
            return back()->withInput()->withErrors(['form' => $body['message'] ?? 'Error']);
        }

        return back()->with('crm_form_done', optional($form)->id);
    }
}
