<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;

class TenantContext
{
    /**
     * The tenant the current request is operating within.
     * Null for platform admins (global scope) or unauthenticated requests.
     */
    protected ?Tenant $tenant = null;

    /**
     * Set the current tenant.
     */
    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        if ($tenant) {
            \Illuminate\Support\Facades\URL::defaults(['tenant' => $tenant->slug]);
        }
    }

    /**
     * Get the current tenant.
     */
    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Get the current tenant's ID, or null if no tenant is set.
     */
    public function getTenantId(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * Check if a tenant context is active.
     */
    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Temporarily run a callback within a specific tenant's context.
     * Useful for background jobs, commands, or platform-admin impersonation.
     *
     * Example:
     *   TenantContext::runAs($tenant, fn() => Package::all());
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }

    /**
     * Clear the current tenant context.
     */
    public function forget(): void
    {
        $this->tenant = null;
    }

    /**
     * Resolve the tenant from the current request's host (subdomain).
     * Example: "acme.localhost" -> Tenant::where('slug', 'acme')
     */
    public function resolveFromHost(string $host): ?Tenant
    {
        // Strip the port if present (e.g., "acme.localhost:8000" -> "acme.localhost")
        $host = strtolower(explode(':', $host)[0]);
        $baseDomain = strtolower((string) config('tenancy.base_domain', 'localhost'));

        if ($host === '' || $host === $baseDomain) {
            return null;
        }

        $suffix = '.'.$baseDomain;
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        // Only a single label is allowed (no nested subdomains).
        if ($slug === '' || str_contains($slug, '.')) {
            return null;
        }

        return Tenant::where('slug', $slug)->first();
    }
}