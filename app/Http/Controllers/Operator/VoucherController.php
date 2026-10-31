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

        return view('operator.vouchers.index', compact(
            'generatedThisMonth', 'unusedStock', 'redeemed', 'voided',
            'vouchers', 'packages', 'latestBatch'
        ));
    }

    public function store(Request $request, TenantContext $context)
    {
        $validated = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
            'quantity'   => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        // Auto-increment batch code scoped to this tenant
        $batchCount = VoucherBatch::count() + 1;
        $batchCode  = 'BATCH-' . str_pad($batchCount, 3, '0', STR_PAD_LEFT);

        $batch = VoucherBatch::create([
            'tenant_id'  => $context->getTenantId(),
            'package_id' => $validated['package_id'],
            'created_by' => auth()->id(),
            'batch_code' => $batchCode,
            'quantity'   => $validated['quantity'],
        ]);

        // Generate individual vouchers in bulk
        $vouchersToInsert = [];
        $now = now();

        for ($i = 0; $i < $validated['quantity']; $i++) {
            // Format: KL-XXXX-XXXX (e.g. KL-D7XP-4M2A)
            $code = 'KL-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));

            $vouchersToInsert[] = [
                'tenant_id'        => $context->getTenantId(),
                'voucher_batch_id' => $batch->id,
                'package_id'       => $validated['package_id'],
                'code'             => $code,
                'status'           => 'unused',
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        // Insert in chunks of 100 to avoid memory pressure
        foreach (array_chunk($vouchersToInsert, 100) as $chunk) {
            Voucher::insert($chunk);
        }

        return redirect()->route('operator.vouchers.index')
            ->with('status', "Successfully generated {$validated['quantity']} vouchers for {$batchCode}.");
    }
}
