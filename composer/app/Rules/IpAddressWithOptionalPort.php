<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An IPv4/IPv6 address, optionally followed by ":port" (IPv6 as "[::1]:8080").
 *
 * The installer accepts the app address in this form and stores it as the
 * site alias IP, so Site Settings must accept the same format back.
 */
class IpAddressWithOptionalPort implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $input = trim((string) $value);

        if (preg_match('/^https?:\/\//i', $input)) {
            $fail('Enter domain or IP only, without http:// or https://.');
            return;
        }

        if (str_contains($input, '/')) {
            $fail('IP address cannot include path segments.');
            return;
        }

        $ipPart = $input;
        $portPart = null;

        // Bare IP (incl. IPv6) takes precedence; otherwise split the trailing :port.
        if (!filter_var($input, FILTER_VALIDATE_IP)
            && preg_match('/^(.+):(\d{1,5})$/', $input, $matches)) {
            $ipPart = $matches[1];
            $portPart = $matches[2];
        }

        $ipPart = trim($ipPart, '[]');

        if (!filter_var($ipPart, FILTER_VALIDATE_IP)) {
            $fail('Enter a valid IP address.');
            return;
        }

        if ($portPart !== null) {
            $port = (int) $portPart;
            if ($port < 1 || $port > 65535) {
                $fail('Port must be between 1 and 65535.');
            }
        }
    }
}
