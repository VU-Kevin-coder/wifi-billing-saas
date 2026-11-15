<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Payments\XenteGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class XenteProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_and_classifies_xente_mobile_money_product_items(): void
    {
        PlatformSetting::setSecret('payment.xente.app_key', 'test-app-key');
        PlatformSetting::setSecret('payment.xente.app_password', 'test-app-password');
        PlatformSetting::setSecret('payment.xente.user_id', 'test-user-id');

        Http::fake([
            'api.xente.co/api/auth/login' => Http::response(['Token' => 'test-token']),
            'api.xente.co/products' => Http::response(['data' => ['collection' => [
                ['productId' => 'MTNMOBILEMONEYUG', 'name' => 'MTN'],
                ['productId' => 'MTNMOBILEMONEYPAYOUTUG', 'name' => 'MTN payouts'],
                ['productId' => 'AIRTELMONEYUG', 'name' => 'Airtel'],
                ['productId' => 'AIRTELMONEYPAYOUTUG', 'name' => 'Airtel payouts'],
            ]]]),
            'api.xente.co/products/*' => function ($request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));
                $item = match ($id) {
                    'MTNMOBILEMONEYUG' => ['productItemId' => 'MTN-COLLECT', 'name' => 'MTN collection', 'type' => 'CREDIT'],
                    'MTNMOBILEMONEYPAYOUTUG' => ['productItemId' => 'MTN-PAYOUT', 'name' => 'MTN payout', 'type' => 'DEBIT'],
                    'AIRTELMONEYUG' => ['productItemId' => 'AIRTEL-COLLECT', 'name' => 'Airtel collection', 'type' => 'CREDIT'],
                    default => ['productItemId' => 'AIRTEL-PAYOUT', 'name' => 'Airtel payout', 'type' => 'DEBIT'],
                };

                return Http::response(['data' => ['collection' => [$item]]]);
            },
        ]);

        $products = app(XenteGateway::class)->availableProductItems();

        $this->assertEqualsCanonicalizing([
            ['operation' => 'collection', 'network' => 'mtn', 'id' => 'MTN-COLLECT', 'name' => 'MTN collection'],
            ['operation' => 'payout', 'network' => 'mtn', 'id' => 'MTN-PAYOUT', 'name' => 'MTN payout'],
            ['operation' => 'collection', 'network' => 'airtel', 'id' => 'AIRTEL-COLLECT', 'name' => 'Airtel collection'],
            ['operation' => 'payout', 'network' => 'airtel', 'id' => 'AIRTEL-PAYOUT', 'name' => 'Airtel payout'],
        ], $products);
    }

    public function test_selected_xente_products_must_exist_for_the_requested_network_and_operation(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        Http::fake([
            'api.xente.co/api/auth/login' => Http::response(['Token' => 'test-token']),
            'api.xente.co/products' => Http::response(['data' => ['collection' => [
                ['productId' => 'MTNMOBILEMONEYUG', 'name' => 'MTN'],
                ['productId' => 'MTNMOBILEMONEYPAYOUTUG', 'name' => 'MTN payouts'],
                ['productId' => 'AIRTELMONEYUG', 'name' => 'Airtel'],
                ['productId' => 'AIRTELMONEYPAYOUTUG', 'name' => 'Airtel payouts'],
            ]]]),
            'api.xente.co/products/*' => function ($request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));
                $item = match ($id) {
                    'MTNMOBILEMONEYUG' => ['productItemId' => 'MTN-COLLECT', 'name' => 'MTN collection', 'type' => 'CREDIT'],
                    'MTNMOBILEMONEYPAYOUTUG' => ['productItemId' => 'MTN-PAYOUT', 'name' => 'MTN payout', 'type' => 'DEBIT'],
                    'AIRTELMONEYUG' => ['productItemId' => 'AIRTEL-COLLECT', 'name' => 'Airtel collection', 'type' => 'CREDIT'],
                    default => ['productItemId' => 'AIRTEL-PAYOUT', 'name' => 'Airtel payout', 'type' => 'DEBIT'],
                };

                return Http::response(['data' => ['collection' => [$item]]]);
            },
        ]);

        $this->actingAs($admin)
            ->put(route('platform.configuration.payments.update'), [
                'active_gateway' => 'xente',
                'pawapay_environment' => 'sandbox',
                'xente_app_key' => 'test-app-key',
                'xente_app_password' => 'test-app-password',
                'xente_user_id' => 'test-user-id',
                'xente_collection_mtn' => 'MTN-COLLECT',
                'xente_collection_airtel' => 'AIRTEL-COLLECT',
                'xente_payout_mtn' => 'AIRTEL-PAYOUT',
                'xente_payout_airtel' => 'MTN-PAYOUT',
            ])
            ->assertSessionHasErrors('xente_payout_mtn');

        $this->assertNull(PlatformSetting::get('payment.active_gateway'));
    }
}
