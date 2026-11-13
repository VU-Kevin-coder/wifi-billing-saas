<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_sweep_removes_expired_data_but_keeps_live_and_pending_sessions(): void
    {
        $tenant = Tenant::create(['name' => 'Retention Test', 'slug' => 'retention-test', 'status' => 'active']);
        PlatformSetting::set('audit.retention', 90);
        PlatformSetting::set('retention.sessions', 30);
        PlatformSetting::set('retention.commands', 7);
        PlatformSetting::set('retention.sms', 7);

        $old = now()->subDays(100);
        $fresh = now()->subDays(2);
        DB::table('audit_logs')->insert([
            ['action' => 'old.action', 'created_at' => $old, 'updated_at' => $old],
            ['action' => 'fresh.action', 'created_at' => $fresh, 'updated_at' => $fresh],
        ]);
        DB::table('sms_messages')->insert([
            ['tenant_id' => $tenant->id, 'type' => 'test', 'recipient' => '+256700000000', 'status' => 'sent', 'segments' => 1, 'created_at' => $old],
            ['tenant_id' => $tenant->id, 'type' => 'test', 'recipient' => '+256700000000', 'status' => 'sent', 'segments' => 1, 'created_at' => $fresh],
        ]);

        $expired = Session::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'status' => 'expired',
            'started_at' => $old,
            'expires_at' => $old,
            'pending_revocation' => false,
        ]);
        $active = Session::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'started_at' => $old,
            'expires_at' => now()->addHour(),
        ]);
        $pending = Session::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'status' => 'expired',
            'started_at' => $old,
            'expires_at' => $old,
            'pending_revocation' => true,
        ]);
        DB::table('sessions_log')
            ->whereIn('id', [$expired->id, $active->id, $pending->id])
            ->update(['updated_at' => $old]);

        $this->artisan('platform:prune-retained-data')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'old.action']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fresh.action']);
        $this->assertDatabaseMissing('sms_messages', ['created_at' => $old]);
        $this->assertDatabaseHas('sessions_log', ['id' => $active->id]);
        $this->assertDatabaseHas('sessions_log', ['id' => $pending->id]);
        $this->assertDatabaseMissing('sessions_log', ['id' => $expired->id]);
    }
}
