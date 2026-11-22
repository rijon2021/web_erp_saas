<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Remembers which courier city a Stocky city name was matched to, so a
 * correction made once at booking time is reused for every later order.
 */
class CourierCityMapping extends Model
{
    protected $table = 'courier_city_mappings';

    protected $fillable = ['courier', 'city_name', 'courier_city_code', 'courier_city_name'];

    public static function normalize(?string $city): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $city)));
    }

    public static function lookup(string $courier, ?string $city): ?self
    {
        $key = static::normalize($city);

        return $key === '' ? null : static::query()
            ->where('courier', $courier)
            ->where('city_name', $key)
            ->first();
    }

    public static function remember(string $courier, string $city, ?string $code, string $courierCityName): self
    {
        return static::query()->updateOrCreate(
            ['courier' => $courier, 'city_name' => static::normalize($city)],
            ['courier_city_code' => $code, 'courier_city_name' => $courierCityName]
        );
    }
}
