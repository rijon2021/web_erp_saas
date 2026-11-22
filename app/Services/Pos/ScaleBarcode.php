<?php

namespace App\Services\Pos;

use Illuminate\Validation\ValidationException;

/**
 * Label layouts printed by counter weighing scales (System Settings > Weighing
 * scale). A label reads, left to right:
 *
 *   prefix | item code (plu_digits) | skipped digits | value (value_digits) | check digit
 *
 * The value is a weight or the total price the scale worked out, with
 * value_decimals implied decimals. Decoding happens in the browser
 * (resources/src/lib/scaleBarcode.js) so the POS keeps working offline; this
 * class only cleans and validates what the settings page saves.
 */
class ScaleBarcode
{
    public const MAX_FORMATS = 10;

    /** A label is the layout plus one trailing check digit: EAN-8 up to 14 digits. */
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 14;

    /**
     * The layout every tenant used before this setting existed: the first 7
     * digits are the product code, the next 5 the weight in grams.
     */
    public static function legacyFormat(): array
    {
        return [
            'name' => 'Legacy (7-digit product code)',
            'prefix' => '',
            'plu_digits' => 7,
            'skip_digits' => 0,
            'value_digits' => 5,
            'value_decimals' => 3,
            'value_type' => 'weight',
            'check_digit' => false,
        ];
    }

    /** Formats stored on a pos_settings row, cleaned; [] when none. */
    public static function formatsOf($posSetting): array
    {
        $raw = $posSetting->scale_barcode_formats ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $formats = [];
        foreach (array_slice(array_values($raw), 0, static::MAX_FORMATS) as $row) {
            try {
                $formats[] = static::cleanFormat(is_array($row) ? $row : []);
            } catch (\InvalidArgumentException $e) {
                // A row edited by hand into something unreadable is skipped.
            }
        }

        return $formats;
    }

    /**
     * Clean the formats posted by the settings page.
     *
     * @throws ValidationException when a format is unusable or two formats
     *         would claim the same labels
     */
    public static function validateFormats($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            $input = [];
        }
        $input = array_values($input);

        if (count($input) > static::MAX_FORMATS) {
            throw ValidationException::withMessages([
                'scale_barcode_formats' => __('You can add up to :n label formats.', ['n' => static::MAX_FORMATS]),
            ]);
        }

        $formats = [];
        $seen = [];
        foreach ($input as $i => $row) {
            try {
                $format = static::cleanFormat(is_array($row) ? $row : []);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    "scale_barcode_formats.$i" => __('Label format :n: :reason', ['n' => $i + 1, 'reason' => $e->getMessage()]),
                ]);
            }

            // Same prefix and same label length = the two formats read the
            // same barcodes, so only the first one could ever be used.
            $key = $format['prefix'].'|'.static::length($format);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "scale_barcode_formats.$i" => __('Label formats :a and :b use the same prefix and length, so they would read the same labels.', ['a' => $seen[$key] + 1, 'b' => $i + 1]),
                ]);
            }
            $seen[$key] = $i;
            $formats[] = $format;
        }

        return $formats;
    }

    /** Digits on a label of this format, check digit included. */
    public static function length(array $format): int
    {
        return strlen($format['prefix']) + $format['plu_digits'] + $format['skip_digits'] + $format['value_digits'] + 1;
    }

    /** @throws \InvalidArgumentException */
    protected static function cleanFormat(array $row): array
    {
        $prefix = trim((string) ($row['prefix'] ?? ''));
        if ($prefix !== '' && ! preg_match('/^\d{1,4}$/', $prefix)) {
            throw new \InvalidArgumentException(__('the prefix must be 1 to 4 digits.'));
        }

        $int = static fn ($key, $default) => is_numeric($row[$key] ?? null) ? (int) $row[$key] : $default;

        $format = [
            'name' => mb_substr(trim(strip_tags((string) ($row['name'] ?? ''))), 0, 60),
            'prefix' => $prefix,
            'plu_digits' => $int('plu_digits', 5),
            'skip_digits' => $int('skip_digits', 0),
            'value_digits' => $int('value_digits', 5),
            'value_decimals' => $int('value_decimals', 3),
            'value_type' => ($row['value_type'] ?? 'weight') === 'price' ? 'price' : 'weight',
            'check_digit' => filter_var($row['check_digit'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        if ($format['plu_digits'] < 1 || $format['plu_digits'] > 8) {
            throw new \InvalidArgumentException(__('the item code must be 1 to 8 digits.'));
        }
        if ($format['skip_digits'] < 0 || $format['skip_digits'] > 3) {
            throw new \InvalidArgumentException(__('digits to skip must be 0 to 3.'));
        }
        if ($format['value_digits'] < 1 || $format['value_digits'] > 8) {
            throw new \InvalidArgumentException(__('the value must be 1 to 8 digits.'));
        }
        if ($format['value_decimals'] < 0 || $format['value_decimals'] > min(6, $format['value_digits'])) {
            throw new \InvalidArgumentException(__('value decimals must be between 0 and the number of value digits (6 at most).'));
        }

        $length = static::length($format);
        if ($length < static::MIN_LENGTH || $length > static::MAX_LENGTH) {
            throw new \InvalidArgumentException(__('the label would be :n digits long; scale labels are :min to :max digits.', [
                'n' => $length, 'min' => static::MIN_LENGTH, 'max' => static::MAX_LENGTH,
            ]));
        }

        return $format;
    }
}
