<?php
declare(strict_types=1);

function loadSettings(string $file): array {
    $settings=[];
    if (is_file($file)) foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line=trim($line); if ($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
        [$key,$value]=explode('=',$line,2); $settings[trim($key)]=trim(trim($value),"\"'");
    }
    return $settings;
}
$env=loadSettings(dirname(__DIR__).'/.env');
$pdo=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'diagen_lager').';charset=utf8mb4', $env['DB_USERNAME']??'root', $env['DB_PASSWORD']??'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$sql=file_get_contents(__DIR__.'/schema.sql');
foreach (array_filter(array_map('trim', explode(';',$sql))) as $statement) $pdo->exec($statement);
// Idempotent upgrades for installations created by earlier versions of the app.
foreach ([
 'suppliers'=>['bank_details'=>'VARCHAR(255) NULL AFTER email','contract_start'=>'DATE NULL AFTER contract_no','contract_end'=>'DATE NULL AFTER contract_start'],
 'categories'=>['parent_id'=>'BIGINT UNSIGNED NULL AFTER name'],
 'products'=>['barcode'=>'VARCHAR(100) NULL AFTER code','subcategory'=>'VARCHAR(120) NULL AFTER category_id','supplier_id'=>'BIGINT UNSIGNED NULL AFTER category_id','purchase_price'=>'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER supplier_id'],
] as $table=>$columns) foreach ($columns as $column=>$definition) {
    $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $check->execute([$table,$column]);
    if (!(int)$check->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
$barcodeIndex=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME='uq_products_barcode'")->fetchColumn();
if (!(int)$barcodeIndex) $pdo->exec('CREATE UNIQUE INDEX uq_products_barcode ON products(barcode)');
$supplierFk=$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='products' AND CONSTRAINT_NAME='fk_products_supplier'")->fetchColumn();
if (!(int)$supplierFk) $pdo->exec('ALTER TABLE products ADD CONSTRAINT fk_products_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL');
foreach (['receipts'=>'purchase_order_id','stock_lots'=>'purchase_order_id'] as $table=>$column) {
    $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $check->execute([$table,$column]);
    if (!(int)$check->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` BIGINT UNSIGNED NULL");
}
$receiptItemsBackfill=$pdo->prepare("INSERT INTO receipt_items(receipt_id,purchase_order_item_id,product_id,lot_id,qty,unit_cost) SELECT r.id,i.id,m.product_id,m.lot_id,m.qty,m.unit_cost FROM receipts r JOIN movements m ON m.reference=r.receipt_no AND m.type='receipt' LEFT JOIN purchase_order_items i ON i.purchase_order_id=r.purchase_order_id AND i.product_id=m.product_id WHERE NOT EXISTS (SELECT 1 FROM receipt_items ri WHERE ri.receipt_id=r.id AND ri.lot_id=m.lot_id)");$receiptItemsBackfill->execute();
$receivedCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'purchase_order_items\' AND COLUMN_NAME=\'received_qty\'');
$receivedCheck->execute();
if (!(int)$receivedCheck->fetchColumn()) {
 $pdo->exec('ALTER TABLE purchase_order_items ADD COLUMN received_qty DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER ordered_qty');
 $pdo->exec('UPDATE purchase_order_items i SET received_qty=(SELECT COALESCE(SUM(s.qty),0) FROM stock_lots s WHERE s.purchase_order_id=i.purchase_order_id AND s.product_id=i.product_id)');
}
$transferBackfill=$pdo->prepare('INSERT INTO transfer_items(transfer_id,product_id,qty) SELECT t.id,t.product_id,t.qty FROM transfers t WHERE NOT EXISTS (SELECT 1 FROM transfer_items ti WHERE ti.transfer_id=t.id)');$transferBackfill->execute();
$returnIndex=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'returns\' AND INDEX_NAME=\'return_no\' AND NON_UNIQUE=0');
$returnIndex->execute();
if ((int)$returnIndex->fetchColumn()) $pdo->exec('ALTER TABLE returns DROP INDEX return_no, ADD INDEX return_no (return_no)');

$modules=[
 'dashboard'=>'Գլխավոր վահանակ', 'suppliers'=>'Մատակարարներ', 'products'=>'Ապրանքներ', 'branches'=>'Մասնաճյուղեր',
 'purchases'=>'Գնումների պատվերներ', 'receipts'=>'Մուտքեր և գնումներ', 'stock'=>'Մնացորդներ', 'requests'=>'Պահանջագրեր', 'movements'=>'Պահեստի շարժ',
 'inventory'=>'Գույքագրում', 'expiry'=>'Ժամկետների վերահսկում', 'returns'=>'Վերադարձներ', 'transfers'=>'Տեղափոխումներ',
 'notifications'=>'Ծանուցումներ', 'reports'=>'Հաշվետվություններ', 'users'=>'Օգտատերեր', 'roles'=>'Դերեր և իրավունքներ', 'audit'=>'Գործողությունների պատմություն'
];
$actions=['view'=>'Դիտել','create'=>'Ստեղծել','edit'=>'Փոփոխել','delete'=>'Ջնջել','approve'=>'Հաստատել'];
foreach($modules as $module=>$title) foreach($actions as $action=>$actionTitle) {
 $code="$module.$action"; $q=$pdo->prepare('INSERT IGNORE INTO permissions(code,title,module) VALUES(?,?,?)'); $q->execute([$code,"$title — $actionTitle",$module]);
}
$roles=[
 ['admin','Համակարգի ադմինիստրատոր'],['manager','Տնտեսական բաժնի պատասխանատու'],['storekeeper','Պահեստապետ'],['branch','Մասնաճյուղի պատասխանատու'],['finance','Ֆինանսական բաժին'],['viewer','Դիտորդ']
];
foreach($roles as [$name,$title]) { $q=$pdo->prepare('INSERT IGNORE INTO roles(name,title) VALUES(?,?)');$q->execute([$name,$title]); }
$roleIds=array_flip($pdo->query('SELECT id,name FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR));
$allPerms=array_flip($pdo->query('SELECT id,code FROM permissions')->fetchAll(PDO::FETCH_KEY_PAIR));
$sets=[
 'admin'=>array_keys($allPerms),
 'manager'=>array_values(array_filter(array_keys($allPerms),fn($p)=>!str_ends_with($p,'.delete') && !in_array($p,['users.create','users.edit','roles.edit'],true))),
 'storekeeper'=>array_values(array_filter(array_keys($allPerms),fn($p)=>in_array(explode('.',$p)[0],['dashboard','stock','receipts','purchases','requests','movements','inventory','expiry','returns','transfers','notifications','reports'],true) && !str_ends_with($p,'.delete') && !str_ends_with($p,'.approve'))),
 'branch'=>array_values(array_filter(array_keys($allPerms),fn($p)=>in_array(explode('.',$p)[0],['dashboard','stock','requests','movements','inventory','expiry','returns','transfers','notifications','reports'],true) && !str_ends_with($p,'.delete') && !in_array($p,['requests.approve','transfers.approve'],true))),
 'finance'=>array_values(array_filter(array_keys($allPerms),fn($p)=>in_array(explode('.',$p)[0],['dashboard','suppliers','products','purchases','receipts','stock','movements','expiry','returns','reports'],true) && (str_ends_with($p,'.view') || in_array($p,['purchases.approve','receipts.create'],true)))),
 'viewer'=>array_values(array_filter(array_keys($allPerms),fn($p)=>str_ends_with($p,'.view') || $p==='dashboard.view')),
];
foreach($sets as $role=>$codes) foreach($codes as $code) if(isset($allPerms[$code])) { $q=$pdo->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)');$q->execute([$roleIds[$role],$allPerms[$code]]); }
foreach(['manager'=>['users.create'],'branch'=>['requests.approve','transfers.approve','inventory.approve']] as $role=>$revoked){$remove=$pdo->prepare('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.code=?');foreach($revoked as $code)$remove->execute([$roleIds[$role],$code]);}

$hasAdmin=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0;
if($hasAdmin) {
 $pdo->beginTransaction();
 $branchData=[['Կենտրոնական պահեստ','CENTRAL','ք. Երևան, Արշակունյաց պող.','Արման Մկրտչյան','+374 10 000 100'],['Դիագեն Պլյուս — Էրեբունի','EREB','ք. Երևան, Էրեբունի','Անի Սարգսյան','+374 10 000 101'],['Դիագեն Պլյուս — Շենգավիթ','SHENG','ք. Երևան, Շենգավիթ','Կարեն Հովհաննիսյան','+374 10 000 102'],['Դիագեն Պլյուս — Գյումրի','GYUM','ք. Գյումրի','Մարիամ Պետրոսյան','+374 10 000 103']];
 $q=$pdo->prepare('INSERT IGNORE INTO branches(name,code,address,manager,phone) VALUES(?,?,?,?,?)');foreach($branchData as $row)$q->execute($row);
 $supplierData=[['ՄեդՏեխ ՍՊԸ','01234567','Երևան, Կոմիտաս 12','Արմենուհի Ավետիսյան','+374 10 123 456','sales@medtech.am','Պ-2026/014','Բանկային փոխանցում, 30 օր',5],['ԼաբՍափլայ ՍՊԸ','07654321','Երևան, Մաշտոցի 45','Գոռ Մանուկյան','+374 10 456 789','info@labsupply.am','Պ-2026/027','Բանկային փոխանցում',3],['ԱրմՖարմ ՍՊԸ','09876543','Երևան, Նալբանդյան 8','Նարեկ Գրիգորյան','+374 10 987 654','orders@armfarm.am','Պ-2026/033','Կանխավճար',2]];
 $q=$pdo->prepare('INSERT IGNORE INTO suppliers(name,tax_id,address,contact_name,phone,email,contract_no,payment_terms,delivery_days) VALUES(?,?,?,?,?,?,?,?,?)');foreach($supplierData as $row)$q->execute($row);
 foreach(['Վակուտեյներներ','Ասեղներ և ներարկիչներ','Լաբորատոր ռեագենտներ','Ախտահանիչ նյութեր','Բժշկական պարագաներ','Գրենական պիտույքներ'] as $cat){$q=$pdo->prepare('INSERT IGNORE INTO categories(name) VALUES(?)');$q->execute([$cat]);}
 $q=$pdo->prepare('INSERT INTO users(name,email,password,role_id,branch_id) VALUES(?,?,?,?,NULL)');$q->execute(['Համակարգի ադմինիստրատոր','admin@diagen.am',password_hash('Diagen2026!',PASSWORD_DEFAULT),$roleIds['admin']]);
 $q=$pdo->prepare('INSERT INTO users(name,email,password,role_id,branch_id) VALUES(?,?,?,?,?)');$q->execute(['Տնտեսական բաժին','manager@diagen.am',password_hash('Diagen2026!',PASSWORD_DEFAULT),$roleIds['manager'],null]);
 $q->execute(['Կենտրոնական պահեստապետ','storekeeper@diagen.am',password_hash('Diagen2026!',PASSWORD_DEFAULT),$roleIds['storekeeper'],null]);
 $branchIds=array_flip($pdo->query('SELECT id,code FROM branches')->fetchAll(PDO::FETCH_KEY_PAIR));
 $q->execute(['Էրեբունու պատասխանատու','erebuni@diagen.am',password_hash('Diagen2026!',PASSWORD_DEFAULT),$roleIds['branch'],$branchIds['EREB']]);
 $cats=array_flip($pdo->query('SELECT id,name FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR));
 $products=[
 ['VAC-001','Վակուտեյներ EDTA 4 մլ',$cats['Վակուտեյներներ'],'Greiner','հատ','100 հատ/տուփ',20,60,100,'Սենյակային ջերմաստիճան',0,1,1],
 ['NEE-021','Ասեղ 21G x 1½',$cats['Ասեղներ և ներարկիչներ'],'BD','հատ','100 հատ/տուփ',15,45,80,'Չոր և մաքուր վայրում',0,1,1],
 ['REA-310','Գլյուկոզայի ռեագենտ',$cats['Լաբորատոր ռեագենտներ'],'Human Diagnostics','լիտր','4 x 250 մլ',8,24,36,'+2…+8°C',1,1,1],
 ['DIS-055','Ախտահանիչ լուծույթ 1 լ',$cats['Ախտահանիչ նյութեր'],'Septodont','լիտր','1 լ շիշ',10,30,50,'Մինչև +25°C',0,1,1],
 ['MED-104','Միանգամյա ձեռնոցներ M',$cats['Բժշկական պարագաներ'],'Mercator','տուփ','100 զույգ',25,75,120,'Չոր վայրում',0,1,0],
 ['VAC-009','Վակուտեյներ հեպարինով',$cats['Վակուտեյներներ'],'Vacutest','հատ','100 հատ/տուփ',8,30,60,'Սենյակային ջերմաստիճան',0,1,1],
 ['OFF-015','Լաբորատոր պիտակների գլանափաթեթ',$cats['Գրենական պիտույքներ'],'Diagen','գլան','500 պիտակ',10,30,50,'Չոր տեղում',0,1,0],
 ];
 $q=$pdo->prepare('INSERT INTO products(code,name,category_id,manufacturer,unit,package,min_qty,optimal_qty,max_qty,storage_conditions,refrigerated,lot_control,expiry_control) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');foreach($products as $row)$q->execute($row);
 $productIds=array_flip($pdo->query('SELECT id,code FROM products')->fetchAll(PDO::FETCH_KEY_PAIR));$supplierIds=array_flip($pdo->query('SELECT id,name FROM suppliers')->fetchAll(PDO::FETCH_KEY_PAIR));
 $lots=[['VAC-001','EDTA-2605',120,980,'2027-05-30',1,1,'A-01-01'],['NEE-021','BD-26-118',84,1450,'2028-11-15',1,1,'A-02-03'],['REA-310','GLU-2604-A',18,24700,'2026-12-20',2,1,'Սառնարան 1'],['REA-310','GLU-2605-B',24,24700,'2027-02-28',2,1,'Սառնարան 1'],['DIS-055','SEPT-2603',42,1850,'2027-08-31',3,1,'B-01-02'],['MED-104','GLV-2601',96,3200,null,1,1,'B-03-01'],['VAC-009','HEP-2604',35,1250,'2027-04-15',1,2,'A-01-02'],['OFF-015','LBL-2609',6,750,null,1,1,'Գ-01-01']];
 $q=$pdo->prepare('INSERT INTO stock_lots(product_id,location_id,lot_no,expires_on,received_on,supplier_id,unit_cost,bin_location,qty) VALUES(?,?,?,?,?,?,?,?,?)');
 $supplierOrder=$pdo->query('SELECT id FROM suppliers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
 foreach($lots as [$code,$lot,$qty,$cost,$exp,$sid,$loc,$bin]){$q->execute([$productIds[$code],0,$lot,$exp,'2026-09-01',$supplierOrder[$sid-1],$cost,$bin,$qty]);}
 $pdo->commit();
}
if (PHP_SAPI === 'cli') echo "Database initialized.\n";
