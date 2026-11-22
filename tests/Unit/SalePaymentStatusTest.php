<?php

namespace Tests\Unit;

use App\Models\OnlineOrder;
use App\Models\Sale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalePaymentStatusTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_status_follows_the_amount_paid($grandTotal, $paid, string $expected): void
    {
        $this->assertSame($expected, Sale::paymentStatusFor($grandTotal, $paid));
    }

    public static function amounts(): array
    {
        return [
            'nothing paid' => [600, 0, 'unpaid'],
            'part paid' => [600, 200, 'partial'],
            'fully paid' => [600, 600, 'paid'],
            'overpaid' => [600, 650, 'paid'],
            'zero total' => [0, 0, 'paid'],
            'one cent short' => [300.30, 300.29, 'partial'],
            'float noise in the sum' => [300.30, 100.10 + 200.20, 'paid'],
            'numeric strings' => ['300.30', '100.10', 'partial'],
        ];
    }

    public function test_online_order_status_mirrors_the_sale(): void
    {
        $this->assertSame('paid', OnlineOrder::paymentStatusFromSale('paid'));
        $this->assertSame('partial', OnlineOrder::paymentStatusFromSale('partial'));
        $this->assertSame('pending', OnlineOrder::paymentStatusFromSale('unpaid'));
        $this->assertSame('pending', OnlineOrder::paymentStatusFromSale(null));
    }
}
