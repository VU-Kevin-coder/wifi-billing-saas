<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'site_id', 'voucher_batch_id', 'package_id', 'code', 'status', 'redeemed_price',
        'assigned_site', 'redeemed_by', 'redeemed_at', 'expires_at',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(VoucherBatch::class, 'voucher_batch_id');
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function sessions()
    {
        return $this->hasMany(Session::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
