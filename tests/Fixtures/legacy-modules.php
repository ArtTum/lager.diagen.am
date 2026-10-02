<?php

// Legacy navigation snapshot from bfc04ee:public/index.php, before the root Laravel cutover.
// Keep this independent of the current router and permission catalog to catch lost modules.
$modules = [
    'dashboard' => ['Գլխավոր վահանակ', '◫', 'dashboard.view'], 'suppliers' => ['Մատակարարներ', '▣', 'suppliers.view'],
    'products' => ['Ապրանքների ցանկ', '◇', 'products.view'], 'branches' => ['Մասնաճյուղեր', '⌂', 'branches.view'],
    'purchases' => ['Գնումների պատվերներ', '▧', 'purchases.view'], 'receipts' => ['Մուտքեր', '↓', 'receipts.view'], 'stock' => ['Ընդհանուր մնացորդ', '▤', 'stock.view'],
    'requests' => ['Պահանջագրեր', '⇧', 'requests.view'], 'dispatch' => ['Բաշխման փաստաթուղթ', '▤', 'requests.view'], 'movements' => ['Պահեստի շարժ', '⇄', 'movements.view'],
    'inventory' => ['Գույքագրում', '☷', 'inventory.view'], 'expiry' => ['Ժամկետների վերահսկում', '◷', 'expiry.view'],
    'returns' => ['Վերադարձներ', '↩', 'returns.view'], 'transfers' => ['Տեղափոխումներ', '⇆', 'transfers.view'],
    'notifications' => ['Ծանուցումներ', '♧', 'notifications.view'], 'reports' => ['Հաշվետվություններ', '▥', 'reports.view'], 'users' => ['Օգտատերեր', '♙', 'users.view'],
    'roles' => ['Դերեր և իրավունքներ', '⚙', 'roles.view'], 'audit' => ['Գործողությունների պատմություն', '◉', 'audit.view'],
];
