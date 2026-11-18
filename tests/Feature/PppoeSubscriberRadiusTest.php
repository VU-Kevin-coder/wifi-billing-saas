<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RadiusPolicyController;
use App\Models\Package;
use App\Models\Router;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPeer;
use App\Services\RadiusAccountingService;
use App\Services\RadiusUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PppoeSubscriberRadiusTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscriber_creation_shows_credentials_once_and_rejects_non_pppoe_packages(): void
    {
        $tenant = Tenant::create(['name' => 'Subscriber tenant', 'slug' => 'subscriber-tenant', 'status' => 'active']);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'PPPoE only',
            'price' => 1000,
            'duration_value' => 30,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'features' => ['PPPoE'],
        ]);
        Role::findOrCreate('operator_admin', 'web');
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');
        URL::defaults(['tenant' => $tenant->slug]);
        $url = route('operator.subscribers.store', ['tenant' => $tenant->slug]);
        $response = $this->actingAs($operator)->post($url, [
            'name' => 'New subscriber',
            'package_id' => $package->id,
            '_idempotency_key' => str_repeat('a', 40),
        ]);
        $subscriber = Subscriber::withoutTenancy()->where('tenant_id', $tenant->id)->firstOrFail();
        $storedCiphertext = DB::table('subscribers')->where('id', $subscriber->id)->value('password');

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^ppp[0-9a-z]+[a-z0-9]{12}$/', $subscriber->username);
        $this->assertNotSame($subscriber->password, $storedCiphertext);
        $this->assertStringContainsString($subscriber->password, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, Subscriber::withoutTenancy()->where('tenant_id', $tenant->id)->count());

        $nonPppoePackage = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'HotSpot only',
            'price' => 1000,
            'duration_value' => 1,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'features' => ['Hotspot'],
        ]);
        $invalidResponse = $this->post($url, [
            'name' => 'Invalid subscriber',
            'package_id' => $nonPppoePackage->id,
            '_idempotency_key' => str_repeat('b', 40),
        ]);

        $this->assertSame(1, Subscriber::withoutTenancy()->where('tenant_id', $tenant->id)->count());
        $invalidResponse->assertRedirect()->assertSessionHasErrors('package_id');
    }

    public function test_pppoe_credentials_are_authorized_only_for_the_router_tenants_active_subscriber(): void
    {
        config(['radius.enabled' => true]);
        [$tenant, $router, $package] = $this->makeRadiusRouter();
        $subscriber = $this->makeSubscriber($tenant, $package, [
            'username' => 'ppp-account-1',
            'password' => 'generated-secure-password',
        ]);
        $service = app(RadiusAccountingService::class);

        $authorized = $service->authorizePppoeForPeer(
            $router->id,
            '10.8.1.20',
            'radius-nas-test',
            'session-1',
            $subscriber->username,
        );
        $this->assertSame('accept', $authorized['decision']);
        $this->assertSame('generated-secure-password', $authorized['cleartext_password']);
        $this->assertSame(25, $authorized['policy']['rate_limit_mbps']);

        $request = Request::create('/internal/radius/v1/authorize', 'POST', [
            'nas_identifier' => 'radius-nas-test',
            'nas_session_id' => 'session-1',
            'voucher_code' => $subscriber->username,
            'service_type' => 'Framed-User',
        ]);
        $request->attributes->set('authenticated_router_id', $router->id);
        $request->attributes->set('authenticated_peer_ip', '10.8.1.20');
        $response = app(RadiusPolicyController::class)->authorize($request, $service);
        $policyResponse = $response->getData(true);
        $this->assertSame('generated-secure-password', $policyResponse['control:Cleartext-Password']['value'][0]);
        $this->assertSame('25M/25M', $policyResponse['reply:Mikrotik-Rate-Limit']);

        $otherTenant = Tenant::create(['name' => 'Other tenant', 'slug' => 'other-tenant', 'status' => 'active']);
        $otherPackage = Package::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other PPPoE',
            'price' => 1000,
            'duration_value' => 1,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 5,
            'device_limit' => 1,
            'features' => ['PPPoE'],
        ]);
        $otherSubscriber = $this->makeSubscriber($otherTenant, $otherPackage, [
            'username' => 'ppp-other-tenant',
        ]);

        $rejected = $service->authorizePppoeForPeer(
            $router->id,
            '10.8.1.20',
            'radius-nas-test',
            'session-2',
            $otherSubscriber->username,
        );
        $this->assertSame(['decision' => 'reject'], $rejected);

        $subscriber->forceFill(['status' => 'suspended'])->saveOrFail();
        $this->assertSame(['decision' => 'reject'], $service->authorizePppoeForPeer(
            $router->id,
            '10.8.1.20',
            'radius-nas-test',
            'session-3',
            $subscriber->username,
        ));
    }

    public function test_pppoe_accounting_adds_only_counter_deltas_once_and_tracks_session_generations(): void
    {
        config(['radius.enabled' => true]);
        [$tenant, $router, $package] = $this->makeRadiusRouter();
        $subscriber = $this->makeSubscriber($tenant, $package, ['username' => 'ppp-account-2']);
        $service = app(RadiusAccountingService::class);
        $base = [
            'authenticated_router_id' => $router->id,
            'authenticated_peer_ip' => '10.8.1.20',
            'nas_identifier' => 'radius-nas-test',
            'nas_session_id' => 'session-reused',
            'username' => $subscriber->username,
        ];

        $service->recordPppoeAccountingEvent($base + [
            'status_type' => 'Start',
            'input_octets' => 0,
            'output_octets' => 0,
        ]);
        $interim = $base + [
            'status_type' => 'Interim-Update',
            'input_octets' => 1024,
            'output_octets' => 2048,
        ];
        $service->recordPppoeAccountingEvent($interim);
        $service->recordPppoeAccountingEvent($interim);
        $this->assertSame(3072, $subscriber->fresh()->bytes_used);

        $service->recordPppoeAccountingEvent($base + [
            'status_type' => 'Stop',
            'input_octets' => 2048,
            'output_octets' => 4096,
        ]);
        $this->assertSame(6144, $subscriber->fresh()->bytes_used);
        $this->assertDatabaseHas('radius_subscriber_sessions', [
            'router_id' => $router->id,
            'subscriber_id' => $subscriber->id,
            'nas_session_id' => 'session-reused',
            'generation' => 1,
            'state' => 'closed',
        ]);

        $service->recordPppoeAccountingEvent($base + [
            'status_type' => 'Start',
            'input_octets' => 0,
            'output_octets' => 0,
        ]);
        $this->assertDatabaseHas('radius_subscriber_sessions', [
            'router_id' => $router->id,
            'subscriber_id' => $subscriber->id,
            'nas_session_id' => 'session-reused',
            'generation' => 2,
            'state' => 'open',
        ]);

        $otherSubscriber = $this->makeSubscriber($tenant, $package, ['username' => 'ppp-account-3']);
        try {
            $service->recordPppoeAccountingEvent(array_merge($base, [
                'username' => $otherSubscriber->username,
                'status_type' => 'Interim-Update',
                'input_octets' => 100,
                'output_octets' => 100,
            ]));
            $this->fail('A session ID already bound to another subscriber must be rejected.');
        } catch (RadiusUnavailableException) {
            $this->assertSame(0, $otherSubscriber->fresh()->bytes_used);
        }
    }

    private function makeRadiusRouter(): array
    {
        $tenant = Tenant::create(['name' => 'PPPoE tenant', 'slug' => 'pppoe-tenant', 'status' => 'active']);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'PPPoE package',
            'price' => 1000,
            'duration_value' => 30,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 25,
            'device_limit' => 2,
            'data_limit' => 'Unlimited',
            'features' => ['PPPoE'],
        ]);
        $router = Router::create(['tenant_id' => $tenant->id, 'name' => 'PPPoE router']);
        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => 'radius-nas-test',
            'radius_shared_secret' => str_repeat('a', 64),
        ])->saveOrFail();
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'PPPoE peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);

        return [$tenant, $router->fresh(), $package];
    }

    private function makeSubscriber(Tenant $tenant, Package $package, array $overrides = []): Subscriber
    {
        return Subscriber::create(array_merge([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => 'Test Subscriber',
            'username' => 'ppp-account',
            'password' => 'subscriber-password',
            'status' => 'active',
            'bytes_used' => 0,
            'expires_at' => now()->addDays(30),
        ], $overrides));
    }
}
