<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Agreement wording with {placeholders}, merged per contract when printing. */
class RentalAgreementTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'rental_agreement_templates';

    protected $fillable = ['name', 'content', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
    ];
}
