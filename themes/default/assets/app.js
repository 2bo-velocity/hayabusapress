/*
 * HayabusaPress - Default theme script (mobile menu toggle).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 *
 * Mobile menu toggle and the comment thank-you note.
 *
 * Embedded by {# inline_js 'app.js' #} in footer.tpl.php and allowed by its CSP hash, which
 * SecurityHeaderCompiler computes from the minified output. Run assets:rebuild after editing.
 * No inline event handlers (onclick=...): the hash-based CSP does not allow them.
 */
(function () {
    // "Thanks for your comment": the redirect after posting adds ?commented=1. Done here rather
    // than in the template because the page is served from the full-page cache to everyone.
    var thanks = document.getElementById('hbpCommentThanks');
    if (thanks && /(?:^|[?&])commented=1(?:&|$)/.test(location.search)) {
        thanks.hidden = false;
    }

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
