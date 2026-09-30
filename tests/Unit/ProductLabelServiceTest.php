<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Repositories\ProductLabelRepository;
use App\Services\ProductLabelService;
use Mockery;
use Tests\TestCase;

class ProductLabelServiceTest extends TestCase
{
    public function test_label_uses_barcode_and_eager_loaded_category(): void
    {
        $product = new Product([
            'code' => 'DIS-055', 'barcode' => '123ABC', 'name' => 'Ապրանք', 'unit' => 'հատ',
            'package' => 'տուփ', 'category_id' => 3,
        ]);
        $product->setAttribute('id', 12);
        $product->setRelation('category', new Category(['name' => 'Դեղորայք']));

        $repository = Mockery::mock(ProductLabelRepository::class);
        $repository->shouldReceive('findActiveProduct')->once()->with(12)->andReturn($product);

        $result = (new ProductLabelService($repository))->make(12);

        self::assertSame('Դեղորայք', $result['product']['category']);
        self::assertStringContainsString('Code 39՝ 123ABC', $result['barcode_svg']);
        self::assertNull($result['barcode_error']);
    }

    public function test_unsupported_barcode_returns_a_form_error_without_emitting_invalid_svg(): void
    {
        $product = new Product(['code' => 'CODE-1', 'barcode' => 'bad_code', 'name' => 'Ապրանք', 'unit' => 'հատ']);
        $repository = Mockery::mock(ProductLabelRepository::class);
        $repository->shouldReceive('findActiveProduct')->once()->with(4)->andReturn($product);

        $result = (new ProductLabelService($repository))->make(4);

        self::assertNull($result['barcode_svg']);
        self::assertStringContainsString('Code 39-ը չի աջակցում', $result['barcode_error']);
    }
}
