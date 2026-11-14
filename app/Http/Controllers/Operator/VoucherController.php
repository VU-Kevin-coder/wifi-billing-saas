<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\AccessRevoker;
use App\Services\Policy\Policy;
use App\Services\Tenancy\TenantContext;
use App\Support\ReportingTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function index(TenantContext $context)
    {
        // KPI Calculations (tenant-scoped via global scope)
        $generatedThisMonth = Voucher::whereBetween('created_at', ReportingTime::monthRange())->count();
        $unusedStock = Voucher::where('status', 'unused')->count();
        $unusedFaceValueByCurrency = Voucher::query()
            ->where('vouchers.status', 'unused')
            ->join('packages', 'packages.id', '=', 'vouchers.package_id')
            ->select('packages.currency')
            ->selectRaw('SUM(packages.price) as face_value')
            ->groupBy('packages.currency')
            ->orderBy('packages.currency')
            ->get();
        $redeemed = Voucher::where('status', 'redeemed')->count();
        $voided = Voucher::where('status', 'voided')->count();
        $expired = Voucher::where('status', 'expired')->count();

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
        $voucherCharset = PlatformSetting::get('voucher.charset', 'unambiguous');

        return view('operator.vouchers.index', compact(
            'generatedThisMonth', 'unusedStock', 'unusedFaceValueByCurrency', 'redeemed', 'voided', 'expired',
            'vouchers', 'packages', 'latestBatch', 'voucherSettings', 'voucherCharset'
        ));
    }

    public function store(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $settings = $tenant->settings;

        $maxPerBatch = (int) Policy::get('voucher.max_per_batch', 1000);

        $validated = $request->validate([
            'package_id' => ['required', Rule::exists('packages', 'id')->where('tenant_id', $tenant->id)],
            'quantity' => ['required', 'integer', 'min:1', "max:{$maxPerBatch}"],
        ]);

        // Enforce minimum length
        $minLength = (int) Policy::get('voucher.min_length', 0);
        $length = (int) ($settings['voucher_length'] ?? 8);
        if ($minLength > 0 && $length < $minLength) {
            return back()->withErrors(['quantity' => "Voucher length in settings ({$length}) is below the platform minimum ({$minLength}). Update Settings → Hotspot."]);
        }

        $charset = (string) PlatformSetting::get('voucher.charset', 'unambiguous');
        $expiryDays = max(0, (int) PlatformSetting::get('voucher.unused_expiry', 365));

        try {
            // One transaction: a batch either exists with all of its vouchers or not at all.
            [$batch, $batchCode] = DB::transaction(function () use ($tenant, $validated, $settings, $charset, $expiryDays) {
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
                    $charset,
                );

                $now = now();
                $expiresAt = $expiryDays > 0 ? $now->copy()->addDays($expiryDays) : null;
                $rows = array_map(fn (string $code) => [
                    'tenant_id' => $tenant->id,
                    'voucher_batch_id' => $batch->id,
                    'package_id' => $validated['package_id'],
                    'code' => $code,
                    'status' => 'unused',
                    'expires_at' => $expiresAt,
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
     * Take a voucher out of circulation. A redeemed voucher also loses its connection.
     */
    public function void(Request $request, AccessRevoker $revoker, string $tenant, int $voucher)
    {
        $model = Voucher::findOrFail($voucher); // tenant-scoped

        if ($model->status === 'voided') {
            return back()->withErrors(['error' => 'This voucher is already voided.']);
        }
        if (! in_array($model->status, ['unused', 'redeemed'], true)) {
            return back()->withErrors(['error' => 'Only unused or active vouchers can be voided.']);
        }

        $queued = $revoker->revoke($model, 'voided by '.($request->user()?->email ?? 'staff'));

        $response = back()->with('status', "Voucher {$model->code} was voided.");

        return $queued
            ? $response->with('warning', 'Router revocation is pending until the site agent confirms the user was disabled.')
            : $response->with('warning', 'Voucher voided, but router revocation could not be queued. The session remains pending; check the site agent and router.');
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
    private function generateUniqueCodes(int $quantity, string $prefix, int $length, string $format, string $charset): array
    {
        $codes = [];

        for ($round = 0; count($codes) < $quantity && $round < 100; $round++) {
            $candidates = [];
            for ($attempt = 0, $need = $quantity - count($codes); count($candidates) < $need && $attempt < $need * 10; $attempt++) {
                $code = $this->makeCode($prefix, $length, $format, $charset);
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

    private function makeCode(string $prefix, int $length, string $format, string $charset): string
    {
        $length = max(1, $length);
        $effectiveFormat = match (true) {
            $charset === 'numeric', $format === 'numeric' => 'numeric',
            $charset === 'unambiguous' => 'unambiguous',
            default => 'alphanumeric',
        };
        $chars = match ($effectiveFormat) {
            'numeric' => $charset === 'unambiguous' ? '23456789' : '0123456789',
            'alphanumeric' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
            default => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        };
        $raw = '';
        for ($i = 0; $i < $length; $i++) {
            $firstChar = $i === 0 && $effectiveFormat === 'numeric'
                ? str_replace('0', '', $chars)
                : $chars;
            $raw .= $firstChar[random_int(0, strlen($firstChar) - 1)];
        }

        return $prefix !== '' ? "{$prefix}-{$raw}" : $raw;
    }
}
