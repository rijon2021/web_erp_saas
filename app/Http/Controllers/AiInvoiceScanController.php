<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Models\Purchase;
use App\Models\Setting;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiInvoiceScanner;
use Illuminate\Http\Request;

/**
 * AI supplier invoice scanner: POST a photo/PDF, get the extracted invoice
 * with supplier/product matches for the purchase form's review step.
 * The file is read in memory only; the form attaches it to the purchase
 * through the existing purchase documents endpoint after saving.
 */
class AiInvoiceScanController extends Controller
{
    public function scan(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'ai_assistant', Setting::class);
        $this->authorizeForUser($user, 'create', Purchase::class);

        $request->validate([
            'file' => 'required|file|max:10240|mimetypes:application/pdf,'.implode(',', AiInvoiceScanner::IMAGE_TYPES),
        ]);

        if (! AiSetting::current()->isReady()) {
            return response()->json(['message' => __('messages.ai_not_configured')], 422);
        }

        @set_time_limit(180);

        try {
            $result = (new AiInvoiceScanner(AiClient::make()))->scan($request->file('file'), $user->id);
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['invoice' => $result]);
    }
}
