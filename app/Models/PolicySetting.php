<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicySetting extends Model
{
    protected $fillable = ['key', 'value', 'category', 'description'];

    protected $casts = [
        'value' => 'json',
    ];
}
