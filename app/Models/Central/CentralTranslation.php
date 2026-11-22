<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class CentralTranslation extends Model
{
    protected $connection = 'central';

    protected $table = 'central_translations';

    protected $fillable = ['locale', 'group', 'key', 'value', 'is_customized'];

    protected $casts = [
        'is_customized' => 'boolean',
    ];
}
