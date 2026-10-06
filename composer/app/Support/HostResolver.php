<?php

namespace App\Support;

/** DNS lookup, as a class so tests can swap it. */
class HostResolver
{
    /** @return list<string> IPv4 addresses, empty when the name does not resolve */
    public function resolve(string $host): array
    {
        $ips = @gethostbynamel($host);

        return $ips === false ? [] : array_values($ips);
    }
}
