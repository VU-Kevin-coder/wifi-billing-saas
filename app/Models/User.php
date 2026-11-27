<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use BelongsToTenant, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'mfa_enrollment_required_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if the user is a Platform Admin.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->hasRole('platform_admin');
    }

    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->enabledTwoFactorMethods() !== [];
    }

    public function enabledTwoFactorMethods(): array
    {
        return array_values(array_filter(
            ['totp', 'email', 'sms'],
            fn (string $method): bool => MFA::isEnabled($this, $method)
        ));
    }
}
