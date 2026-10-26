<?php
/**
 * HayabusaPress - hbp-contact language pack (English).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 *
 * Every key MUST be prefixed with the plugin id ('hbp-contact.'). Keys without the prefix
 * are dropped by PluginManager::rebuildCache() so a plugin can never shadow a core message.
 * The compiled result lands in storage/cache/plugin_lang_{code}.cache.php.
 */
declare(strict_types=1);

return [
    'hbp-contact.title'         => 'Contact',
    'hbp-contact.name'          => 'Name *',
    'hbp-contact.email'         => 'Email *',
    'hbp-contact.message'       => 'Message *',
    'hbp-contact.send'          => 'Send',
    'hbp-contact.sent'          => 'Your message has been sent. Thank you!',
    'hbp-contact.required'      => 'Please fill in all required fields.',
    'hbp-contact.invalid_email' => 'Please enter a valid email address.',
    'hbp-contact.too_long'      => 'The message is too long (10,000 characters at most).',
    'hbp-contact.failed'        => 'Failed to send message. Please try again later.',
];
