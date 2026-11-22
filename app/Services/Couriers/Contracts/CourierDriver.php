<?php

namespace App\Services\Couriers\Contracts;

use App\Models\Shipment;
use App\Services\Couriers\Data\BookingResult;
use App\Services\Couriers\Data\ConnectionResult;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Couriers\Data\TrackingResult;
use App\Services\Couriers\Data\WebhookUpdate;
use Illuminate\Http\Request;

/**
 * One courier company. A driver is built per CourierAccount (credentials,
 * mode and defaults come from it) and is stateless otherwise.
 *
 * Actions a courier does not offer throw CourierException::unsupported();
 * meta()['capabilities'] tells the UI which ones to show.
 */
interface CourierDriver
{
    /**
     * Static description used by the account form and the catalogue:
     * code, name, region, countries (ISO-2, [] = any), website, logo,
     * tracking_url ({tracking} placeholder), capabilities (booking, labels,
     * cancel, tracking, webhooks, cod, cities, pickup, test_mode → bool),
     * credentials and settings (field lists: key, label, type
     * text|password|select|number|switch, required, help, options).
     */
    public static function meta(): array;

    /** Check the credentials against the courier without side effects. */
    public function testConnection(): ConnectionResult;

    /** Create the consignment at the courier. */
    public function book(ShipmentRequest $request): BookingResult;

    /** Cancel a booked consignment before pickup. */
    public function cancel(Shipment $shipment): void;

    /** Current status and full history of a consignment. */
    public function track(Shipment $shipment): TrackingResult;

    /** Printable airway bill / label: a URL, or raw PDF bytes. */
    public function label(Shipment $shipment): array;

    /** The courier's own city list: [['code' => …, 'name' => …], …]. */
    public function cities(): array;

    /**
     * Turn a courier webhook call into status updates: one WebhookUpdate, a
     * list of them (couriers that batch several consignments), or null to
     * ignore the call. Throws CourierException when verification fails.
     *
     * @return WebhookUpdate|WebhookUpdate[]|null
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null;

    /** [HTTP status, body, optional headers] the courier expects back from a webhook call. */
    public function webhookAck(): array;
}
