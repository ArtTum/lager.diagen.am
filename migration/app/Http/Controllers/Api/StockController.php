<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function lots(Request $request): JsonResponse
    {
        $data=$request->validate(['product_id'=>['required','integer','exists:products,id'],'location_id'=>['nullable','integer']]);
        $location=$this->location($request,$data['location_id']??$request->user()->currentLocationId());
        $lots=DB::table('stock_lots')->where('product_id',$data['product_id'])->where('location_id',$location)->orderByRaw('(expires_on IS NULL),expires_on,received_on,id')->get(['id','lot_no','expires_on','qty','unit_cost','bin_location']);
        return response()->json(['data'=>$lots]);
    }

    public function consume(Request $request): JsonResponse
    {
        $data=$request->validate(['product_id'=>['required','integer','exists:products,id'],'qty'=>['required','numeric','gt:0'],'location_id'=>['nullable','integer'],'issue_type'=>['required','in:usage,expired,damaged,other'],'reason_note'=>['required_if:issue_type,other','nullable','string','min:3','max:1000']]);
        $location=$this->location($request,$data['location_id']??$request->user()->currentLocationId());
        DB::transaction(function()use($request,$data,$location):void{
            $product=DB::table('products')->where('id',$data['product_id'])->where('active',1)->first();abort_unless($product,422,'Ընտրված ապրանքը ակտիվ չէ։');
            $query=DB::table('stock_lots')->where('product_id',$data['product_id'])->where('location_id',$location)->where('qty','>',0);
            if($data['issue_type']==='expired')$query->whereNotNull('expires_on')->where('expires_on','<',now()->toDateString());else $query->where(fn($q)=>$q->whereNull('expires_on')->orWhere('expires_on','>=',now()->toDateString()));
            $lots=$query->orderByRaw('(expires_on IS NULL),expires_on,received_on,id')->lockForUpdate()->get();$remaining=(float)$data['qty'];
            foreach($lots as $lot){if($remaining<=0.00001)break;$take=min($remaining,(float)$lot->qty);DB::table('stock_lots')->where('id',$lot->id)->update(['qty'=>DB::raw('qty - '.(float)$take)]);$reason=$data['issue_type']==='other'?trim($data['reason_note']):$data['issue_type'];$this->movement($request,'consumption',(int)$product->id,(int)$lot->id,$location,null,$take,(float)$lot->unit_cost,'',$reason);$remaining-=$take;}
            if($remaining>0.00001)throw ValidationException::withMessages(['qty'=>['Ընտրված տեսակի դուրսգրման համար մնացորդը բավարար չէ։']]);
            $this->audit($request,'Ապրանքի ելք','movements',null,null,['product_id'=>$product->id,'qty'=>$data['qty'],'location_id'=>$location,'issue_type'=>$data['issue_type']]);
        });
        return response()->json(['message'=>'Ելքը գրանցվեց FEFO հերթականությամբ։']);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data=$request->validate(['product_id'=>['required','integer','exists:products,id'],'location_id'=>['nullable','integer'],'lot_id'=>['nullable','integer'],'delta_qty'=>['required','numeric','not_in:0'],'reason'=>['required','string','min:3','max:1000'],'lot_no'=>['nullable','string','max:100'],'expires_on'=>['nullable','date_format:Y-m-d'],'unit_cost'=>['nullable','numeric','min:0'],'bin_location'=>['nullable','string','max:100']]);
        $location=$this->location($request,$data['location_id']??$request->user()->currentLocationId());
        $result=DB::transaction(function()use($request,$data,$location):array{
            $product=DB::table('products')->where('id',$data['product_id'])->where('active',1)->first();abort_unless($product,422,'Ընտրված ապրանքը ակտիվ չէ։');$delta=(float)$data['delta_qty'];$lotId=(int)($data['lot_id']??0);$before=0.0;$cost=(float)$product->purchase_price;
            if($lotId){$lot=DB::table('stock_lots')->where('id',$lotId)->where('product_id',$product->id)->where('location_id',$location)->lockForUpdate()->first();abort_unless($lot,404,'LOT-ը տվյալ պահեստում չի գտնվել։');$before=(float)$lot->qty;if($delta<0&&abs($delta)>$before+0.00001)throw ValidationException::withMessages(['delta_qty'=>['Ճշգրտման նվազումը գերազանցում է LOT-ի մնացորդը։']]);$cost=(float)$lot->unit_cost;DB::table('stock_lots')->where('id',$lotId)->update(['qty'=>$before+$delta]);}
            else{abort_if($delta<0,422,'Մնացորդը նվազեցնելու համար ընտրեք առկա LOT-ը։');$lotNo=trim($data['lot_no']??'');abort_if($lotNo==='',422,'Նոր մնացորդի համար LOT-ի համարը պարտադիր է։');if($product->expiry_control&&!($data['expires_on']??null))throw ValidationException::withMessages(['expires_on'=>['Այս ապրանքի համար պիտանելիության ժամկետը պարտադիր է։']]);if(!empty($data['expires_on'])&&$data['expires_on']<now()->toDateString())throw ValidationException::withMessages(['expires_on'=>['Նոր LOT-ի պիտանելիության ժամկետն անցած է։']]);$cost=(float)($data['unit_cost']??$product->purchase_price);$exists=DB::table('stock_lots')->where('product_id',$product->id)->where('location_id',$location)->where('lot_no',$lotNo)->whereRaw('IFNULL(expires_on, \'1000-01-01\')=IFNULL(?, \'1000-01-01\')',[$data['expires_on']??null])->lockForUpdate()->exists();abort_if($exists,422,'Այս LOT-ը տվյալ պահեստում արդեն գոյություն ունի։');$lotId=DB::table('stock_lots')->insertGetId(['product_id'=>$product->id,'location_id'=>$location,'lot_no'=>$lotNo,'expires_on'=>$data['expires_on']??null,'received_on'=>now()->toDateString(),'unit_cost'=>$cost,'bin_location'=>trim($data['bin_location']??''),'qty'=>$delta]);}
            $this->movement($request,'inventory_adjustment',(int)$product->id,$lotId,$delta<0?$location:null,$delta>0?$location:null,abs($delta),$cost,'',trim($data['reason']));$this->audit($request,'Պաշարի ճշգրտում','stock_lots',$lotId,['qty'=>$before],['qty'=>$before+$delta,'reason'=>trim($data['reason'])]);return ['lot_id'=>$lotId,'qty'=>$before+$delta];
        });
        return response()->json(['data'=>$result,'message'=>'Պաշարի ճշգրտումը գրանցվեց նոր շարժով։']);
    }

    private function location(Request $request,int $location):int
    { $actor=$request->user();if($actor->currentLocationId()>0)abort_unless($location===$actor->currentLocationId(),403,'Կարող եք աշխատել միայն ձեր մասնաճյուղի մնացորդով։');abort_unless($location===0||DB::table('branches')->where('id',$location)->where('active',1)->exists(),422,'Ընտրված պահեստը ակտիվ չէ։');return $location; }
    private function movement(Request $request,string $type,int $product,int $lot,?int $from,?int $to,float $qty,float $cost,string $reference,string $reason):void
    { DB::table('movements')->insert(['movement_no'=>'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),'type'=>$type,'product_id'=>$product,'lot_id'=>$lot,'from_location'=>$from,'to_location'=>$to,'qty'=>$qty,'unit_cost'=>$cost,'reference'=>$reference,'reason'=>$reason,'actor_id'=>$request->user()->id,'happened_at'=>now(),'created_at'=>now()]); }
    private function audit(Request $request,string $action,string $entity,?int $id,?array $before,?array $after):void
    { AuditLog::create(['actor_id'=>$request->user()->id,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'before_data'=>$before,'after_data'=>$after,'ip_address'=>$request->ip(),'created_at'=>now()]); }
}
