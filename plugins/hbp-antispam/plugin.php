<?php
/**
 * HayabusaPress - Hybrid anti-spam shield plugin.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * HBP Hybrid Anti-Spam Shield ( hardening)
 *
 * 3-layer hybrid spam defense:
 *   Layer 1: structural honeypot (cost under 0.01ms / zero DB or network)
 *   Layer 2: infrastructure-direct DNS-BL (fsockopen UDP + timeout control, fail-open)
 *   Layer 3: external Web API fallback (CURLOPT_TIMEOUT=2 seconds fixed)
 *
 * changes:
 *   - USE_DNS_BL -> DNSBL_ENABLED
 *   - gethostbyname() -> fsockopen(UDP) + stream_set_timeout() (timeout-controllable)
 *   - DNSBL_ZONE / DNSBL_RESOLVER / DNSBL_TIMEOUT_MS / DNSBL_ON_HIT support
 *   - Fail-open on DNSBL timeout/failure (does not stop the POST)
 *   - Unified IP retrieval via AccessControl::clientIp()
 *   - Integration with IpBlocklist (temporary registration when the auto-block threshold is exceeded)
 *   - $_ENV -> Env::get()
 *
 * Knows neither the core's nor the plugin's names at all.
 * Expresses "blocking" via the &$context pass-by-reference protocol.
 */
class HbpAntispamPlugin
{
    private const LOG_FILE = STORAGE_PATH . '/logs/spam.log';

    /**
     * Called from the before_comment_create / before_contact_send hooks.
     * On spam detection, exit immediately with 403 without writing a single character to the DB.
     */
    public static function checkRequest(array &$context): void
    {
        $level = class_exists('Env') ? Env::int('ANTISPAM_LEVEL', 2) : (int)($_ENV['ANTISPAM_LEVEL'] ?? 2);
        if ($level === 0) {
            return;
        }

        // Unified IP retrieval via AccessControl (TRUSTED_PROXIES support)
        $ip = class_exists('AccessControl')
            ? AccessControl::clientIp()
            : ($context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

        $postData = $context['post_data'] ?? $_POST;

        // ---- Layer 1: structural honeypot ----
        if (!empty($postData['honey_pot_email']) || !empty($postData['hbp_hp_email_field'])) {
            self::log($ip, 'honeypot');
            self::autoBlock($ip, 'honeypot');
            http_response_code(403);
            exit;
        }

        if ($level < 2) {
            return;
        }

        // ---- Layer 2: DNS-BL (fsockopen UDP + timeout control) ----
        $dnsblEnabled = class_exists('Env') ? Env::bool('DNSBL_ENABLED', false) : filter_var($_ENV['DNSBL_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        if ($dnsblEnabled) {
            $dnsblResult = self::checkDnsBl($ip);
            if ($dnsblResult === true) {
                $onHit = class_exists('Env') ? Env::get('DNSBL_ON_HIT', 'moderate') : ($_ENV['DNSBL_ON_HIT'] ?? 'moderate');
                self::log($ip, 'dns-bl');
                if ($onHit === 'reject') {
                    self::autoBlock($ip, 'auto:dnsbl');
                    http_response_code(403);
                    exit;
                }
                // moderate: to the pending queue (set a flag on the context)
                $context['spam_moderate'] = true;
            }
            // $dnsblResult === null: timeout/failure -> fail open (does not stop the POST)
        }

        // ---- Layer 3: external Web API (optional) ----
        $apiKey = trim(class_exists('Env') ? Env::get('EXTERNAL_SPAM_API_KEY', '') : ($_ENV['EXTERNAL_SPAM_API_KEY'] ?? ''));
        if ($apiKey !== '' && self::isSpamIpViaApi($ip, $apiKey)) {
            self::log($ip, 'external-api');
            self::autoBlock($ip, 'auto:api');
            http_response_code(403);
            exit;
        }
    }

    /**
     * DNS-BL check (fsockopen UDP + stream_set_timeout).
     * Returns null on timeout/failure to fail open.
     * IPv4 only. Skips private IPs and localhost.
     *
     * @return bool|null true = spam / false = clean / null = undeterminable (fail open)
     */
    private static function checkDnsBl(string $ip): bool|null
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null; // Skip private IPs
        }

        $zone      = class_exists('Env') ? Env::get('DNSBL_ZONE', 'zen.spamhaus.org') : ($_ENV['DNSBL_ZONE'] ?? 'zen.spamhaus.org');
        $resolver  = class_exists('Env') ? Env::get('DNSBL_RESOLVER', '1.1.1.1') : ($_ENV['DNSBL_RESOLVER'] ?? '1.1.1.1');
        $timeoutMs = class_exists('Env') ? Env::int('DNSBL_TIMEOUT_MS', 300) : (int)($_ENV['DNSBL_TIMEOUT_MS'] ?? 300);

        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $hostname = "{$reversed}.{$zone}";

        // Build an A query via fsockopen UDP
        try {
            $sock = @fsockopen('udp://' . $resolver, 53, $errno, $errstr, $timeoutMs / 1000);
            if ($sock === false) {
                return null; // Connection failure -> fail open
            }

            $timeoutSec  = (int)floor($timeoutMs / 1000);
            $timeoutUsec = ($timeoutMs % 1000) * 1000;
            stream_set_timeout($sock, $timeoutSec, $timeoutUsec);

            // Generate a simple DNS A query packet
            $id      = random_bytes(2);
            $flags   = "\x01\x00"; // standard query, recursion desired
            $qdCount = "\x00\x01";
            $anCount = "\x00\x00";
            $nsCount = "\x00\x00";
            $arCount = "\x00\x00";

            $qname = '';
            foreach (explode('.', $hostname) as $label) {
                $qname .= chr(strlen($label)) . $label;
            }
            $qname .= "\x00";
            $qtype  = "\x00\x01"; // A
            $qclass = "\x00\x01"; // IN

            $packet = $id . $flags . $qdCount . $anCount . $nsCount . $arCount . $qname . $qtype . $qclass;

            fwrite($sock, $packet);
            $response = fread($sock, 512);
            fclose($sock);

            if ($response === false || strlen($response) < 12) {
                return null; // Malformed response -> fail open
            }

            // Timeout check
            $meta = stream_get_meta_data($sock ?? null);
            if (isset($meta['timed_out']) && $meta['timed_out']) {
                return null;
            }

            // Check ANCOUNT (the answer count) (clean if 0)
            $anCount = (ord($response[6]) << 8) | ord($response[7]);
            if ($anCount === 0) {
                return false;
            }

            // Spam if there is a 127.0.0.x response
            // Simple parse: the last 4 bytes of the response are the IPv4 address
            $ipBytes = substr($response, -4);
            if (strlen($ipBytes) === 4 && ord($ipBytes[0]) === 127) {
                return true;
            }

            return false;
        } catch (Throwable) {
            return null; // Exception -> fail open
        }
    }

    /**
     * Spam decision via an external Web API (CURLOPT_TIMEOUT=2 seconds fixed).
     * If the API key is empty, even cURL initialization is skipped.
     */
    private static function isSpamIpViaApi(string $ip, string $apiKey): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $url = 'https://api.example-spam-check.com/v1/check?ip=' . urlencode($ip) . '&key=' . urlencode($apiKey);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return false;
        }

        $data = json_decode((string)$response, true);
        return isset($data['spam']) && $data['spam'] === true;
    }

    /**
     * IpBlocklist auto-block (temporary registration when the aggregated-signal threshold is exceeded).
     * When BLOCKLIST_AUTO_THRESHOLD=0, no automatic blocking is performed.
     */
    private static function autoBlock(string $ip, string $signal): void
    {
        if (class_exists('IpBlocklist')) {
            IpBlocklist::autoBlock($ip, $signal);
        }
    }

    /** Lightweight logging of spam detections to storage/logs/spam.log */
    private static function log(string $ip, string $layer): void
    {
        $logDir = dirname(self::LOG_FILE);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $line = date('Y-m-d H:i:s') . "\t" . $layer . "\t" . $ip . "\n";
        file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}
