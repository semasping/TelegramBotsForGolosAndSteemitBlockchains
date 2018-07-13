<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class GolosBotsSettings extends Model
{
    protected $fillable = ['chat_id', 'bot_name', 'lang', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    public function getDataAttribute($value)
    {
        return json_decode($value, true);
    }

    public function setDataAttribute($value)
    {
        $this->attributes['data'] = json_encode($value);
    }
}
