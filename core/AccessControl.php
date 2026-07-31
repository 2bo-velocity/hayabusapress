<?php
/**
 * HayabusaPress - Client IP resolution and admin IP allowlist check.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * A-series/B-series: determine the client IP + admin IP allowlist check.
 * X-Forwarded-For is adopted only via TRUSTED_PROXIES, preventing spoofing breakthroughs.
 * Single source of IP information for Throttle, IpBlocklist, and SiteDenylistCompiler.
 */
class AccessControl
{
    /**
     * Determine the client IP.
     * XFF is adopted only when TRUSTED_PROXIES is set.
     */
    public static function clientIp(): string
    {
        $trustedProxies = array_filter(array_map(
            'trim',
            explode(',', Env::get('TRUSTED_PROXIES', ''))
        ));

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (!empty($trustedProxies) && self::isInCidrList($remoteAddr, $trustedProxies)) {
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff !== '') {
                // In XFF, the leftmost (client) entry is the genuine one
                $ips = array_map('trim', explode(',', $xff));
                foreach (array_reverse($ips) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false
                        && !self::isPrivateIp($ip)) {
                        return $ip;
                    }
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Determine whether admin access is allowed.
     * When ADMIN_IP_ALLOWLIST is empty, always true (default = unrestricted).
     */
    public static function adminIpAllowed(string $ip): bool
    {
        $allowlist = array_filter(array_map(
            'trim',
            explode(',', Env::get('ADMIN_IP_ALLOWLIST', ''))
        ));

        if (empty($allowlist)) {
            return true; // Default: unrestricted
        }

        return self::isInCidrList($ip, $allowlist);
    }

    /**
     * Enforce admin access control.
     * When ADMIN_IP_MODE=enforce, return 404 to non-allowed IPs to hide its existence.
     * When ADMIN_IP_MODE=log-only, only log it.
     */
    public static function checkAdminAccess(): void
    {
        $ip     = self::clientIp();
        $mode   = Env::get('ADMIN_IP_MODE', 'enforce');
        $allowed = self::adminIpAllowed($ip);

        if (!$allowed) {
            self::log("admin_ip_blocked: {$ip}");
            if ($mode !== 'log-only') {
                http_response_code(404);
                exit;
            }
        }

        // REQUIRE_ADMIN_2FA check (the post-login session check is done in Auth::requireLogin)
    }

    /**
     * Determine whether an IP is within a CIDR list (IPv4/IPv6 supported).
     */
    public static function isInCidrList(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::matchCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** Match against a single CIDR/IP. */
    private static function matchCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }

        // Single IP (no slash)
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $prefix = (int)$prefix;

        $ipLong  = self::ipToLong($ip);
        $netLong = self::ipToLong($network);

        if ($ipLong === false || $netLong === false) {
            return false;
        }

        // IPv6
        if (str_contains($ip, ':')) {
            return self::matchIpv6Cidr($ip, $network, $prefix);
        }

        // IPv4
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        return ((int)$ipLong & $mask) === ((int)$netLong & $mask);
    }

    private static function ipToLong(string $ip): int|false
    {
        if (str_contains($ip, ':')) {
            return false;
        }
        return ip2long($ip);
    }

    private static function matchIpv6Cidr(string $ip, string $network, int $prefix): bool
    {
        $ipPacked  = inet_pton($ip);
        $netPacked = inet_pton($network);
        if ($ipPacked === false || $netPacked === false) {
            return false;
        }
        if ($prefix < 0 || $prefix > 128) {
            return false;
        }
        $bytes    = (int)floor($prefix / 8);
        $remBits  = $prefix % 8;
        $ipSlice  = substr($ipPacked, 0, $bytes);
        $netSlice = substr($netPacked, 0, $bytes);
        if ($ipSlice !== $netSlice) {
            return false;
        }
        if ($remBits > 0 && $bytes < 16) {
            $mask = 0xFF & (0xFF << (8 - $remBits));
            return (ord($ipPacked[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask);
        }
        return true;
    }

    private static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private static function log(string $message): void
    {
        $logFile = STORAGE_PATH . '/logs/access_control.log';
        @file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . ' ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
