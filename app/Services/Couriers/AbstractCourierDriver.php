<?php

namespace App\Services\Couriers;

use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Services\Couriers\Contracts\CourierDriver;
use App\Services\Couriers\Data\ConnectionResult;
use App\Services\Couriers\Data\TrackingResult;
use App\Services\Couriers\Data\WebhookUpdate;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for drivers: the account, credential checks, an HTTP client
 * with sane timeouts, and "unsupported" defaults for optional actions.
 */
abstract class AbstractCourierDriver implements CourierDriver
{
    public function __construct(protected CourierAccount $account)
    {
    }

    public function account(): CourierAccount
    {
        return $this->account;
    }

    protected function courierName(): string
    {
        return static::meta()['name'] ?? class_basename(static::class);
    }

    /** Labels of required credential fields that are still empty. */
    public function missingCredentials(): array
    {
        $missing = [];
        foreach (static::meta()['credentials'] ?? [] as $field) {
            if (! empty($field['required']) && $this->account->credential($field['key']) === '') {
                $missing[] = $field['label'];
            }
        }

        return $missing;
    }

    /** Drivers without a cheap API probe at least verify the form is complete. */
    public function testConnection(): ConnectionResult
    {
        $missing = $this->missingCredentials();

        return $missing
            ? ConnectionResult::failed('Missing: ' . implode(', ', $missing))
            : ConnectionResult::ok();
    }

    public function cancel(Shipment $shipment): void
    {
        throw CourierException::unsupported($this->courierName(), 'cancelling bookings');
    }

    public function track(Shipment $shipment): TrackingResult
    {
        throw CourierException::unsupported($this->courierName(), 'tracking');
    }

    public function label(Shipment $shipment): array
    {
        throw CourierException::unsupported($this->courierName(), 'labels');
    }

    public function cities(): array
    {
        return [];
    }

    /**
     * Priced options for this parcel (aggregators). Each Rate::$id can be sent
     * back as ShipmentRequest::$service to book that exact option.
     *
     * @return \App\Services\Couriers\Data\Rate[]
     */
    public function rates(\App\Services\Couriers\Data\ShipmentRequest $request): array
    {
        throw CourierException::unsupported($this->courierName(), 'rate comparison');
    }

    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        return null;
    }

    public function webhookAck(): array
    {
        return [200, ['ok' => true]];
    }

    /** Every courier call goes through here, so Couriers → Logs sees it (CourierLogger). */
    protected function http(): PendingRequest
    {
        return Http::timeout(25)->connectTimeout(10)->acceptJson()
            ->withMiddleware(CourierLogger::middleware($this->account));
    }
}
