<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'tax_id', 'address', 'contact_name', 'phone', 'email', 'bank_details', 'contract_no', 'contract_start', 'contract_end', 'payment_terms', 'delivery_days', 'active'];
    protected function casts(): array { return ['active' => 'boolean', 'contract_start' => 'date:Y-m-d', 'contract_end' => 'date:Y-m-d']; }
}
