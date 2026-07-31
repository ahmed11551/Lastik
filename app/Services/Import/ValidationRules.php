<?php

declare(strict_types=1);

namespace App\Services\Import;

class ValidationRules
{
    public function validateRow(array $row): array
    {
        $errors = [];

        if (empty($row['type'])) {
            $errors['type'] = 'Customer type is required';
        } elseif (! in_array($row['type'], ['individual', 'legal'], true)) {
            $errors['type'] = 'Invalid customer type';
        }

        if (empty($row['phone'])) {
            $errors['phone'] = 'Phone is required';
        }

        if (isset($row['inn']) && $row['inn'] !== '' && preg_match('/^\d{10,12}$/', (string) $row['inn']) !== 1) {
            $errors['inn'] = 'INN must be 10 or 12 digits';
        }

        if (isset($row['email']) && $row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Email is invalid';
        }

        return $errors;
    }
}
