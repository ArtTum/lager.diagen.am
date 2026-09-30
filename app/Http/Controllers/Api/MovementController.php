<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MovementFiltersRequest;
use App\Http\Requests\Api\ReverseMovementRequest;
use App\Services\MovementService;
use App\Services\XlsxExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MovementController extends Controller
{
    public function __construct(private readonly MovementService $movements, private readonly XlsxExportService $xlsx) {}

    public function index(MovementFiltersRequest $request): JsonResponse
    {
        return response()->json($this->movements->index($request->user(), $request->validated()));
    }

    public function reverse(ReverseMovementRequest $request, string $movement): JsonResponse
    {
        $result = $this->movements->reverse($request->user(), (string) $request->ip(), (int) $movement, $request->validated());

        return response()->json(['data' => $result, 'message' => 'Ուղղիչ շարժը գրանցվեց։ Սկզբնական գրառումը պահպանվել է։']);
    }

    public function export(MovementFiltersRequest $request): StreamedResponse
    {
        $export = $this->movements->export($request->user(), $request->validated());
        $format = $request->validated('format', 'csv') ?? 'csv';
        $filename = 'diagen-movements.'.$format;

        if ($format === 'xlsx') {
            $rows = (function () use ($export): \Generator {
                foreach ($export['query']->cursor() as $movement) {
                    yield $this->exportRow($movement, $export['show_cost'], $export['show_supplier']);
                }
            })();
            $content = $this->xlsx->build($export['headers'], $rows);

            return response()->streamDownload(static function () use ($content): void {
                echo $content;
            }, $filename,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($export): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $export['headers']);
            foreach ($export['query']->cursor() as $movement) {
                $cells = $this->exportRow($movement, $export['show_cost'], $export['show_supplier']);
                $cells = array_map(static function (mixed $cell): mixed {
                    if (! is_string($cell)) {
                        return $cell;
                    }

                    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $cell) ? "'".$cell : $cell;
                }, $cells);
                fputcsv($output, $cells);
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<mixed> */
    private function exportRow(object $movement, bool $showCost, bool $showSupplier): array
    {
        $row = [$movement->happened_at, $movement->reference ?: $movement->movement_no, $movement->type,
            $movement->product_code, $movement->product, $movement->category, $movement->lot_no];
        if ($showSupplier) {
            $row[] = $movement->supplier;
        }
        array_push($row,
            $movement->from_location === null ? '' : ((int) $movement->from_location === 0 ? 'Կենտրոնական պահեստ' : $movement->from_branch),
            $movement->to_location === null ? '' : ((int) $movement->to_location === 0 ? 'Կենտրոնական պահեստ' : $movement->to_branch),
            $movement->qty);
        if ($showCost) {
            $row[] = $movement->unit_cost;
        }
        array_push($row, $movement->actor, $movement->reason);

        return $row;
    }
}
