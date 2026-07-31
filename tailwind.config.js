/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './core/admin_views/**/*.{tpl.php,php}',
    './plugins/*/plugin.php',
    './plugins/*/*.tpl.php',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}
