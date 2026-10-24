<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
<!DOCTYPE html>
<html lang="{{ $userLocale ?? 'en' }}" class="">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/site/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/site/favicon-16x16.png">
<link rel="icon" type="image/png" sizes="96x96" href="/assets/site/favicon-96x96.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/site/apple-touch-icon-180x180.png">
<title>{{ $pageTitle }} — {{ $siteName }}</title>
<!-- in production, serve admin.css built with the tailwindcss standalone CLI locally.
     If public/admin/assets/admin.css exists, prefer it (for strict CSP with style-src 'self').
     If it does not exist, fall back to the Play CDN (for development; requires CSP relaxation).
     Build: ./tailwindcss -i core/admin_views/admin.src.css -o public/admin/assets/admin.css --minify -->
<?php if (@filesize(PUBLIC_PATH . '/admin/assets/admin.css') > 0): /* If a built CSS exists, serve locally; if empty/absent, fall back to the CDN */ ?>
<script>
(function() {
    var t = localStorage.getItem('hbp-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (t === 'dark') document.documentElement.classList.add('dark');
})();
</script>
<link rel="stylesheet" href="/{{ $adminPath }}/assets/admin.css">
<?php else: ?>
<script>
tailwind.config = { darkMode: 'class' };
(function() {
    var t = localStorage.getItem('hbp-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (t === 'dark') document.documentElement.classList.add('dark');
})();
</script>
<script src="https://cdn.tailwindcss.com"></script>
<?php endif; ?>
</head>
<body class="bg-white dark:bg-black text-slate-800 dark:text-slate-200">

<!-- Mobile overlay -->
<div id="overlay" class="fixed inset-0 bg-black/50 z-20 hidden md:hidden" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside id="sidebar"
       class="fixed top-0 left-0 h-full w-56 bg-white dark:bg-black border-r border-slate-100 dark:border-slate-800
              flex flex-col z-30 transform -translate-x-full md:translate-x-0 transition-transform duration-200">
    <div class="p-4 border-b border-slate-100 dark:border-slate-800">
        <h1 class="font-bold text-sm tracking-wider text-slate-500 dark:text-slate-400 uppercase">HBP Control</h1>
    </div>

    <nav class="flex-1 overflow-y-auto p-3 space-y-0.5 text-sm">
        <a href="/{{ $adminPath }}/posts"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            {@ posts @}
        </a>
        {% if Gate::can('comment:moderate') %}
        <a href="/{{ $adminPath }}/comments"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            {@ comments @}
        </a>
        {% endif %}
        {% if Gate::can('nav:manage') %}
        <a href="/{{ $adminPath }}/menu"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            {@ menus @}
        </a>
        <a href="/{{ $adminPath }}/sidebar"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
            {@ sidebar @}
        </a>
        {% endif %}
        {% if Gate::can('post:edit_other') %}
        <a href="/{{ $adminPath }}/schedule"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            {@ schedule @}
        </a>
        {% endif %}
        {% if Gate::can('templates:manage') %}
        <a href="/{{ $adminPath }}/templates"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
            {@ post_templates @}
        </a>
        {% endif %}
        {% if Gate::can('blocks:manage') %}
        <a href="/{{ $adminPath }}/blocks"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1" stroke-width="2"/><rect x="14" y="3" width="7" height="7" rx="1" stroke-width="2"/><rect x="3" y="14" width="7" height="7" rx="1" stroke-width="2"/><rect x="14" y="14" width="7" height="7" rx="1" stroke-width="2"/></svg>
            {@ blocks @}
        </a>
        <a href="/{{ $adminPath }}/speakers"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            {@ speakers @}
        </a>
        {% endif %}
        {% if Gate::can('links:manage') %}
        <a href="/{{ $adminPath }}/links"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
            {@ links @}
        </a>
        {% endif %}
        {% if Gate::can('taxonomy:manage') %}
        <a href="/{{ $adminPath }}/categories"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
            {@ categories_tags @}
        </a>
        {% endif %}
        {% if Gate::can('user:manage') %}
        <a href="/{{ $adminPath }}/users"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            {@ users @}
        </a>
        {% endif %}
        {% if Gate::can('plugin:manage') %}
        <a href="/{{ $adminPath }}/plugins"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
            {@ plugins @}
        </a>
        {% if PluginManager::isActive('hbp-badwords') %}
        <a href="/{{ $adminPath }}/badwords"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            {@ badwords @}
        </a>
        {% endif %}
        {% endif %}
        {% if Gate::can('theme:manage') %}
        <a href="/{{ $adminPath }}/themes"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.563-2.512 5.563-5.563C22 6.012 17.5 2 12 2z"/></svg>
            {@ themes @}
        </a>
        {% endif %}
        {% if Gate::can('site:setting') %}
        <a href="/{{ $adminPath }}/seo"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
            {@ seo @}
        </a>
        {% endif %}
        {% if Gate::can('maintenance:run') %}
        <a href="/{{ $adminPath }}/maintenance"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z"/></svg>
            {@ maintenance @}
        </a>
        {% endif %}

        <div class="my-2 border-t border-slate-100 dark:border-slate-800"></div>

        <a href="/{{ $adminPath }}/profile"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            {{ $authUser['name'] }}
        </a>
        <a href="/{{ $adminPath }}/logout"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors text-rose-500">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            {@ logout @}
        </a>
    </nav>

    <div class="p-3 border-t border-slate-100 dark:border-slate-800">
        <button id="themeToggle" onclick="toggleTheme()"
                class="flex items-center gap-2 px-3 py-2 w-full rounded-lg hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors text-sm text-slate-500 dark:text-slate-400">
            <svg id="sunIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <svg id="moonIcon" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            {@ theme_toggle @}
        </button>
        <p class="text-xs text-slate-300 dark:text-slate-700 px-3 mt-2">© 2026 HayabusaPress</p>
    </div>
</aside>

<!-- Main content -->
<div class="md:ml-56 min-h-screen flex flex-col">

    <!-- Mobile header -->
    <header class="md:hidden flex items-center gap-3 px-4 py-3 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-black z-10">
        <button onclick="toggleSidebar()" class="p-1">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="font-bold text-sm">{{ $siteName }}</h1>
    </header>

    <!-- Main area -->
    <main class="flex-1 p-4 md:p-6 max-w-6xl w-full mx-auto">
        {% if $schemaDrift %}
        <div class="mb-4 flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
            <span>{{ $schemaDrift }}</span>
        </div>
        {% endif %}
        {% if $dbEncoding %}
        <div class="mb-4 flex items-start gap-2 rounded-xl border border-rose-300 bg-rose-50 dark:border-rose-700 dark:bg-rose-950/40 px-4 py-3 text-sm text-rose-800 dark:text-rose-200">
            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
            <span>{{ $dbEncoding }}</span>
        </div>
        {% endif %}
