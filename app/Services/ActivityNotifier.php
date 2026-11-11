<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class ActivityNotifier
{
    public function tenantOperators(Tenant $tenant, string $title, string $message, string $path): void
    {
        $users = User::withoutTenancy()
            ->where('tenant_id', $tenant->getKey())
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                'operator_admin',
                'operator_finance',
                'operator_network',
                'operator_support',
            ]))
            ->get();

        $this->send($users, $title, $message, $tenant->url($path));
    }

    public function platformAdmins(string $title, string $message, string $path): void
    {
        $users = User::withoutTenancy()
            ->whereHas('roles', fn ($query) => $query->where('name', 'platform_admin'))
            ->get();

        $this->send($users, $title, $message, rtrim((string) config('app.url'), '/').$path);
    }

    public function routerFailure(Router $router, string $reason = 'The application health checks marked the router offline.'): void
    {
        $tenant = $router->tenant;
        if (! $tenant || ! Cache::add($this->routerAlertKey($router), true, now()->addHour())) {
            return;
        }

        $message = "{$router->name} in {$tenant->name} is unreachable. {$reason}";
        $this->tenantOperators($tenant, 'Router health alert', $message, '/operator/routers/'.$router->getKey());
        $this->platformAdmins('Router failure reported', $message, '/platform/tenants/'.$tenant->getKey());
    }

    public function routerRecovered(Router $router): void
    {
        $tenant = $router->tenant;
        if (! $tenant) {
            return;
        }

        Cache::forget($this->routerAlertKey($router));
        $message = "{$router->name} in {$tenant->name} is reporting online again.";
        $this->tenantOperators($tenant, 'Router recovered', $message, '/operator/routers/'.$router->getKey());
        $this->platformAdmins('Router recovered', $message, '/platform/tenants/'.$tenant->getKey());
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, string $title, string $message, string $actionUrl): void
    {
        $notification = new ActivityNotification($title, $message, $actionUrl);

        foreach ($users as $user) {
            $user->notify($notification);
        }
    }

    private function routerAlertKey(Router $router): string
    {
        return 'router-health-alert:'.$router->getKey();
    }
}
