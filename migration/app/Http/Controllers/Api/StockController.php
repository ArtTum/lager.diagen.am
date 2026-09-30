<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdjustStockRequest;
use App\Http\Requests\Api\ConsumeStockRequest;
use App\Http\Requests\Api\StockLotsRequest;
use App\Http\Requests\Api\StockMatrixRequest;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function lots(StockLotsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->stock->lots($request->user(), $request->validated())]);
    }

    public function matrix(StockMatrixRequest $request): JsonResponse
    {
        return response()->json($this->stock->matrix($request->user(), $request->validated()));
    }

    public function exportMatrix(StockMatrixRequest $request)
    {
        $actor = $request->user();
        $search = (string) ($request->validated('search') ?? '');

        return response()->streamDownload(function () use ($actor, $search): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            $headers = ['Կոդ', 'Ապրանք', 'Միավոր'];
            foreach ($this->stock->matrixLocationNames($actor) as $locationName) {
                $headers[] = $locationName;
            }
            $headers[] = 'Ընդամենը';
            fputcsv($stream, $headers, ';', '"', '\\');
            $this->stock->eachMatrixExportRow($actor, $search, static function (array $row) use ($stream): void {
                fputcsv($stream, array_map(static function ($value) {
                    $value = (string) $value;

                    return preg_match('/^[=+@-]/u', $value) ? "'".$value : $value;
                }, $row), ';', '"', '\\');
            });
            fclose($stream);
        }, 'diagen-stock-matrix.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function consume(ConsumeStockRequest $request): JsonResponse
    {
        $this->stock->consume($request->user(), (string) $request->ip(), $request->validated());

        return response()->json(['message' => 'Ելքը գրանցվեց FEFO հերթականությամբ։']);
    }

    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->stock->adjust($request->user(), (string) $request->ip(), $request->validated()), 'message' => 'Պաշարի ճշգրտումը գրանցվեց նոր շարժով։']);
    }
}
