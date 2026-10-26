<?php
/**
 * HayabusaPress - Contact form plugin (page template).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * HBP Contact Form Plugin
 *
 * A contact form that works as a static-page page template.
 * Does not INSERT into the DB at all; sends directly to .env's SUPER_USER_EMAIL via mb_send_mail().
 * Fires the before_contact_send event before sending, integrating automatically with hbp-antispam.
 *
 * migrated to Env::get(), uses AccessControl::clientIp(), IpBlocklist integration
 */
class HbpContactPlugin
{
    /** Display and process the contact form */
    public static function renderForm(array &$context): void
    {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');
        $lang        = defined('CURRENT_LANG') ? CURRENT_LANG : $defaultLang;
        $dict        = HbpLocale::loadFrontendDictionary($lang);

        $sent         = false;
        $error        = '';
        $inputName    = '';
        $inputEmail   = '';
        $inputMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_once CORE_PATH . '/IpBlocklist.php';   // not loaded by the public front controller
            // B-series: IpBlocklist check at the front line of write POSTs
            $clientIp = class_exists('AccessControl') ? AccessControl::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            if (class_exists('IpBlocklist') && IpBlocklist::isBlocked($clientIp)) {
                $onHit = class_exists('Env') ? Env::get('BLOCKLIST_ON_HIT', 'reject') : 'reject';
                if ($onHit === 'reject') {
                    http_response_code(403);
                    exit;
                }
            }

            // Per-IP sending rate (CONTACT_RATE_LIMIT, "count/seconds"): every accepted message is a mail
            // to the site owner, so a flood here is a flood of their inbox
            if (class_exists('IpBlocklist') && IpBlocklist::rateLimited('contact', $clientIp, 'CONTACT_RATE_LIMIT', '3/600')) {
                http_response_code(429);
                header('Retry-After: 600');
                exit;
            }

            // Fire the before_contact_send hook (automatic integration with anti-spam, etc.)
            $sendContext = [
                'post_data'  => &$_POST,
                'ip_address' => $clientIp,
            ];
            $hooks = PluginManager::getHooks('before_contact_send');
            foreach ($hooks as $hook) {
                // Even with a stale cache after a plugin is removed, do not let a fatal error take down
                // contact sending; verify the existence of the file, class, and method before executing.
                if (empty($hook['file']) || !is_file($hook['file'])) {
                    continue;
                }
                require_once $hook['file'];
                $class  = $hook['class'];
                $method = $hook['method'];
                if (!method_exists($class, $method)) {
                    continue;
                }
                call_user_func_array([$class, $method], [&$sendContext]);
            }

            // Honeypot (spam-bot defense; last line of defense even when the plugin is disabled)
            if (!empty($_POST['hbp_hp_email_field'])) {
                if (class_exists('IpBlocklist')) {
                    IpBlocklist::autoBlock($clientIp, 'honeypot');
                }
                $sent = true; // Pretend a normal completion to the bot
            } else {
                // Arrays (field[]=...) are treated as empty rather than reaching trim() and a 500
                $str          = static fn($v): string => is_string($v) ? $v : '';
                $inputName    = mb_substr(trim($str($_POST['contact_name']    ?? '')), 0, 100);
                $inputEmail   = mb_substr(trim($str($_POST['contact_email']   ?? '')), 0, 255);
                $inputMessage = trim($str($_POST['contact_message'] ?? ''));

                if ($inputName === '' || $inputEmail === '' || $inputMessage === '') {
                    $error = $dict['hbp-contact.required'] ?? 'All fields are required.';
                } elseif (mb_strlen($inputMessage) > 10000) {
                    $error = $dict['hbp-contact.too_long'] ?? 'The message is too long (10,000 characters at most).';
                } elseif (!filter_var($inputEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = $dict['hbp-contact.invalid_email'] ?? 'Invalid email address.';
                } else {
                    $siteName    = class_exists('Env') ? Env::get('SITE_NAME', 'HBP') : ($_ENV['SITE_NAME'] ?? 'HBP');
                    $to          = class_exists('Env') ? Env::get('SUPER_USER_EMAIL', '') : ($_ENV['SUPER_USER_EMAIL'] ?? '');
                    $mailFrom    = class_exists('Env') ? Env::get('MAIL_FROM', 'noreply@example.com') : 'noreply@example.com';
                    $mailFromName = class_exists('Env') ? Env::get('MAIL_FROM_NAME', $siteName) : $siteName;

                    // Mail header injection countermeasure: strip newlines
                    $safeSubject     = str_replace(["\r", "\n"], '', '[' . $siteName . '] ' . ($dict['hbp-contact.title'] ?? 'Contact') . " from {$inputName}");
                    $safeReplyTo     = str_replace(["\r", "\n"], '', $inputEmail);
                    $safeFromName    = str_replace(["\r", "\n", '"'], '', $mailFromName);
                    $body            = "Name: {$inputName}\nEmail: {$inputEmail}\n\n{$inputMessage}";
                    // From uses MAIL_FROM (the sender is the site); Reply-To sets the inquirer's address
                    $headers         = "From: \"{$safeFromName}\" <{$mailFrom}>\r\nReply-To: {$safeReplyTo}";

                    if ($to !== '' && mb_send_mail($to, $safeSubject, $body, $headers)) {
                        $sent         = true;
                        $inputName    = '';
                        $inputEmail   = '';
                        $inputMessage = '';
                    } else {
                        $error = $dict['hbp-contact.failed'] ?? 'Failed to send message.';
                    }
                }
            }
        }

        $siteName     = class_exists('Env') ? Env::get('SITE_NAME', 'HayabusaPress') : ($_ENV['SITE_NAME'] ?? 'HayabusaPress');
        $siteUrl      = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $menus        = MenuCompiler::load();
        $seoHead      = SeoCompiler::loadHead();
        $seoGlobal    = SeoCompiler::loadGlobal();
        $seoMeta      = '<title>' . htmlspecialchars(($dict['hbp-contact.title'] ?? 'Contact') . ' — ' . $siteName, ENT_QUOTES, 'UTF-8') . '</title>';
        $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);
        $sidebarLeft  = SidebarCompiler::loadSidebar('sidebar_left', $lang);

        View::render('plugin/hbp-contact/contact', compact(
            'lang', 'defaultLang', 'siteName', 'siteUrl',
            'menus', 'seoHead', 'seoGlobal', 'seoMeta',
            'sidebarRight', 'sidebarLeft',
            'sent', 'error', 'inputName', 'inputEmail', 'inputMessage'
        ));
        exit;
    }
}
