<?php
// ============================================================================
//  kyc_ui_structure.php — Step 5 KYC-UI wiring proof (structural, no DB)
// ----------------------------------------------------------------------------
//  The KYC screens are static HTML/JS that drive the existing S18 KYC APIs.
//  The backend lifecycle (submit→review→approve) is proven by
//  s18_kyc_vectors.php against the live DB. This harness proves the UI is
//  actually WIRED to those endpoints: the media upload endpoint exists and is
//  dispatched, the seller PWA has a KYC form that posts to /kyc + /api/media,
//  and the standalone rider onboarding page registers as a rider and posts to
//  the rider KYC + media endpoints. Deterministic string/structure asserts.
// ============================================================================

$ROOT = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function has(string $hay, string $needle): bool { return strpos($hay, $needle) !== false; }
function get(string $p): string { return is_file($p) ? (string)file_get_contents($p) : ''; }
function check(string $label, bool $cond, string $got = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS  %-52s %s\n", $label, $got); }
    else       { $fail++; printf("  FAIL  %-52s %s\n", $label, $got); }
}

$media   = get("$ROOT/heart/api/media/index.php");
$heart   = get("$ROOT/heart/index.php");
$rider   = get("$ROOT/heart/api/commerce-rider/index.php");
$seller  = get("$ROOT/heart/api/commerce-seller/index.php");
$register= get("$ROOT/heart/api/auth/register.php");
$sjs     = get("$ROOT/seller/seller.js");
$shtml   = get("$ROOT/seller/index.html");
$ronb    = get("$ROOT/rider-onboard/index.html");

echo "\n== Media upload endpoint ==\n";
check("media endpoint file exists",            $media !== '');
check("handles POST /api/media/upload",        has($media, "/api/media/upload") && has($media, "'POST'"));
check("requires auth",                         has($media, "AuthMiddleware::require"));
check("validates file size (<=5MB)",           has($media, "5 * 1024 * 1024"));
check("whitelists image/pdf mimes",            has($media, "image/jpeg") && has($media, "application/pdf"));
check("inserts media row",                     has($media, "INSERT INTO media"));
check("returns media_id",                      has($media, "'media_id'"));

echo "\n== Dispatch wiring (heart/index.php) ==\n";
check("dispatches /api/media",                 has($heart, "/api/media"));
check("dispatches /api/commerce-rider",        has($heart, "/api/commerce-rider"));
check("dispatches /api/commerce-seller",       has($heart, "/api/commerce-seller"));

echo "\n== Rider KYC API routes ==\n";
check("rider requireRole delivery",            has($rider, "requireRole(ROLE_DELIVERY"));
check("GET  /api/commerce-rider/kyc",          has($rider, "'/api/commerce-rider/kyc'") && has($rider, "'GET'"));
check("POST /api/commerce-rider/kyc",          has($rider, "'/api/commerce-rider/kyc'") && has($rider, "'POST'"));
check("POST /api/commerce-rider/kyc/submit",   has($rider, "/api/commerce-rider/kyc/submit"));

echo "\n== Seller KYC API routes ==\n";
check("seller KYC route present",              has($seller, "/kyc"));
check("seller KYC submit route present",       has($seller, "/kyc/submit"));

echo "\n== register allows rider self-register ==\n";
check("register validation allows 'delivery'", has($register, "delivery"));

echo "\n== Seller PWA KYC screen ==\n";
check("index.html has KYC tab button",         has($shtml, "tab-kyc") && has($shtml, "Seller.go('kyc')"));
check("seller.js has kyc branch",              has($sjs, 'tab === "kyc"') && has($sjs, "renderKyc"));
check("seller.js uploadDoc→/api/media/upload", has($sjs, "uploadDoc") && has($sjs, "/api/media/upload"));
check("seller.js saveKyc posts /kyc",          has($sjs, "saveKyc") && has($sjs, '"/kyc"'));
check("seller.js submitKyc posts /kyc/submit", has($sjs, "submitKyc") && has($sjs, "/kyc/submit"));
check("seller.js exposes KYC handlers",        has($sjs, "uploadDoc:") && has($sjs, "saveKyc:") && has($sjs, "submitKyc:"));

echo "\n== Standalone rider onboarding page ==\n";
check("rider-onboard page exists",             $ronb !== '');
check("registers with role=delivery",          has($ronb, "/api/auth/register") && has($ronb, 'role:"delivery"'));
check("phone OTP send + verify",               has($ronb, "/api/auth/send-otp") && has($ronb, "/api/auth/verify-otp"));
check("KYC upsert → rider kyc endpoint",       has($ronb, "/api/commerce-rider/kyc"));
check("KYC submit → rider submit endpoint",    has($ronb, "/api/commerce-rider/kyc/submit"));
check("uploads docs → /api/media/upload",      has($ronb, "/api/media/upload"));
check("collects payout (bank/upi)",            has($ronb, "bank_account_number") && has($ronb, "upi_id"));
check("collects licence + RC",                 has($ronb, "licence_number") && has($ronb, "rc_number"));

echo "\n────────────────────────────────────\n";
printf("KYC-UI STRUCTURE: %d PASS, %d FAIL\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
