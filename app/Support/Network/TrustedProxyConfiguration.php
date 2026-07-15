<?php

namespace App\Support\Network;

use InvalidArgumentException;

final class TrustedProxyConfiguration
{
    /**
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $proxies = [];

        foreach (explode(',', $value) as $index => $candidate) {
            $proxy = trim($candidate);

            if (! self::isValidIpOrCidr($proxy)) {
                throw new InvalidArgumentException(sprintf(
                    'MAPILIO_TRUSTED_PROXIES entry %d must be an explicit IP address or CIDR range.',
                    $index + 1,
                ));
            }

            $proxies[$proxy] = true;
        }

        return array_keys($proxies);
    }

    private static function isValidIpOrCidr(string $candidate): bool
    {
        if ($candidate === '' || in_array($candidate, ['*', '**', 'REMOTE_ADDR'], true)) {
            return false;
        }

        if (! str_contains($candidate, '/')) {
            return filter_var($candidate, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix, $remainder] = array_pad(explode('/', $candidate, 3), 3, null);

        if ($remainder !== null || $prefix === null || ! ctype_digit($prefix)) {
            return false;
        }

        $ipVersion = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? 4
            : (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : null);

        if ($ipVersion === null) {
            return false;
        }

        return (int) $prefix <= ($ipVersion === 4 ? 32 : 128);
    }
}
