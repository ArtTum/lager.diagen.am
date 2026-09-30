<?php

namespace App\Support;

final class PermissionCatalog
{
    /** @return array<string, string> */
    public static function modules(): array
    {
        return [
            'dashboard' => 'Գլխավոր վահանակ',
            'suppliers' => 'Մատակարարներ',
            'products' => 'Ապրանքներ',
            'branches' => 'Մասնաճյուղեր',
            'purchases' => 'Գնումների պատվերներ',
            'receipts' => 'Մուտքեր և գնումներ',
            'stock' => 'Մնացորդներ',
            'requests' => 'Պահանջագրեր',
            'movements' => 'Պահեստի շարժ',
            'inventory' => 'Գույքագրում',
            'expiry' => 'Ժամկետների վերահսկում',
            'returns' => 'Վերադարձներ',
            'transfers' => 'Տեղափոխումներ',
            'notifications' => 'Ծանուցումներ',
            'reports' => 'Հաշվետվություններ',
            'users' => 'Օգտատերեր',
            'roles' => 'Դերեր և իրավունքներ',
            'audit' => 'Գործողությունների պատմություն',
        ];
    }

    /** @return array<string, string> */
    public static function actions(): array
    {
        return [
            'view' => 'Դիտել',
            'create' => 'Ստեղծել',
            'edit' => 'Փոփոխել',
            'delete' => 'Ջնջել',
            'approve' => 'Հաստատել',
            'export' => 'Արտահանել',
        ];
    }

    /**
     * Only expose actions backed by a real route or workflow. This avoids
     * creating meaningless permissions such as dashboard.approve.
     *
     * @return array<string, list<string>>
     */
    public static function moduleActions(): array
    {
        return [
            'dashboard' => ['view'],
            'suppliers' => ['view', 'create', 'edit', 'delete'],
            'products' => ['view', 'create', 'edit', 'delete', 'export'],
            'branches' => ['view', 'create', 'edit', 'delete', 'export'],
            'purchases' => ['view', 'create', 'approve', 'export'],
            'receipts' => ['view', 'create', 'export'],
            'stock' => ['view', 'create', 'edit', 'export'],
            'requests' => ['view', 'create', 'edit', 'approve', 'export'],
            'movements' => ['view', 'edit', 'export'],
            'inventory' => ['view', 'create', 'edit', 'approve', 'export'],
            'expiry' => ['view', 'export'],
            'returns' => ['view', 'create', 'export'],
            'transfers' => ['view', 'create', 'edit', 'approve', 'export'],
            'notifications' => ['view'],
            'reports' => ['view', 'export'],
            'users' => ['view', 'create', 'edit', 'delete', 'export'],
            'roles' => ['view', 'create', 'edit', 'export'],
            'audit' => ['view', 'export'],
        ];
    }

    /** @return list<array{code: string, title: string, module: string}> */
    public static function definitions(): array
    {
        $definitions = [];
        $actions = self::actions();
        $modules = self::modules();

        foreach (self::moduleActions() as $module => $moduleActions) {
            foreach ($moduleActions as $action) {
                $definitions[] = [
                    'code' => "$module.$action",
                    'title' => $modules[$module].' — '.$actions[$action],
                    'module' => $module,
                ];
            }
        }

        return $definitions;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::definitions(), 'code');
    }

    /** @param list<string> $modules @return list<string> */
    public static function codesForModules(array $modules): array
    {
        $selected = [];
        foreach ($modules as $module) {
            foreach (self::moduleActions()[$module] ?? [] as $action) {
                $selected[] = "$module.$action";
            }
        }

        return $selected;
    }

    public static function contains(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /** @return list<string> */
    public static function centralOnly(): array
    {
        return ['inventory.approve', 'requests.approve', 'transfers.approve', 'purchases.approve', 'receipts.create'];
    }

    /** Capabilities a branch-scoped account may receive even if its role is misconfigured. @return list<string> */
    public static function branchAllowed(): array
    {
        return [
            'dashboard.view',
            'stock.view', 'stock.create', 'stock.export',
            'requests.view', 'requests.create', 'requests.edit', 'requests.export',
            'movements.view', 'movements.edit', 'movements.export',
            'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.export',
            'expiry.view', 'expiry.export',
            'returns.view', 'returns.create', 'returns.export',
            'transfers.view', 'transfers.create', 'transfers.edit', 'transfers.export',
            'notifications.view',
            'reports.view', 'reports.export',
        ];
    }
}
