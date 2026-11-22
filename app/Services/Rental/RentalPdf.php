<?php

namespace App\Services\Rental;

use App\Models\Currency;
use App\Models\RentalAgreementTemplate;
use App\Models\RentalContract;
use App\Models\RentalInvoice;
use App\Models\Setting;
use PDF;

/**
 * The rental documents as PDFs — one place, so the admin screens and the
 * client portal print exactly the same agreement and invoice.
 */
class RentalPdf
{
    public function __construct(private RentalAgreementRenderer $renderer)
    {
    }

    /** The agreement, merged into the chosen template (else the default, else the built-in wording). */
    public function agreement(RentalContract $contract, ?int $templateId = null)
    {
        $contract->loadMissing('items', 'client', 'customer');

        $template = $templateId ? RentalAgreementTemplate::whereNull('deleted_at')->find($templateId) : null;
        $template = $template
            ?: RentalAgreementTemplate::whereNull('deleted_at')->where('is_default', true)->first()
            ?: RentalAgreementTemplate::whereNull('deleted_at')->orderBy('id')->first();

        $html = view('pdf.rental_contract_pdf', [
            'contract' => $contract,
            'body' => $this->renderer->render($contract, $template ? (string) $template->content : RentalAgreementRenderer::defaultTemplate()),
            'setting' => Setting::whereNull('deleted_at')->first(),
            'signature' => $this->renderer->signatureImage($contract),
            'bodyHasSignature' => $template && str_contains((string) $template->content, '{signature}'),
        ])->render();

        return PDF::loadHTML($this->shapeArabic($html));
    }

    public function invoice(RentalInvoice $inv)
    {
        $inv->loadMissing('contract.items', 'client', 'allocations.payment.method');
        $setting = Setting::whereNull('deleted_at')->first();
        $symbol = optional(Currency::find(optional($setting)->currency_id))->symbol ?? '';

        $c = $inv->contract;
        $periods = ($c && $inv->period_start && $inv->period_end && $inv->period_end->gt($inv->period_start))
            ? max(1, (int) app(RentalContractService::class)->periodsBetween($inv->period_start, $inv->period_end, $c->rate_period))
            : 1;

        return PDF::loadHTML(view('pdf.rental_invoice_pdf', [
            'inv' => $inv,
            'periods' => $periods,
            'setting' => $setting,
            'money' => fn ($v) => trim($symbol . ' ' . number_format((float) $v, 2)),
        ])->render());
    }

    /** dompdf can't shape Arabic itself; ArPHP does it when installed. */
    private function shapeArabic(string $html): string
    {
        if (! class_exists(\ArPHP\I18N\Arabic::class)) {
            return $html;
        }
        try {
            $arabic = new \ArPHP\I18N\Arabic;
            $p = $arabic->arIdentify($html);
            for ($i = count($p) - 1; $i >= 0; $i -= 2) {
                $utf8ar = $arabic->utf8Glyphs(substr($html, $p[$i - 1], $p[$i] - $p[$i - 1]));
                $html = substr_replace($html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
            }
        } catch (\Throwable $e) {
            // Shaping is cosmetic; print without it.
        }

        return $html;
    }
}
