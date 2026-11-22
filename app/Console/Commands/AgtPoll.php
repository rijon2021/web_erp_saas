<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Services\Agt\AgtService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Angola AGT e-invoicing is asynchronous: registarFactura only returns a
 * requestID. Every five minutes this fetches the verdict of submitted
 * documents (obterEstado) and re-sends documents whose registration failed
 * on the network, for every tenant whose plan carries the `agt` feature.
 */
class AgtPoll extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'agt:poll {--limit=50 : Max documents per tenant per pass}';

    protected $description = 'Fetch AGT validation results and re-send pending e-invoices';

    public function handle(AgtService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->forFeatureTenants(AgtService::FEATURE, function ($tenant) use ($service, $limit) {
            if (! Schema::hasTable('agt_documents')) {
                return;
            }
            $counts = $service->poll($limit);
            if ($counts['checked'] || $counts['sent']) {
                $this->line("tenant {$tenant->id}: {$counts['checked']} checked ({$counts['valid']} valid, {$counts['invalid']} invalid), {$counts['sent']} re-sent");
            }
        });

        return self::SUCCESS;
    }
}
