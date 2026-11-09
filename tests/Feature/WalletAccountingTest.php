<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WalletAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_kpis_only_count_completed_transactions(): void
    {
        Role::create(['name' => 'operator_admin']);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('operator_admin');

        foreach ([
            ['voucher_sale', 'completed', 200, 10],
            ['voucher_sale', 'pending', 500, 25],
            ['voucher_sale', 'failed', 300, 15],
            ['withdrawal', 'completed', 50, 0],
            ['withdrawal', 'pending', 30, 0],
            ['withdrawal', 'failed', 20, 0],
        ] as $index => [$type, $status, $amount, $fee]) {
            Transaction::create([
                'tenant_id' => $tenant->id,
                'type' => $type,
                'status' => $status,
                'amount' => $amount,
                'platform_fee' => $fee,
                'reference' => "TEST-{$index}",
            ]);
        }

        URL::defaults(['tenant' => $tenant->slug]);

        $response = $this->actingAs($admin)->get('http://acme.localhost:8000/operator/wallet');

        $response->assertOk()
            ->assertViewHas('totalGross', 200)
            ->assertViewHas('totalFees', 10)
            ->assertViewHas('totalWithdrawn', 50)
            ->assertViewHas('pendingPayouts', 30);
    }
}
