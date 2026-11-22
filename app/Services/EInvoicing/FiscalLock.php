<?php

namespace App\Services\EInvoicing;

use App\Models\AgtDocument;
use App\Models\DgiDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A sale or return already communicated to a tax authority (AGT Angola,
 * DGI DR Congo) is immutable: corrections go through a credit note (sale
 * return). The lock follows the documents, not the module switch, so
 * turning a module off later cannot unlock what was already reported.
 *
 * One exception: while AGT's verdict on the latest document is "rejected"
 * (invalid), the source may be EDITED — the fix is then sent as the
 * correction document. Deleting it stays blocked: AGT expects a correction.
 */
class FiscalLock
{
    public static function reason(?Model $source, bool $deleting = false): ?string
    {
        if (! $source) {
            return null;
        }

        $agt = AgtDocument::forSource($source);
        if ($agt && ! ($agt->status === 'invalid' && ! $deleting)) {
            return 'This document was communicated to AGT as '.$agt->document_no.' and can no longer be '.($deleting ? 'deleted' : 'changed').'. Issue a credit note (sale return) instead.';
        }

        $dgi = DgiDocument::forSource($source);
        if ($dgi && $dgi->isCertified()) {
            return 'This invoice was certified by the DGI (facture normalisée) and can no longer be changed or deleted. Issue a credit note (sale return) instead.';
        }

        return null;
    }

    public static function isLocked(?Model $source, bool $deleting = false): bool
    {
        return self::reason($source, $deleting) !== null;
    }

    /** Throws a 422 validation error when the document is locked. */
    public static function assertMutable(?Model $source, bool $deleting = false): void
    {
        $reason = self::reason($source, $deleting);
        if ($reason !== null) {
            throw ValidationException::withMessages(['fiscal' => [$reason]]);
        }
    }
}
