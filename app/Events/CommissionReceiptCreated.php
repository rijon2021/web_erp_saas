<?php

namespace App\Events;

use App\Models\CommissionReceipt;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommissionReceiptCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public CommissionReceipt $receipt;

    public function __construct(CommissionReceipt $receipt)
    {
        $this->receipt = $receipt;
    }
}
