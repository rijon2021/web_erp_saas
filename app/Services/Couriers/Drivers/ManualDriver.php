<?php

namespace App\Services\Couriers\Drivers;

use App\Models\Shipment;
use App\Services\Couriers\AbstractCourierDriver;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\Data\BookingResult;
use App\Services\Couriers\Data\ShipmentRequest;

/**
 * Any courier without an API: staff hand the parcel over, type the tracking
 * number the courier gave them, and move the status by hand. The account's
 * tracking_url template ({tracking}) turns the number into a tracking link.
 */
class ManualDriver extends AbstractCourierDriver
{
    public static function meta(): array
    {
        return [
            'code' => 'manual',
            'name' => 'Manual / Other courier',
            'region' => 'Any',
            'countries' => [],
            'website' => null,
            'tracking_url' => null,
            'description' => 'Any courier without an API: enter the tracking number by hand and update the status yourself.',
            'capabilities' => [
                'booking' => true,
                'manual_tracking' => true,
                'labels' => false,
                'cancel' => true,
                'tracking' => false,
                'webhooks' => false,
                'cod' => true,
                'cities' => false,
                'pickup' => false,
                'test_mode' => false,
            ],
            'credentials' => [],
            'settings' => [],
        ];
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        if (! $request->trackingNumber) {
            throw new CourierException('Enter the tracking number given by the courier.');
        }

        return new BookingResult(trackingNumber: $request->trackingNumber);
    }

    /** Nothing to call: cancelling only changes the status in Stocky. */
    public function cancel(Shipment $shipment): void
    {
    }
}
