<?php
// core/controllers/SettingsController.php

require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/Response.php';

class SettingsController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getAllSettings(): void
    {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value, value_type, label, is_public FROM app_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [
                'text' => [],
                'number' => [],
                'boolean' => [],
                'json' => []
            ];

            foreach ($rows as &$row) {
                $row['is_public'] = (bool)$row['is_public'];
                if ($row['value_type'] === 'number') {
                    $row['setting_value'] = (float)$row['setting_value'];
                } elseif ($row['value_type'] === 'boolean') {
                    $row['setting_value'] = (bool)$row['setting_value'];
                } elseif ($row['value_type'] === 'json') {
                    $row['setting_value'] = json_decode($row['setting_value'], true);
                }
                
                $type = $row['value_type'];
                if (isset($grouped[$type])) {
                    $grouped[$type][] = $row;
                }
            }

            \Core\Helpers\Response::success($grouped);
        } catch (\Exception $e) {
            \Core\Helpers\Response::serverError('Failed to load settings');
        }
    }

    public function updateSetting(string $key): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!array_key_exists('value', $input)) {
            \Core\Helpers\Response::validation('Value is required');
        }
        $value = $input['value'];

        try {
            $stmt = $this->db->prepare("SELECT value_type FROM app_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $type = $stmt->fetchColumn();

            if (!$type) {
                \Core\Helpers\Response::json(['error' => 'Setting not found'], 404);
            }

            // Validation
            if ($type === 'number') {
                if (!is_numeric($value)) {
                    \Core\Helpers\Response::validation('Value must be numeric');
                }
            } elseif ($type === 'boolean') {
                if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1' && $value !== true && $value !== false) {
                    \Core\Helpers\Response::validation('Value must be 0 or 1');
                }
                $value = (int)$value;
            } elseif ($type === 'json') {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                } else if (is_string($value)) {
                    if (json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
                        \Core\Helpers\Response::validation('Value must be valid JSON string');
                    }
                } else {
                    \Core\Helpers\Response::validation('Value must be valid JSON');
                }
            } elseif ($type === 'text') {
                if (!is_string($value)) {
                    $value = (string)$value;
                }
                if (strlen($value) > 1000) {
                    \Core\Helpers\Response::validation('Value cannot exceed 1000 characters');
                }
            }

            $strValue = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;

            $upd = $this->db->prepare("UPDATE app_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $upd->execute([$strValue, $key]);

            $stmt2 = $this->db->prepare("SELECT updated_at FROM app_settings WHERE setting_key = ?");
            $stmt2->execute([$key]);
            $updatedAt = $stmt2->fetchColumn();

            \Core\Helpers\Response::success([
                'success' => true,
                'setting_key' => $key,
                'new_value' => $value,
                'updated_at' => $updatedAt
            ]);
        } catch (\Exception $e) {
            \Core\Helpers\Response::serverError('Failed to update setting');
        }
    }

    public function getPublicSettings(): void
    {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value, value_type FROM app_settings WHERE is_public = 1");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $flat = [];
            foreach ($rows as $row) {
                $val = $row['setting_value'];
                if ($row['value_type'] === 'number') {
                    $val = (float)$val;
                } elseif ($row['value_type'] === 'boolean') {
                    $val = (bool)$val;
                } elseif ($row['value_type'] === 'json') {
                    $val = json_decode($val, true);
                }
                $flat[$row['setting_key']] = $val;
            }

            \Core\Helpers\Response::success($flat);
        } catch (\Exception $e) {
            \Core\Helpers\Response::serverError('Failed to load public settings');
        }
    }
}
