<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
    </main>

    <?php $layout = $layout ?? ThemeManager::layout(null); ?>
    <?php if (SidebarCompiler::layoutHas('sidebar_right', $layout)): ?>
    <aside class="hbp-sidebar-area">
        {{{ $sidebarRight ?? SidebarCompiler::loadSidebar('sidebar_right', $lang ?? '') }}}
    </aside>
    <?php endif; ?>
</div>

<footer class="hbp-footer">
    <div class="hbp-container">
        {{{ SidebarCompiler::areaFor('footer', $lang ?? '', $layout) }}}
        <nav class="hbp-footer-nav">
            {{{ $menus['pc_footer'] ?? '' }}}
        </nav>
        <p class="hbp-footer-copy">&copy; <?php echo date('Y'); ?> {{ $siteName }}.<?php if (Env::bool('SITE_CREDIT_LINK', true)): ?> Powered by <a href="https://hayabusapress.org" target="_blank" rel="noopener noreferrer">HayabusaPress</a>.<?php endif; ?></p>
    </div>
</footer>

{# inline_js 'app.js' #}
<?php
// Static snippets of enabled plugins (manifest "snippets"). Printed here, not passed in by the
// caller, so plugin page templates that include this footer get them too.
echo PluginManager::snippet('body_end');
?>
</body>
</html>
