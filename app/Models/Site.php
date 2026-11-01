<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'location',
        'status',
    ];

    public function routers(): HasMany
    {
        return $this->hasMany(Router::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
