<?php

/**
 * BrainCore v3 — ClientModel
 *
 * Handles authentication against brain_clients.
 *
 * ─── SECURITY UPGRADE (v3) ───────────────────────────────────
 *
 *   v2: API keys stored and compared as plaintext.
 *   v3: API keys are stored as SHA-256 hashes in the DB.
 *       Comparison is hash(input) vs stored_hash.
 *
 *   Migration: run the 006_adaptive_upgrade.sql migration which
 *   updates existing keys. Then use storeClient() for new clients.
 *
 *   Timing attack prevention: hash_equals() for comparison.
 *   This prevents brute-force timing attacks on key guessing.
 *
 * ─── KEY FORMAT ──────────────────────────────────────────────
 *
 *   Raw key (given to client): wt_key_live_XXXXXXXXXXXX
 *   Stored in DB:              hash('sha256', raw_key)
 *
 * ─── HEADER AUTH (v3) ────────────────────────────────────────
 *
 *   v2: api_key was a GET query param.
 *   v3: api_key is sent in the X-Api-Key header.
 *       Controllers read from $_SERVER['HTTP_X_API_KEY'].
 *       GET param is rejected (no fallback).
 */

class ClientModel
{
    /**
     * Validate client_id + raw api_key (from X-Api-Key header).
     *
     * Hashes the provided key and compares against stored hash.
     * Uses hash_equals() to prevent timing attacks.
     *
     * @param  string $clientId
     * @param  string $rawApiKey  The raw key from the X-Api-Key header
     * @return bool
     */
    public static function validateApiKey(string $clientId, string $rawApiKey): bool
    {
        $db  = getDB();
        $sql = "
            SELECT api_key
            FROM   brain_clients
            WHERE  id        = :client_id
              AND  is_active = 1
            LIMIT  1
        ";

        $st = $db->prepare($sql);
        $st->execute([':client_id' => $clientId]);
        $row = $st->fetch();

        if (!$row) return false;

        $storedHash  = $row['api_key'];
        $inputHash   = hash('sha256', $rawApiKey);

        // Constant-time comparison — prevents timing attacks
        return hash_equals($storedHash, $inputHash);
    }

    /**
     * Hash a raw API key for storage.
     *
     * Use this when creating or rotating client keys:
     *   $hash = ClientModel::hashKey($rawKey);
     *   // Store $hash in brain_clients.api_key
     *
     * @param  string $rawKey
     * @return string  64-character hex SHA-256 hash
     */
    public static function hashKey(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }
}
