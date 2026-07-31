<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
<!DOCTYPE html>
<html lang="{{ $htmlLang ?? 'en' }}" class="">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/site/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/site/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/site/apple-touch-icon-180x180.png">
<title>{@ login_title @} — {{ $siteName }}</title>
<script>
(function() {
    var t = localStorage.getItem('hbp-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (t === 'dark') document.documentElement.classList.add('dark');
})();
</script>
<?php if (@filesize(PUBLIC_PATH . '/admin/assets/admin.css') > 0): /* Built CSS if present, otherwise the CDN */ ?>
<link rel="stylesheet" href="/{{ $adminPath }}/assets/admin.css">
<?php else: ?>
<script>tailwind.config = { darkMode: 'class' };</script>
<script src="https://cdn.tailwindcss.com"></script>
<?php endif; ?>
</head>
<body class="bg-white dark:bg-black min-h-screen flex items-center justify-center">

<div class="w-full max-w-sm px-4">
    <div class="mb-8 text-center">
        <h1 class="text-2xl font-bold tracking-tight dark:text-white">{{ $siteName }}</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{@ admin_panel @}</p>
    </div>

    {% if $error %}
    <div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">
        {{ $error }}
    </div>
    {% endif %}

    <form method="post" action="/{{ $adminPath }}/login" class="space-y-4">
        {{{ Security::csrfField() }}}
        <div>
            <label class="block text-sm font-medium dark:text-slate-300 mb-1">{@ email @}</label>
            <input type="email" name="email" required autocomplete="username"
                   class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl
                          dark:bg-slate-900 dark:text-white text-sm
                          focus:outline-none focus:ring-2 focus:border-emerald-500 focus:ring-emerald-500/20">
        </div>
        <div>
            <label class="block text-sm font-medium dark:text-slate-300 mb-1">{@ password @}</label>
            <input type="password" name="password" required autocomplete="current-password"
                   class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl
                          dark:bg-slate-900 dark:text-white text-sm
                          focus:outline-none focus:ring-2 focus:border-emerald-500 focus:ring-emerald-500/20">
        </div>
        <button type="submit"
                class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-2.5 rounded-xl transition-colors text-sm">
            {@ login_btn @}
        </button>
    </form>

    <p class="mt-6 text-center text-xs text-slate-400 dark:text-slate-600">HayabusaPress</p>
</div>

</body>
</html>
