<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(TenantContext $context)
    {
        // KPIs
        $totalCustomers = Customer::count();
        $activeCustomers = Customer::where('status', 'active')->count();
        $outstandingBalance = 0;    // TODO: wire up once balance tracking is connected
        $newThisMonth = Customer::whereMonth('created_at', now()->month)->count();

        // Paginated customer list with assigned package
        $customers = Customer::with('package')->orderBy('created_at', 'desc')->paginate(25);

        // All packages available for this tenant
        $packages = Package::select('id', 'name', 'price', 'duration_value', 'duration_unit')->get();

        return view('operator.customers.index', compact(
            'totalCustomers',
            'activeCustomers',
            'outstandingBalance',
            'newThisMonth',
            'customers',
            'packages'
        ));
    }

    public function show(Request $request, string $tenant, Customer $customer)
    {
        $customer->load('package');

        return view('operator.customers.show', compact('customer'));
    }

    public function store(Request $request, TenantContext $context)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:50'],
            'email'       => ['nullable', 'email', 'max:255'],
            'package_id'  => ['nullable', 'exists:packages,id'],
            'mac_address' => ['nullable', 'string', 'max:50'],
            'status'      => ['nullable', 'in:active,inactive'],
        ]);

        $validated['tenant_id'] = $context->getTenant()->id;
        $validated['status'] = $validated['status'] ?? 'active';

        Customer::create($validated);

        return redirect()->route('operator.customers.index')
            ->with('status', 'Customer created and plan assigned successfully.');
    }

    public function update(Request $request, string $tenant, Customer $customer)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:50'],
            'email'       => ['nullable', 'email', 'max:255'],
            'package_id'  => ['nullable', 'exists:packages,id'],
            'mac_address' => ['nullable', 'string', 'max:50'],
            'status'      => ['nullable', 'in:active,inactive'],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';

        $customer->update($validated);

        return redirect()->route('operator.customers.index')
            ->with('status', 'Customer details updated successfully.');
    }

    public function destroy(Request $request, string $tenant, Customer $customer)
    {
        $customer->delete();

        return redirect()->route('operator.customers.index')
            ->with('status', 'Customer deleted successfully.');
    }
}
