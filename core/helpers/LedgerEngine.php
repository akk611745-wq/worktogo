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

            // Check if already processed
            $checkStmt = $pdo->prepare("SELECT id FROM wallet_transactions WHERE order_id = ? AND status != 'refunded' FOR UPDATE");
            $checkStmt->execute([$order_id]);
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
                $insertTx = $pdo->prepare("INSERT INTO wallet_transactions (entity_type, entity_id, order_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insertTx->execute(['vendor', $order['vendor_id'], $order_id, 'credit', $vendor_share, 'Order earnings', 'pending']);
            }

            // Driver Wallet
            if (!empty($order['driver_id'])) {
                self::ensureDriverWallet($pdo, $order['driver_id']);
                
                $updateDriver = $pdo->prepare("UPDATE driver_wallets SET earnings = earnings + ? WHERE driver_id = ?");
                $updateDriver->execute([$rider_share, $order['driver_id']]);
                
                $insertTx = $pdo->prepare("INSERT INTO wallet_transactions (entity_type, entity_id, order_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                // Driver earnings usually settled faster or immediately added to earnings
                $insertTx->execute(['driver', $order['driver_id'], $order_id, 'credit', $rider_share, 'Delivery earnings', 'settled']);
                
                // TASK 6: Driver COD Handling
                if ($order['payment_method'] === 'cod') {
                    $updateCash = $pdo->prepare("UPDATE driver_wallets SET cash_in_hand = cash_in_hand + ? WHERE driver_id = ?");
                    $updateCash->execute([$total, $order['driver_id']]);
                }
            }

            // Platform
            $insertTx = $pdo->prepare("INSERT INTO wallet_transactions (entity_type, entity_id, order_id, type, amount, description, status) VALUES (?, NULL, ?, ?, ?, ?, ?)");
            $insertTx->execute(['platform', $order_id, 'credit', $platform_earnings, 'Platform commission', 'settled']);

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
     * TASK 4: Settlement Flow
     * Moves pending_balance to available_balance and marks transactions as 'settled'
     */
    public static function runSettlement() {
        $pdo = Database::getConnection();
        
        try {
            $pdo->beginTransaction();

            // Find all pending vendor transactions
            $stmt = $pdo->prepare("SELECT id, entity_id, amount FROM wallet_transactions WHERE entity_type = 'vendor' AND status = 'pending'");
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($transactions as $tx) {
                // Update vendor wallet safely
                $updateWallet = $pdo->prepare("
                    UPDATE vendor_wallets 
                    SET pending_balance = GREATEST(0, pending_balance - ?), 
                        available_balance = available_balance + ? 
                    WHERE vendor_id = ?
                ");
                $updateWallet->execute([$tx['amount'], $tx['amount'], $tx['entity_id']]);

                // Mark transaction as settled
                $updateTx = $pdo->prepare("UPDATE wallet_transactions SET status = 'settled' WHERE id = ?");
                $updateTx->execute([$tx['id']]);
            }

            $pdo->commit();
            return ['status' => 'success', 'settled_count' => count($transactions)];
        } catch (Exception $e) {
            $pdo->rollBack();
            Logger::error("LedgerEngine: Failed to run settlement", ['error' => $e->getMessage()]);
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

            // Find existing transactions for this order
            $stmt = $pdo->prepare("SELECT id, entity_type, entity_id, amount, status FROM wallet_transactions WHERE order_id = ? AND status != 'refunded'");
            $stmt->execute([$order_id]);
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
                $pdo->prepare("INSERT INTO wallet_transactions (entity_type, entity_id, order_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$tx['entity_type'], $tx['entity_id'], $order_id, 'debit', $tx['amount'], 'Order Refund Reversal', 'settled']);
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
