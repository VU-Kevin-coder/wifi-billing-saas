<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'package_id',
        'mac_address',
        'status',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
