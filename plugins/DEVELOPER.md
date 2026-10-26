# HBP Plugin Developer Guide

**HayabusaPress プラグイン開発者向け公式ガイド**

---

## 概要

HBP のプラグインシステムは「マニフェスト駆動・静的フックレジストリ型」を採用する。WordPressの「全プラグインを毎リクエストで動的スキャン」という応答速度を致命的に低下させる構造を完全排除している。

プラグインの追加・有効化・無効化のたびに `PluginManager::rebuildCache()` が実行され、
1枚のソート済みPHP配列キャッシュとして事前焼き付けされる。
実行時は「配列をincludeしてループするだけ」のため、プラグインが1枚だろうが100枚だろうがコストは一定。

---

## ディレクトリ構造

```
plugins/
└── your-plugin-name/
    ├── manifest.json   ← 必須。メタデータとフック宣言
    ├── plugin.php      ← フック実装（クラスベース）
    └── snippets/       ← 任意。公開ページに焼き込む静的HTML断片
```

---

## 拡張ポイント（6つ）

プラグインが手を入れられるのは次の6か所だけ。これ以外にコアへ割り込む口は無い。

| # | 拡張ポイント | 宣言 | できること |
|---|---|---|---|
| 1 | イベントフック | `hooks` | 下の「標準イベント」に割り込む |
| 2 | 独自ルート | `routes` | 任意のURLを自分で描画する |
| 3 | ページテンプレート | `page_template` ＋ `template_route` | 固定ページの本文を差し替える |
| 4 | 専用テーブル | `database/install.{driver}.sql` | 有効化時に自分のテーブルを作る |
| 5 | 言語パック | `lang/{code}.php` | `{plugin-id}.` 接頭辞つきのメッセージを辞書へ足す |
| 6 | 静的ページ断片 | `snippets` | 公開ページの `<head>` 末尾と `</body>` 直前に固定のHTMLを置く |

---

## manifest.json の書き方

```json
{
    "id": "your-plugin-name",
    "name": "プラグインの表示名",
    "version": "1.0.0",
    "author": "あなたの名前",
    "description": "プラグインの説明。",
    "license": "AGPL-3.0-or-later",
    "status": "disabled",
    "hooks": {
        "before_comment_create": {
            "file": "plugins/your-plugin-name/plugin.php",
            "class": "YourPluginClass",
            "method": "checkRequest",
            "priority": 10
        }
    }
}
```

### フィールド説明

| フィールド | 必須 | 説明 |
|---|---|---|
| `id` | ◯ | プラグイン一意ID（物理ディレクトリ名と一致させること） |
| `name` | ◯ | 管理画面に表示される名前 |
| `version` | ◯ | セマンティックバージョン |
| `author` | ◯ | 制作者名 |
| `description` | ◯ | 管理画面に表示される説明 |
| `license` | ◯ | SPDX識別子。公式プラグインは `AGPL-3.0-or-later` 固定。サードパーティは例外規定により任意（`MIT` 等） |
| `status` | ◯ | `"enabled"` または `"disabled"` |
| `hooks` | △ | フック宣言（フックが不要なら空オブジェクト `{}` でOK） |
| `hooks.*.file` | ◯ | フック実装ファイルのパス（ROOT_PATHからの相対パス） |
| `hooks.*.class` | ◯ | クラス名 |
| `hooks.*.method` | ◯ | スタティックメソッド名 |
| `hooks.*.priority` | △ | 実行優先度（数値が小さいほど先に実行。デフォルト `10`） |
| `snippets` | △ | 静的ページ断片（下記）。キーは `head` / `body_end` |

---

## 静的ページ断片（`snippets`）

```json
"snippets": {
    "head":     "snippets/head.html",
    "body_end": "snippets/body_end.html"
}
```

- パスは**プラグインディレクトリからの相対**。ディレクトリの外を指すもの・存在しないもの・64 KiB を超えるものは警告を出して読み飛ばす。
- 読むのは `plugins:rebuild`（有効化・無効化・削除を含む）のときだけ。有効なプラグインの断片をスロットごとにプラグイン ID 順で連結し、`storage/cache/plugin_snippets.cache.php` に書き出す。テーマがそれをページに出力し、FPC に焼き込まれるので、**閲覧時のコストはゼロ**。
- 断片の中のインライン `<script>` / `<style>` は、rebuild のときにハッシュが CSP へ自動で足される。改行は LF に揃えて読む。`src` 付きの `<script>` で外部ホストを使うなら、運用者が `.env` の `CSP_SCRIPT_HOSTS` に足す必要がある。
- 連結結果が変わったら、全ページのキャッシュと CSP は自動で作り直される。
- **断片は静的。** PHP は実行されず、リクエストごとにも閲覧者ごとにも変わらない。閲覧者によって振る舞いを変えたいなら、断片の中のクライアント側 JS で行う。JS が動かない環境でもページが壊れないように書くこと。
- `CSP_MODE=enforce` では `style="..."` 属性と `onclick` などのインラインハンドラは効かない。クラスと `addEventListener` を使う。
- 断片はサニタイズされずに出力される。
- テーマが `PluginManager::snippet('head')` / `PluginManager::snippet('body_end')` を出力していないと、断片は出ない（同梱テーマは出力している）。
- 独自ルートやページテンプレートで同梱テーマの `header` を使うページは、`$seoMeta = SeoCompiler::buildListMeta('ページ名', $canonical, $lang)` を渡すと、SEO 設定のタイトル書式・概要・OGP・パンくずの JSON-LD がそろう（渡さなければサイト名だけの `<title>`）。
- 最小の例は `hbp-helloworld/snippets/head.html`（HTML コメントを1行置くだけ）。

---

## フック実装の書き方

フックメソッドは**スタティックメソッド**として実装し、`array &$context` を受け取る。

```php
<?php
declare(strict_types=1);

class YourPluginClass
{
    public static function checkRequest(array &$context): void
    {
        $ip       = $context['ip_address'] ?? '0.0.0.0';
        $postData = $context['post_data']  ?? [];

        // スパムを検知した場合: exit() でリクエストを遮断
        if (isSpam($ip)) {
            http_response_code(403);
            exit;
        }

        // フィルター: $context['post_data'] の中身を書き換えて後続処理に引き継ぐ
        // $context['post_data']['content'] = str_replace('NG word', '***', $context['post_data']['content']);
    }
}
```

### 参照渡しプロトコル（`array &$context`）

コアとプラグインは互いの名前を**一切知らない**。統一されたプロトコルとして `&$context` 参照渡しを使う。

- **インターセプター（遮断）**: スパム等を検知したら `exit()` でリクエストを破棄
- **フィルター（置換）**: `$context['post_data']` の中身を書き換えて後続処理に引き継ぐ

---

## 標準イベント（フック地点）

| イベント名 | 発火地点 | $context のキー |
|---|---|---|
| `before_comment_create` | コメント受付コントローラーのDB接続前 | `post_data`, `ip_address` |
| `before_contact_send` | お問い合わせプラグインのメール送出前 | `post_data`, `ip_address` |
| `post_saved` | 記事・固定ページの保存の最後（DB確定・キャッシュ再生成の後）。遮断できない通知 | `post_id`, `type`, `lang`, `slug`, `status`, `is_new`, `series_id`（1.5.0〜。連載外・固定ページは `null`） |

---

## 3つの鉄則（絶対に破らないこと）

### 鉄則 1: 実行時の動的ファイルスキャン（I/O）の厳禁

```php
// NG: ランタイムで glob() を実行する
$files = glob(ROOT_PATH . '/data/*.json');

// OK: コンパイル時（rebuildCache 等）にスキャンし、結果をキャッシュに焼き付ける
```

### 鉄則 2: フロントエンドでの設定取得目的のDBクエリの厳禁

```php
// NG: フロント表示時にDBを叩く
$config = DB::fetchOne("SELECT value FROM plugin_settings WHERE key='my_setting'");

// OK: .env または静的キャッシュファイルから読む
$config = $_ENV['MY_PLUGIN_SETTING'] ?? 'default';
```

### 鉄則 3: グローバル汚染・コア書き換えの完全禁止

```php
// NG: グローバル関数・クラスをコア空間に定義
function add_action($hook, $callback) { ... }  // WP風モンキーパッチ禁止

// OK: プラグイン固有の名前空間（クラス名プレフィックス）で完結
class HbpMyPlugin { ... }
```

---

## インストール方法

### 手動インストール

1. `plugins/your-plugin-name/` ディレクトリを作成
2. `manifest.json` と実装ファイルを配置
3. `php core/cli.php plugins:rebuild` を実行

### ZIPインストール

管理画面の「プラグイン管理」からZIPファイルをアップロード。
ZIPの構造は `your-plugin-name/manifest.json` が存在すること（パストラバーサル防御のため）。
中に置けるファイルの拡張子は `php` `json` `md` `txt` `html` `css` `js` `svg` `png` `jpg` `webp` のみ。拡張子のないファイルは `LICENSE` `LICENCE` `COPYING` `NOTICE` `README` `CHANGELOG` `AUTHORS` の名前に限って置ける。

---

## 管理画面での有効化・無効化

管理画面 → プラグイン管理 → 「有効化」または「無効化」ボタンをクリック。

または CLI:
```bash
php core/cli.php plugins:rebuild
```

---

## 同梱サンプルプラグイン

| プラグイン | 説明 |
|---|---|
| `hbp-helloworld` | 最小構成のチュートリアルプラグイン（独自ルートと静的ページ断片の例。雛形として自由に流用可） |
| `hbp-antispam` | 3層ハイブリッドスパム防御（ハニーポット / DNS-BL / 外部API） |
| `hbp-contact` | DBクエリゼロのお問い合わせフォーム（MUI追従） |
| `hbp-badwords` | NGワードフィルタ（事前コンパイル語彙・外部通信ゼロ） |
| `sample-related-posts` | FPC生成に介入する関連記事の実装サンプル |

> すべての同梱プラグインは HBP 本体と同じ **AGPL-3.0-or-later**（＋プラグイン/テーマ例外規定）で提供されます。全ソースファイルが SPDX 識別子 `AGPL-3.0-or-later` を持ちます。
>
> **サードパーティ製プラグインは例外規定によりライセンス自由**です（MIT / Apache 2.0 / GPLv3 / 独自商用など）。ただし公式接頭辞 `hbp-` と「HayabusaPress Official」の名称は使用できません。詳細は `LICENSE` および https://hayabusapress.org/ja/docs/legal/license 。
