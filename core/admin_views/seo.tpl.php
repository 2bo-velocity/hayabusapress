<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ seo @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ seo @}</h2>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ seo_save_success @}</div>{% endif %}

<form method="post" action="/{{ $adminPath }}/seo/save" class="space-y-6 max-w-2xl">
    {{{ Security::csrfField() }}}
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">
        <h3 class="font-medium">{@ seo_default_section @}</h3>
        <div>
            <label class="block text-sm font-medium mb-1">{@ meta_description @}</label>
            <textarea name="default_description" rows="2"
                      class="w-full px-4 py-2.5 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">{{{ $seoConfig['default_description'] ?? '' }}}</textarea>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">{@ meta_keywords @}</label>
            <input type="text" name="default_keywords" value="{{ $seoConfig['default_keywords'] ?? '' }}"
                   class="w-full px-4 py-2.5 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">
        <h3 class="font-medium">Google Analytics 4</h3>
        <div>
            <label class="block text-sm font-medium mb-1">{@ seo_ga4_label @}</label>
            <input type="text" name="ga4_id" value="{{ $seoConfig['ga4_id'] ?? '' }}"
                   placeholder="G-XXXXXXXXXX"
                   class="w-full px-4 py-2.5 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">
        <h3 class="font-medium">Google Search Console</h3>
        <div>
            <label class="block text-sm font-medium mb-1">{@ seo_gsc_label @}</label>
            <input type="text" name="search_console_token" value="{{ $seoConfig['search_console_token'] ?? '' }}"
                   class="w-full px-4 py-2.5 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">
        <h3 class="font-medium">robots.txt</h3>
        <textarea name="robots" rows="6"
                  class="w-full px-4 py-2.5 text-sm font-mono border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">{{{ $robots }}}</textarea>
    </div>

    <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-6 py-2.5 rounded-xl transition-colors text-sm">
        {@ save @}
    </button>
</form>

{% include 'admin/_layout_foot' %}
