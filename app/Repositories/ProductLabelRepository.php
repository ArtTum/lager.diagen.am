<?php

namespace App\Repositories;

use App\Models\Product;

class ProductLabelRepository
{
    public function findActiveProduct(int $id): Product
    {
        return Product::query()->with(['supplier:id,name', 'category:id,name'])
            ->where('active', true)->findOrFail($id);
    }
}
