<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use JsonException;

final class WorkflowStatus
{
    /** @var array<string, array<string, array<string, mixed>>>|null */
    private static ?array $catalog = null;

    public static function label(string $workflow, mixed $status): mixed
    {
        if (! is_string($status) && ! is_int($status)) {
            return $status;
        }

        $catalog = self::catalog();
        $label = $catalog[$workflow][$status]['label'] ?? $catalog['generic'][$status]['label'] ?? null;

        return is_string($label) ? $label : $status;
    }

    public static function workflowFromReport(string $type): string
    {
        return match ($type) {
            'supplier_purchases', 'purchases_by_period' => 'purchases',
            'inventory_differences' => 'inventory',
            'branch_requests', 'rejected_requests' => 'requests',
            default => 'generic',
        };
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private static function catalog(): array
    {
        if (self::$catalog === null) {
            try {
                $catalog = json_decode(File::get(resource_path('js/workflowStatuses.json')), true, 512, JSON_THROW_ON_ERROR);
            } catch (FileNotFoundException|JsonException) {
                $catalog = [];
            }
            self::$catalog = is_array($catalog) ? $catalog : [];
        }

        return self::$catalog;
    }
}
