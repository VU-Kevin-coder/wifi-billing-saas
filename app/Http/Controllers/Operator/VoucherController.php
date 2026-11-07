<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    public function index(TenantContext $context)
    {
        // KPI Calculations (tenant-scoped via global scope)
        $generatedThisMonth = Voucher::whereMonth('created_at', now()->month)->count();
        $unusedStock        = Voucher::where('status', 'unused')->count();
        $redeemed           = Voucher::where('status', 'redeemed')->count();
        $voided             = Voucher::where('status', 'voided')->count();

        // Paginated voucher list with eager-loaded relations
        $vouchers = Voucher::with(['package', 'batch'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Published packages for the generation form
        $packages = Package::where('is_published', true)->get();

        // Latest batch for the summary card
        $latestBatch = VoucherBatch::with(['package', 'creator'])
            ->latest()
            ->first();

        $voucherSettings = $context->getTenant()->settings;

        return view('operator.vouchers.index', compact(
            'generatedThisMonth', 'unusedStock', 'redeemed', 'voided',
            'vouchers', 'packages', 'latestBatch', 'voucherSettings'
        ));
    }

    public function store(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $settings = $tenant->settings;

        $maxPerBatch = (int) \App\Services\Policy\Policy::get('voucher.max_per_batch', 1000);

        $validated = $request->validate([
            'package_id' => ['required', Rule::exists('packages', 'id')->where('tenant_id', $tenant->id)],
            'quantity' => ['required', 'integer', 'min:1', "max:{$maxPerBatch}"],
        ]);

        // Enforce minimum length
        $minLength = (int) \App\Services\Policy\Policy::get('voucher.min_length', 0);
        $length = (int) ($settings['voucher_length'] ?? 8);
        if ($minLength > 0 && $length < $minLength) {
            return back()->withErrors(['quantity' => "Voucher length in settings ({$length}) is below the platform minimum ({$minLength}). Update Settings → Hotspot."]);
        }

        try {
            // One transaction: a batch either exists with all of its vouchers or not at all.
            [$batch, $batchCode] = DB::transaction(function () use ($request, $tenant, $validated, $settings) {
                // Serialise batch creation per tenant so numbering cannot race.
                Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

                $batchCode = $this->nextBatchCode(strtoupper($settings['customer_id_prefix'] ?? 'BATCH'));

                $batch = VoucherBatch::create([
                    'tenant_id' => $tenant->id,
                    'package_id' => $validated['package_id'],
                    'created_by' => auth()->id(),
                    'batch_code' => $batchCode,
                    'quantity' => $validated['quantity'],
                ]);

                $codes = $this->generateUniqueCodes(
                    (int) $validated['quantity'],
                    (string) ($settings['voucher_prefix'] ?? ''),
                    (int) ($settings['voucher_length'] ?? 8),
                    (string) ($settings['voucher_format'] ?? 'alphanumeric'),
                );

                $now = now();
                $rows = array_map(fn (string $code) => [
                    'tenant_id' => $tenant->id,
                    'voucher_batch_id' => $batch->id,
                    'package_id' => $validated['package_id'],
                    'code' => $code,
                    'status' => 'unused',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $codes);

                foreach (array_chunk($rows, 100) as $chunk) {
                    Voucher::insert($chunk);
                }

                AuditLog::record('vouchers.generated', $batch, [
                    'batch_code' => $batchCode,
                    'package_id' => (int) $validated['package_id'],
                    'quantity' => (int) $validated['quantity'],
                ], auth()->user(), $tenant);

                return [$batch, $batchCode];
            });
        } catch (\DomainException $exception) {
            return back()->withErrors(['quantity' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operator.vouchers.index', ['tenant' => $tenant->slug])
            ->with('status', "Successfully generated {$validated['quantity']} vouchers for {$batchCode}.");
    }

    /**
     * Next free batch code for the current tenant. Never reuses a code, even
     * when earlier batches were deleted (the count alone would collide).
     */
    private function nextBatchCode(string $prefix): string
    {
        $number = VoucherBatch::count() + 1;

        do {
            $code = $prefix.'-'.str_pad((string) $number++, 3, '0', STR_PAD_LEFT);
        } while (VoucherBatch::where('batch_code', $code)->exists());

        return $code;
    }

    /**
     * @return list<string> codes that are unique within the batch and among the tenant's existing vouchers
     */
    private function generateUniqueCodes(int $quantity, string $prefix, int $length, string $format): array
    {
        $codes = [];

        for ($round = 0; count($codes) < $quantity && $round < 100; $round++) {
            $candidates = [];
            for ($attempt = 0, $need = $quantity - count($codes); count($candidates) < $need && $attempt < $need * 10; $attempt++) {
                $code = $this->makeCode($prefix, $length, $format);
                if (! isset($codes[$code])) {
                    $candidates[$code] = true;
                }
            }

            // Array keys turn numeric strings into ints; compare as strings so the
            // varchar column (and its index) is matched correctly on every database.
            foreach (array_chunk(array_map('strval', array_keys($candidates)), 500) as $chunk) {
                $taken = array_flip(Voucher::whereIn('code', $chunk)->pluck('code')->all());
                foreach ($chunk as $code) {
                    if (! isset($taken[$code])) {
                        $codes[$code] = true;
                    }
                }
            }
        }

        if (count($codes) < $quantity) {
            throw new \DomainException('Not enough unique voucher codes are available with the current voucher length. Increase the voucher length in Settings, or generate fewer vouchers.');
        }

        return array_map('strval', array_slice(array_keys($codes), 0, $quantity));
    }

    private function makeCode(string $prefix, int $length, string $format): string
    {
        $length = max(1, $length);

        if ($format === 'numeric') {
            $raw = (string) random_int((int) str_pad('1', $length, '0'), (int) str_pad('9', $length, '9'));
        } else {
            // No look-alike characters (O, 0, I, 1).
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $raw = '';
            for ($i = 0; $i < $length; $i++) {
                $raw .= $chars[random_int(0, strlen($chars) - 1)];
            }
        }

        return $prefix !== '' ? "{$prefix}-{$raw}" : $raw;
    }
}
