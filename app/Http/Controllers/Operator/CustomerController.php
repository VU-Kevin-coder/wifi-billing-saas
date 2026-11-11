<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Services\Tenancy\TenantContext;
use App\Support\ReportingTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        // KPIs
        $totalCustomers = Customer::count();
        $activeCustomers = Customer::where('status', 'active')->count();
        $outstandingBalance = 0;    // TODO: wire up once balance tracking is connected
        $currency = strtoupper((string) PlatformSetting::get('currency', 'UGX'));
        $newThisMonth = Customer::whereBetween('created_at', ReportingTime::monthRange())->count();

        $customers = $this->filteredCustomers($validated)->paginate(25)->withQueryString();

        // All packages available for this tenant
        $packages = Package::select('id', 'name', 'price', 'currency', 'duration_value', 'duration_unit')->get();

        return view('operator.customers.index', compact(
            'totalCustomers',
            'activeCustomers',
            'outstandingBalance',
            'newThisMonth',
            'customers',
            'packages',
            'validated',
            'currency',
        ));
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $customers = $this->filteredCustomers($validated);

        return response()->streamDownload(function () use ($customers): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the customer export stream.');
            }

            fputcsv($output, ['Name', 'Phone', 'Email', 'Package', 'Status', 'Created at']);
            $customers->chunk(500, function ($records) use ($output): void {
                foreach ($records as $customer) {
                    fputcsv($output, array_map($this->safeCsvValue(...), [
                        $customer->name,
                        $customer->phone,
                        $customer->email,
                        $customer->package?->name,
                        $customer->status,
                        $customer->created_at?->toIso8601String(),
                    ]));
                }
            });

            fclose($output);
        }, 'customers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $tenant, Customer $customer)
    {
        $customer->load('package');

        return view('operator.customers.show', compact('customer'));
    }

    public function store(Request $request, TenantContext $context)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'package_id' => ['nullable', Rule::exists('packages', 'id')->where('tenant_id', $context->getTenant()->id)],
            'mac_address' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
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
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'package_id' => ['nullable', Rule::exists('packages', 'id')->where('tenant_id', $customer->tenant_id)],
            'mac_address' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';

        $customer->update($validated);

        return redirect()->route('operator.customers.index')
            ->with('status', 'Customer details updated successfully.');
    }

    public function destroy(Request $request, string $tenant, Customer $customer)
    {
        AuditLog::record('customer.deleted', $customer, [
            'name' => $customer->name,
            'phone' => $customer->phone,
        ]);

        $customer->delete();

        return redirect()->route('operator.customers.index')
            ->with('status', 'Customer deleted successfully.');
    }

    private function safeCsvValue(?string $value): string
    {
        $value = $value ?? '';

        return preg_match('/^[\t\r ]*[=+\-@]/', $value) ? "'".$value : $value;
    }

    /**
     * @param  array{q?: string|null, status?: string|null}  $filters
     */
    private function filteredCustomers(array $filters): Builder
    {
        $customers = Customer::query()->with('package')->orderByDesc('created_at');
        $search = trim($filters['q'] ?? '');

        if ($search !== '') {
            $customers->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mac_address', 'like', "%{$search}%");
            });
        }

        if (filled($filters['status'] ?? null)) {
            $customers->where('status', $filters['status']);
        }

        return $customers;
    }
}
