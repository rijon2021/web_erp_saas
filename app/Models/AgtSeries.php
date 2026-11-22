<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgtSeries extends Model
{
    protected $table = 'agt_series';

    protected $fillable = [
        'environment', 'document_type', 'series_code', 'series_year', 'establishment_number',
        'contingency', 'first_number', 'last_number', 'next_number', 'active',
    ];

    protected $casts = [
        'series_year' => 'integer',
        'first_number' => 'integer',
        'last_number' => 'integer',
        'next_number' => 'integer',
        'active' => 'boolean',
    ];

    public function remaining(): int
    {
        return max(0, $this->last_number - $this->next_number + 1);
    }
}
