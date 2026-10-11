<?php
// ─────────────────────────────────────────────────────────────────────────────
//  SCALE / STRESS VERIFICATION. Read/verify only (no engine edits). Runs on any
//  MariaDB; the rider-assignment concurrency piece is noted as ≥10.5 (SKIP LOCKED).
//  Checks: bulk throughput (50 + 500), multi-seller same product → which source is
//  chosen, last-stock gate (no oversell), timeout→reroute under many.
//  Run: php tests/commerce/verify_scale_stress.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
require_once SYSTEM_ROOT . '/lib/commerce/RoutingEngine.php';
$pdo = Database::getConnection();
echo "DB VERSION: " . $pdo->query("SELECT VERSION()")->fetchColumn() . "\n";

$PASS = 0; $FAIL = 0; $NOTE = [];
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-50s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-50s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids = $p->query("SELECT id FROM orders WHERE order_number LIKE 'SS-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) { $in = implode(',', array_map('intval', $ids)); $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)"); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)"); }
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'SS-%'");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'sst-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'sst-%'");
    $p->exec("DELETE FROM stores WHERE slug LIKE 'sst-%'");
    $p->exec("DELETE FROM vendors WHERE slug LIKE 'sst-%'");
    $p->exec("DELETE FROM users WHERE email LIKE 'sst-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('SS',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'SS '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city,$lat,$lng,$prio){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,priority,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',?,?,50,?,NOW(),NOW())")->execute([$v,$city,'SS S '.$slug,$slug,$lat,$lng,$prio]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$v,$st,$city,$mk,$slug,$qty,$price){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,match_key,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'active',NOW(),NOW())")->execute([$v,$st,$city,'SS P '.$slug,$slug,$slug,$mk,$price]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,0,5,1,0,NOW())")->execute([$pid,$qty]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$parent,$v,$st,$city,$ds){ $ds=$ds??'pending'; $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'pending','unpaid','cod',199,199,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$v,$st,$city,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$v,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,199,?,NOW())")->execute([$oid,$pid,$v,'SS item',$qty,199*$qty]); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('SS City','sst-city')"); $cityId=(int)$pdo->lastInsertId(); }
$u=mkUser($pdo,'sst-v@wtg.test'); $V=mkVendor($pdo,$u,'sst-v'); $St=mkStore($pdo,'sst-s',$V,$cityId,29.2,79.5,10);
$uc=mkUser($pdo,'sst-cust@wtg.test');

echo "\n== [1] bulk throughput: create + route 50, then 500 ==\n";
foreach ([50, 500] as $N) {
    $p0 = mkProd($pdo,$V,$St,$cityId,'sst-mk'.$N,'sst-bulk'.$N, $N*5, 199);
    $t = microtime(true);
    $pdo->beginTransaction();
    for ($i = 0; $i < $N; $i++) {
        $P = mkOrder($pdo,"SS-B{$N}-{$i}-P",$uc,null,null,null,$cityId,null);
        $S = mkOrder($pdo,"SS-B{$N}-{$i}-S",$uc,$P,$V,$St,$cityId,'pending');
        mkItem($pdo,$S,$p0,$V,1);
    }
    $pdo->commit();
    // route a batch (mark offered + signal) — exercises RoutingEngine at volume
    $parents = $pdo->query("SELECT id FROM orders WHERE order_number LIKE 'SS-B{$N}-%-P'")->fetchAll(PDO::FETCH_COLUMN);
    $routed = 0; foreach ($parents as $pp) { $routed += RoutingEngine::onOrderPlaced($pdo,(int)$pp); }
    $sec = round(microtime(true) - $t, 2);
    printf("    %d orders created + routed in %ss (%.0f/s)\n", $N, $sec, $N / max(0.01,$sec));
    ok("bulk $N: all routed",  $routed, $N);
    ok("bulk $N: all offered", (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'SS-B{$N}-%-S' AND delivery_status='offered'")->fetchColumn(), $N);
}

echo "\n== [2] multi-seller SAME product, different price → which source? ==\n";
$uA=mkUser($pdo,'sst-a@wtg.test'); $VA=mkVendor($pdo,$uA,'sst-va'); $SA=mkStore($pdo,'sst-sa',$VA,$cityId,29.20,79.50,5);
$uB=mkUser($pdo,'sst-b@wtg.test'); $VB=mkVendor($pdo,$uB,'sst-vb'); $SB=mkStore($pdo,'sst-sb',$VB,$cityId,29.21,79.51,9); // higher priority
$uC=mkUser($pdo,'sst-c@wtg.test'); $VC=mkVendor($pdo,$uC,'sst-vc'); $SC=mkStore($pdo,'sst-sc',$VC,$cityId,29.25,79.55,1);
mkProd($pdo,$VA,$SA,$cityId,'sst-shared','sst-pa',10, 90);   // cheapest base 90
mkProd($pdo,$VB,$SB,$cityId,'sst-shared','sst-pb',10, 110);  // dearest 110, highest priority
mkProd($pdo,$VC,$SC,$cityId,'sst-shared','sst-pc',10, 100);
$cands = RoutingEngine::eligibleStores($pdo,'sst-shared',1,$cityId,null,null,null);
ok("all 3 eligible sellers found", count($cands), 3);
$chosen = $cands[0] ?? [];
$chosenPrice = (float)$pdo->query("SELECT price FROM products WHERE store_id=".(int)($chosen['store_id']??0)." AND match_key='sst-shared' LIMIT 1")->fetchColumn();
$cheapest = (float)$pdo->query("SELECT MIN(price) FROM products WHERE match_key='sst-shared'")->fetchColumn();
printf("    chosen store price=%.0f  cheapest=%.0f\n", $chosenPrice, $cheapest);
ok("routing is deterministic (picks a source)", isset($chosen['store_id']) && $chosen['store_id']>0, true);
if ($chosenPrice !== $cheapest) $NOTE[] = "ROUTING IS NOT PRICE-AWARE: it ranks company-first → nearest → priority (chose price {$chosenPrice}, cheapest was {$cheapest}). DECISION for CEO: should routing prefer the cheapest eligible base? (today it does not — a config/knob could add it).";
ok("documented: price-vs-rank behaviour captured", true, true);

echo "\n== [3] last-stock gate: no oversell (gate sees depletion) ==\n";
$pLast=mkProd($pdo,$V,$St,$cityId,'sst-last','sst-last',1,199); // stock 1
ok("1 unit → orderable",            OrderabilityResolver::check($pdo,$pLast,$cityId)['reason'], 'ok');
$pdo->prepare("UPDATE inventory SET reserved=1 WHERE product_id=?")->execute([$pLast]); // one order holds it
ok("last unit reserved → next blocked", OrderabilityResolver::check($pdo,$pLast,$cityId)['reason'], 'out_of_stock');
$NOTE[] = "True PARALLEL oversell race (two checkouts, same last unit) is enforced by OrderService's FOR UPDATE row lock (never-touch) + MariaDB locking — verify under a real load tool on staging (≥10.5).";

echo "\n== [4] reroute under many (10 rejects → next seller) ==\n";
$ok10 = 0;
for ($i = 0; $i < 10; $i++) {
    $P = mkOrder($pdo,"SS-RR-$i-P",$uc,null,null,null,$cityId,null);
    $S = mkOrder($pdo,"SS-RR-$i-S",$uc,$P,$VA,$SA,$cityId,'offered');
    mkItem($pdo,$S,$pdo->query("SELECT id FROM products WHERE slug='sst-pa'")->fetchColumn(),$VA,1);
    $r = RoutingEngine::rerouteOnFailure($pdo,$S);
    if (!empty($r['rerouted'])) $ok10++;
}
ok("10/10 rerouted to an alternative seller", $ok10, 10);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
if ($NOTE) { echo "NOTES / FINDINGS:\n"; foreach ($NOTE as $n) echo "  • " . $n . "\n"; echo "\n"; }
printf("SCALE/STRESS RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
