<?php

namespace SergeyBruhin\Analytics\Support;

/**
 * Zeroes the last octet (IPv4) / last 80 bits (IPv6) before persistence —
 * the standard GA/Matomo-style anonymization. Deliberately truncation, not a
 * one-way hash: this keeps country/city geo-lookup possible later while
 * still removing individual identifiability.
 */
class IpAnonymizer
{
    public function truncate(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $binary = inet_pton($ip);

            if ($binary === false) {
                return null;
            }

            // Keep the first 48 bits (network/subnet), zero the remaining 80.
            $truncated = substr($binary, 0, 6).str_repeat("\0", 10);

            return inet_ntop($truncated) ?: null;
        }

        return null;
    }
}
