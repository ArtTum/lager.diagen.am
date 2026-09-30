<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends Model
{
    protected $table = 'receipts';

    public $timestamps = false;

    protected $fillable = ['receipt_no', 'purchase_order_id', 'supplier_id', 'invoice_no', 'contract_no', 'received_by', 'received_on', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['received_on' => 'date:Y-m-d'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class, 'receipt_id');
    }
}
