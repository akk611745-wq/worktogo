<?php
/**
 * ================================================================
 *  WorkToGo — Refund Controller
 *  Handles refund requests, approvals, and processing
 *  Stack: PHP 8.1, MySQL 8.0
 * ================================================================
 */

declare(strict_types=1);

class RefundController
{
    /**
     * POST /api/user/refund/request
     * User requests a refund for their order
     * JWT required
     */
    public static function requestRefund(): void
    {
        $auth = AuthMiddleware::require();
        $userId = $auth['user_id'];
        
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        
        // Validate input
        $v = Validator::make($input, [
            'order_id' => 'required|integer',
            'reason'   => 'required|string|min:10|max:500',
        ]);
        
        if ($v->fails()) {
            Response::validation($v->firstError(), $v->errors());
        }
        
        $orderId = (int) $input['order_id'];
        $reason  = trim($input['reason']);
        
        $db = getDB();
        
        try {
            $db->beginTransaction();
            
            // 1. Verify order belongs to this user and get transaction
            $stmt = $db->prepare("
                SELECT t.id, t.amount, t.status, t.refund_status, o.status as order_status, o.user_id
                FROM transactions t
                INNER JOIN orders o ON t.reference_id = o.id AND t.reference_type = 'order'
                WHERE o.id = ? AND t.reference_type = 'order'
                FOR UPDATE
            ");
            $stmt->execute([$orderId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                $db->rollBack();
                Response::notFound('Transaction for this order');
            }
            
            // Verify ownership
            if ((int)$transaction['user_id'] !== (int)$userId) {
                $db->rollBack();
                Response::forbidden('You do not have permission to request refund for this order');
            }
            
            // 2. Check order status is 'delivered' or 'cancelled'
            if (!in_array($transaction['order_status'], ['delivered', 'cancelled'], true)) {
                $db->rollBack();
                Response::validation('Refunds can only be requested for delivered or cancelled orders');
            }
            
            // 3. Check no existing refund request (prevent duplicates)
            if ($transaction['refund_status'] !== 'none') {
                $db->rollBack();
                Response::validation('A refund request already exists for this order with status: ' . $transaction['refund_status']);
            }
            
            // 4. Update transaction with refund request
            $updateStmt = $db->prepare("
                UPDATE transactions 
                SET refund_status = 'requested',
                    refund_amount = amount,
                    refund_reason = ?,
                    refund_initiated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$reason, $transaction['id']]);
            
            $db->commit();
            
            Logger::info('Refund requested', [
                'user_id'        => $userId,
                'order_id'       => $orderId,
                'transaction_id' => $transaction['id'],
                'amount'         => $transaction['amount']
            ]);
            
            // 5. Return success response
            Response::success([
                'refund_id'      => $transaction['id'],
                'order_id'       => $orderId,
                'amount'         => (float) $transaction['amount'],
                'status'         => 'requested',
                'message'        => 'Refund request submitted successfully'
            ], 201);
            
        } catch (PDOException $e) {
            $db->rollBack();
            Logger::error('Refund request failed', [
                'error'    => $e->getMessage(),
                'user_id'  => $userId,
                'order_id' => $orderId
            ]);
            Response::serverError('Failed to process refund request');
        }
    }
    
    /**
     * GET /api/user/refunds
     * Get user's refund requests
     * JWT required
     */
    public static function getMyRefunds(): void
    {
        $auth = AuthMiddleware::require();
        $userId = $auth['user_id'];
        
        $db = getDB();
        
        try {
            $stmt = $db->prepare("
                SELECT 
                    t.id as refund_id,
                    t.reference_id as order_id,
                    o.order_number,
                    t.amount,
                    t.refund_status,
                    t.refund_amount,
                    t.refund_reason,
                    t.refund_initiated_at,
                    t.refund_processed_at,
                    o.status as order_status
                FROM transactions t
                INNER JOIN orders o ON t.reference_id = o.id AND t.reference_type = 'order'
                WHERE o.user_id = ? AND t.refund_status != 'none'
                ORDER BY t.refund_initiated_at DESC
            ");
            $stmt->execute([$userId]);
            $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format amounts
            foreach ($refunds as &$refund) {
                $refund['amount'] = (float) $refund['amount'];
                $refund['refund_amount'] = (float) $refund['refund_amount'];
            }
            
            Response::success([
                'refunds' => $refunds,
                'total'   => count($refunds)
            ]);
            
        } catch (PDOException $e) {
            Logger::error('Failed to fetch user refunds', [
                'error'   => $e->getMessage(),
                'user_id' => $userId
            ]);
            Response::serverError('Failed to fetch refunds');
        }
    }
    
    /**
     * POST /api/admin/refund/approve
     * Admin approves and processes refund
     * Admin JWT required
     */
    public static function adminApproveRefund(): void
    {
        $auth = AuthMiddleware::requireRole(ROLE_ADMIN);
        
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        
        $v = Validator::make($input, [
            'transaction_id' => 'required|integer',
        ]);
        
        if ($v->fails()) {
            Response::validation($v->firstError(), $v->errors());
        }
        
        $transactionId = (int) $input['transaction_id'];
        
        $db = getDB();
        
        try {
            $db->beginTransaction();
            
            // 1. SELECT FOR UPDATE to prevent double refunds (row-level locking)
            $stmt = $db->prepare("
                SELECT t.id, t.reference_id, t.reference_type, t.amount, t.refund_status, 
                       t.refund_amount, t.gateway, t.gateway_ref, o.status as order_status
                FROM transactions t
                INNER JOIN orders o ON t.reference_id = o.id AND t.reference_type = 'order'
                WHERE t.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                $db->rollBack();
                Response::notFound('Transaction');
            }
            
            // Check if already processed
            if ($transaction['refund_status'] === 'processed') {
                $db->rollBack();
                Response::validation('Refund already processed');
            }
            
            // Check if in requested state
            if ($transaction['refund_status'] !== 'requested') {
                $db->rollBack();
                Response::validation('Refund must be in requested state. Current state: ' . $transaction['refund_status']);
            }
            
            // Update to approved first
            $db->prepare("UPDATE transactions SET refund_status = 'approved' WHERE id = ?")
               ->execute([$transactionId]);
            
            // 2. Call Payment gateway to initiate refund
            require_once SYSTEM_ROOT . '/core/helpers/Payment.php';
            
            $refundAmount = (float) $transaction['refund_amount'];
            $orderId = (int) $transaction['reference_id'];
            
            try {
                $gatewayResult = Payment::initiateRefund($transactionId, $refundAmount);
                
                if (!$gatewayResult['success']) {
                    throw new Exception($gatewayResult['message'] ?? 'Gateway refund failed');
                }
                
                // 3. Update refund status to processed on gateway success
                $updateStmt = $db->prepare("
                    UPDATE transactions 
                    SET refund_status = 'processed',
                        refund_processed_at = NOW(),
                        refund_gateway_id = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $gatewayResult['gateway_refund_id'] ?? null,
                    $transactionId
                ]);
                
                // Update order payment status
                $db->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?")
                   ->execute([$orderId]);
                
                // 4. Log to LedgerEngine
                require_once SYSTEM_ROOT . '/core/helpers/LedgerEngine.php';
                \Core\Helpers\LedgerEngine::processRefund($orderId);
                
                $db->commit();
                
                Logger::info('Refund approved and processed', [
                    'admin_id'        => $auth['user_id'],
                    'transaction_id'  => $transactionId,
                    'order_id'        => $orderId,
                    'amount'          => $refundAmount,
                    'gateway_ref_id'  => $gatewayResult['gateway_refund_id'] ?? null
                ]);
                
                Response::success([
                    'transaction_id'     => $transactionId,
                    'order_id'           => $orderId,
                    'refund_status'      => 'processed',
                    'amount'             => $refundAmount,
                    'gateway_refund_id'  => $gatewayResult['gateway_refund_id'] ?? null,
                    'message'            => 'Refund approved and processed successfully'
                ]);
                
            } catch (Exception $e) {
                // Gateway call failed - rollback to requested state
                $db->prepare("UPDATE transactions SET refund_status = 'requested' WHERE id = ?")
                   ->execute([$transactionId]);
                $db->commit();
                
                Logger::error('Gateway refund failed', [
                    'error'          => $e->getMessage(),
                    'transaction_id' => $transactionId,
                    'admin_id'       => $auth['user_id']
                ]);
                
                Response::serverError('Payment gateway refund failed: ' . $e->getMessage());
            }
            
        } catch (PDOException $e) {
            $db->rollBack();
            Logger::error('Refund approval failed', [
                'error'          => $e->getMessage(),
                'transaction_id' => $transactionId,
                'admin_id'       => $auth['user_id']
            ]);
            Response::serverError('Failed to process refund approval');
        }
    }
    
    /**
     * POST /api/admin/refund/reject
     * Admin rejects refund request
     * Admin JWT required
     */
    public static function adminRejectRefund(): void
    {
        $auth = AuthMiddleware::requireRole(ROLE_ADMIN);
        
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        
        $v = Validator::make($input, [
            'transaction_id' => 'required|integer',
            'reason'         => 'required|string|min:10|max:500',
        ]);
        
        if ($v->fails()) {
            Response::validation($v->firstError(), $v->errors());
        }
        
        $transactionId = (int) $input['transaction_id'];
        $reason = trim($input['reason']);
        
        $db = getDB();
        
        try {
            $db->beginTransaction();
            
            // Verify transaction exists and is in requested state
            $stmt = $db->prepare("
                SELECT id, refund_status, reference_id 
                FROM transactions 
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                $db->rollBack();
                Response::notFound('Transaction');
            }
            
            if ($transaction['refund_status'] !== 'requested') {
                $db->rollBack();
                Response::validation('Can only reject refunds in requested state. Current state: ' . $transaction['refund_status']);
            }
            
            // Update refund status to rejected
            $updateStmt = $db->prepare("
                UPDATE transactions 
                SET refund_status = 'rejected',
                    refund_reason = CONCAT(refund_reason, ' | REJECTED: ', ?)
                WHERE id = ?
            ");
            $updateStmt->execute([$reason, $transactionId]);
            
            $db->commit();
            
            Logger::info('Refund rejected', [
                'admin_id'       => $auth['user_id'],
                'transaction_id' => $transactionId,
                'order_id'       => $transaction['reference_id'],
                'reason'         => $reason
            ]);
            
            Response::success([
                'transaction_id' => $transactionId,
                'refund_status'  => 'rejected',
                'message'        => 'Refund request rejected'
            ]);
            
        } catch (PDOException $e) {
            $db->rollBack();
            Logger::error('Refund rejection failed', [
                'error'          => $e->getMessage(),
                'transaction_id' => $transactionId,
                'admin_id'       => $auth['user_id']
            ]);
            Response::serverError('Failed to reject refund');
        }
    }
    
    /**
     * GET /api/admin/refunds
     * Admin lists all refund requests with filters
     * Admin JWT required
     */
    public static function adminListRefunds(): void
    {
        $auth = AuthMiddleware::requireRole(ROLE_ADMIN);
        
        $status = $_GET['status'] ?? null;
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;
        
        $db = getDB();
        
        try {
            $where = ['t.refund_status != ?'];
            $bind  = ['none'];
            
            // Filter by status if provided
            if ($status && in_array($status, ['requested', 'approved', 'rejected', 'processed'], true)) {
                $where[] = 't.refund_status = ?';
                $bind[]  = $status;
            }
            
            $whereSQL = 'WHERE ' . implode(' AND ', $where);
            
            // Get total count
            $countStmt = $db->prepare("
                SELECT COUNT(*) 
                FROM transactions t
                INNER JOIN orders o ON t.reference_id = o.id AND t.reference_type = 'order'
                {$whereSQL}
            ");
            $countStmt->execute($bind);
            $total = (int) $countStmt->fetchColumn();
            
            // Get paginated results with user and order info
            $stmt = $db->prepare("
                SELECT 
                    t.id as transaction_id,
                    t.reference_id as order_id,
                    o.order_number,
                    o.user_id,
                    u.name as user_name,
                    u.phone as user_phone,
                    u.email as user_email,
                    t.amount,
                    t.refund_status,
                    t.refund_amount,
                    t.refund_reason,
                    t.refund_initiated_at,
                    t.refund_processed_at,
                    t.refund_gateway_id,
                    o.status as order_status,
                    o.created_at as order_created_at
                FROM transactions t
                INNER JOIN orders o ON t.reference_id = o.id AND t.reference_type = 'order'
                INNER JOIN users u ON o.user_id = u.id
                {$whereSQL}
                ORDER BY t.refund_initiated_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $bind[] = $limit;
            $bind[] = $offset;
            $stmt->execute($bind);
            $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format amounts
            foreach ($refunds as &$refund) {
                $refund['amount'] = (float) $refund['amount'];
                $refund['refund_amount'] = (float) $refund['refund_amount'];
            }
            
            Response::success([
                'refunds'    => $refunds,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int) ceil($total / max($limit, 1))
                ]
            ]);
            
        } catch (PDOException $e) {
            Logger::error('Failed to fetch admin refunds', [
                'error'    => $e->getMessage(),
                'admin_id' => $auth['user_id']
            ]);
            Response::serverError('Failed to fetch refunds');
        }
    }
}
