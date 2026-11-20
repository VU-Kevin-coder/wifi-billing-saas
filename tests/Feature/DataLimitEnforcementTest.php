<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DataLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DataLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('validLimits')]
    public function test_valid_limits_become_bytes(string $text, int $bytes): void
    {
        $this->assertSame($bytes, DataLimit::bytes($text));
        $this->assertTrue(DataLimit::isValid($text));
    }

    public static function validLimits(): array
    {
        return [
            '1GB' => ['1GB', 1073741824],
            'lower case with space' => ['5 gb', 5368709120],
            '500 MB' => ['500 MB', 524288000],
            'decimal' => ['2.5GB', 2684354560],
            'kilobytes' => ['100KB', 102400],
            'terabytes' => ['1TB', 1099511627776],
            'spelled bytes' => ['10 Gbytes', 10737418240],
        ];
    }

    #[DataProvider('unlimitedValues')]
    public function test_unlimited_values_mean_no_cap(?string $text): void
    {
        $this->assertNull(DataLimit::bytes($text));
        $this->assertTrue(DataLimit::isUnlimited($text));
    }

    public static function unlimitedValues(): array
    {
        return [[null], [''], ['  '], ['Unlimited'], ['unlimited data'], ['No limit'], ['none']];
    }

    #[DataProvider('invalidLimits')]
    public function test_unclear_limits_are_rejected_not_guessed(string $text): void
    {
        $this->assertFalse(DataLimit::isValid($text));
        $this->expectException(\InvalidArgumentException::class);
        DataLimit::bytes($text);
    }

    public static function invalidLimits(): array
    {
        return [['lots'], ['5'], ['GB'], ['-1GB'], ['1 GB per day'], ['0GB'], ['1,5GB'], ['5 Gigabytes']];
    }

    public function test_operators_can_only_save_limits_the_policy_service_can_enforce(): void
    {
        Role::findOrCreate('operator_admin', 'web');
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('operator_admin');
        URL::defaults(['tenant' => 'acme']);

        $form = [
            'name' => 'Weekly',
            'price' => 5000,
            'duration_value' => 7,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
        ];

        $this->actingAs($admin)->post(route('operator.packages.store'), [...$form, 'data_limit' => 'lots'])
            ->assertSessionHasErrors('data_limit');
        $this->actingAs($admin)->post(route('operator.packages.store'), [...$form, 'data_limit' => '5'])
            ->assertSessionHasErrors('data_limit');
        $this->actingAs($admin)->post(route('operator.packages.store'), [...$form, 'data_limit' => '5GB'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('operator.packages.store'), [...$form, 'name' => 'Open', 'data_limit' => 'Unlimited'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Package::withoutTenancy()->where('tenant_id', $tenant->id)->count());
    }
}
