<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class InternalPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Only the submitted representation must have well-formed escapes;
        // decoding %25 legitimately produces a literal percent in query/hash.
        if (! is_string($value) || preg_match('/%(?![a-fA-F0-9]{2})/', $value)) {
            $fail('連結僅接受安全的站內路徑。');
            return;
        }
        $path = $value;
        // Recheck each decoding layer: browsers/proxies must never turn it into an authority.
        for ($layer = 0; $layer < 8; $layer++) {
            if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')
                || preg_match('/[\\\\\x00-\x1f\x7f]/', $path)) {
                $fail('連結僅接受安全的站內路徑。');
                return;
            }
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                return;
            }
            $path = $decoded;
        }
        $fail('連結編碼過深，請使用一般站內路徑。');
    }
}
