<?php
// ============================================================
//  WorkToGo CORE — Validator Helper
//  Validates and sanitizes incoming input.
//  Usage: $v = Validator::make($input, $rules); if ($v->fails()) Response::validation(...);
// ============================================================

class Validator
{
    private array $errors  = [];
    private array $data    = [];

    private function __construct(array $input, array $rules)
    {
        foreach ($rules as $field => $ruleString) {
            $value = $input[$field] ?? null;
            $fieldRules = array_map('trim', explode('|', $ruleString));

            foreach ($fieldRules as $rule) {
                [$ruleName, $ruleParam] = array_pad(explode(':', $rule, 2), 2, null);

                $error = $this->applyRule($field, $value, $ruleName, $ruleParam);

                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break; // First failure wins per field
                }
            }

            // Store sanitized value
            $this->data[$field] = is_string($value) ? trim($value) : $value;
        }
    }

    // ── Factory ──────────────────────────────────────────────
    public static function make(array $input, array $rules): self
    {
        return new self($input, $rules);
    }

    // ── Check ────────────────────────────────────────────────
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return reset($this->errors) ?: 'Validation failed';
    }

    // Returns only validated fields
    public function validated(): array
    {
        return $this->data;
    }

    // ── Rule Engine ──────────────────────────────────────────
    private function applyRule(string $field, mixed $value, string $rule, ?string $param): ?string
    {
        $label = ucfirst(str_replace('_', ' ', $field));

        return match ($rule) {
            'required'  => (is_null($value) || $value === '' || $value === [])
                            ? "{$label} is required"
                            : null,

            'nullable'  => null, // Always passes

            'string'    => (!is_null($value) && !is_string($value))
                            ? "{$label} must be a string"
                            : null,

            'numeric'   => (!is_null($value) && !is_numeric($value))
                            ? "{$label} must be numeric"
                            : null,

            'integer'   => (!is_null($value) && filter_var($value, FILTER_VALIDATE_INT) === false)
                            ? "{$label} must be an integer"
                            : null,

            'boolean'   => (!is_null($value) && !in_array($value, [true, false, 0, 1, '0', '1'], true))
                            ? "{$label} must be a boolean"
                            : null,

            'array'     => (!is_null($value) && !is_array($value))
                            ? "{$label} must be an array"
                            : null,

            'email'     => (!is_null($value) && !filter_var($value, FILTER_VALIDATE_EMAIL))
                            ? "{$label} must be a valid email address"
                            : null,

            'phone'     => (!is_null($value) && !preg_match('/^\+?[0-9]{7,15}$/', (string)$value))
                            ? "{$label} must be a valid phone number"
                            : null,

            'min'       => (!is_null($value) && strlen((string)$value) < (int)$param)
                            ? "{$label} must be at least {$param} characters"
                            : null,

            'max'       => (!is_null($value) && strlen((string)$value) > (int)$param)
                            ? "{$label} may not exceed {$param} characters"
                            : null,

            'min_val'   => (!is_null($value) && (float)$value < (float)$param)
                            ? "{$label} must be at least {$param}"
                            : null,

            'max_val'   => (!is_null($value) && (float)$value > (float)$param)
                            ? "{$label} must not exceed {$param}"
                            : null,

            'in'        => (!is_null($value) && !in_array($value, explode(',', $param ?? ''), true))
                            ? "{$label} must be one of: {$param}"
                            : null,

            'date'      => (!is_null($value) && !strtotime((string)$value))
                            ? "{$label} must be a valid date"
                            : null,

            'url'       => (!is_null($value) && !filter_var($value, FILTER_VALIDATE_URL))
                            ? "{$label} must be a valid URL"
                            : null,

            'confirmed' => (!is_null($value) && $value !== ($this->data["{$field}_confirmation"] ?? null))
                            ? "{$label} confirmation does not match"
                            : null,

            default     => null,
        };
    }
}
