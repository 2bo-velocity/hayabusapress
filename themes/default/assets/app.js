/*
 * HayabusaPress - Default theme script (mobile menu toggle).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 *
 * Embedded by {# inline_js 'app.js' #} in footer.tpl.php and allowed by its CSP hash, which
 * SecurityHeaderCompiler computes from the minified output. Run assets:rebuild after editing.
 * No inline event handlers (onclick=...): the hash-based CSP does not allow them.
 */
(function () {
    var btn = document.getElementById('hamburgerBtn');
    var menu = document.getElementById('spMenu');
    if (!btn || !menu) {
        return;
    }
    btn.addEventListener('click', function () {
        menu.hidden = !menu.hidden;
        btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
})();
