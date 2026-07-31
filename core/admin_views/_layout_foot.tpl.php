<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
    </main>
</div>

<script>
function toggleSidebar() {
    var sb = document.getElementById('sidebar');
    var ov = document.getElementById('overlay');
    sb.classList.toggle('-translate-x-full');
    ov.classList.toggle('hidden');
}

function toggleTheme() {
    var isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('hbp-theme', isDark ? 'dark' : 'light');
    document.getElementById('sunIcon').classList.toggle('hidden', !isDark);
    document.getElementById('moonIcon').classList.toggle('hidden', isDark);
}

// アイコン初期状態
(function() {
    var isDark = document.documentElement.classList.contains('dark');
    document.getElementById('sunIcon').classList.toggle('hidden', !isDark);
    document.getElementById('moonIcon').classList.toggle('hidden', isDark);
})();
</script>

</body>
</html>
