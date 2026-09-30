<?php

namespace App\Services;

use App\Models\User;
use App\Models\Branch;
use App\Models\Supplier;
use App\Repositories\PageDataRepository;
use Illuminate\Database\Eloquent\Builder;

class PageDataService
{
    private const SENSITIVE_AUDIT_FIELDS = ['purchase_price', 'unit_cost', 'counted_unit_cost', 'stock_value', 'bank_details'];

    public function __construct(private readonly PageDataRepository $pages) {}

    public function page(string $name, int $location, array $filters, User $actor): array
    {
        [$columns, $query] = $this->filteredDefinition($name, $location, $filters, $actor);
        $rows = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 5), 100))->withQueryString();
        $data = $rows->items();
        if ($name === 'audit' && ! $actor->hasPermissionCode('purchases.view')) {
            foreach ($data as $row) {
                foreach (['before_data', 'after_data'] as $field) {
                    $value = $row->{$field};
                    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
                    if (is_array($decoded)) {
                        $row->{$field} = json_encode($this->redactAuditFields($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            }
        }

        return ['data' => $data, 'columns' => $columns, 'filter_options' => $this->filterOptions($name), 'pagination' => [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(), 'total' => $rows->total(),
        ]];
    }

    /** @return array{columns: array<string, string>, query: Builder} */
    public function export(string $name, int $location, array $filters, User $actor): array
    {
        [$columns, $query] = $this->filteredDefinition($name, $location, $filters, $actor);
        $showCosts = $actor->hasPermissionCode('purchases.view');

        return [
            'columns' => $columns,
            'rows' => (function () use ($name, $columns, $query, $showCosts): \Generator {
                foreach ($query->cursor() as $row) {
                    $values = [];
                    foreach (array_keys($columns) as $key) {
                        $value = $row->{$key} ?? null;
                        if ($name === 'audit' && ! $showCosts && in_array($key, ['before_data', 'after_data'], true)) {
                            $decoded = is_array($value) ? $value : json_decode((string) $value, true);
                            if (is_array($decoded)) {
                                $value = json_encode($this->redactAuditFields($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }
                        }
                        $values[] = $this->exportValue($name, $key, $value);
                    }
                    yield $values;
                }
            })(),
        ];
    }

    /** @return array{0: array<string, string>, 1: Builder} */
    private function filteredDefinition(string $name, int $location, array $filters, User $actor): array
    {
        [$columns, $query, $searchFields] = $this->pages->definition($name, $location, $filters, $actor->hasPermissionCode('purchases.view'));
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($where) use ($searchFields, $search): void {
                foreach ($searchFields as $field) {
                    $where->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        $statusColumns = [
            'requests' => 'stock_requests.status', 'transfers' => 'transfers.status',
            'purchases' => 'purchase_orders.status', 'inventory' => 'inventory_sessions.status',
        ];
        if (isset($statusColumns[$name]) && filled($filters['status'] ?? null)) {
            $query->where($statusColumns[$name], $filters['status']);
        }

        $dateColumns = [
            'requests' => 'stock_requests.created_at', 'transfers' => 'transfers.created_at',
            'purchases' => 'purchase_orders.ordered_on', 'receipts' => 'receipts.received_on',
            'inventory' => 'inventory_sessions.started_at', 'audit' => 'audit_logs.created_at',
        ];
        if (isset($dateColumns[$name])) {
            if (filled($filters['from'] ?? null)) {
                $query->whereDate($dateColumns[$name], '>=', $filters['from']);
            }
            if (filled($filters['to'] ?? null)) {
                $query->whereDate($dateColumns[$name], '<=', $filters['to']);
            }
        }

        if ($name === 'requests' && filled($filters['urgency'] ?? null)) {
            $query->where('stock_requests.urgency', $filters['urgency']);
        }
        if ($name === 'requests' && filled($filters['branch_id'] ?? null)) {
            $query->where('stock_requests.branch_id', (int) $filters['branch_id']);
        }
        if ($name === 'transfers') {
            foreach (['from_branch' => 'transfers.from_branch', 'to_branch' => 'transfers.to_branch'] as $key => $column) {
                if (filled($filters[$key] ?? null)) {
                    $query->where($column, (int) $filters[$key]);
                }
            }
        }
        if (in_array($name, ['purchases', 'receipts'], true) && filled($filters['supplier_id'] ?? null)) {
            $query->where($name === 'purchases' ? 'purchase_orders.supplier_id' : 'receipts.supplier_id', (int) $filters['supplier_id']);
        }

        return [$columns, $query];
    }

    private function redactAuditFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, self::SENSITIVE_AUDIT_FIELDS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->redactAuditFields($value);
            }
        }

        return $data;
    }

    private function filterOptions(string $page): array
    {
        return match ($page) {
            'requests', 'transfers' => ['branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name'])],
            'purchases', 'receipts' => ['suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name'])],
            default => [],
        };
    }

    private function exportValue(string $page, string $key, mixed $value): mixed
    {
        if ($key === 'active') {
            return (bool) $value ? 'Ակտիվ' : 'Ապաակտիվ';
        }
        if ($key === 'urgency') {
            return ['normal' => 'Սովորական', 'high' => 'Բարձր', 'urgent' => 'Շտապ'][$value] ?? $value;
        }
        if ($key !== 'status') {
            return $value;
        }

        return [
            'pending' => 'Սպասում է հաստատման', 'stock_shortage' => 'Սպասում է պաշարի համալրման', 'draft' => 'Սևագիր', 'sent' => 'Ուղարկված',
            'review' => 'Ստուգման փուլում', 'approved' => 'Հաստատված', 'partially_approved' => 'Մասնակի հաստատված',
            'collecting' => 'Հավաքագրվում է', 'ready_to_ship' => 'Պատրաստ է առաքման', 'shipped' => 'Ուղարկված է',
            'received' => 'Ստացված է', 'closed' => 'Փակված', 'open' => 'Հաշվարկման փուլում',
            'counted' => 'Սպասում է հաստատման', 'completed' => 'Ավարտված', 'cancelled' => 'Չեղարկված', 'rejected' => 'Մերժված',
        ][$value] ?? $value;
    }
}
