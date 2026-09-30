<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ApproveInventorySessionRequest;
use App\Http\Requests\Api\CountInventorySessionRequest;
use App\Http\Requests\Api\PageDataRequest;
use App\Http\Requests\Api\StoreInventorySessionRequest;
use App\Services\InventoryService;
use App\Services\ReportPdfService;
use App\Services\TabularExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly TabularExportService $exports) {}

    public function index(PageDataRequest $request): JsonResponse
    {
        return response()->json($this->inventory->index($request->user(), $request->validated()));
    }

    public function export(PageDataRequest $request): StreamedResponse
    {
        $export = $this->inventory->export($request->user(), $request->validated());
        $format = $request->validated('format', 'csv') ?? 'csv';

        return $this->exports->download($export['headers'], $export['rows'], $format, 'diagen-inventory');
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return response()->json(['data' => $this->inventory->show($request->user(), (int) $session)]);
    }

    public function act(Request $request, string $session): JsonResponse
    {
        return response()->json(['data' => $this->inventory->act($request->user(), (int) $session)]);
    }

    public function downloadActPdf(Request $request, string $session, ReportPdfService $pdfs): Response
    {
        $act = $this->inventory->act($request->user(), (int) $session);
        $date = static fn ($value): string => $value ? $value->format('d.m.Y H:i') : '—';
        $rows = array_map(static fn (array $line): array => [
            $line['code'] ?? '—', $line['product'] ?? '—', $line['lot_no'] ?? '—', $line['unit'] ?? '—',
            $line['expected_qty'], $line['counted_qty'], $line['difference'], $line['reason'] ?: '—',
        ], $act['lines']);

        return $pdfs->download(
            ['Կոդ', 'Ապրանք', 'LOT', 'Միավոր', 'Հաշվառված', 'Փաստացի', 'Տարբերություն', 'Պատճառ'],
            $rows,
            'Գույքագրման ակտ · '.$act['inventory_no'],
            'inventory-act-'.$session,
            [
                'Փաստաթուղթ' => $act['inventory_no'],
                'Պահեստ' => $act['location'],
                'Սկսվել է' => $date($act['started_at']),
                'Փակվել է' => $date($act['closed_at']),
                'Գրանցող' => $act['starter'] ?: '—',
                'Հաստատող' => $act['approver'] ?: '—',
                ...($act['note'] ? ['Նշում' => $act['note']] : []),
            ],
        );
    }

    public function store(StoreInventorySessionRequest $request): JsonResponse
    {
        $session = $this->inventory->start($request->user(), (string) $request->ip(), $request->validated());

        return response()->json(['data' => $session, 'message' => 'Գույքագրումը սկսվեց։'], 201);
    }

    public function count(CountInventorySessionRequest $request, string $session): JsonResponse
    {
        $result = $this->inventory->count($request->user(), (string) $request->ip(), (int) $session, $request->validated());

        return response()->json(['data' => $result, 'message' => 'Քանակները պահպանվեցին և ուղարկվեցին անկախ հաստատման։']);
    }

    public function approve(ApproveInventorySessionRequest $request, string $session): JsonResponse
    {
        $result = $this->inventory->approve($request->user(), (string) $request->ip(), (int) $session);

        return response()->json(['data' => $result, 'message' => 'Գույքագրումը հաստատվեց, տարբերությունները գրանցվեցին։']);
    }
}
