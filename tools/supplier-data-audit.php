<?php
declare(strict_types=1);

require_once __DIR__ . '/db-transfer-common.php';

try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Run this script from the local PHP CLI only.');
    $pdo = transferPdo();
    $fields = [
        'name' => 'անվանում', 'tax_id' => 'ՀՎՀՀ', 'address' => 'հասցե', 'contact_name' => 'կոնտակտային անձ',
        'phone' => 'հեռախոս', 'email' => 'էլ. փոստ', 'bank_details' => 'բանկային տվյալներ',
        'contract_no' => 'պայմանագրի համար', 'contract_start' => 'պայմանագրի սկիզբ',
        'contract_end' => 'պայմանագրի ավարտ', 'payment_terms' => 'վճարման պայմաններ', 'delivery_days' => 'առաքման օրեր',
    ];
    $summary = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(active=1),0) active FROM suppliers')->fetch();
    printf("Մատակարարներ՝ %d ընդհանուր, %d ակտիվ։\n", (int)$summary['total'], (int)$summary['active']);
    foreach ($fields as $field => $label) {
        $sql = 'SELECT COALESCE(SUM(active=1 AND (`' . $field . '` IS NULL OR TRIM(CAST(`' . $field . '` AS CHAR)) = "")),0) FROM suppliers';
        printf("Բացակայող %-24s %d ակտիվ գրառում\n", $label . ':', (int)$pdo->query($sql)->fetchColumn());
    }
    $links = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(product_count>0),0) with_products, COALESCE(SUM(lot_count>0),0) with_lots, COALESCE(SUM(receipt_count>0),0) with_receipts FROM (SELECT s.id, (SELECT COUNT(*) FROM products p WHERE p.supplier_id=s.id) product_count, (SELECT COUNT(*) FROM stock_lots l WHERE l.supplier_id=s.id) lot_count, (SELECT COUNT(*) FROM receipts r WHERE r.supplier_id=s.id) receipt_count FROM suppliers s WHERE s.active=1) x')->fetch();
    printf("Ակտիվ մատակարարներ՝ ապրանքի կապով %d/%d, LOT կապով %d/%d, մուտքի պատմությամբ %d/%d։\n", (int)$links['with_products'], (int)$links['total'], (int)$links['with_lots'], (int)$links['total'], (int)$links['with_receipts'], (int)$links['total']);
    echo "Հաշվետվությունը ցուցադրում է միայն քանակներ, ոչ կոնտակտային տվյալներ։ Լրացրեք միայն փաստաթղթերով հաստատված արժեքները։\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Supplier audit failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
