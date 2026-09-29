<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PageDataController extends Controller
{
    /** Read models for the inventory workspace. Only fixed, reviewed queries are exposed. */
    public function show(Request $request, string $page): JsonResponse
    {
        $definitions = $this->definitions((int) $request->user()->currentLocationId());
        abort_unless(isset($definitions[$page]), 404);
        [$columns, $query, $searchFields] = $definitions[$page];

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $where) use ($searchFields, $search): void {
                foreach ($searchFields as $field) $where->orWhere($field, 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 15), 5), 100);
        $rows = $query->paginate($perPage)->withQueryString();
        return response()->json([
            'data' => $rows->items(),
            'columns' => $columns,
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    private function definitions(int $location): array
    {
        $branches = DB::table('branches');
        $products = DB::table('products as p')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')->select('p.id', 'p.code', 'p.name', 'c.name as category', 'p.unit', 'p.min_qty', 'p.active');
        $stock = DB::table('products as p')->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'p.id')->where('l.location_id', '=', $location);
        })->where('p.active', 1)->groupBy('p.id', 'p.code', 'p.name', 'p.unit', 'p.min_qty')
            ->select('p.id', 'p.code', 'p.name', 'p.unit', 'p.min_qty', DB::raw('COALESCE(SUM(l.qty),0) as quantity'), DB::raw('COALESCE(SUM(l.qty*l.unit_cost),0) as stock_value'));
        $movements = DB::table('movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('branches as f', 'f.id', '=', 'm.from_location')->leftJoin('branches as t', 't.id', '=', 'm.to_location')
            ->when($location > 0, fn ($q) => $q->where(fn ($w) => $w->where('m.from_location', $location)->orWhere('m.to_location', $location)))
            ->select('m.id', 'm.movement_no', 'm.type', 'p.name as product', 'm.qty', 'm.unit_cost', 'f.name as from_branch', 't.name as to_branch', 'm.reference', 'm.happened_at');

        return [
            'branches' => [['name' => 'Մասնաճյուղ', 'code' => 'Կոդ', 'address' => 'Հասցե', 'manager' => 'Պատասխանատու', 'phone' => 'Հեռախոս', 'active' => 'Կարգավիճակ'], $branches->select('id','name','code','address','manager','phone','active')->orderBy('name'), ['name','code','address','manager','phone']],
            'products' => [['code' => 'Կոդ', 'name' => 'Ապրանք', 'category' => 'Խումբ', 'unit' => 'Միավոր', 'min_qty' => 'MIN', 'active' => 'Կարգավիճակ'], $products->orderBy('p.name'), ['p.code','p.name','c.name']],
            'stock' => [['code' => 'Կոդ', 'name' => 'Ապրանք', 'quantity' => 'Մնացորդ', 'unit' => 'Միավոր', 'min_qty' => 'MIN', 'stock_value' => 'Արժեք'], $stock->orderBy('p.name'), ['p.code','p.name']],
            'movements' => [['movement_no' => 'Փաստաթուղթ', 'type' => 'Տեսակ', 'product' => 'Ապրանք', 'qty' => 'Քանակ', 'from_branch' => 'Ումից', 'to_branch' => 'Ուր', 'reference' => 'Հղում', 'happened_at' => 'Ամսաթիվ'], $movements->orderByDesc('m.id'), ['m.movement_no','p.name','m.reference','m.type']],
            'expiry' => [['code' => 'Կոդ', 'product' => 'Ապրանք', 'lot_no' => 'LOT', 'expires_on' => 'Պիտանի է մինչև', 'location' => 'Պահեստ', 'qty' => 'Քանակ', 'unit' => 'Միավոր'], DB::table('stock_lots as l')->join('products as p','p.id','=','l.product_id')->leftJoin('branches as b','b.id','=','l.location_id')->where('l.qty','>',0)->whereNotNull('l.expires_on')->where('l.expires_on','<=',now()->addDays(180)->toDateString())->when($location>0,fn($q)=>$q->where('l.location_id',$location))->select('p.code','p.name as product','l.lot_no','l.expires_on','b.name as location','l.qty','p.unit')->orderBy('l.expires_on'), ['p.code','p.name','l.lot_no','b.name']],
            'purchases' => [['order_no' => 'Պատվեր', 'supplier' => 'Մատակարար', 'status' => 'Կարգավիճակ', 'ordered_on' => 'Պատվերի օր', 'expected_on' => 'Սպասվող օր', 'created_at' => 'Ստեղծվել է'], DB::table('purchase_orders as o')->join('suppliers as s','s.id','=','o.supplier_id')->select('o.id','o.order_no','s.name as supplier','o.status','o.ordered_on','o.expected_on','o.created_at')->orderByDesc('o.id'), ['o.order_no','s.name','o.status']],
            'receipts' => [['receipt_no' => 'Մուտք', 'supplier' => 'Մատակարար', 'invoice_no' => 'Հաշիվ', 'received_on' => 'Ստացման օր', 'note' => 'Նշում'], DB::table('receipts as r')->join('suppliers as s','s.id','=','r.supplier_id')->select('r.id','r.receipt_no','s.name as supplier','r.invoice_no','r.received_on','r.note')->orderByDesc('r.id'), ['r.receipt_no','s.name','r.invoice_no']],
            'requests' => [['request_no' => 'Պահանջագիր', 'branch' => 'Մասնաճյուղ', 'status' => 'Կարգավիճակ', 'urgency' => 'Հրատապություն', 'created_at' => 'Ստեղծվել է'], DB::table('stock_requests as r')->join('branches as b','b.id','=','r.branch_id')->when($location>0,fn($q)=>$q->where('r.branch_id',$location))->select('r.id','r.request_no','b.name as branch','r.status','r.urgency','r.created_at')->orderByDesc('r.id'), ['r.request_no','b.name','r.status']],
            'transfers' => [['transfer_no' => 'Տեղափոխում', 'from_branch' => 'Ումից', 'to_branch' => 'Ուր', 'status' => 'Կարգավիճակ', 'reason' => 'Պատճառ', 'created_at' => 'Ստեղծվել է'], DB::table('transfers as t')->join('branches as f','f.id','=','t.from_branch')->join('branches as b','b.id','=','t.to_branch')->when($location>0,fn($q)=>$q->where(fn($w)=>$w->where('t.from_branch',$location)->orWhere('t.to_branch',$location)))->select('t.id','t.transfer_no','f.name as from_branch','b.name as to_branch','t.status','t.reason','t.created_at')->orderByDesc('t.id'), ['t.transfer_no','f.name','b.name','t.status']],
            'returns' => [['return_no' => 'Վերադարձ', 'direction' => 'Ուղղություն', 'product' => 'Ապրանք', 'qty' => 'Քանակ', 'reason' => 'Պատճառ', 'created_at' => 'Ամսաթիվ'], DB::table('returns as r')->join('products as p','p.id','=','r.product_id')->when($location>0,fn($q)=>$q->where('r.from_location',$location))->select('r.id','r.return_no','r.direction','p.name as product','r.qty','r.reason','r.created_at')->orderByDesc('r.id'), ['r.return_no','p.name','r.reason']],
            'inventory' => [['inventory_no' => 'Գույքագրում', 'location' => 'Պահեստ', 'status' => 'Կարգավիճակ', 'started_at' => 'Սկսվել է', 'closed_at' => 'Փակվել է'], DB::table('inventory_sessions as i')->leftJoin('branches as b','b.id','=','i.location_id')->when($location>0,fn($q)=>$q->where('i.location_id',$location))->select('i.id','i.inventory_no','b.name as location','i.status','i.started_at','i.closed_at')->orderByDesc('i.id'), ['i.inventory_no','b.name','i.status']],
            'users' => [['name' => 'Օգտատեր', 'email' => 'Էլ. փոստ', 'role' => 'Դեր', 'branch' => 'Մասնաճյուղ', 'active' => 'Կարգավիճակ'], DB::table('users as u')->join('roles as r','r.id','=','u.role_id')->leftJoin('branches as b','b.id','=','u.branch_id')->select('u.id','u.name','u.email','r.title as role','b.name as branch','u.active')->orderBy('u.name'), ['u.name','u.email','r.title','b.name']],
            'roles' => [['title' => 'Դեր', 'name' => 'Ներքին անուն', 'permissions_count' => 'Իրավունքների քանակ'], DB::table('roles as r')->leftJoin('role_permissions as rp','rp.role_id','=','r.id')->select('r.id','r.title','r.name',DB::raw('COUNT(rp.permission_id) as permissions_count'))->groupBy('r.id','r.title','r.name')->orderBy('r.title'), ['r.title','r.name']],
            'audit' => [['created_at' => 'Ամսաթիվ', 'actor' => 'Օգտատեր', 'action' => 'Գործողություն', 'entity' => 'Բաժին', 'entity_id' => 'Գրառում'], DB::table('audit_logs as a')->leftJoin('users as u','u.id','=','a.actor_id')->select('a.id','a.created_at','u.name as actor','a.action','a.entity','a.entity_id')->orderByDesc('a.id'), ['u.name','a.action','a.entity']],
        ];
    }
}
