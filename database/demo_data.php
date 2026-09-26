<?php
declare(strict_types=1);
require __DIR__.'/init.php';
if((int)$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn()>0){echo "Demo records already exist.\n";exit;}
$admin=(int)$pdo->query("SELECT id FROM users WHERE email='admin@diagen.am'")->fetchColumn();
$suppliers=$pdo->query('SELECT id FROM suppliers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$lots=$pdo->query('SELECT s.*,p.code FROM stock_lots s JOIN products p ON p.id=s.product_id WHERE s.location_id=0 ORDER BY s.id')->fetchAll();
$pdo->beginTransaction();
foreach($lots as $i=>$lot){
 $no='ՄՈՒՏ-'.date('ymd',strtotime($lot['received_on'])).'-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT);
 $supplier=(int)($lot['supplier_id']?:$suppliers[$i%count($suppliers)]);
 $q=$pdo->prepare('INSERT INTO receipts(receipt_no,supplier_id,invoice_no,contract_no,received_by,received_on,note) VALUES(?,?,?,?,?,?,?)');$q->execute([$no,$supplier,'ՀԱՇԻՎ-'.str_pad((string)($i+301),4,'0',STR_PAD_LEFT),'Պ-2026/'.str_pad((string)($i+14),3,'0',STR_PAD_LEFT),$admin,$lot['received_on'],'Փորձնական մուտք՝ սկզբնական մնացորդի ձևավորման համար']);
 $q=$pdo->prepare("INSERT INTO movements(movement_no,type,product_id,lot_id,from_location,to_location,qty,unit_cost,reference,reason,actor_id,happened_at) VALUES(?,?,?,?,NULL,0,?,?,?,?,?,?)");$q->execute(['ՇԱՐԺ-'.date('ymd',strtotime($lot['received_on'])).'-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'receipt',$lot['product_id'],$lot['id'],$lot['qty'],$lot['unit_cost'],$no,'Մատակարարից ընդունում',$admin,$lot['received_on'].' 09:15:00']);
}
$branchCodes=$pdo->query('SELECT code,id FROM branches')->fetchAll(PDO::FETCH_KEY_PAIR);
$allocations=[['VAC-001','EREB',14],['NEE-021','EREB',10],['DIS-055','EREB',8],['REA-310','SHENG',3],['VAC-009','SHENG',7],['MED-104','GYUM',18],['NEE-021','GYUM',8]];
foreach($allocations as $i=>[$code,$branchCode,$qty]){
 $q=$pdo->prepare('SELECT s.* FROM stock_lots s JOIN products p ON p.id=s.product_id WHERE p.code=? AND s.location_id=0 AND s.qty>=? ORDER BY (s.expires_on IS NULL),s.expires_on,s.received_on LIMIT 1 FOR UPDATE');$q->execute([$code,$qty]);$source=$q->fetch();if(!$source)continue;
 $pdo->prepare('UPDATE stock_lots SET qty=qty-? WHERE id=?')->execute([$qty,$source['id']]);
 $q=$pdo->prepare('INSERT INTO stock_lots(product_id,location_id,lot_no,expires_on,received_on,supplier_id,unit_cost,bin_location,qty) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$source['product_id'],$branchCodes[$branchCode],$source['lot_no'],$source['expires_on'],'2026-09-10',$source['supplier_id'],$source['unit_cost'],'Մասնաճյուղի պահարան',$qty]);$dest=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO movements(movement_no,type,product_id,lot_id,from_location,to_location,qty,unit_cost,reference,reason,actor_id,happened_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");$q->execute(['ՇԱՐԺ-260910-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'branch_transfer',$source['product_id'],$dest,0,$branchCodes[$branchCode],$qty,$source['unit_cost'],'ՍԿԶ-ՏՂ-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'Փորձնական պաշարի բաշխում մասնաճյուղին',$admin,'2026-09-10 14:'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT).':00']);
}
$branchUser=(int)$pdo->query("SELECT id FROM users WHERE email='erebuni@diagen.am'")->fetchColumn();
$branchId=(int)$branchCodes['EREB'];
$q=$pdo->prepare("INSERT INTO stock_requests(request_no,branch_id,requested_by,status,urgency,reason,created_at) VALUES('ՊՀ-2609-0001',?,?,'sent','high','Ռեագենտների շաբաթական պաշարի համալրում',NOW())");$q->execute([$branchId,$branchUser]);$requestId=(int)$pdo->lastInsertId();
$productId=(int)$pdo->query("SELECT id FROM products WHERE code='REA-310'")->fetchColumn();
$pdo->prepare('INSERT INTO request_items(request_id,product_id,requested_qty,approved_qty,note) VALUES(?,?,4,0,?)')->execute([$requestId,$productId,'Մնացորդն ու սպառումը հաշվի առնել հաստատելիս']);
$pdo->commit();echo "Demo movement, branch stock and an open request have been added.\n";
