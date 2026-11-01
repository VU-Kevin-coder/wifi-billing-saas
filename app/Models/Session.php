<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'sessions_log';

    protected $fillable = [
        'tenant_id', 'site_id', 'customer_id', 'voucher_id', 'package_id', 'router_id',
        'status', 'started_at', 'expires_at', 'ip_address', 'mac_address',
        'bytes_in', 'bytes_out', 'rate_in', 'rate_out',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'rate_in' => 'integer',
        'rate_out' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
