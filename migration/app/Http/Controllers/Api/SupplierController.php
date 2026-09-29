<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()->orderBy('name');
        if ($request->boolean('active_only')) $query->where('active', true);
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%"));
        }
        return response()->json($query->paginate(min(max($request->integer('per_page', 15), 1), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $supplier = DB::transaction(function () use ($data, $request): Supplier {
            $record = Supplier::create($data);
            $this->audit($request, 'Ստեղծում', $record->id, array_keys($data));
            return $record;
        });
        return response()->json(['data' => $supplier], 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($supplier, $data, $request): void {
            $supplier->update($data);
            $this->audit($request, 'Փոփոխություն', $supplier->id, array_keys($data));
        });
        return response()->json(['data' => $supplier->refresh()]);
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        DB::transaction(function () use ($supplier, $request): void {
            $supplier->update(['active' => false]); // Keep financial and stock history intact.
            $this->audit($request, 'Ապաակտիվացում', $supplier->id, ['active']);
        });
        return response()->json(['message' => 'Մատակարարը ապաակտիվացվեց։']);
    }

    private function audit(Request $request, string $action, int $id, array $fields): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'entity' => 'suppliers',
            'entity_id' => $id,
            // Avoid duplicating bank, tax, or contact values in the audit trail.
            'after_data' => ['changed_fields' => array_values($fields)],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'tax_id' => ['required', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:190'],
            'bank_details' => ['required', 'string', 'max:255'],
            'contract_no' => ['required', 'string', 'max:100'],
            'contract_start' => ['required', 'date'],
            'contract_end' => ['required', 'date', 'after_or_equal:contract_start'],
            'payment_terms' => ['required', 'string', 'max:190'],
            'delivery_days' => ['required', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
