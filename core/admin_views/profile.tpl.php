<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle = '{@ profile @}';
$sns = json_decode($user['sns_links'] ?? '{}', true) ?? [];
$hasTwoFactor = !empty($user['two_factor_secret']);

// Unified avatar resolution (shared by the header card and the views below).
$__avatarFile   = basename((string)($user['avatar_filename'] ?? ''));
$__avatarRel    = $__avatarFile !== '' ? Media::avatarRel($__avatarFile) : '';
$__avatarUrl    = $__avatarRel !== '' ? '/assets/site/avatars/' . $__avatarRel : '';
$__avatarExists = $__avatarFile !== ''
    && (is_file(Media::avatarPath($__avatarFile)) || is_file(Media::avatarsBase() . '/' . $__avatarFile));
$__initial      = htmlspecialchars(mb_strtoupper(mb_substr((string)($user['name'] ?? '?'), 0, 1)), ENT_QUOTES, 'UTF-8');
$__roleLabel    = ($user['id'] ?? null) === 0 ? 'Super User' : ucfirst((string)($user['role'] ?? ''));
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ profile @}</h2>

<div class="max-w-2xl space-y-6">

    {% if $_GET['saved'] %}<div class="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
    {% if $_GET['error'] %}<div class="p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}
    {% if $_GET['info'] %}<div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-900 text-slate-600 dark:text-slate-400 text-sm">{@ superuser_profile_env_note @}</div>{% endif %}

    <!-- Identity header card (shown for every admin) -->
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
        <div class="flex flex-col sm:flex-row items-center sm:items-start gap-5 text-center sm:text-left">
            <?php if ($__avatarExists): ?>
            <img src="<?= htmlspecialchars($__avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="{{ $user['name'] }}"
                 class="w-20 h-20 rounded-full object-cover ring-2 ring-slate-100 dark:ring-slate-800 shrink-0">
            <?php else: ?>
            <div class="w-20 h-20 rounded-full bg-emerald-50 dark:bg-emerald-900/20 text-emerald-500 flex items-center justify-center text-2xl font-bold shrink-0"><?= $__initial ?></div>
            <?php endif; ?>
            <div class="min-w-0">
                <h3 class="text-lg font-bold truncate">{{ $user['name'] }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 break-all mt-0.5">{{ $user['email'] }}</p>
                <span class="inline-block mt-2 text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 px-2.5 py-0.5 rounded-full"><?= htmlspecialchars($__roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </div>

{% if $user['id'] !== 0 %}
    <!-- Editable profile form (regular staff) -->
    <form method="post" action="/{{ $adminPath }}/profile/save" enctype="multipart/form-data"
          class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-6 shadow-sm space-y-6">
        {{{ Security::csrfField() }}}
        <div>
            <label class="block text-sm font-medium mb-1.5">{@ display_name @}</label>
            <input type="text" name="name" value="{{ $user['name'] }}" required
                   class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">{@ biography @}</label>
            <textarea name="biography" rows="3"
                      class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">{{{ $user['biography'] }}}</textarea>
        </div>

        <!-- Avatar -->
        <!-- the avatar is uploaded via a dedicated API (same EXIF removal / shardPath as body images).
             The file input has no name = not submitted with the profile form, but sent to the API via AJAX. -->
        <div>
            <label class="block text-sm font-medium mb-1.5">{@ avatar @}</label>
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <img id="avatarPreview" src="<?= $__avatarExists ? htmlspecialchars($__avatarUrl, ENT_QUOTES, 'UTF-8') : '' ?>"
                     alt="avatar" class="w-16 h-16 rounded-full object-cover ring-2 ring-slate-100 dark:ring-slate-800 shrink-0 <?= $__avatarExists ? '' : 'hidden' ?>">
                <div class="flex items-center gap-2 min-w-0">
                    <input type="file" id="avatarFile" accept="image/jpeg,image/png,image/webp,image/gif" class="text-sm min-w-0">
                    <button type="button" onclick="submitAvatar('avatarFile', false)"
                            class="text-xs bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 px-3 py-2 rounded-xl transition-colors whitespace-nowrap">{@ avatar_upload @}</button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">X (Twitter) URL</label>
                <input type="url" name="sns_x" value="{{ $sns['x'] ?? '' }}"
                       class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">GitHub URL</label>
                <input type="url" name="sns_github" value="{{ $sns['github'] ?? '' }}"
                       class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1.5">{@ display_language_label @}</label>
            <select name="locale" class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                {% foreach $availableLocales as $lCode => $lLabel %}
                <option value="{{ $lCode }}" {% if ($user['locale'] ?? 'en') === $lCode %}selected{% endif %}>{{ $lLabel }}</option>
                {% endforeach %}
            </select>
            <p class="text-xs text-slate-400 mt-1.5">{@ locale_note @}</p>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1.5">{@ new_password @}</label>
            <input type="password" name="new_password" autocomplete="new-password"
                   class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>

        <div class="pt-2 border-t border-slate-100 dark:border-slate-800">
            <button type="submit" class="w-full sm:w-auto bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-6 py-2.5 rounded-xl transition-colors text-sm">
                {@ save @}
            </button>
        </div>
    </form>
{% endif %}

{% if $user['id'] === 0 %}
    <!-- Super User: read-only profile managed in .env -->
    <p class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900 text-slate-600 dark:text-slate-400 text-sm">{@ superuser_profile_env_note @}</p>

    <!-- Details (label/value rows with generous spacing + dividers) -->
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden divide-y divide-slate-100 dark:divide-slate-800">
        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">{@ display_name @}</p>
            <p class="text-sm break-words">{{ $user['name'] }}</p>
        </div>
        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">{@ email @}</p>
            <p class="text-sm break-all">{{ $user['email'] }}</p>
        </div>
        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">{@ display_language_label @}</p>
            <p class="text-sm">{{ $user['locale'] }}</p>
        </div>
        {% if $user['biography'] %}
        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">{@ biography @}</p>
            <p class="text-sm whitespace-pre-line break-words">{{ $user['biography'] }}</p>
        </div>
        {% endif %}
        {% if $sns['x'] %}
        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">X (Twitter)</p>
            <a href="{{ $sns['x'] }}" target="_blank" rel="noopener noreferrer" class="text-sm text-emerald-600 hover:underline break-all">{{ $sns['x'] }}</a>
        </div>
        {% endif %}
        {% if $sns['github'] %}
        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">GitHub</p>
            <a href="{{ $sns['github'] }}" target="_blank" rel="noopener noreferrer" class="text-sm text-emerald-600 hover:underline break-all">{{ $sns['github'] }}</a>
        </div>
        {% endif %}
    </div>

    <!-- Avatar (display + dedicated upload API) -->
    <!-- the Super User also uploads the avatar via the dedicated API (EXIF removal / shardPath).
         Since .env is not auto-rewritten, the generated filename is shown for manual entry into SUPER_USER_AVATAR. -->
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-6 shadow-sm space-y-4">
        <h3 class="font-semibold text-sm">{@ avatar @}</h3>
        {% if $user['avatar_filename'] %}
            {% if $__avatarExists %}
            <img src="{{ $__avatarUrl }}" alt="{{ $user['name'] }}" class="w-20 h-20 rounded-full object-cover ring-2 ring-slate-100 dark:ring-slate-800">
            {% else %}
            <div class="text-xs text-amber-600 dark:text-amber-400">
                <p>{@ avatar_file_missing @}</p>
                <code class="block mt-1 break-all">public/assets/site/avatars/{{ $__avatarRel }}</code>
            </div>
            {% endif %}
        {% endif %}
        <div class="flex items-center gap-2">
            <input type="file" id="suAvatarFile" accept="image/jpeg,image/png,image/webp,image/gif" class="text-sm min-w-0">
            <button type="button" onclick="submitAvatar('suAvatarFile', true)"
                    class="text-xs bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 px-3 py-2 rounded-xl transition-colors whitespace-nowrap">{@ avatar_upload @}</button>
        </div>
        <p class="text-xs text-slate-400">{@ avatar_su_upload_note @}</p>
        <div id="suAvatarResult" class="hidden p-3 rounded-xl bg-slate-50 dark:bg-slate-900">
            <p class="text-xs text-slate-500 mb-1">{@ avatar_su_filename_label @}</p>
            <div class="flex items-center gap-2">
                <code id="suAvatarFilename" class="flex-1 font-mono text-xs bg-slate-100 dark:bg-slate-800 px-3 py-2 rounded-lg break-all"></code>
                <button type="button" onclick="copySuAvatar()" class="text-xs text-emerald-500 hover:text-emerald-700 whitespace-nowrap">{@ copy @}</button>
            </div>
        </div>
    </div>
{% endif %}

    <!-- 2FA settings -->
    <div>
        <h3 class="text-lg font-bold mb-4">{@ two_factor_title @}</h3>

        {% if ($_GET['2fa'] ?? '') === 'enabled' %}
        <div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ two_factor_enabled_msg @}</div>
        {% endif %}
        {% if ($_GET['2fa'] ?? '') === 'disabled' %}
        <div class="mb-4 p-3 rounded-xl bg-slate-50 dark:bg-slate-900 text-slate-600 text-sm">{@ two_factor_disabled_msg @}</div>
        {% endif %}

        {% if $hasTwoFactor %}
        <!-- Enabled state -->
        <div class="p-4 bg-emerald-50 dark:bg-emerald-900/20 rounded-2xl mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
            <p class="text-sm text-emerald-600 dark:text-emerald-400 font-medium">{@ two_factor_active @}</p>
        </div>
        {% if $user['id'] !== 0 %}
        <form method="post" action="/{{ $adminPath }}/profile/2fa/disable" onsubmit="return confirm('{@ confirm_delete @}')">
        {{{ Security::csrfField() }}}
            <button type="submit" class="text-sm text-rose-500 hover:text-rose-700">{@ two_factor_disable @}</button>
        </form>
        {% endif %}

        {% else %}
        <!-- Not-yet-enabled state -->
        <div class="p-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-sm space-y-4">
            <p class="text-sm text-slate-500">{@ two_factor_setup_hint @}</p>

            {% if $user['id'] !== 0 %}
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <code id="secretCode" class="flex-1 text-center sm:text-left font-mono text-lg tracking-widest bg-slate-100 dark:bg-slate-800 px-4 py-2 rounded-xl break-all">{{ $newSecret }}</code>
                <button type="button" onclick="copySecret()" class="text-sm text-emerald-500 hover:text-emerald-700 whitespace-nowrap">{@ copy @}</button>
            </div>

            <form method="post" action="/{{ $adminPath }}/profile/2fa/setup" class="space-y-3">
        {{{ Security::csrfField() }}}
                <input type="hidden" name="secret" value="{{ $newSecret }}">
                <div>
                    <label class="block text-sm font-medium mb-1.5">{@ two_factor_code_label @}</label>
                    <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                           autocomplete="one-time-code" required placeholder="000000"
                           class="w-full text-center text-2xl font-mono tracking-widest px-4 py-3
                                  border border-slate-200 dark:border-slate-700 rounded-2xl
                                  dark:bg-slate-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:border-emerald-500">
                </div>
                <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-2.5 rounded-xl transition-colors text-sm">
                    {@ two_factor_enable @}
                </button>
            </form>
            {% else %}
            <p class="text-sm text-slate-400">{@ two_factor_su_note @}</p>
            <p class="text-sm text-slate-400">{@ two_factor_su_locale_note @}</p>
            {% endif %}
        </div>
        {% endif %}
    </div>

</div>

<script>
var msgCopied = '{@ copied_msg @}';
function copySecret() {
    var code = document.getElementById('secretCode').textContent.trim();
    navigator.clipboard.writeText(code).then(function() { alert(msgCopied + ': ' + code); });
}

// アバターを専用APIでアップロード（通常ユーザー・Super User 共通）
var adminPath = '{{ $adminPath }}';
var avatarCsrf = '{{ Security::generateCsrfToken() }}';
var msgAvatarUploaded = '{@ avatar_uploaded @}';
var msgAvatarError = '{@ error_label @}';

function submitAvatar(inputId, isSuper) {
    var el = document.getElementById(inputId);
    var file = el && el.files[0];
    if (!file) return;
    var fd = new FormData();
    fd.append('avatar', file);
    fd.append('_csrf_token', avatarCsrf);
    fetch('/' + adminPath + '/api/avatar', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { alert(msgAvatarError + ': ' + (d.message || '')); return; }
            if (isSuper) {
                // Super User: .env は自動書換しないので、生成ファイル名を表示してコピーさせる
                document.getElementById('suAvatarFilename').textContent = d.filename;
                document.getElementById('suAvatarResult').classList.remove('hidden');
            } else {
                var img = document.getElementById('avatarPreview');
                if (img) { img.src = d.url + '?t=' + Date.now(); img.classList.remove('hidden'); }
                alert(msgAvatarUploaded);
            }
        })
        .catch(function() { alert(msgAvatarError); });
}

function copySuAvatar() {
    var fn = document.getElementById('suAvatarFilename').textContent.trim();
    navigator.clipboard.writeText(fn).then(function() { alert(msgCopied + ': ' + fn); });
}
</script>

{% include 'admin/_layout_foot' %}
