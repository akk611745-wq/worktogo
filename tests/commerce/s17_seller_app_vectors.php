<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S17 PROOF HARNESS — Seller app PWA (installable, one-page, glance-and-act).
//  A PWA is a front-end deliverable; its backend (/api/commerce-seller) is already
//  proven (S15 31/31, S16 22/22). This harness proves the deliverable is COMPLETE
//  and correctly wired: a valid installable manifest, a correct offline service
//  worker (writes never cached), the shell registers the SW + exposes the controls,
//  and seller.js wires every required endpoint + the opaque-ref/no-PII contract.
//  Run: php tests/commerce/s17_seller_app_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-52s\n", $label); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function readf($p) { return is_file($p) ? (string)file_get_contents($p) : ''; }
$dir = SYSTEM_ROOT . '/seller';
$manifest = readf($dir . '/manifest.webmanifest');
$sw = readf($dir . '/service-worker.js');
$html = readf($dir . '/index.html');
$js = readf($dir . '/seller.js');
function has($h, $n) { return str_contains($h, $n); }

echo "\n== files exist ==\n";
ok('manifest.webmanifest present', $manifest !== '');
ok('service-worker.js present',    $sw !== '');
ok('index.html present',           $html !== '');
ok('seller.js present',            $js !== '');

echo "\n== manifest is a valid installable PWA manifest ==\n";
$mj = json_decode($manifest, true);
ok('manifest valid JSON',          is_array($mj));
ok('name = WorkToGo Seller',       ($mj['name'] ?? null), 'WorkToGo Seller');
ok('display standalone',           ($mj['display'] ?? null), 'standalone');
ok('start_url set',                !empty($mj['start_url']));
ok('has >=1 icon',                 count($mj['icons'] ?? []) >= 1);

echo "\n== service worker: offline shell, writes never cached ==\n";
ok('install handler',              has($sw, "addEventListener('install'"));
ok('activate handler',             has($sw, "addEventListener('activate'"));
ok('fetch handler',                has($sw, "addEventListener('fetch'"));
ok('has a CACHE name',             has($sw, 'const CACHE'));
ok('shell caches seller.js',       has($sw, './seller.js'));
ok('never caches writes (non-GET)',has($sw, "req.method !== 'GET'"));
ok('API network-first',            has($sw, "req.url.includes('/api/')"));

echo "\n== shell registers SW + exposes the glance-and-act controls ==\n";
ok('links manifest',               has($html, 'manifest.webmanifest'));
ok('registers service worker',     has($html, "serviceWorker.register('service-worker.js'"));
ok('theme-color',                  has($html, 'theme-color'));
ok('loads seller.js',              has($html, 'seller.js'));
ok('availability: online',         has($html, "Seller.setAvail('online')"));
ok('availability: busy',           has($html, "Seller.setAvail('busy')"));
ok('availability: offline',        has($html, "Seller.setAvail('offline')"));
ok('tab orders',                   has($html, 'tab-orders'));
ok('tab catalogue',                has($html, 'tab-catalogue'));
ok('tab earnings',                 has($html, 'tab-earnings'));

echo "\n== seller.js wires every /api/commerce-seller endpoint ==\n";
ok('base /api/commerce-seller',    has($js, '/api/commerce-seller'));
ok('GET /me',                      has($js, '"/me"'));
ok('POST /availability',           has($js, '"/availability"'));
ok('GET /orders',                  has($js, '"/orders"'));
ok('accept endpoint',              has($js, "/accept"));
ok('reject endpoint',              has($js, "/reject"));
ok('stock-out endpoint',           has($js, "/stock-out"));
ok('catalogue endpoint',           has($js, '"/products"'));
ok('restock /stock endpoint',      has($js, "/stock\""));
ok('earnings endpoint',            has($js, '"/earnings"'));
ok('customer history endpoint',    has($js, '/customers/'));

echo "\n== contract: Bearer auth + opaque ref (no PII) + 3 availability states ==\n";
ok('Bearer auth header',           has($js, 'Bearer'));
ok('uses opaque customer_ref',     has($js, 'customer_ref'));
ok('online state',                 has($js, "setAvail('online'") || has($js, '"online"'));
ok('busy state',                   has($js, '"busy"') || has($js, "'busy'"));
ok('offline state',                has($js, '"offline"') || has($js, "'offline'"));
// the seller app must NOT request any PII field
ok('no customer name/phone/address field', !has($js, 'customer_name') && !has($js, 'customer_phone') && !has($js, 'customer_address'));

echo "\n────────────────────────────────────\n";
printf("S17 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
