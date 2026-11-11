<?php

namespace App\Observers;

use App\Models\Package;
use App\Models\Router;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherBatch;
use App\Services\ActivityNotifier;
use Illuminate\Database\Eloquent\Model;

class ActivityNotificationObserver
{
    public function __construct(private readonly ActivityNotifier $notifier) {}

    public function created(Model $model): void
    {
        if ($model instanceof Tenant) {
            $this->notifier->platformAdmins(
                'New tenant signup',
                "{$model->name} ({$model->slug}) created an account.",
                '/platform/tenants/'.$model->getKey(),
            );
        } elseif ($model instanceof User) {
            $this->notifier->platformAdmins(
                'New user signup',
                "{$model->name} ({$model->email}) created an account.",
                '/platform/tenants'.($model->tenant_id ? '/'.$model->tenant_id : ''),
            );
        } elseif ($model instanceof Router && $model->tenant) {
            $this->notifier->tenantOperators(
                $model->tenant,
                'Router added',
                "{$model->name} was added to your network.",
                '/operator/routers/'.$model->getKey(),
            );
        } elseif ($model instanceof Package && $model->tenant) {
            $this->notifier->tenantOperators(
                $model->tenant,
                'Package created',
                "Internet package “{$model->name}” was created.",
                '/operator/packages',
            );
        } elseif ($model instanceof Site && $model->tenant) {
            $this->notifier->tenantOperators($model->tenant, 'Site added', "Site “{$model->name}” was added.", '/operator/sites');
        } elseif ($model instanceof VoucherBatch && $model->tenant) {
            $this->notifier->tenantOperators(
                $model->tenant,
                'Vouchers generated',
                "{$model->quantity} vouchers were generated in batch {$model->batch_code}.",
                '/operator/vouchers',
            );
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Tenant
            && $model->wasChanged(['platform_plan_id', 'plan', 'subscription_status', 'subscription_ends_at'])) {
            $plan = $model->plan ?: $model->getRawOriginal('plan') ?: 'Unassigned';
            $status = $model->subscription_status ?: 'not set';
            $message = "{$model->name}: subscription is now {$plan} ({$status}).";

            $this->notifier->platformAdmins('Tenant subscription updated', $message, '/platform/tenants/'.$model->getKey());
            $this->notifier->tenantOperators($model, 'Subscription updated', $message, '/operator/settings');
        }

        if ($model instanceof Tenant && $model->wasChanged(['status', 'kyc_status'])) {
            $message = "{$model->name}: account status is {$model->status}; KYC status is {$model->kyc_status}.";
            $this->notifier->platformAdmins('Tenant account updated', $message, '/platform/tenants/'.$model->getKey());
            $this->notifier->tenantOperators($model, 'Account status updated', $message, '/operator/settings');
        }

        if ($model instanceof Router) {
            if ($model->wasChanged('status') && in_array($model->status, ['offline', 'failed', 'provisioning_failed'], true)) {
                $this->notifier->routerFailure($model);
            } elseif ($model->wasChanged('status') && $model->status === 'online') {
                $this->notifier->routerRecovered($model);
            }

            if ($model->wasChanged([
                'name', 'site_id', 'hardware_model', 'management_ip', 'wifi_ssid',
                'has_wireless', 'enable_hotspot', 'hotspot_config',
            ]) && $model->tenant) {
                $this->notifier->tenantOperators(
                    $model->tenant,
                    'Router updated',
                    "Router settings for “{$model->name}” were updated.",
                    '/operator/routers/'.$model->getKey(),
                );
            }
        }

        if ($model instanceof Package && $model->wasChanged([
            'name', 'price', 'currency', 'duration_value', 'duration_unit',
            'speed_limit_mbps', 'device_limit', 'data_limit', 'is_published',
        ]) && $model->tenant) {
            $this->notifier->tenantOperators(
                $model->tenant,
                'Package updated',
                "Internet package “{$model->name}” was updated.",
                '/operator/packages',
            );
        }

        if ($model instanceof Site && $model->wasChanged(['name', 'location']) && $model->tenant) {
            $this->notifier->tenantOperators(
                $model->tenant,
                'Site updated',
                "Site “{$model->name}” was updated.",
                '/operator/sites',
            );
        }
    }
}
