<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
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
        $validated = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $tenant = $context->getTenant();
        $settings = $tenant->settings;

        // 1. Create the Batch
        // Use the customer_id_prefix from settings, fallback to 'BATCH'
        $batchPrefix = strtoupper($settings['customer_id_prefix'] ?? 'BATCH');
        $batchCount = VoucherBatch::count() + 1;
        $batchCode = $batchPrefix . '-' . str_pad($batchCount, 3, '0', STR_PAD_LEFT);

        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $validated['package_id'],
            'created_by' => auth()->id(),
            'batch_code' => $batchCode,
            'quantity' => $validated['quantity'],
        ]);

        // 2. Generate the individual Vouchers
        $vouchersToInsert = [];
        $now = now();

        // Extract settings
        $prefix = $settings['voucher_prefix'] ?? '';
        $length = (int) ($settings['voucher_length'] ?? 8);
        $format = $settings['voucher_format'] ?? 'alphanumeric';

        for ($i = 0; $i < $validated['quantity']; $i++) {
            // Generate the raw code string
            if ($format === 'numeric') {
                // Generate random number of specific length
                $min = (int) str_pad('1', $length, '0');
                $max = (int) str_pad('9', $length, '9');
                $rawCode = (string) rand($min, $max);
            } else {
                // Generate alphanumeric string of specific length
                // Remove confusing characters (O, 0, I, 1)
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $rawCode = '';
                for ($j = 0; $j < $length; $j++) {
                    $rawCode .= $chars[random_int(0, strlen($chars) - 1)];
                }
            }

            // Combine prefix and raw code (e.g., ACME-12345678)
            $finalCode = $prefix ? "{$prefix}-{$rawCode}" : $rawCode;

            $vouchersToInsert[] = [
                'tenant_id' => $tenant->id,
                'voucher_batch_id' => $batch->id,
                'package_id' => $validated['package_id'],
                'code' => $finalCode,
                'status' => 'unused',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Insert in chunks to avoid memory issues
        foreach (array_chunk($vouchersToInsert, 100) as $chunk) {
            Voucher::insert($chunk);
        }

        return redirect()->route('operator.vouchers.index', ['tenant' => $tenant->slug])
            ->with('status', "Successfully generated {$validated['quantity']} vouchers for {$batchCode}.");
    }
}
