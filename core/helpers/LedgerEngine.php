<?php

namespace Core\Helpers;

use Core\Helpers\Database;
use Core\Helpers\Logger;
use Exception;
use PDO;

class LedgerEngine {

    /**
     * Ensure wallet exists for a vendor
     */
    private static function ensureVendorWallet(PDO $pdo, $vendor_id) {
        $stmt = $pdo->prepare("SELECT id FROM vendor_wallets WHERE vendor_id = ?");
        $stmt->execute([$vendor_id]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO vendor_wallets (vendor_id) VALUES (?)")->execute([$vendor_id]);
        }
    }

    /**
     * Ensure wallet exists for a driver
     */
    private static function ensureDriverWallet(PDO $pdo, $driver_id) {
        $stmt = $pdo->prepare("SELECT id FROM driver_wallets WHERE driver_id = ?");
        $stmt->execute([$driver_id]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO driver_wallets (driver_id) VALUES (?)")->execute([$driver_id]);
        }
    }

    // ── H3 money-safety: reference_type/reference_id disambiguation ───────────
    // wallet_transactions.order_id historically held BOTH service booking_ids and
    // shopping order_ids in one id-space, so booking #N and order #N could collide
    // once both engines are live. These helpers tag every row with reference_type
    // + reference_id (mirroring transactions.reference_*), so lookups can never
    // cross engines. They stay safe if the reference columns don't exist yet
    // (migration not applied): writes keep populating order_id, reads fall back to
    // the legacy order_id match.

    /** @var bool|null cached: do the reference columns exist yet */
    private static $walletRefCols = null;

    private static function walletHasRefCols(PDO $pdo): bool {
        if (self::$walletRefCols !== null) return self::$walletRefCols;
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_transactions'
                    AND COLUMN_NAME = 'reference_type'"
            );
            $stmt->execute();
            return self::$walletRefCols = ((int)$stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$walletRefCols = false;
        }
    }

    // ── Expert-visit / inspection fee detection (schema-defensive) ────────────
    // The Expert Visit / inspection fee is the COMPANY's diagnostic charge, not
    // job revenue — the vendor gets 0% of it (founder rule). A booking carries
    // that fee when booking_mode='inspection' (legacy inspection + paid expert
    // visit, which sets mode='inspection'), or visit_type='expert_visit', or
    // expert_visit_fee>0. These V2 columns are optional-checked so completion
    // still works on a partially-migrated DB (missing columns => normal split).

    /** @var array<string,bool> cached: does a given bookings column exist */
    private static $bookingCols = [];

    private static function bookingsHasColumn(PDO $pdo, string $col): bool {
        if (array_key_exists($col, self::$bookingCols)) return self::$bookingCols[$col];
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = ?"
            );
            $stmt->execute([$col]);
            return self::$bookingCols[$col] = ((int)$stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$bookingCols[$col] = false;
        }
    }

    /**
     * Is the money on this completed booking an expert-visit / inspection fee
     * (100% company) rather than normal splittable job revenue? Reads whichever
     * of booking_mode / visit_type / expert_visit_fee the schema actually has.
     */
    private static function isExpertVisitFee(array $booking): bool {
        return (($booking['booking_mode'] ?? null) === 'inspection')
            || (($booking['visit_type']   ?? null) === 'expert_visit')
            || ((float)($booking['expert_visit_fee'] ?? 0) > 0);
    }

    /**
     * Did the COMPANY fulfil this job with its own worker (no registered
     * vendor), so the whole amount is company margin and there is NO vendor
     * commission to split? True when fulfilment_type='company' (the admin
     * assigned the company's own team), or — defensively — when a completing
     * non-visit job carries no vendor_id at all (there is simply no vendor to
     * pay). Checked AFTER isExpertVisitFee so visit fees still route to their
     * own 100%-company row first. Schema-defensive: fulfilment_type is optional.
     */
    private static function isCompanyFulfilled(array $booking): bool {
        if (($booking['fulfilment_type'] ?? null) === 'company') return true;
        return empty($booking['vendor_id']);
    }

    /**
     * Insert one wallet_transactions row. Always populates order_id (backward
     * compat) and, when present, reference_type + reference_id (the canonical,
     * collision-proof source key). Amounts / entities are unchanged — this is
     * disambiguation only. $entityId is null for platform rows.
     */
    private static function insertWalletTx(PDO $pdo, string $entityType, ?int $entityId, string $refType, int $refId, string $type, float $amount, string $description, string $status): void {
        if (self::walletHasRefCols($pdo)) {
            $pdo->prepare(
                "INSERT INTO wallet_transactions
                    (entity_type, entity_id, order_id, reference_type, reference_id, type, amount, description, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$entityType, $entityId, $refId, $refType, $refId, $type, $amount, $description, $status]);
        } else {
            $pdo->prepare(
                "INSERT INTO wallet_transactions
                    (entity_type, entity_id, order_id, type, amount, description, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$entityType, $entityId, $refId, $type, $amount, $description, $status]);
        }
    }

    /**
     * Reference-scoping fragment for reads: prefers (reference_type, reference_id)
     * post-migration, falls back to the legacy order_id match pre-migration.
     * @return array{0:string,1:array} [whereSql, binds]
     */
    private static function walletRefMatch(PDO $pdo, string $refType, int $refId): array {
        if (self::walletHasRefCols($pdo)) {
            return ["reference_type = ? AND reference_id = ?", [$refType, $refId]];
        }
        return ["order_id = ?", [$refId]];
    }

    /**
     * TASK 3: Order Completion Logic
     * Triggers when an order is completed.
     * Calculates shares and updates wallets.
     */
    public static function processOrderCompletion($order_id) {
        $pdo = Database::getConnection();
        
        try {
            $pdo->beginTransaction();

            // 1. Read order total and details with FOR UPDATE to prevent race conditions
            $stmt = $pdo->prepare("
                SELECT o.total, o.subtotal, o.delivery_fee, o.vendor_id, o.delivery_id, o.payment_method, 
                       v.commission_rate, d.driver_id, o.status as order_status, o.ledger_status
                FROM orders o
                LEFT JOIN vendors v ON o.vendor_id = v.id
                LEFT JOIN deliveries d ON o.delivery_id = d.id
                WHERE o.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Order not found.");
            }

            if ($order['ledger_status'] === 'processed') {
                $pdo->rollBack();
                return true; // Already processed, fail-safe.
            }

            // Check if already processed (scoped to this ORDER's ledger rows)
            [$refWhere, $refBind] = self::walletRefMatch($pdo, 'order', (int)$order_id);
            $checkStmt = $pdo->prepare("SELECT id FROM wallet_transactions WHERE {$refWhere} AND status != 'refunded' FOR UPDATE");
            $checkStmt->execute($refBind);
            if ($checkStmt->fetch()) {
                // Should not happen with ledger_status check, but keeping for double safety
                $pdo->rollBack();
                return true; // Return true without error if already processed, to avoid retries causing errors
            }

            // 2. Calculate Shares
            $total = (float)$order['total'];
            $subtotal = (float)$order['subtotal']; // items cost
            $delivery_fee = (float)$order['delivery_fee'];
            
            // Platform takes commission on subtotal (or whatever business rule applies)
            $commission_rate = $order['commission_rate'] ? (float)$order['commission_rate'] : 10.0;
            $platform_commission = ($subtotal * $commission_rate) / 100;
            $vendor_share = $subtotal - $platform_commission;
            
            // Rider earnings (assuming delivery fee goes to rider entirely, adjust if needed)
            $rider_share = $delivery_fee; 
            
            // Platform net earnings
            $platform_earnings = $platform_commission;

            // 3. Update Wallets
            // Vendor Wallet
            if ($order['vendor_id']) {
                self::ensureVendorWallet($pdo, $order['vendor_id']);
                
                // Pending until settled
                $updateVendor = $pdo->prepare("UPDATE vendor_wallets SET pending_balance = pending_balance + ? WHERE vendor_id = ?");
                $updateVendor->execute([$vendor_share, $order['vendor_id']]);
                
                // 4. Insert Wallet Transactions
                self::insertWalletTx($pdo, 'vendor', (int)$order['vendor_id'], 'order', (int)$order_id, 'credit', $vendor_share, 'Order earnings', 'pending');
            }

            // Driver Wallet
            if (!empty($order['driver_id'])) {
                self::ensureDriverWallet($pdo, $order['driver_id']);
                
                $updateDriver = $pdo->prepare("UPDATE driver_wallets SET earnings = earnings + ? WHERE driver_id = ?");
                $updateDriver->execute([$rider_share, $order['driver_id']]);
                
                // Driver earnings usually settled faster or immediately added to earnings
                self::insertWalletTx($pdo, 'driver', (int)$order['driver_id'], 'order', (int)$order_id, 'credit', $rider_share, 'Delivery earnings', 'settled');
                
                // TASK 6: Driver COD Handling
                if ($order['payment_method'] === 'cod') {
                    $updateCash = $pdo->prepare("UPDATE driver_wallets SET cash_in_hand = cash_in_hand + ? WHERE driver_id = ?");
                    $updateCash->execute([$total, $order['driver_id']]);
                }
            }

            // Platform
            self::insertWalletTx($pdo, 'platform', null, 'order', (int)$order_id, 'credit', $platform_earnings, 'Platform commission', 'settled');

            // Update order ledger status
            $pdo->prepare("UPDATE orders SET ledger_status = 'processed' WHERE id = ?")->execute([$order_id]);

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            Logger::error("LedgerEngine: Failed to process order completion", ['error' => $e->getMessage(), 'order_id' => $order_id]);
            return false;
        }
    }

    /**
     * Service Booking Completion Logic
     * Triggers when a service booking (bookings table) is completed.
     * Calculates platform commission vs vendor earning and updates wallets.
     */
    public static function processBookingCompletion($booking_id, $db = null) {
        $pdo = $db ?: Database::getConnection();

        try {
            $pdo->beginTransaction();

            // 1. Read booking total, vendor commission rate, and the
            // category's commission rate (via the booking's service), FOR
            // UPDATE to prevent race conditions. Also pull the expert-visit /
            // inspection markers (only those the schema has) so we can route the
            // fee to 100% company below without a second query.
            $evCols = [];
            foreach (['booking_mode', 'visit_type', 'expert_visit_fee', 'fulfilment_type'] as $c) {
                $evCols[] = self::bookingsHasColumn($pdo, $c) ? "b.{$c}" : "NULL AS {$c}";
            }
            $evColsSql = implode(', ', $evCols);
            // LEFT JOIN vendors so a COMPANY-fulfilled booking (vendor_id NULL —
            // the company did the job itself) is still read and booked below; an
            // inner join would drop it and silently record no revenue at all.
            $stmt = $pdo->prepare("
                SELECT b.total, b.vendor_id, b.payment_status,
                       {$evColsSql},
                       v.commission_rate AS vendor_commission_rate,
                       c.commission_rate AS category_commission_rate
                FROM bookings b
                LEFT JOIN vendors v ON v.id = b.vendor_id
                LEFT JOIN services s ON s.id = b.service_id
                LEFT JOIN categories c ON c.id = s.category_id
                WHERE b.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception("Booking not found.");
            }

            // Idempotency: skip if ANY non-refunded ledger row already exists for
            // this booking (scoped to this BOOKING's rows so an order #N cannot
            // fool it). Checks any entity_type — the expert-visit-fee path writes
            // only a platform row (no vendor row), so a vendor-only check would
            // miss it and let a re-run double-insert.
            [$refWhere, $refBind] = self::walletRefMatch($pdo, 'booking', (int)$booking_id);
            $checkStmt = $pdo->prepare("SELECT id FROM wallet_transactions WHERE {$refWhere} AND status != 'refunded' FOR UPDATE");
            $checkStmt->execute($refBind);
            if ($checkStmt->fetch()) {
                $pdo->rollBack();
                return true; // Already processed, fail-safe.
            }

            $total = (float)$booking['total'];

            // 2a. Expert-visit / inspection fee → 100% COMPANY, 0% vendor.
            // Founder rule: the diagnostic fee (e.g. ₹299) is the company's, not
            // job revenue — the vendor has NO share. Record ONLY a platform
            // revenue row: no vendor wallet credit, no pending payout. This is the
            // fix for the 90/10-to-vendor leak (Audit 05 §3). Normal job/service
            // revenue is untouched and still splits below.
            if (self::isExpertVisitFee($booking)) {
                self::insertWalletTx($pdo, 'platform', null, 'booking', (int)$booking_id, 'credit', $total, 'Expert visit / inspection fee (company)', 'settled');
                $pdo->commit();
                return true;
            }

            // 2a-bis. Company self-fulfilled job → 100% COMPANY margin.
            // The company did the job with its own worker, so there is no
            // registered vendor and no commission to carve out — the entire
            // amount is company revenue. Record ONLY a platform revenue row (no
            // vendor wallet credit, no pending payout), mirroring the expert-visit
            // path. Normal vendor jobs are untouched and still split in 2b.
            if (self::isCompanyFulfilled($booking)) {
                self::insertWalletTx($pdo, 'platform', null, 'booking', (int)$booking_id, 'credit', $total, 'Company-fulfilled job (full margin)', 'settled');
                $pdo->commit();
                return true;
            }

            // 2b. Normal job/service revenue — standard split (UNCHANGED).
            // Commission rate priority: category override > vendor override > 10% default.
            // Explicit null checks (not truthy checks) so a deliberately-set
            // 0% rate isn't mistaken for "not set" and overridden by the fallback.
            if ($booking['category_commission_rate'] !== null) {
                $commission_rate = (float)$booking['category_commission_rate'];
            } elseif ($booking['vendor_commission_rate'] !== null) {
                $commission_rate = (float)$booking['vendor_commission_rate'];
            } else {
                $commission_rate = 10.0;
            }
            $platform_commission = ($total * $commission_rate) / 100;
            $vendor_earning = $total - $platform_commission;

            // 3. Update Vendor Wallet
            self::ensureVendorWallet($pdo, $booking['vendor_id']);

            $updateVendor = $pdo->prepare("UPDATE vendor_wallets SET pending_balance = pending_balance + ? WHERE vendor_id = ?");
            $updateVendor->execute([$vendor_earning, $booking['vendor_id']]);

            self::insertWalletTx($pdo, 'vendor', (int)$booking['vendor_id'], 'booking', (int)$booking_id, 'credit', $vendor_earning, 'Booking earnings', 'pending');

            // 4. Platform commission
            self::insertWalletTx($pdo, 'platform', null, 'booking', (int)$booking_id, 'credit', $platform_commission, 'Platform commission (booking)', 'settled');

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            Logger::error("LedgerEngine: Failed to process booking completion", ['error' => $e->getMessage(), 'booking_id' => $booking_id]);
            return false;
        }
    }

    /**
     * TASK 4: Settlement Flow — advance vendor earnings from pending -> available.
     *
     * Moves each eligible pending vendor wallet_transaction's amount from
     * vendor_wallets.pending_balance to available_balance and marks the row
     * 'settled'. This is an INTERNAL ledger state change meaning "ready to pay
     * out" — it does NOT transfer money to any bank (a payout-gateway step is
     * separate). Admin-triggered; never runs on its own.
     *
     * SAFE: runs in one transaction and SELECT ... FOR UPDATE locks the eligible
     * pending rows so a concurrent settle can't move the same earnings twice.
     * IDEMPOTENT: only status='pending' rows are eligible, so a re-run settles
     * nothing already settled. Does NOT change how earnings are credited.
     *
     * @param int|null $vendorId  settle only this vendor; null = all vendors
     * @param PDO|null $db        reuse the caller's connection (default: shared)
     * @return array{status:string, settled_count:int, total_amount:float,
     *               vendors:array<array{vendor_id:int,amount:float,tx_count:int}>}
     */
    public static function runSettlement($vendorId = null, $db = null) {
        $pdo = $db ?: Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Eligible = pending vendor credits (optionally one vendor), locked.
            $sql  = "SELECT id, entity_id, amount FROM wallet_transactions
                      WHERE entity_type = 'vendor' AND status = 'pending'";
            $bind = [];
            if ($vendorId !== null) { $sql .= " AND entity_id = ?"; $bind[] = (int)$vendorId; }
            $sql .= " FOR UPDATE";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare once, reuse per row (same move logic as before).
            $updateWallet = $pdo->prepare("
                UPDATE vendor_wallets
                   SET pending_balance   = GREATEST(0, pending_balance - ?),
                       available_balance = available_balance + ?
                 WHERE vendor_id = ?
            ");
            $markSettled = $pdo->prepare("UPDATE wallet_transactions SET status = 'settled' WHERE id = ?");

            $byVendor = []; $total = 0.0;
            foreach ($transactions as $tx) {
                $amount = (float)$tx['amount'];
                $vid    = (int)$tx['entity_id'];

                $updateWallet->execute([$amount, $amount, $vid]);
                $markSettled->execute([$tx['id']]);

                $total += $amount;
                if (!isset($byVendor[$vid])) {
                    $byVendor[$vid] = ['vendor_id' => $vid, 'amount' => 0.0, 'tx_count' => 0];
                }
                $byVendor[$vid]['amount']   += $amount;
                $byVendor[$vid]['tx_count'] += 1;
            }

            $pdo->commit();

            foreach ($byVendor as &$v) { $v['amount'] = round($v['amount'], 2); }
            return [
                'status'        => 'success',
                'settled_count' => count($transactions),
                'total_amount'  => round($total, 2),
                'vendors'       => array_values($byVendor),
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            Logger::error("LedgerEngine: Failed to run settlement", ['error' => $e->getMessage(), 'vendor_id' => $vendorId]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Task 5: Refund Logic
     * Reverses balances if order is refunded
     */
    public static function processRefund($order_id) {
        $pdo = Database::getConnection();
        
        try {
            $pdo->beginTransaction();

            // Find existing transactions for this order (scoped to ORDER rows so a
            // same-numbered service booking's ledger can never be reversed here)
            [$refWhere, $refBind] = self::walletRefMatch($pdo, 'order', (int)$order_id);
            $stmt = $pdo->prepare("SELECT id, entity_type, entity_id, amount, status FROM wallet_transactions WHERE {$refWhere} AND status != 'refunded'");
            $stmt->execute($refBind);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($transactions)) {
                // No ledger entries to reverse
                $pdo->rollBack();
                return true;
            }

            // Check if already refunded in ledger
            $orderCheck = $pdo->prepare("SELECT ledger_status FROM orders WHERE id = ? FOR UPDATE");
            $orderCheck->execute([$order_id]);
            $orderStatus = $orderCheck->fetchColumn();
            
            if ($orderStatus === 'refunded') {
                $pdo->rollBack();
                return true; // Already processed
            }

            foreach ($transactions as $tx) {
                if ($tx['entity_type'] === 'vendor') {
                    if ($tx['status'] === 'pending') {
                        // Prevent negative pending balance
                        $pdo->prepare("UPDATE vendor_wallets SET pending_balance = GREATEST(0, pending_balance - ?) WHERE vendor_id = ?")
                            ->execute([$tx['amount'], $tx['entity_id']]);
                    } else if ($tx['status'] === 'settled') {
                        // Mark as pending recovery if available_balance is not enough (this allows negative balances so we need to track it)
                        // If DB column is UNSIGNED, we'd have to handle it differently, but DECIMAL(12,2) allows negatives
                        $pdo->prepare("UPDATE vendor_wallets SET available_balance = available_balance - ? WHERE vendor_id = ?")
                            ->execute([$tx['amount'], $tx['entity_id']]);
                    }
                } else if ($tx['entity_type'] === 'driver') {
                    // Prevent negative driver earnings
                    $pdo->prepare("UPDATE driver_wallets SET earnings = earnings - ? WHERE driver_id = ?")
                        ->execute([$tx['amount'], $tx['entity_id']]);
                }
                
                // Mark transaction as refunded
                $pdo->prepare("UPDATE wallet_transactions SET status = 'refunded' WHERE id = ?")
                    ->execute([$tx['id']]);
                
                // Add debit transaction for record
                self::insertWalletTx($pdo, (string)$tx['entity_type'], $tx['entity_id'] !== null ? (int)$tx['entity_id'] : null, 'order', (int)$order_id, 'debit', (float)$tx['amount'], 'Order Refund Reversal', 'settled');
            }

            // TASK 3: Trigger external payment gateway refund if paid online
            $orderStmt = $pdo->prepare("SELECT payment_method, payment_status, payment_id, total FROM orders WHERE id = ?");
            $orderStmt->execute([$order_id]);
            $orderForRefund = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if ($orderForRefund && $orderForRefund['payment_method'] === 'online' && $orderForRefund['payment_status'] === 'paid' && !empty($orderForRefund['payment_id'])) {
                require_once __DIR__ . '/Payment.php';
                try {
                    // Use Payment::refundOrder or similar
                    Payment::refundOrder($order_id, $orderForRefund['total'], "Refund for order #{$order_id}");
                    $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?")->execute([$order_id]);
                } catch (Exception $e) {
                    Logger::error("LedgerEngine: Failed to trigger external refund", ['error' => $e->getMessage(), 'order_id' => $order_id]);
                    // Don't rollback ledger if external refund fails, but log it for manual intervention
                }
            }

            // Also check if driver collected COD, we might need to deduct cash_in_hand?
            // Usually, if an order is refunded, COD was never collected or driver returned it.
            // Leaving cash_in_hand as is for COD refunds since physical cash return is external.

            // Update order ledger status
            $pdo->prepare("UPDATE orders SET ledger_status = 'refunded' WHERE id = ?")->execute([$order_id]);

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            Logger::error("LedgerEngine: Failed to process refund", ['error' => $e->getMessage(), 'order_id' => $order_id]);
            return false;
        }
    }

    /**
     * COMMERCE ORDER SETTLEMENT (Fix Phase 1) — books money for a delivered
     * multi-vendor commerce order. The commerce order model is a PARENT order
     * (vendor_id NULL, subtotal = all items) + per-vendor SUB-orders, with the
     * delivery/fee on the parent — a shape the legacy flat processOrderCompletion
     * does not fit. This settles it correctly:
     *   • per vendor sub-order: vendor pending += subtotal − commission; commission
     *     rate priority = store override > vendor override > 10%.
     *   • rider: earnings += rider_payout; if COD, cash_in_hand += the cash the
     *     rider owes the platform (items + delivery − rider_payout) → this is what
     *     makes isDriverBlocked's cash-limit guard actually live.
     *   • platform: items commission + delivery margin.
     * Every row is reference_type='order', reference_id = the PARENT order id, so
     * it can NEVER collide with a service booking (reference_type='booking').
     * TRANSACTIONAL + IDEMPOTENT: one transaction, FOR UPDATE lock on the parent,
     * skips if orders.ledger_status='processed' or any non-refunded 'order' ledger
     * row already exists — so a re-run (retry cron / double delivery) is a no-op.
     * Accepts a parent id OR a sub-order id (resolves to the parent).
     *
     * @param int      $orderId a parent order id (or any of its sub-order ids)
     * @param PDO|null $db      reuse the caller's connection (default: shared)
     */
    public static function processCommerceOrderSettlement($orderId, $db = null) {
        $pdo = $db ?: Database::getConnection();
        try {
            $pdo->beginTransaction();

            // Resolve to the parent order, then lock it.
            $r = $pdo->prepare("SELECT id, parent_order_id FROM orders WHERE id = ? LIMIT 1");
            $r->execute([(int)$orderId]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $pdo->rollBack(); return false; }
            $parentId = !empty($row['parent_order_id']) ? (int)$row['parent_order_id'] : (int)$row['id'];

            $ps = $pdo->prepare("SELECT id, subtotal, total, delivery_fee, payment_method, ledger_status FROM orders WHERE id = ? FOR UPDATE");
            $ps->execute([$parentId]);
            $order = $ps->fetch(PDO::FETCH_ASSOC);
            if (!$order) { $pdo->rollBack(); return false; }
            if (($order['ledger_status'] ?? null) === 'processed') { $pdo->rollBack(); return true; }

            // Idempotency: any non-refunded 'order' ledger row for this parent?
            [$refWhere, $refBind] = self::walletRefMatch($pdo, 'order', $parentId);
            $chk = $pdo->prepare("SELECT id FROM wallet_transactions WHERE {$refWhere} AND status != 'refunded' FOR UPDATE");
            $chk->execute($refBind);
            if ($chk->fetch()) { $pdo->rollBack(); return true; }

            $isCod = (($order['payment_method'] ?? '') === 'cod');
            $itemsSubtotal = 0.0; $platformCommission = 0.0;

            // Per-vendor sub-orders → vendor earning + platform commission.
            // S1 (margin model): read the seller base alongside the customer subtotal.
            $subs = $pdo->prepare("
                SELECT o.id, o.vendor_id, o.subtotal, o.seller_subtotal,
                       v.commission_rate AS vendor_rate, s.commission_rate AS store_rate
                  FROM orders o
                  LEFT JOIN vendors v ON v.id = o.vendor_id
                  LEFT JOIN stores  s ON s.id = o.store_id
                 WHERE o.parent_order_id = ? FOR UPDATE
            ");
            $subs->execute([$parentId]);
            $subOrders = $subs->fetchAll(PDO::FETCH_ASSOC);

            // $platformCommission accumulates WorkToGo's margin across sub-orders
            // (margin model) and the delivery platform fee is added later.
            foreach ($subOrders as $sub) {
                $subtotal = (float)$sub['subtotal'];   // customer price (margin already included)
                $itemsSubtotal += $subtotal;

                // Company store (ownership='company' → no external seller): the whole
                // customer amount is WorkToGo margin; there is no vendor to pay.
                if (empty($sub['vendor_id'])) {
                    $platformCommission += $subtotal;
                    continue;
                }

                $sellerBase = $sub['seller_subtotal'] !== null ? (float)$sub['seller_subtotal'] : null;
                if ($sellerBase !== null) {
                    // MARGIN model: the seller is paid their base IN FULL; WorkToGo
                    // keeps only the spread it added on top.
                    $vendorShare = round($sellerBase, 2);
                    $margin      = round($subtotal - $sellerBase, 2);
                } else {
                    // Back-compat: an order placed before seller-base capture has no
                    // base recorded → fall back to the legacy COMMISSION split so
                    // in-flight/legacy orders still settle correctly.
                    $rate = $sub['store_rate'] !== null ? (float)$sub['store_rate']
                          : ($sub['vendor_rate'] !== null ? (float)$sub['vendor_rate'] : 10.0);
                    $margin      = round($subtotal * $rate / 100, 2);
                    $vendorShare = round($subtotal - $margin, 2);
                }

                self::ensureVendorWallet($pdo, (int)$sub['vendor_id']);
                $pdo->prepare("UPDATE vendor_wallets SET pending_balance = pending_balance + ? WHERE vendor_id = ?")
                    ->execute([$vendorShare, (int)$sub['vendor_id']]);
                self::insertWalletTx($pdo, 'vendor', (int)$sub['vendor_id'], 'order', $parentId, 'credit', $vendorShare, 'Order earnings', 'pending');
                $platformCommission += $margin;
            }
            // Fallback for a flat order (no sub-orders): the parent's own subtotal.
            if (empty($subOrders)) { $itemsSubtotal = (float)$order['subtotal']; }

            // Delivery → rider payout + COD cash owed + platform delivery margin.
            $del = $pdo->prepare("SELECT driver_id, price, rider_payout, platform_fee FROM deliveries WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $del->execute([$parentId]);
            $delivery = $del->fetch(PDO::FETCH_ASSOC) ?: [];
            $deliveryFee = isset($delivery['price']) && $delivery['price'] !== null ? (float)$delivery['price'] : (float)($order['delivery_fee'] ?? 0);
            $riderPayout = (float)($delivery['rider_payout'] ?? 0);
            $platformDelivery = (float)($delivery['platform_fee'] ?? 0);
            $driverId = (int)($delivery['driver_id'] ?? 0);

            if ($driverId > 0) {
                self::ensureDriverWallet($pdo, $driverId);
                if ($riderPayout > 0) {
                    $pdo->prepare("UPDATE driver_wallets SET earnings = earnings + ? WHERE driver_id = ?")->execute([$riderPayout, $driverId]);
                    self::insertWalletTx($pdo, 'driver', $driverId, 'order', $parentId, 'credit', $riderPayout, 'Delivery earnings', 'settled');
                }
                if ($isCod) {
                    $owed = round($itemsSubtotal + $deliveryFee - $riderPayout, 2);
                    if ($owed > 0) {
                        $pdo->prepare("UPDATE driver_wallets SET cash_in_hand = cash_in_hand + ? WHERE driver_id = ?")->execute([$owed, $driverId]);
                    }
                }
            }

            $platformTotal = round($platformCommission + $platformDelivery, 2);
            if ($platformTotal != 0.0) {
                self::insertWalletTx($pdo, 'platform', null, 'order', $parentId, 'credit', $platformTotal, 'Platform margin + delivery (order)', 'settled');
            }

            $pdo->prepare("UPDATE orders SET ledger_status = 'processed' WHERE id = ?")->execute([$parentId]);
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            Logger::error("LedgerEngine: commerce order settlement failed", ['error' => $e->getMessage(), 'order_id' => $orderId]);
            return false;
        }
    }

    /**
     * Check if driver is blocked due to exceeding cash_in_hand limit
     */
    public static function isDriverBlocked($driver_id) {
        $pdo = Database::getConnection();
        
        $stmt = $pdo->prepare("SELECT cash_in_hand, collection_limit FROM driver_wallets WHERE driver_id = ?");
        $stmt->execute([$driver_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($wallet) {
            return (float)$wallet['cash_in_hand'] >= (float)$wallet['collection_limit'];
        }
        return false;
    }
}
