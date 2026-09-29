<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function options(string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['purchases','receipts'], true), 404);
        $data = [
            'suppliers' => DB::table('suppliers')->where('active',1)->orderBy('name')->get(['id','name']),
            'products' => DB::table('products')->where('active',1)->orderBy('name')->get(['id','code','name','unit','expiry_control']),
        ];
        if ($kind === 'receipts') $data['orders'] = DB::table('purchase_orders as o')->join('suppliers as s','s.id','=','o.supplier_id')
            ->where('o.status','approved')->select('o.id','o.order_no','s.name as supplier','o.supplier_id')->orderByDesc('o.id')->get()
            ->map(function ($order): array {
                $order->items = DB::table('purchase_order_items as i')->join('products as p','p.id','=','i.product_id')
                    ->where('i.purchase_order_id',$order->id)->whereRaw('i.received_qty < i.ordered_qty')
                    ->select('i.id','i.product_id','p.code','p.name','p.unit','p.expiry_control','i.ordered_qty','i.received_qty','i.unit_cost')->get();
                return (array) $order;
            });
        return response()->json(['data'=>$data]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id'=>['required','integer','exists:suppliers,id'],'ordered_on'=>['required','date_format:Y-m-d'],
            'expected_on'=>['nullable','date_format:Y-m-d','after_or_equal:ordered_on'],'note'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1'],'items.*.product_id'=>['required','integer','distinct','exists:products,id'],
            'items.*.qty'=>['required','numeric','gt:0'],'items.*.unit_cost'=>['required','numeric','min:0'],
        ]);
        abort_unless(DB::table('suppliers')->where('id',$data['supplier_id'])->where('active',1)->exists(),422,'Ընտրեք ակտիվ մատակարար։');
        foreach($data['items'] as $line) abort_unless(DB::table('products')->where('id',$line['product_id'])->where('active',1)->exists(),422,'Ընտրված ապրանքներից մեկն ակտիվ չէ։');
        $id=DB::transaction(function()use($request,$data):int{
            $number='ՊԱՏ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $id=DB::table('purchase_orders')->insertGetId(['order_no'=>$number,'supplier_id'=>$data['supplier_id'],'status'=>'pending','ordered_on'=>$data['ordered_on'],'expected_on'=>$data['expected_on']??null,'created_by'=>$request->user()->id,'note'=>trim($data['note']??''),'created_at'=>now()]);
            foreach($data['items'] as $line)DB::table('purchase_order_items')->insert(['purchase_order_id'=>$id,'product_id'=>$line['product_id'],'ordered_qty'=>$line['qty'],'received_qty'=>0,'unit_cost'=>$line['unit_cost']]);
            $this->audit($request,'Գնման պատվերը ստեղծվեց','purchase_orders',$id,null,['order_no'=>$number,'supplier_id'=>$data['supplier_id'],'line_count'=>count($data['items'])]);
            return $id;
        });
        return response()->json(['data'=>DB::table('purchase_orders')->where('id',$id)->first()],201);
    }

    public function approve(Request $request,int $order):JsonResponse
    {
        DB::transaction(function()use($request,$order):void{
            $row=DB::table('purchase_orders')->where('id',$order)->lockForUpdate()->first();
            abort_unless($row&&$row->status==='pending',409,'Հաստատման սպասող պատվերը չի գտնվել։');
            DB::table('purchase_orders')->where('id',$order)->update(['status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now()]);
            $this->audit($request,'Գնման պատվերը հաստատվեց','purchase_orders',$order,['status'=>'pending'],['status'=>'approved']);
        });
        return response()->json(['message'=>'Գնման պատվերը հաստատվեց։']);
    }

    public function receive(Request $request):JsonResponse
    {
        $data=$request->validate([
            'purchase_order_id'=>['required','integer','exists:purchase_orders,id'],'received_on'=>['required','date_format:Y-m-d'],
            'invoice_no'=>['nullable','string','max:100'],'contract_no'=>['nullable','string','max:100'],'note'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1'],'items.*.purchase_order_item_id'=>['required','integer','distinct','exists:purchase_order_items,id'],
            'items.*.qty'=>['required','numeric','gt:0'],'items.*.lot_no'=>['required','string','max:100'],
            'items.*.expires_on'=>['nullable','date_format:Y-m-d'],'items.*.bin_location'=>['nullable','string','max:100'],
        ]);
        foreach($data['items'] as $line)if(!empty($line['expires_on'])&&$line['expires_on']<$data['received_on'])throw ValidationException::withMessages(['items'=>['LOT-ի պիտանելիության ժամկետը չի կարող նախորդել մուտքի ամսաթվին։']]);
        $receipt=DB::transaction(function()use($request,$data):array{
            $order=DB::table('purchase_orders')->where('id',$data['purchase_order_id'])->lockForUpdate()->first();
            abort_unless($order&&$order->status==='approved',409,'Մուտքի համար անհրաժեշտ է հաստատված գնման պատվեր։');
            $locked=[];$totals=[];
            foreach($data['items'] as $line)$totals[$line['purchase_order_item_id']]=($totals[$line['purchase_order_item_id']]??0)+(float)$line['qty'];
            foreach($totals as $itemId=>$qty){$item=DB::table('purchase_order_items as i')->join('products as p','p.id','=','i.product_id')->where('i.id',$itemId)->where('i.purchase_order_id',$order->id)->lockForUpdate()->first(['i.*','p.expiry_control','p.name']);abort_unless($item,422,'Ընտրված տողը այս պատվերին չի պատկանում։');if($qty>(float)$item->ordered_qty-(float)$item->received_qty+0.00001)throw ValidationException::withMessages(['items'=>['Մուտքի քանակը գերազանցում է պատվերի չստացված մնացորդը։']]);$locked[$itemId]=$item;}
            foreach($data['items'] as $line){$item=$locked[$line['purchase_order_item_id']];if($item->expiry_control&&!($line['expires_on']??null))throw ValidationException::withMessages(['items'=>['Ժամկետով վերահսկվող ապրանքի համար LOT-ի պիտանելիության ժամկետը պարտադիր է։']]);}
            $number='ՄՈՒՏ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));$supplierId=(int)$order->supplier_id;
            $receiptId=DB::table('receipts')->insertGetId(['receipt_no'=>$number,'purchase_order_id'=>$order->id,'supplier_id'=>$supplierId,'invoice_no'=>trim($data['invoice_no']??''),'contract_no'=>trim($data['contract_no']??''),'received_by'=>$request->user()->id,'received_on'=>$data['received_on'],'note'=>trim($data['note']??''),'created_at'=>now()]);
            foreach($data['items'] as $line){$item=$locked[$line['purchase_order_item_id']];$lotId=DB::table('stock_lots')->insertGetId(['product_id'=>$item->product_id,'purchase_order_id'=>$order->id,'location_id'=>0,'lot_no'=>trim($line['lot_no']),'expires_on'=>$line['expires_on']??null,'received_on'=>$data['received_on'],'supplier_id'=>$supplierId,'unit_cost'=>$item->unit_cost,'bin_location'=>trim($line['bin_location']??''),'qty'=>$line['qty']]);
                DB::table('receipt_items')->insert(['receipt_id'=>$receiptId,'purchase_order_item_id'=>$item->id,'product_id'=>$item->product_id,'lot_id'=>$lotId,'qty'=>$line['qty'],'unit_cost'=>$item->unit_cost]);
                DB::table('purchase_order_items')->where('id',$item->id)->update(['received_qty'=>DB::raw('received_qty + '.(float)$line['qty'])]);
                DB::table('movements')->insert(['movement_no'=>'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),'type'=>'receipt','product_id'=>$item->product_id,'lot_id'=>$lotId,'from_location'=>null,'to_location'=>0,'qty'=>$line['qty'],'unit_cost'=>$item->unit_cost,'reference'=>$number,'reason'=>'Հաստատված գնման պատվերից ապրանքի ընդունում','actor_id'=>$request->user()->id,'happened_at'=>now(),'created_at'=>now()]);}
            $this->audit($request,'Ապրանքների մուտքագրում','receipts',$receiptId,null,['receipt_no'=>$number,'purchase_order_id'=>$order->id,'line_count'=>count($data['items'])]);
            return ['id'=>$receiptId,'receipt_no'=>$number];
        });
        return response()->json(['data'=>$receipt],201);
    }

    private function audit(Request $request,string $action,string $entity,int $id,?array $before,?array $after):void
    {AuditLog::create(['actor_id'=>$request->user()->id,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'before_data'=>$before,'after_data'=>$after,'ip_address'=>$request->ip(),'created_at'=>now()]);}
}
