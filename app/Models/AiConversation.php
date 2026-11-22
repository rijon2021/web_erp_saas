<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $fillable = ['user_id', 'title', 'input_tokens', 'output_tokens'];

    protected $casts = [
        'user_id' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    public function messages()
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }
}
