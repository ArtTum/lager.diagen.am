<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferItem extends Model
{
    protected $table = 'transfer_items';
    public $timestamps = false;
    protected $fillable = ['transfer_id', 'product_id', 'qty', 'received_qty', 'note'];
    protected function casts(): array { return ['qty' => 'decimal:3', 'received_qty' => 'decimal:3']; }
}
