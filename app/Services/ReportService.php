<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\ReportRepository;
use App\Support\WorkflowStatus;
use Illuminate\Validation\ValidationException;

class ReportService
{
    /** @var array<string, string> */
    private const TYPES = [
        'stock_by_location' => 'Ընդհանուր մնացորդներ',
        'central_stock' => 'Կենտրոնական պահեստի մնացորդ',
        'branch_stock' => 'Մասնաճյուղերի մնացորդ',
        'receipts' => 'Մուտքեր',
        'issues' => 'Ելքեր',
        'movements' => 'Պահեստի շարժ',
        'product_movement' => 'Ըստ ապրանքի շարժ',
        'branch_expense' => 'Ըստ մասնաճյուղի ծախս',
        'supplier_purchases' => 'Ըստ մատակարարի գնումներ',
        'purchases_by_period' => 'Ըստ ժամանակահատվածի գնումներ',
        'item_value' => 'Ապրանքների արժեք',
        'low_stock' => 'Ցածր մնացորդ',
        'expired_lots' => 'Ժամկետանց ապրանքներ',
        'near_expiry' => 'Մոտ ժամկետանց ապրանքներ',
        'returns' => 'Վերադարձներ',
        'inventory_differences' => 'Գույքագրման տարբերություններ',
        'branch_requests' => 'Մասնաճյուղերի պահանջագրեր',
        'rejected_requests' => 'Մերժված պահանջագրեր',
        'average_usage' => 'Ապրանքների միջին ամսական սպառում',
    ];

    public function __construct(private readonly ReportRepository $reports) {}

    /** @return array<string, string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function data(User $actor, array $filters): array
    {
        $type = (string) ($filters['report_type'] ?? 'stock_by_location');
        $showCosts = $actor->hasPermissionCode('purchases.view');
        $showSuppliers = $this->canViewSupplierDetails($actor);
        $this->assertReportAllowed($actor, $type, $showCosts);
        if (! $showSuppliers) {
            unset($filters['supplier_id']);
        }

        $location = $actor->currentLocationId();
        $branchId = $location > 0
            ? $location
            : $this->selectedBranch($filters['branch_id'] ?? null);
        $from = (string) ($filters['from'] ?? now()->startOfMonth()->toDateString());
        $to = (string) ($filters['to'] ?? now()->toDateString());
        $columns = $this->columnsFor($type, $showCosts, $showSuppliers);
        $query = $this->reports->query($type, $from, $to, $branchId, $filters);
        $rows = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 5), 100))->withQueryString();

        $summary = $this->reports->stockSummary($location);
        if (! $showCosts) {
            $summary['value'] = null;
        }

        return [
            'report_type' => $type,
            'report_types' => $this->availableTypes($actor, $showCosts),
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'show_cost' => $showCosts,
            'filters' => $this->reports->filterOptions($location, $showCosts, $showSuppliers),
            'summary' => $summary,
            'columns' => $columns,
            'data' => $rows->getCollection()
                ->map(fn (object $row): array => $this->projectRow($row, array_keys($columns)))
                ->all(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function export(User $actor, array $filters): array
    {
        $type = (string) ($filters['report_type'] ?? 'stock_by_location');
        $showCosts = $actor->hasPermissionCode('purchases.view');
        $showSuppliers = $this->canViewSupplierDetails($actor);
        $this->assertReportAllowed($actor, $type, $showCosts);
        if (! $showSuppliers) {
            unset($filters['supplier_id']);
        }

        $location = $actor->currentLocationId();
        $branchId = $location > 0
            ? $location
            : $this->selectedBranch($filters['branch_id'] ?? null);
        $from = (string) ($filters['from'] ?? now()->startOfMonth()->toDateString());
        $to = (string) ($filters['to'] ?? now()->toDateString());
        $columns = $this->columnsFor($type, $showCosts, $showSuppliers);
        $query = $this->reports->query($type, $from, $to, $branchId, $filters);
        $keys = array_keys($columns);

        return [
            'type' => $type,
            'headers' => array_values($columns),
            'column_keys' => $keys,
            'query' => $query,
            'rows' => (function () use ($query, $keys, $type): \Generator {
                foreach ($query->cursor() as $row) {
                    $values = $this->projectRow($row, $keys);
                    if (array_key_exists('status', $values)) {
                        $values['status'] = WorkflowStatus::label(WorkflowStatus::workflowFromReport($type), $values['status']);
                    }
                    yield array_map(static fn (string $key): mixed => $values[$key] ?? null, $keys);
                }
            })(),
        ];
    }

    private function assertReportAllowed(User $actor, string $type, bool $showCosts): void
    {
        if (! array_key_exists($type, self::TYPES)) {
            throw ValidationException::withMessages(['report_type' => ['Հաշվետվության տեսակը վավեր չէ։']]);
        }

        if (in_array($type, ['supplier_purchases', 'purchases_by_period'], true) && ! $showCosts) {
            abort(403, 'Գնումների այս հաշվետվությունը հասանելի է միայն գնումների դիտման իրավունք ունեցող դերերին։');
        }

        if ($actor->currentLocationId() > 0 && $type === 'central_stock') {
            abort(403, 'Կենտրոնական պահեստի հաշվետվությունը հասանելի է միայն կենտրոնական դերերին։');
        }
    }

    /** @return array<string, string> */
    private function availableTypes(User $actor, bool $showCosts): array
    {
        $types = self::TYPES;

        if ($actor->currentLocationId() > 0) {
            unset($types['central_stock']);
        }

        if (! $showCosts || $actor->currentLocationId() > 0) {
            unset($types['supplier_purchases'], $types['purchases_by_period']);
        }

        return $types;
    }

    private function selectedBranch(mixed $branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        return (int) $branchId;
    }

    /** @return array<string, string> */
    private function canViewSupplierDetails(User $actor): bool
    {
        return $actor->hasPermissionCode('suppliers.view') || $actor->hasPermissionCode('purchases.view');
    }

    /** @return array<string, string> */
    private function columnsFor(string $type, bool $showCosts, bool $showSuppliers): array
    {
        $columns = match ($type) {
            'stock_by_location', 'central_stock', 'branch_stock', 'item_value', 'low_stock' => [
                'branch_name' => 'Պահեստ', 'code' => 'Կոդ', 'product_name' => 'Ապրանք',
                'category' => 'Խումբ', 'supplier' => 'Մատակարար', 'unit' => 'Միավոր',
                'quantity' => 'Փաստացի մնացորդ', 'reserved_quantity' => 'Պահուստավորված', 'free_quantity' => 'Ազատ մնացորդ',
                'min_qty' => 'MIN', 'optimal_qty' => 'OPTIMAL', 'max_qty' => 'MAX',
                'value' => 'Ընդհանուր արժեք',
            ],
            'receipts' => [
                'document_no' => 'Մուտքի փաստաթուղթ', 'happened_on' => 'Ամսաթիվ', 'branch_name' => 'Պահեստ',
                'supplier' => 'Մատակարար', 'code' => 'Կոդ', 'product_name' => 'Ապրանք', 'lot_no' => 'LOT',
                'expires_on' => 'Պիտանելիության ժամկետ', 'quantity' => 'Քանակ', 'unit_cost' => 'Միավորի գին',
                'value' => 'Ընդհանուր արժեք', 'actor' => 'Ընդունող',
            ],
            'issues', 'movements', 'product_movement' => [
                'document_no' => 'Փաստաթուղթ', 'movement_type' => 'Գործողություն', 'happened_on' => 'Ամսաթիվ',
                'code' => 'Կոդ', 'product_name' => 'Ապրանք', 'lot_no' => 'LOT', 'from_name' => 'Ումից',
                'to_name' => 'Ուր', 'quantity' => 'Քանակ', 'unit_cost' => 'Միավորի գին', 'value' => 'Ընդհանուր արժեք',
                'reference' => 'Հղում', 'reason' => 'Պատճառ', 'supplier' => 'Մատակարար', 'actor' => 'Կատարող',
            ],
            'branch_expense' => [
                'branch_name' => 'Մասնաճյուղ', 'code' => 'Կոդ', 'product_name' => 'Ապրանք', 'unit' => 'Միավոր',
                'used_qty' => 'Օգտագործված քանակ', 'average_unit_cost' => 'Միջին միավորի արժեք', 'used_cost' => 'Ընդհանուր արժեք',
            ],
            'supplier_purchases', 'purchases_by_period' => [
                'document_no' => 'Պատվեր', 'happened_on' => 'Ամսաթիվ', 'status' => 'Կարգավիճակ', 'supplier' => 'Մատակարար',
                'code' => 'Կոդ', 'product_name' => 'Ապրանք', 'ordered_quantity' => 'Պատվիրված',
                'received_quantity' => 'Ստացված', 'unit_cost' => 'Միավորի գին', 'value' => 'Ընդհանուր արժեք',
            ],
            'expired_lots', 'near_expiry' => [
                'branch_name' => 'Պահեստ', 'code' => 'Կոդ', 'product_name' => 'Ապրանք', 'lot_no' => 'LOT',
                'expires_on' => 'Պիտանելիության ժամկետ', 'quantity' => 'Քանակ', 'supplier' => 'Մատակարար', 'bin_location' => 'Պահեստային տեղ',
            ],
            'returns' => [
                'document_no' => 'Վերադարձի փաստաթուղթ', 'happened_on' => 'Ամսաթիվ', 'direction' => 'Ուղղություն',
                'branch_name' => 'Պահեստ', 'supplier' => 'Մատակարար', 'code' => 'Կոդ', 'product_name' => 'Ապրանք',
                'lot_no' => 'LOT', 'quantity' => 'Քանակ', 'reason' => 'Պատճառ', 'actor' => 'Կատարող',
            ],
            'inventory_differences' => [
                'document_no' => 'Գույքագրման ակտ', 'happened_on' => 'Ամսաթիվ', 'status' => 'Կարգավիճակ',
                'branch_name' => 'Պահեստ', 'code' => 'Կոդ', 'product_name' => 'Ապրանք', 'lot_no' => 'LOT',
                'expected_qty' => 'Հաշվառված', 'counted_qty' => 'Փաստացի', 'difference' => 'Տարբերություն', 'difference_reason' => 'Պատճառ',
            ],
            'branch_requests', 'rejected_requests' => [
                'document_no' => 'Պահանջագիր', 'happened_on' => 'Ամսաթիվ', 'status' => 'Կարգավիճակ', 'urgency' => 'Հրատապություն',
                'branch_name' => 'Մասնաճյուղ', 'code' => 'Կոդ', 'product_name' => 'Ապրանք', 'requested_qty' => 'Պահանջված',
                'approved_qty' => 'Հաստատված', 'received_qty' => 'Ստացված', 'rejection_reason' => 'Մերժման պատճառ', 'actor' => 'Պահանջող',
            ],
            'average_usage' => [
                'branch_name' => 'Մասնաճյուղ', 'code' => 'Կոդ', 'product_name' => 'Ապրանք',
                'consumed' => '90 օրվա սպառում', 'monthly_average' => 'Միջին ամսական',
            ],
            default => [],
        };

        if (! $showCosts) {
            foreach (['unit_cost', 'average_unit_cost', 'used_cost', 'value'] as $sensitiveColumn) {
                unset($columns[$sensitiveColumn]);
            }
        }
        if (! $showSuppliers) {
            unset($columns['supplier']);
        }

        return $columns;
    }

    /** @param list<string> $keys @return array<string, mixed> */
    private function projectRow(object $row, array $keys): array
    {
        $values = method_exists($row, 'getAttributes')
            ? $row->getAttributes()
            : (array) $row;

        if (in_array('reserved_quantity', $keys, true)) {
            $reserved = (float) ($values['reserved_transfers'] ?? 0);
            if ((int) ($values['location_id'] ?? 0) === 0) {
                $reserved += (float) ($values['reserved_requests'] ?? 0);
            }
            $values['reserved_quantity'] = $reserved;
            $values['free_quantity'] = max(0, (float) ($values['quantity'] ?? 0) - $reserved);
        }

        return array_intersect_key($values, array_fill_keys($keys, true));
    }
}
