<?php

namespace App\Services\PosTerminals;

use App\Models\PosTerminal;
use App\Services\PosTerminals\Contracts\TerminalDriver;
use App\Services\PosTerminals\Drivers\IngenicoIde280Driver;

/**
 * Every POS device model Stocky can drive. Adding one = a driver class in
 * Drivers/ plus one line in DRIVERS.
 */
final class TerminalRegistry
{
    /** code => driver class */
    public const DRIVERS = [
        'ingenico_ide280' => IngenicoIde280Driver::class,
    ];

    public static function has(?string $code): bool
    {
        return $code !== null && isset(self::DRIVERS[$code]);
    }

    public static function driver(PosTerminal|string $terminal): TerminalDriver
    {
        $code = $terminal instanceof PosTerminal ? $terminal->driver : $terminal;
        if (! self::has($code)) {
            throw new TerminalException("Unknown POS device model: {$code}");
        }

        $class = self::DRIVERS[$code];

        return new $class();
    }

    public static function meta(string $code): array
    {
        return self::has($code) ? (self::DRIVERS[$code])::meta() : [];
    }

    /** For the device form: [{code, label, brand, model, country, modes, capabilities, defaults}]. */
    public static function models(): array
    {
        $out = [];
        foreach (self::DRIVERS as $code => $class) {
            $out[] = ['code' => $code] + $class::meta();
        }

        return $out;
    }

    public static function supports(PosTerminal $terminal, string $capability): bool
    {
        return in_array($capability, self::meta($terminal->driver)['capabilities'] ?? [], true);
    }
}
