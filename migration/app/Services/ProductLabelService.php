<?php

namespace App\Services;

use App\Repositories\ProductLabelRepository;
use Illuminate\Support\Str;
use RuntimeException;

class ProductLabelService
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

    private const ENCODINGS = [0x034, 0x121, 0x061, 0x160, 0x031, 0x130, 0x070, 0x025, 0x124, 0x064, 0x109, 0x049, 0x148, 0x019, 0x118, 0x058, 0x00D, 0x10C, 0x04C, 0x01C, 0x103, 0x043, 0x142, 0x013, 0x112, 0x052, 0x007, 0x106, 0x046, 0x016, 0x181, 0x0C1, 0x1C0, 0x091, 0x190, 0x0D0, 0x085, 0x184, 0x0C4, 0x0A8, 0x0A2, 0x08A, 0x02A];

    public function __construct(private readonly ProductLabelRepository $products) {}

    public function make(int $productId): array
    {
        $product = $this->products->findActiveProduct($productId);
        $category = $product->category?->name;
        $code = trim((string) ($product->barcode ?: $product->code));
        try {
            $barcode = $this->code39($code);
            $barcodeError = null;
        } catch (RuntimeException $exception) {
            $barcode = null;
            $barcodeError = $exception->getMessage();
        }

        return ['product' => ['id' => (int) $product->id, 'code' => $product->code, 'barcode' => $product->barcode,
            'name' => $product->name, 'unit' => $product->unit, 'package' => $product->package, 'category' => $category],
            'barcode_svg' => $barcode, 'barcode_error' => $barcodeError];
    }

    private function code39(string $value): string
    {
        $value = Str::upper(trim($value));
        if ($value === '' || str_contains($value, '*')) {
            throw new RuntimeException('Պիտակի համար մուտքագրեք Code 39-ին համապատասխան շտրիխ կոդ կամ ապրանքի կոդ։');
        }
        $characters = '*'.$value.'*';
        $x = 10;
        $rectangles = '';
        for ($characterIndex = 0, $length = strlen($characters); $characterIndex < $length; $characterIndex++) {
            $character = $characters[$characterIndex];
            $index = $character === '*' ? -1 : strpos(self::ALPHABET, $character);
            if ($index === false) {
                throw new RuntimeException('Code 39-ը չի աջակցում «'.$character.'» նիշը։ Օգտագործեք A–Z, 0–9 կամ - . $ / + %։');
            }
            $pattern = $index === -1 ? 0x094 : self::ENCODINGS[$index];
            for ($bar = 0; $bar < 9; $bar++) {
                $wide = ($pattern & (1 << (8 - $bar))) !== 0;
                $width = $wide ? 2 : 1;
                if ($bar % 2 === 0) {
                    $rectangles .= '<rect x="'.$x.'" y="0" width="'.$width.'" height="46"/>';
                }
                $x += $width;
            }
            if ($characterIndex < $length - 1) {
                $x++;
            }
        }
        $width = 20 + strlen($characters) * 13;
        $safeValue = e($value);

        return '<svg class="barcode-svg" role="img" aria-label="Code 39՝ '.$safeValue.'" viewBox="0 0 '.$width.' 62" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="white"/><g fill="#111">'.$rectangles.'</g><text x="50%" y="59" text-anchor="middle" font-family="Arial,sans-serif" font-size="9">'.$safeValue.'</text></svg>';
    }
}
