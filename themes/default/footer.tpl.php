<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
    </main>

    <aside class="hbp-sidebar-area">
        {{{ $sidebarRight ?? '' }}}
    </aside>
</div>

<footer class="hbp-footer">
    <div class="hbp-container">
        <nav class="hbp-footer-nav">
            {{{ $menus['pc_footer'] ?? '' }}}
        </nav>
        <p class="hbp-footer-copy">&copy; <?php echo date('Y'); ?> {{ $siteName }}.<?php if (Env::bool('SITE_CREDIT_LINK', true)): ?> Powered by <a href="https://hayabusapress.org" target="_blank" rel="noopener noreferrer">HayabusaPress</a>.<?php endif; ?></p>
    </div>
</footer>

<script>
function toggleSpMenu() {
    var m = document.getElementById('spMenu');
    m.hidden = !m.hidden;
}
</script>

</body>
</html>
