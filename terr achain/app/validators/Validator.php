<?php
declare(strict_types=1);

final class Validator
{
    private array $errors = [];

    public static function make(): self
    {
        return new self();
    }

    public function rule(string $field, mixed $value, callable $check, string $message): self
    {
        if (!$check($value)) {
            $this->errors[$field][] = $message;
        }
        return $this;
    }

    public function required(string $field, mixed $value, string $message = 'This field is required'): self
    {
        return $this->rule($field, $value, static fn($v) => $v !== null && $v !== '' && !(is_array($v) && count($v) === 0), $message);
    }

    public function string(string $field, mixed $value, int $max = 255, string $message = ''): self
    {
        $message = $message ?: "$field must be a string of at most $max characters";
        return $this->rule($field, $value, static fn($v) => $v === null || (is_string($v) && mb_strlen($v) <= $max), $message);
    }

    public function email(string $field, mixed $value, string $message = 'Invalid email address'): self
    {
        return $this->rule($field, $value, static fn($v) => $v === null || $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) !== false, $message);
    }

    public function date(string $field, mixed $value, string $message = 'Invalid date'): self
    {
        return $this->rule($field, $value, static function ($v) {
            if ($v === null || $v === '') {
                return true;
            }
            $d = DateTime::createFromFormat('Y-m-d', (string)$v);
            return $d !== false && $d->format('Y-m-d') === $v;
        }, $message);
    }

    public function numeric(string $field, mixed $value, string $message = 'Must be numeric'): self
    {
        return $this->rule($field, $value, static fn($v) => $v === null || is_numeric($v), $message);
    }

    public function in(string $field, mixed $value, array $allowed, string $message = 'Invalid value'): self
    {
        return $this->rule($field, $value, static fn($v) => $v === null || in_array($v, $allowed, true), $message);
    }

    public function minLength(string $field, mixed $value, int $min, string $message = ''): self
    {
        $message = $message ?: "$field must be at least $min characters";
        return $this->rule($field, $value, static fn($v) => $v === null || mb_strlen((string)$v) >= $min, $message);
    }

    public function unique(string $field, mixed $value, string $table, string $column, ?int $ignoreId = null, string $message = 'Already exists'): self
    {
        return $this->rule($field, $value, static function ($v) use ($table, $column, $ignoreId, $message) {
            if ($v === null || $v === '') {
                return true;
            }
            $sql = "SELECT COUNT(*) AS c FROM `$table` WHERE `$column` = ?";
            $params = [$v];
            if ($ignoreId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $ignoreId;
            }
            $row = App::db()->fetchOne($sql, $params);
            return (int)$row['c'] === 0;
        }, $message);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function fails(): bool
    {
        return count($this->errors) > 0;
    }

    public function throwIfFails(string $message = 'Validation failed'): void
    {
        if ($this->fails()) {
            throw new ApiException($message, 422, $this->errors);
        }
    }
}
