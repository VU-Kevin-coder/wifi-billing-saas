<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VoucherGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
    }

    /** @return array{0: Tenant, 1: User, 2: Package} */
    private function operator(string $slug, array $settings = []): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active']);
        if ($settings) {
            $tenant->update(['settings' => array_merge($tenant->settings ?? [], $settings)]);
        }
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');
        $package = Package::create([
            'tenant_id' => $tenant->id, 'name' => 'Daily', 'price' => 1000, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'days', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);

        return [$tenant, $user, $package];
    }

    private function generate(Tenant $tenant, User $user, Package $package, int $quantity)
    {
        URL::defaults(['tenant' => $tenant->slug]);

        return $this->actingAs($user)->post(route('operator.vouchers.store'), [
            'package_id' => $package->id,
            'quantity' => $quantity,
        ]);
    }

    public function test_two_tenants_with_default_settings_can_each_create_their_first_batch(): void
    {
        [$a, $userA, $packageA] = $this->operator('alpha');
        [$b, $userB, $packageB] = $this->operator('bravo');

        $this->generate($a, $userA, $packageA, 5)->assertSessionHasNoErrors();
        $this->generate($b, $userB, $packageB, 5)->assertSessionHasNoErrors();

        $this->assertSame('KL-001', VoucherBatch::withoutTenancy()->where('tenant_id', $a->id)->value('batch_code'));
        $this->assertSame('KL-001', VoucherBatch::withoutTenancy()->where('tenant_id', $b->id)->value('batch_code'));
        $this->assertSame(5, Voucher::withoutTenancy()->where('tenant_id', $a->id)->count());
        $this->assertSame(5, Voucher::withoutTenancy()->where('tenant_id', $b->id)->count());
    }

    public function test_batch_numbers_are_never_reused_after_a_batch_is_deleted(): void
    {
        [$tenant, $user, $package] = $this->operator('alpha');

        $this->generate($tenant, $user, $package, 2);
        $this->generate($tenant, $user, $package, 2);
        VoucherBatch::withoutTenancy()->where('tenant_id', $tenant->id)->where('batch_code', 'KL-001')->delete();
        $this->generate($tenant, $user, $package, 2)->assertSessionHasNoErrors();

        $codes = VoucherBatch::withoutTenancy()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('batch_code')->all();
        $this->assertSame(['KL-002', 'KL-003'], $codes);
    }

    public function test_generates_the_requested_number_of_unique_codes_in_the_configured_format(): void
    {
        [$tenant, $user, $package] = $this->operator('alpha', ['voucher_prefix' => 'ACME', 'voucher_length' => 8]);

        $this->generate($tenant, $user, $package, 300)->assertSessionHasNoErrors();

        $codes = Voucher::withoutTenancy()->where('tenant_id', $tenant->id)->pluck('code');
        $this->assertCount(300, $codes);
        $this->assertCount(300, $codes->unique());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^ACME-[A-HJ-NP-Z2-9]{8}$/', $code);
        }
        $this->assertSame(1, AuditLog::where('action', 'vouchers.generated')->count());
    }

    public function test_numeric_vouchers_use_digits_only(): void
    {
        [$tenant, $user, $package] = $this->operator('alpha', ['voucher_prefix' => '', 'voucher_format' => 'numeric', 'voucher_length' => 8]);

        $this->generate($tenant, $user, $package, 100)->assertSessionHasNoErrors();

        foreach (Voucher::withoutTenancy()->where('tenant_id', $tenant->id)->pluck('code') as $code) {
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{7}$/', $code);
        }
    }

    public function test_generation_avoids_codes_the_tenant_already_has(): void
    {
        // One-digit numeric codes: only 1..9 exist. Seed 1..8 so 9 is the single free code.
        [$tenant, $user, $package] = $this->operator('alpha', ['voucher_prefix' => '', 'voucher_format' => 'numeric', 'voucher_length' => 1]);
        $batch = VoucherBatch::create(['tenant_id' => $tenant->id, 'package_id' => $package->id, 'batch_code' => 'SEED', 'quantity' => 8]);
        foreach (range(1, 8) as $n) {
            Voucher::create(['tenant_id' => $tenant->id, 'voucher_batch_id' => $batch->id, 'package_id' => $package->id, 'code' => (string) $n, 'status' => 'unused']);
        }

        $this->generate($tenant, $user, $package, 1)->assertSessionHasNoErrors();

        $this->assertTrue(Voucher::withoutTenancy()->where('tenant_id', $tenant->id)->where('code', '9')->exists());
    }

    public function test_running_out_of_unique_codes_fails_cleanly_with_no_partial_batch(): void
    {
        [$tenant, $user, $package] = $this->operator('alpha', ['voucher_prefix' => '', 'voucher_format' => 'numeric', 'voucher_length' => 1]);

        $this->generate($tenant, $user, $package, 20)->assertSessionHasErrors('quantity');

        $this->assertSame(0, VoucherBatch::withoutTenancy()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, Voucher::withoutTenancy()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'vouchers.generated')->count());
    }

    public function test_the_same_code_may_exist_in_two_tenants_but_not_twice_in_one(): void
    {
        [$a, , $packageA] = $this->operator('alpha');
        [$b, , $packageB] = $this->operator('bravo');
        $make = fn (Tenant $t, Package $p, string $batch) => VoucherBatch::create(
            ['tenant_id' => $t->id, 'package_id' => $p->id, 'batch_code' => $batch, 'quantity' => 1]
        );

        $batchA = $make($a, $packageA, 'B-1');
        $batchB = $make($b, $packageB, 'B-1');
        $voucher = fn (Tenant $t, Package $p, VoucherBatch $batch) => Voucher::create([
            'tenant_id' => $t->id, 'voucher_batch_id' => $batch->id, 'package_id' => $p->id, 'code' => 'SAME-1', 'status' => 'unused',
        ]);

        $voucher($a, $packageA, $batchA);
        $voucher($b, $packageB, $batchB);
        $this->assertSame(2, Voucher::withoutTenancy()->where('code', 'SAME-1')->count());

        $this->expectException(QueryException::class);
        $voucher($a, $packageA, $batchA);
    }

    public function test_a_package_from_another_tenant_cannot_be_used_for_vouchers(): void
    {
        [$a, $userA] = $this->operator('alpha');
        [, , $foreignPackage] = $this->operator('bravo');

        $this->generate($a, $userA, $foreignPackage, 3)->assertSessionHasErrors('package_id');

        $this->assertSame(0, VoucherBatch::withoutTenancy()->where('tenant_id', $a->id)->count());
    }

    public function test_a_package_from_another_tenant_cannot_be_assigned_to_a_customer(): void
    {
        [$a, $userA, $ownPackage] = $this->operator('alpha');
        [, , $foreignPackage] = $this->operator('bravo');
        URL::defaults(['tenant' => $a->slug]);

        $this->actingAs($userA)
            ->post(route('operator.customers.store'), ['name' => 'Jane', 'package_id' => $foreignPackage->id])
            ->assertSessionHasErrors('package_id');

        $this->actingAs($userA)
            ->post(route('operator.customers.store'), ['name' => 'Jane', 'package_id' => $ownPackage->id])
            ->assertSessionHasNoErrors();
    }
}
