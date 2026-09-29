<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function options(string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['products', 'users', 'roles', 'transfers', 'stock'], true), 404);
        return response()->json(['data' => [
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'suppliers' => DB::table('suppliers')->where('active', 1)->orderBy('name')->get(['id', 'name']),
            'branches' => DB::table('branches')->where('active', 1)->orderBy('name')->get(['id', 'name', 'code']),
            'roles' => DB::table('roles')->orderBy('title')->get(['id', 'title', 'name']),
            'permissions' => $kind === 'roles' ? DB::table('permissions')->orderBy('module')->orderBy('title')->get(['code','title','module']) : [],
            'products' => in_array($kind, ['products', 'transfers', 'requests', 'stock'], true) ? DB::table('products')->where('active', 1)->orderBy('name')->get(['id','code','name','unit','purchase_price','expiry_control']) : [],
        ]]);
    }

    public function show(string $kind, int $record): JsonResponse
    {
        if ($kind === 'roles') return response()->json(['data' => $this->role($record)]);
        $row = DB::table($this->table($kind))->where('id', $record)->first();
        abort_unless($row, 404, 'Գրառումը չի գտնվել։');
        if ($kind === 'users') unset($row->password);
        return response()->json(['data' => $row]);
    }

    public function store(Request $request, string $kind): JsonResponse
    {
        $data = $this->validated($request, $kind);
        $id = DB::transaction(function () use ($request, $kind, $data): int {
            if ($kind === 'users') $data['password'] = Hash::make($data['password']);
            $id = DB::table($this->table($kind))->insertGetId($data);
            $this->audit($request, 'Ստեղծում', $kind, $id, null, $this->safe($kind, $data));
            return $id;
        });
        return response()->json(['data' => DB::table($this->table($kind))->where('id', $id)->first()], 201);
    }

    public function update(Request $request, string $kind, int $record): JsonResponse
    {
        $table = $this->table($kind);
        $current = DB::table($table)->where('id', $record)->first();
        abort_unless($current, 404, 'Գրառումը չի գտնվել։');
        $data = $this->validated($request, $kind, $record);
        if ($kind === 'branches' && $current->code === 'CENTRAL' && (($data['code'] ?? 'CENTRAL') !== 'CENTRAL' || empty($data['active']))) {
            throw ValidationException::withMessages(['code' => ['Կենտրոնական պահեստի հիմնական կոդը և ակտիվությունը չեն փոփոխվում։']]);
        }
        if ($kind === 'users' && empty($data['password'])) unset($data['password']);
        if ($kind === 'users' && ! empty($data['password'])) $data['password'] = Hash::make($data['password']);
        DB::transaction(function () use ($request, $kind, $record, $current, $data, $table): void {
            if ($kind === 'users' && $record === (int) $request->user()->id && empty($data['active'])) {
                throw ValidationException::withMessages(['active' => ['Չեք կարող ապաակտիվացնել ձեր ընթացիկ հաշիվը։']]);
            }
            DB::table($table)->where('id', $record)->update($data);
            $this->audit($request, 'Փոփոխություն', $kind, $record, $this->safe($kind, (array) $current), $this->safe($kind, $data));
        });
        return response()->json(['data' => DB::table($table)->where('id', $record)->first()]);
    }

    public function deactivate(Request $request, string $kind, int $record): JsonResponse
    {
        $table = $this->table($kind);
        $current = DB::table($table)->where('id', $record)->first();
        abort_unless($current, 404, 'Գրառումը չի գտնվել։');
        if ($kind === 'users' && $record === (int) $request->user()->id) throw ValidationException::withMessages(['id' => ['Չեք կարող ապաակտիվացնել ձեր ընթացիկ հաշիվը։']]);
        if ($kind === 'products') {
            $qty = (float) DB::table('stock_lots')->where('product_id', $record)->sum('qty');
            if ($qty > 0.00001) throw ValidationException::withMessages(['id' => ['Մնացորդ ունեցող ապրանքը հնարավոր չէ ապաակտիվացնել։']]);
        }
        if ($kind === 'branches' && $current->code === 'CENTRAL') throw ValidationException::withMessages(['id' => ['Կենտրոնական պահեստը հնարավոր չէ ապաակտիվացնել։']]);
        if ($kind === 'branches' && (float) DB::table('stock_lots')->where('location_id', $record)->sum('qty') > 0.00001) {
            throw ValidationException::withMessages(['id' => ['Մնացորդ ունեցող պահեստը հնարավոր չէ ապաակտիվացնել։']]);
        }
        DB::transaction(function () use ($request, $kind, $record, $current, $table): void {
            DB::table($table)->where('id', $record)->update(['active' => 0]);
            $this->audit($request, 'Ապաակտիվացում', $kind, $record, ['active' => (int) $current->active], ['active' => 0]);
        });
        return response()->json(['message' => 'Գրառումն ապաակտիվացվեց։']);
    }

    public function createRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120', Rule::unique('roles', 'title')],
            'permissions' => ['sometimes', 'array'], 'permissions.*' => ['string', 'distinct', 'exists:permissions,code'],
        ]);
        $roleId = DB::transaction(function () use ($request, $data): int {
            abort_unless($request->user()->hasPermissionCode('roles.edit'), 403, 'Նոր դեր ստեղծելու համար պետք է նաև իրավունքներ խմբագրելու թույլտվություն։');
            $roleId = DB::table('roles')->insertGetId(['name' => 'custom_'.bin2hex(random_bytes(8)), 'title' => trim($data['title']), 'created_at' => now()]);
            $this->saveRolePermissions($request, $roleId, $data['permissions'] ?? []);
            $this->audit($request, 'Նոր դեր ստեղծվեց', 'roles', $roleId, null, ['title' => $data['title'], 'permissions' => $data['permissions'] ?? []]);
            return $roleId;
        });
        return response()->json(['data' => $this->role($roleId)], 201);
    }

    public function rolePermissions(Request $request, int $record): JsonResponse
    {
        $role = DB::table('roles')->where('id', $record)->first();
        abort_unless($role, 404, 'Դերը չի գտնվել։');
        if ($role->name === 'admin') throw ValidationException::withMessages(['role' => ['Ադմինիստրատորի լիարժեք իրավունքները չեն փոփոխվում։']]);
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'distinct', 'exists:permissions,code']]);
        DB::transaction(function () use ($request, $record, $data): void {
            $before = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $record)->pluck('p.code')->all();
            $this->saveRolePermissions($request, $record, $data['permissions']);
            $this->audit($request, 'Իրավունքների փոփոխություն', 'roles', $record, $before, $data['permissions']);
        });
        return response()->json(['data' => $this->role($record)]);
    }

    private function validated(Request $request, string $kind, ?int $id = null): array
    {
        $table = $this->table($kind);
        $rules = match ($kind) {
            'branches' => [
                'name' => ['required', 'string', 'max:160'], 'code' => ['required', 'string', 'max:40', Rule::unique('branches', 'code')->ignore($id)],
                'address' => ['nullable', 'string', 'max:255'], 'manager' => ['nullable', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:50'], 'active' => ['sometimes', 'boolean'],
            ],
            'products' => [
                'code' => ['required', 'string', 'max:80', Rule::unique('products', 'code')->ignore($id)],
                'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($id)],
                'name' => ['required', 'string', 'max:190'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'subcategory' => ['nullable', 'string', 'max:120'], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
                'purchase_price' => ['required', 'numeric', 'min:0'], 'manufacturer' => ['nullable', 'string', 'max:160'],
                'unit' => ['required', 'string', 'max:50'], 'package' => ['nullable', 'string', 'max:120'],
                'min_qty' => ['required', 'numeric', 'min:0'], 'optimal_qty' => ['required', 'numeric', 'min:0'], 'max_qty' => ['required', 'numeric', 'min:0'],
                'storage_conditions' => ['nullable', 'string', 'max:190'], 'refrigerated' => ['sometimes', 'boolean'],
                'lot_control' => ['sometimes', 'boolean'], 'expiry_control' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'],
            ],
            'users' => [
                'name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($id)],
                'role_id' => ['required', 'integer', 'exists:roles,id'], 'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'max:255'], 'active' => ['sometimes', 'boolean'],
            ],
            default => throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(),
        };
        $data = $request->validate($rules);
        if ($kind === 'products') {
            if ((float) $data['max_qty'] > 0 && ((float) $data['min_qty'] > (float) $data['max_qty'] || (float) $data['optimal_qty'] > (float) $data['max_qty'])) {
                throw ValidationException::withMessages(['max_qty' => ['MIN-ը և OPTIMAL-ը չեն կարող գերազանցել MAX-ը։']]);
            }
            foreach (['refrigerated', 'lot_control', 'expiry_control', 'active'] as $flag) if (isset($data[$flag])) $data[$flag] = (int) (bool) $data[$flag];
            if (! empty($data['supplier_id']) && ! DB::table('suppliers')->where('id', $data['supplier_id'])->where('active', 1)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => ['Ընտրված մատակարարը ակտիվ չէ։']]);
            }
        }
        if (isset($data['active'])) $data['active'] = (int) (bool) $data['active'];
        return $data;
    }

    private function table(string $kind): string
    {
        $tables = ['branches' => 'branches', 'products' => 'products', 'users' => 'users'];
        abort_unless(isset($tables[$kind]), 404);
        return $tables[$kind];
    }

    private function safe(string $kind, array $data): array
    {
        if ($kind === 'users') unset($data['password']);
        if ($kind === 'suppliers') unset($data['bank_details']);
        return $data;
    }

    private function saveRolePermissions(Request $request, int $roleId, array $permissions): void
    {
        if ($request->user()->role?->name !== 'admin') {
            $permitted = array_flip(array_keys($request->user()->permissionMap()));
            $permissions = array_values(array_filter($permissions, static fn (string $code): bool => isset($permitted[$code])));
        }
        DB::table('role_permissions')->where('role_id', $roleId)->delete();
        $permissionIds = DB::table('permissions')->whereIn('code', $permissions)->pluck('id');
        foreach ($permissionIds as $permissionId) DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }

    private function role(int $id): array
    {
        $role = (array) DB::table('roles')->where('id', $id)->first();
        $role['permissions'] = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $id)->pluck('p.code')->all();
        return $role;
    }

    private function audit(Request $request, string $action, string $entity, int $id, ?array $before, ?array $after): void
    {
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $request->ip(), 'created_at' => now()]);
    }
}
