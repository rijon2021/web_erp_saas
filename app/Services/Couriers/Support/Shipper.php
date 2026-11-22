<?php

namespace App\Services\Couriers\Support;

use App\Models\CourierAccount;
use App\Services\CountryService;

/**
 * Structured sender address and parcel defaults for drivers that need full
 * postal addresses (international aggregators). The account form gets the
 * extra fields from fields(); values come from the account's pickup columns
 * plus these settings.
 */
final class Shipper
{
    /** Setting fields to merge into a driver's meta()['settings']. */
    public static function fields(bool $withParcel = true): array
    {
        $fields = [
            ['key' => 'sender_zip', 'label' => 'Pickup postal code', 'type' => 'text', 'required' => true],
            ['key' => 'sender_state', 'label' => 'Pickup state / province', 'type' => 'text'],
            ['key' => 'sender_country', 'label' => 'Pickup country (ISO code)', 'type' => 'text', 'required' => true,
                'help' => 'Two letters, e.g. US, GB, SA, IN.'],
            ['key' => 'sender_email', 'label' => 'Pickup email', 'type' => 'text'],
            ['key' => 'sender_company', 'label' => 'Company name on labels', 'type' => 'text'],
        ];

        if ($withParcel) {
            $fields[] = ['key' => 'parcel_length', 'label' => 'Default parcel length (cm)', 'type' => 'number'];
            $fields[] = ['key' => 'parcel_width', 'label' => 'Default parcel width (cm)', 'type' => 'number'];
            $fields[] = ['key' => 'parcel_height', 'label' => 'Default parcel height (cm)', 'type' => 'number'];
        }

        return $fields;
    }

    /** The account's sender address. */
    public static function address(CourierAccount $account): array
    {
        return [
            'name' => (string) ($account->pickup_contact ?: $account->name),
            'company' => (string) ($account->setting('sender_company') ?: ''),
            'phone' => (string) $account->pickup_phone,
            'email' => (string) ($account->setting('sender_email') ?: ''),
            'street' => (string) $account->pickup_address,
            'city' => (string) $account->pickup_city,
            'state' => (string) ($account->setting('sender_state') ?: ''),
            'zip' => (string) ($account->setting('sender_zip') ?: ''),
            'country' => CountryService::toCode($account->setting('sender_country')) ?? strtoupper((string) $account->setting('sender_country')),
        ];
    }

    /** Parcel size in cm: account defaults, else 20 × 15 × 10. */
    public static function dimensions(CourierAccount $account): array
    {
        return [
            'length' => (float) ($account->setting('parcel_length') ?: 20),
            'width' => (float) ($account->setting('parcel_width') ?: 15),
            'height' => (float) ($account->setting('parcel_height') ?: 10),
        ];
    }
}
