<?php
/**
 * WorkToGo Core — Payment Bridge (Razorpay/Cashfree)
 */

class Payment
{
    /**
     * Create a payment session/order on the gateway
     */
    public static function createOrder(string $provider, float $amount, string $receiptId): array
    {
        if ($provider !== 'cashfree') {
            throw new Exception("Unsupported payment provider: {$provider}");
        }

        $appId = getenv('CASHFREE_APP_ID');
        $secret = getenv('CASHFREE_SECRET_KEY');
        $baseUrl = getenv('APP_ENV') === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';

        $ch = curl_init("{$baseUrl}/orders");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-client-id: $appId",
            "x-client-secret: $secret",
            "x-api-version: 2022-09-01",
            "Content-Type: application/json"
        ]);
        
        $payload = json_encode([
            "order_id" => $receiptId,
            "order_amount" => $amount,
            "order_currency" => "INR",
            "customer_details" => [
                "customer_id" => "cust_" . time(),
                "customer_phone" => "9999999999"
            ]
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['order_id'])) {
            return [
                'success'      => true,
                'payment_id'   => $data['order_id'],
                'provider'     => $provider,
                'amount'       => $amount,
                'currency'     => 'INR',
                'status'       => 'created',
                'gateway_data' => $data
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to create Cashfree order',
            'error'   => $data
        ];
    }

    /**
     * Verify payment signature (Webhook/Return)
     */
    public static function verify(array $payload, string $signature): bool
    {
        $secret = getenv('CASHFREE_SECRET_KEY');
        $orderId = $payload['orderId'] ?? '';
        $orderAmount = $payload['orderAmount'] ?? '';
        $referenceId = $payload['referenceId'] ?? '';
        $txStatus = $payload['txStatus'] ?? '';
        $paymentMode = $payload['paymentMode'] ?? '';
        $txMsg = $payload['txMsg'] ?? '';
        $txTime = $payload['txTime'] ?? '';

        $data = $orderId . $orderAmount . $referenceId . $txStatus . $paymentMode . $txMsg . $txTime;
        $hash = base64_encode(hash_hmac('sha256', $data, $secret, true));

        return hash_equals($hash, $signature);
    }

    /**
     * Initiate a new payment session
     */
    public static function initiatePayment(string $provider, float $amount, string $receiptId): array
    {
        $appId = getenv('CASHFREE_APP_ID');
        $secret = getenv('CASHFREE_SECRET_KEY');
        return self::createOrder($provider, $amount, $receiptId);
    }

    /**
     * Verify a payment
     */
    public static function verifyPayment(array $payload, string $signature): bool
    {
        $secret = getenv('CASHFREE_SECRET_KEY');
        return self::verify($payload, $signature);
    }

    /**
     * Process refund via payment gateway
     */
    public static function refundOrder($orderId, $amount, $reason = "Refund")
    {
        $appId = getenv('CASHFREE_APP_ID');
        $secret = getenv('CASHFREE_SECRET_KEY');
        $baseUrl = getenv('APP_ENV') === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';

        $ch = curl_init("{$baseUrl}/orders/{$orderId}/refunds");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-client-id: $appId",
            "x-client-secret: $secret",
            "x-api-version: 2022-09-01",
            "Content-Type: application/json"
        ]);
        
        $payload = json_encode([
            "refund_amount" => $amount,
            "refund_id" => "ref_" . time(),
            "refund_note" => $reason
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        
        error_log("[Payment] Cashfree refund failed for Order ID: {$orderId}, Response: {$response}");
        return false;
    }

    /**
     * Initiate refund via Cashfree gateway
     * Called by RefundController after admin approval
     *
     * @param int $transactionId Transaction ID from transactions table
     * @param float $amount Amount to refund
     * @return array ['success' => bool, 'gateway_refund_id' => string|null, 'message' => string]
     * @throws Exception on gateway communication failure
     */
    public static function initiateRefund(int $transactionId, float $amount): array
    {
        try {
            // Get transaction details
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT gateway, gateway_ref, reference_id, reference_type
                FROM transactions
                WHERE id = ?
            ");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                throw new Exception("Transaction not found: {$transactionId}");
            }
            
            // Validate gateway reference exists
            if (empty($transaction['gateway_ref'])) {
                throw new Exception("No gateway reference found for transaction {$transactionId}");
            }
            
            $gateway = $transaction['gateway'] ?? 'cashfree';
            $gatewayRef = $transaction['gateway_ref'];
            $orderId = $transaction['reference_id'];
            
            // Call Cashfree Refund API
            // In production, this would make actual API call to Cashfree
            // Example endpoint: POST https://api.cashfree.com/pg/orders/{order_id}/refunds
            
            if ($gateway === 'cashfree') {
                $appId = getenv('CASHFREE_APP_ID');
                $secret = getenv('CASHFREE_SECRET_KEY');
                $baseUrl = getenv('APP_ENV') === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
                
                // Generate unique refund ID for the request
                $refundId = 'refund_' . uniqid();
                
                $ch = curl_init("{$baseUrl}/orders/{$gatewayRef}/refunds");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "x-client-id: $appId",
                    "x-client-secret: $secret",
                    "x-api-version: 2022-09-01",
                    "Content-Type: application/json"
                ]);
                
                $payload = json_encode([
                    "refund_amount" => $amount,
                    "refund_id" => $refundId,
                    "refund_note" => "Admin Initiated Refund"
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $data = json_decode($response, true);
                
                if ($httpCode >= 200 && $httpCode < 300 && isset($data['cf_refund_id'])) {
                    $actualRefundId = $data['cf_refund_id'];
                    if (class_exists('Logger')) {
                        Logger::info('Cashfree refund successful', [
                            'transaction_id'  => $transactionId,
                            'order_id'        => $orderId,
                            'gateway_ref'     => $gatewayRef,
                            'amount'          => $amount,
                            'refund_id'       => $actualRefundId
                        ]);
                    }
                    return [
                        'success'           => true,
                        'gateway_refund_id' => (string)$actualRefundId,
                        'message'           => 'Refund initiated successfully',
                        'gateway'           => 'cashfree',
                        'status'            => 'PENDING'
                    ];
                } else {
                    $errorMsg = $data['message'] ?? 'Unknown error';
                    if (class_exists('Logger')) {
                        Logger::error('Cashfree refund failed', [
                            'transaction_id'  => $transactionId,
                            'order_id'        => $orderId,
                            'gateway_ref'     => $gatewayRef,
                            'error'           => $errorMsg,
                            'response'        => $data
                        ]);
                    }
                    return [
                        'success' => false,
                        'message' => 'Cashfree refund failed: ' . $errorMsg
                    ];
                }
                
            } else {
                // Handle other gateways (Razorpay, etc.)
                throw new Exception("Unsupported payment gateway: {$gateway}");
            }
            
        } catch (PDOException $e) {
            if (class_exists('Logger')) {
                Logger::error('Refund initiation DB error', [
                    'error'          => $e->getMessage(),
                    'transaction_id' => $transactionId
                ]);
            }
            throw new Exception("Database error during refund: " . $e->getMessage());
            
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('Refund initiation failed', [
                    'error'          => $e->getMessage(),
                    'transaction_id' => $transactionId
                ]);
            }
            throw $e;
        }
    }
}
