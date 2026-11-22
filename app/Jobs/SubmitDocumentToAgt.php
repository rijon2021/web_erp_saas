<?php

namespace App\Jobs;

use App\Services\Agt\AgtService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Communicates a completed Sale (FT/FR) or received SaleReturn (NC) to the
 * Angola AGT after commit. Runs inline when QUEUE_CONNECTION=sync; the
 * guards in processQuietly() make it a no-op for tenants without the module.
 * The verdict arrives later through agt:poll.
 */
class SubmitDocumentToAgt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /**
     * @param string $sourceClass App\Models\Sale | App\Models\SaleReturn
     */
    public function __construct(public string $sourceClass, public int $sourceId)
    {
    }

    public function handle(AgtService $service): void
    {
        if (! in_array($this->sourceClass, [\App\Models\Sale::class, \App\Models\SaleReturn::class], true)) {
            return;
        }

        $source = $this->sourceClass::query()
            ->where('deleted_at', '=', null)
            ->find($this->sourceId);

        if ($source) {
            $service->processQuietly($source);
        }
    }
}
