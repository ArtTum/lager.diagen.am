<?php

namespace App\Repositories;

use App\Models\AuditLog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockRequestRepository
{
    public function paginateVisible(int $location, int $perPage = 15): mixed
    {
        return DB::table('stock_requests as r')->join('branches as b','b.id','=','r.branch_id')
            ->when($location>0,fn(Builder $q)=>$q->where('r.branch_id',$location))
            ->select('r.*','b.name as branch_name')->orderByDesc('r.id')->paginate($perPage);
    }

    public function lock(int $id): ?object
    { return DB::table('stock_requests')->where('id',$id)->lockForUpdate()->first(); }

    public function findWithItems(int $id): ?object
    {
        $row=DB::table('stock_requests as r')->join('branches as b','b.id','=','r.branch_id')->where('r.id',$id)->select('r.*','b.name as branch_name')->first();
        if($row)$row->items=$this->items($id);
        return $row;
    }

    public function items(int $requestId, bool $lock = false): Collection
    {
        $query=DB::table('request_items as i')->join('products as p','p.id','=','i.product_id')->where('i.request_id',$requestId)
            ->select('i.*','p.code','p.name','p.unit','p.expiry_control')->orderBy('i.product_id');
        return $lock?$query->lockForUpdate()->get():$query->get();
    }

    public function replaceItems(int $id, array $items): void
    {
        DB::table('request_items')->where('request_id',$id)->delete();
        foreach($items as $item)DB::table('request_items')->insert(['request_id'=>$id,'product_id'=>$item['product_id'],'requested_qty'=>$item['qty'],'approved_qty'=>0,'note'=>trim($item['note']??'')]);
    }

    public function centralFreeStock(int $productId,int $excludeRequest):float
    {
        $lots=DB::table('stock_lots')->where('product_id',$productId)->where('location_id',0)->where('qty','>',0)
            ->where(fn(Builder $q)=>$q->whereNull('expires_on')->orWhere('expires_on','>=',now()->toDateString()))->lockForUpdate()->get(['qty']);
        $physical=(float)$lots->sum(static fn($lot):float=>(float)$lot->qty);
        $requests=(float)DB::table('request_items as i')->join('stock_requests as r','r.id','=','i.request_id')->where('i.product_id',$productId)->where('r.id','<>',$excludeRequest)
            ->whereIn('r.status',['approved','partially_approved','collecting','ready_to_ship'])->sum('i.approved_qty');
        $central=(int)DB::table('branches')->where('code','CENTRAL')->value('id');
        $transfers=(float)DB::table('transfer_items as i')->join('transfers as t','t.id','=','i.transfer_id')->where('i.product_id',$productId)->where('t.from_branch',$central)->where('t.status','approved')->sum('i.qty');
        return max(0,$physical-$requests-$transfers);
    }

    public function lockCentralLots(int $productId): Collection
    { return DB::table('stock_lots')->where('product_id',$productId)->where('location_id',0)->where('qty','>',0)->where(fn(Builder $q)=>$q->whereNull('expires_on')->orWhere('expires_on','>=',now()->toDateString()))->orderByRaw('(expires_on IS NULL),expires_on,received_on,id')->lockForUpdate()->get(); }

    public function matchingLot(int $product,int $location,string $lot,?string $expiresOn,bool $lock=true):?object
    { $q=DB::table('stock_lots')->where('product_id',$product)->where('location_id',$location)->where('lot_no',$lot)->whereRaw('IFNULL(expires_on, \'1000-01-01\')=IFNULL(?, \'1000-01-01\')',[$expiresOn]);if($lock)$q->lockForUpdate();return $q->first(); }

    public function audit(int $actor,string $action,int $id,?array $before,?array $after,?string $ip):void
    { AuditLog::create(['actor_id'=>$actor,'action'=>$action,'entity'=>'stock_requests','entity_id'=>$id,'before_data'=>$before,'after_data'=>$after,'ip_address'=>$ip,'created_at'=>now()]); }

    public function movement(int $actor,string $type,int $product,int $lot,?int $from,?int $to,float $qty,float $cost,string $reference,string $reason):void
    { DB::table('movements')->insert(['movement_no'=>'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),'type'=>$type,'product_id'=>$product,'lot_id'=>$lot,'from_location'=>$from,'to_location'=>$to,'qty'=>$qty,'unit_cost'=>$cost,'reference'=>$reference,'reason'=>$reason,'actor_id'=>$actor,'happened_at'=>now(),'created_at'=>now()]); }
}
