<?php

namespace App\Validation;

/**
 * SSRF guard for outbound URLs: only http(s), no embedded credentials, and the
 * host must not resolve to a private / loopback / link-local / reserved
 * (incl. cloud-metadata 169.254.0.0/16) address.
 */
class UrlGuard
{
    public function assertSafe(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Only http(s) URLs are allowed, got scheme '{$scheme}'");
        }

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new \InvalidArgumentException("URLs with embedded credentials are not allowed: {$url}");
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = $this->resolveHostIps($host);

        if ($ips === []) {
            throw new \InvalidArgumentException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException("Target resolves to a blocked address ({$ip}): {$url}");
            }
        }
    }

    /**
     * @return array<int, string> resolved IPs (the literal IP itself if host is an IP)
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
