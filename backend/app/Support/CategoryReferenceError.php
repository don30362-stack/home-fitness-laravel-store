<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class CategoryReferenceError
{
    public static function rethrow(QueryException $exception, string $constraint, string $field, string $message): never
    {
        // Only a missing Category FK target is a normal concurrent-delete business failure.
        // Preserve deadlocks, connection failures, other constraints, and all other SQL errors.
        $matchesConstraint = preg_match(
            '/CONSTRAINT [`"]'.preg_quote($constraint, '/').'[`"] FOREIGN KEY/i',
            $exception->errorInfo[2] ?? ''
        ) === 1;
        if (($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1452
            && $matchesConstraint) {
            throw ValidationException::withMessages([$field => $message]);
        }

        throw $exception;
    }
}
