[![English](https://img.shields.io/badge/lang-English-blue)](README.md)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3_with_Plugin_Exception-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4)](https://www.php.net/)

# HayabusaPress

**生SQLと事前コンパイルアーキテクチャによって駆動する、爆速・超軽量・マルチロケール対応・プラグイン拡張可能な高セキュリティブログエンジン。**

> · PHP 8.0+ · MySQL 8 / PostgreSQL 13–16 · Composer依存ゼロ · AGPL-3.0-or-later WITH Plugin Exception

---

## コンセプト

HayabusaPress（HBP）は、WordPress への徹底したアンチテーゼです。
WordPress の「重さ・セキュリティの緩さ・積み上がった技術的負債」を、モダンな PHP 8.0 以降の上でゼロから再構築することで解体します。

> **引き算の美学。** 不要なものを徹底的に削ぎ落とす。「決して作らないもの」を明確に定義する。

すべての設計判断は、たった一つの問いに答えます — *これは事前計算して、フロントエンドのコストをゼロにできないか？*

---

## 機能ハイライト

| 機能 | 詳細 |
|---|---|
| **Composer依存ゼロ** | バックエンドは PHP ネイティブ機能のみで構築。ベンダーロックインなし。 |
| **生SQL至上主義** | ORM を完全排除。プレーン PDO ＋ 生SQL のみ。 |
| **事前コンパイルアーキテクチャ** | ルート・メニュー・サイドバー・カテゴリ・タグ・著者プロフィールを保存時に全コンパイル。実行時計算ゼロ。 |
| **オンデマンド・フルページキャッシュ（FPC）** | 初回アクセスで HTML をディスクに保存。2回目以降は `readfile()` のみ — DBクエリゼロ。 |
| **スタンピード防止ロック** | `flock + LOCK_NB` ＋ ダブルチェックで、同時キャッシュミス時のサンダリングハードを防止。 |
| **精密なFPC無効化** | 1記事の編集で、その記事本体＋トップ＋カテゴリ＋タグ＋アーカイブページ（とそのページネーション派生）だけをパージ。全再構築なし。TTLなし。 |
| **CSS/JS インラインパッキング** | テーマの CSS/JS をコンパイル時にミニファイして HTML へインライン化 — 1回のHTTPラウンドトリップで配信。 |
| **オートヒーリング** | キャッシュファイルが消えても、次回アクセス時に自動再生成。 |
| **マルチロケール（2軸）** | コンテンツ言語（`lang`）と管理画面UI言語（`locale`）は完全独立。既定言語は `/` を占有、他は `/{lang}/` プレフィックス。 |
| **翻訳グループ + hreflang** | 記事の翻訳同士を連結。`hreflang` タグと言語スイッチャーを単一の真実 `posts.hreflang_cache` から生成。 |
| **著者シングルトン** | 全著者プロフィールを `authors.cache.php` にコンパイル。記事は `user_id` で参照するのみ — JOINなし、N+1なし。 |
| **事前コンパイルCSP** | ハッシュベースの CSP（nonce不要・FPC互換）。`report-only` → `enforce` の段階的ロールアウト。 |
| **認証スロットリング** | `auth_throttle` テーブル：IP単位＋アカウント単位の指数バックオフとロックアウト。 |
| **HTMLサニタイザ** | DOM 許可リストサニタイザ（`ext-dom`） — 蓄積型XSSに対する第二の防衛線。 |
| **フリガナ・傍点** | 小説向け記法 — `｜漢字《かんじ》` のルビと `《《文字》》` の傍点（圏点） — を保存時に `<ruby>` / `<em class="hbp-bouten">` へコンパイル。記事・固定ページ両対応。 |
| **分割ペインMarkdownエディタ** | タイトル/本文を分離入力。本文はプレーンテキスト（その場リッチ変換なし）＋サーバー側 `MarkdownCompiler` を共有する横並びライブプレビュー。画像のドラッグ&ドロップ、オートセーブ、ルビ/傍点ツールバー。 |
| **添付画像の一覧・削除** | 本文入力欄の直下に、編集中コンテンツの添付画像（`attachments`）をサムネ一覧。各画像をカーソル位置へ本文挿入、または**物理削除**（確認必須・本文で使用中なら警告を強化）。本文の `![]()` 自動書き換えは行わない（リンク切れ除去はユーザー手動）。削除APIは `media:manage` ＋ CSRF、`post:edit_other` 無しなら自分のコンテンツのみ。 |
| **文字数カウント** | タイトル・本文入力欄の左上に文字数をリアルタイム表示。**コードポイント単位**（`[...str].length`＝和文も半角も1文字）。タイトルは 255 ハード上限警告（230超で注意）、本文は推奨レンジのソフトガイド（薄い／標準／網羅）。クライアント側のみ＝サーバー・FPC への影響ゼロ。 |
| **アイキャッチ画像 / OGP** | `posts.featured_image` ＋ `featured_image_alt`。右ドロワーで設定。フォールバック連鎖（本文先頭画像 → サイトロゴ）を**保存時に解決**して焼き付け — 実行時の本文走査ゼロ。`og:image` を駆動。 |
| **レスポンシブ・流動的タイポグラフィ** | テーマ指針：読者側は本文カラム ~720px・`clamp()` 16→18px、管理側はリキッド全幅・ラベル14px・**入力欄≥16px**（iOSズーム防止）。 |
| **メディアの物理隔離** | 下書きメディアは `storage/upload/`（Web到達不可）に格納。公開時に `public/assets/articles/` へ atomic 移動、**非公開化で逆移動**。 |
| **メディアライフサイクル** | 公開/非公開で移動するファイルの正は `attachments` テーブル（本文正規表現は補助検証）。孤児の下書きファイルは `media:gc` で回収。 |
| **ディレクトリ階層化（sharding）** | メディアはファイル名先頭の `{ab}/{cd}/` 2階層（65,536分岐）に分散保存し、単一ディレクトリへのファイル集中（数百万規模での走査劣化・inode 上限）を防止。`attachments.filename` はファイル名のみ保持し、保存/配信パスは単一の `shardPath()` で導出（スキーマ変更なし）。既存フラット配置は `media:reshard` で移行。 |
| **保存先ディレクトリの自動確保** | 書き込み前に単一ヘルパ `ensureDir()` が保存先（親＋シャード中間 `{ab}/{cd}/`）を再帰作成（既定 0755・`.env` `MEDIA_DIR_MODE` で変更可）。インストール/環境診断で3メディア親の作成・書き込み可否を事前検証。失敗時は明確なエラーを返し **`attachments` 行を作らない**（順序＝ディレクトリ確保→保存成功→DB記録）。 |
| **アバターアップロードの統一** | 通常ユーザー・Super User とも専用 API でアバターをアップロード。本文画像と同じ **EXIF除去・`finfo`・`shardPath()`・`ensureDir()`** を共有し、`public/assets/site/avatars/{ab}/{cd}/` へ保存。手動直上げ＝EXIF素通りの穴を解消。Super User は DB 非依存のため参照のみ `.env` `SUPER_USER_AVATAR` に手動記載。 |
| **コメントのメール任意化** | コメントは名前＋本文のみ必須、**メール・ウェブサイトは任意**（`comments.author_email` は NULL 許容）。HBP は返信通知メールも外部 Gravatar も持たず、使えないメールを収集しない。コメント者アバターは**同梱の SVG シルエット**（`public/assets/site/avatar-default.svg`・`fill="currentColor"` でテーマ追従）＝外部リクエストゼロ。コンテンツ単位の受付可否はエディタ右ドロワーの `posts.allow_comment` トグル。 |
| **コメント管理のフィルタ** | コメント管理画面をコンテンツ一覧と統一デザインに（PC=フィルタバー＋テーブル、スマホ=カード、ページネーション）。ステータス区分（件数バッジ付き）＋**投稿日時の期間**＋名前/メール/ウェブサイト（`LIKE`）＋紐づくコンテンツ（`post_id`・コンテンツ一覧から導線）で絞り込み。**本文は意図的に検索対象外**（長文＝索引非効率）。索引追加なし — `status`/期間は `idx_status_created`、`post_id` は `idx_comments_post`。一括操作なし・削除は確認必須。要 `comment:moderate`。 |
| **スーパーユーザー** | 最高権限の管理者を `.env` のみで認証 — DBには一切保存しない。DB全消去でも締め出されない。 |
| **ロールベースアクセス制御** | 静的RBACマトリクス（`permissions.php`・DBクエリゼロ）。4つのスタッフロール（Administrator / Editor / Author / Contributor）＋ Super User を、15の細粒度権限（記事・コメント・メディア・分類・ナビ・メンテ・IP・プラグイン・テーマ・ユーザー・設定）にマッピング。 |
| **ネイティブTOTP 2FA** | RFC 6238 準拠。リプレイ防止（`tfa_last_step`）、5回失敗でセッション破棄、time-step再利用検知。 |
| **マニフェスト型プラグイン** | `manifest.json` でフックを宣言。フックレジストリは事前コンパイル＋優先度ソート済み。リクエストごとの全プラグインロードなし。 |
| **zip-slip安全インストーラ** | `PluginInstaller` が全ZIPエントリを検証し、シンボリックリンク＋zip爆弾を拒否、任意で Ed25519 署名を強制。 |
| **デュアルIPブロックリスト** | `blocked_ips` テーブルが POST型スパムをブロック。`SITE_IP_DENYLIST` はコンパイル済み配列でページ閲覧をブロック — DBヒットゼロ。 |
| **Atom 1.0 + RSS 2.0 フィード** | 言語別の事前コンパイルフィード（`feed.atom.xml`・`feed.rss.xml`）。記事公開時に生成 — 配信時DBゼロ。全 `<head>` に autodiscovery `<link>`。 |
| **予約公開** | `status='scheduled'` ＋ `published_at`（UTC）。擬似cron（既定・**FPC高速パスへの影響ゼロ**＝整数1回比較）、OS cron、手動 `/cron` エンドポイントで発火。`published_at` が投稿日時の単一の真実。 |
| **サイトマップインデックス** | サイト規模が大きくなると `sitemaps/posts-N.xml`（5万URLチャンク）へ自動分割。 |
| **ブラウザメンテナンス** | 管理画面 → メンテナンスページから sitemap / feeds / plugins / 全キャッシュをブラウザで再生成 — SSH不要（`system:init` 相当）。 |
| **設定可能な管理URL** | `ADMIN_PATH` を任意の値に（フロントコントローラルーティング・`.htaccess` 編集不要）。予約済み公開ルートは `admin` にフォールバック。 |
| **共有レンタルサーバー対応** | 方式B（本体を公開ルート外＋`HBP_BASE_PATH`）または方式A（`.htaccess` リライト）。`.env` 露出はインストール時に自己診断。 |
| **MySQL 8 + PostgreSQL 13–16** | 完全なデュアルドライバ対応。FK挙動・部分インデックス・UPSERT構文をすべて適切に分岐。 |
| **XML-RPCなし** | 一行も書かない。攻撃面を排除。 |

---

## 動作要件

| コンポーネント | 要件 |
|---|---|
| **PHP** | 8.0 以降（`declare(strict_types=1)` を厳格に強制） |
| **データベース** | MySQL 8.x **または** PostgreSQL 13–16 |
| **Webサーバー** | Nginx（推奨）· Apache（`mod_rewrite`） |
| **PHP拡張** | `pdo_mysql` または `pdo_pgsql`、`mbstring`、`hash`、`ZipArchive`、`dom` |
| **任意** | `sodium`（Ed25519 プラグイン署名 ＋ 2FAシークレット暗号化） |

---

## クイックスタート

### 方法A — ブラウザインストーラ

Webサーバーのドキュメントルートを `public/` に設定してサイトを開きます。
`.env` が無ければ、組み込みインストーラが自動起動し、次を収集します：

1. サイト名・URL・既定コンテンツ言語
2. データベースドライバと接続情報
3. スーパーユーザーのメール・パスワード・管理画面UI言語
4. 管理画面パス（`admin` から変更してログインURLを秘匿）

インストーラは `.env` を生成し、スキーマを適用、Hello World 記事を投入、全キャッシュをコンパイルし、ログインページへリダイレクトします。

> **共有レンタルサーバー利用者**：先に [共有レンタルサーバーへのデプロイ](#共有レンタルサーバーへのデプロイ) を読んでください。

### 方法B — CLI（ヘッドレス / Docker）

```bash
# 1. クローン
git clone https://github.com/2bo-velocity/hayabusapress.git && cd hayabusapress

# 2. 設定
cp .env.example .env
# .env を編集: DB接続情報、SITE_URL、SUPER_USER_EMAIL など

# 3. シークレット生成
php core/cli.php tool:key              # → APP_KEY を .env に貼る
php core/cli.php tool:hash "password"  # → ハッシュを SUPER_USER_PASSWORD_HASH に貼る

# 4. スキーマ適用 + サンプル記事投入 + マイグレーション baseline 記録
php core/cli.php db:install

# 5. 全キャッシュをコンパイル
php core/cli.php system:init
php core/cli.php plugins:rebuild
php core/cli.php authors:rebuild
```

**Docker / コンテナのエントリポイント：**

```bash
php core/cli.php db:install && php core/cli.php system:init && php core/cli.php plugins:rebuild
```

### 方法C — 既存インストールのアップグレード

**1.0.0 は最初の公開リリースなので、アップグレード元となる版がまだ存在しません。** `core/database/migrations/` は空で、`db:migrate` は「未適用なし」を返します。新規インストールは方法A・方法Bを使ってください。

1.0.0 の公開後、スキーマ変更を伴うリリースには前進専用のマイグレーションが付きます。そのときの手順は次の通りです。

```bash
# 1. まず DB とファイルをバックアップ
mysqldump ...

# 2. 新コードをデプロイ — スキーマ変更の前に新クラスが存在している必要あり

# 3. 未適用のマイグレーションを適用（冪等・前進専用）
php core/cli.php db:status    # DB版とコード版、未適用の一覧を表示
php core/cli.php db:migrate

# 4. 派生データを作り直し、全キャッシュを再生成
php core/cli.php system:init && php core/cli.php plugins:rebuild
```

> **手順4の中身はリリースごとに異なります。** スキーマ変更によっては、非正規化データを埋め直すまでサイトが正しく表示されません（よく使うのは `tags:reindex`＝`post_tags` の一覧キー、`counters:rebuild`＝`categories.post_count` / `tags.post_count`）。どれが必要かは各リリースのリリースノートに明記します。
>
> `core/database/schema.{mysql,pgsql}.sql` を手で取り込んだ場合（phpMyAdmin など）は、そのファイル自身が版を記録しているので**すでに最新版**であり、`db:migrate` は何もしません。

---

## 初期キーの生成（`APP_KEY` と `SUPER_USER_PASSWORD_HASH`）

インストール時、`.env` にはこの2つの値が必要です。**どちらも生のパスワードではなく、整形済みの文字列です。**

```dotenv
# 🔑 アプリケーションキー（暗号化・署名キー）
APP_KEY="base64:CHANGE_ME"

# 🛡️ スーパーユーザー（最上位管理者）パスワードハッシュ
SUPER_USER_PASSWORD_HASH='$2y$10$...'
```

同梱CLI（`tool:key` / `tool:hash`）が SSH 環境では最速ですが、**SSH なしでも両方生成できます**。

> ⚠️ **重要**
> - **パスワードハッシュを「オンライン生成サイト」で作らないこと** — パスワードが第三者サーバーへ送信される恐れがあります。**必ず自分のマシンでローカル生成してください。**
> - **`APP_KEY` は一度設定したら変更しないこと。** 変更すると暗号化済みデータ（例：2FAシークレット）が復号不能になります。
> - 生成した値や生パスワードを、チャット・メール・スクショで誰かに送らないこと。

### 1. `APP_KEY` の生成

これは **32バイトのランダム値を base64 エンコード**し、`base64:` を前置したものです。いずれかの方法で：

| 方法 | コマンド | 環境 |
|---|---|---|
| A. 同梱CLI（最速） | `php core/cli.php tool:key` | SSH |
| B. PC上のPHP | `php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"` | SSHなし |
| C. OpenSSL | `echo "base64:$(openssl rand -base64 32)"` | Mac/Linux・SSHなし |
| D. ブラウザコンソール | `"base64:" + btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))` | PHP/OpenSSLなし |

> 方法Dは、DevTools（`F12`）→ **Console** で上記行を貼り付け、表示された `"base64:…"` の**ダブルクォート内**の中身を使います。`crypto.getRandomValues` は完全にブラウザ内で動き、外部送信はありません。

`.env` への記入（`base64:` 値は `$` を含まないのでダブルクォートで可）：

```dotenv
APP_KEY="base64:k7Xp2v9Qw…（生成した値）…="
```

### 2. `SUPER_USER_PASSWORD_HASH` の生成

これは **ログインパスワードそのものではなく、その bcrypt ハッシュ**（`$2y$10$...`・約60文字）です。ログイン時に入力したパスワードがこのハッシュと照合され、生パスワードは `.env` に保存されません。

| 方法 | コマンド / 手順 | 環境 |
|---|---|---|
| A. 同梱CLI（最速） | `php core/cli.php tool:hash "your-password"` | SSH |
| B. PC上のPHP（推奨） | `php -r "echo password_hash('your-password', PASSWORD_BCRYPT) . PHP_EOL;"` | SSHなし |
| C. 一時PHPファイル | 下記参照（PHPは動くが SSH が無いホスト向け） | SSHなし |

> 💡 **シェル履歴に残さない（Mac/Linux・任意）** — コマンドラインにパスワードを打つと履歴に残ります。気になる場合：
> ```bash
> php -r 'fwrite(STDOUT, "Password: "); system("stty -echo"); $p = trim(fgets(STDIN)); system("stty echo"); echo PHP_EOL . password_hash($p, PASSWORD_BCRYPT) . PHP_EOL;'
> ```

**方法C（一時PHPファイル）** — PHP は動くが SSH が使えない場合：

1. この `makehash.php` を Web 領域（`public_html` など）にアップロード：
   ```php
   <?php
   // makehash.php — ハッシュ生成後ただちに削除すること
   if (!empty($_GET['p'])) {
       echo '<pre>' . htmlspecialchars(password_hash($_GET['p'], PASSWORD_BCRYPT)) . '</pre>';
   } else {
       echo '<form>Password: <input name="p" autocomplete="off"><button>Generate</button></form>';
   }
   ```
2. `https://your-site/makehash.php` を（**HTTPS** で）開き、パスワードを入力 → **Generate**。
3. 表示された `$2y$10$...` をコピー。
4. **`makehash.php` を削除** — 残すと誰でもハッシュ生成できてしまいます。

#### `.env` への記入（シングルクォート必須）

bcrypt ハッシュは `$` を含みます。HBP の `.env` では **シングルクォート `'...'` は値をそのまま読み込み**ますが、ダブルクォート `"..."` は `$` を特殊扱いして壊す恐れがあります。**ハッシュは必ずシングルクォートで囲んでください。**

```dotenv
SUPER_USER_PASSWORD_HASH='$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU'
```

- ✅ 正：シングルクォート `'$2y$10$...'`
- ❌ 誤：ダブルクォート `"$2y$10$..."`（`$2y` で壊れる恐れ）／ クォートなし

### 3. 設定したあと

- `APP_KEY` が `base64:CHANGE_ME` のままでないことを確認。
- スーパーユーザーのメール（`.env` の `SUPER_USER_EMAIL`）と、ハッシュ化した生パスワードで管理画面にログイン。
- 失敗する場合：(1) ハッシュがシングルクォート、(2) ハッシュ元のパスワードが入力と一致、(3) コピー時に欠けていない（bcrypt は約60文字）— を確認。
- **（任意）スーパーユーザーのアバター。** `SUPER_USER_AVATAR` には**ファイル名のみ**（パス不要）を設定します。**専用のアバターAPIからアップロード**して EXIF 除去・`shardPath()` 配置を行い、表示されたファイル名を `.env` に手動記載します（手動直上げは非推奨＝EXIF が残るため）。配信は `public/assets/site/avatars/{ab}/{cd}/{file}`。未設定（空）ならアバターなし。変更後は `php core/cli.php authors:rebuild` を実行。

### 4. FAQ

- **`APP_KEY` を後で変更できる？** 原則不可 — 暗号化データが読めなくなります。やむを得ない場合は移行手順書の再暗号化手順に従ってください。
- **パスワードを変えたい？** 新パスワードを再ハッシュして `SUPER_USER_PASSWORD_HASH` を置き換え。
- **なぜ生パスワードを `.env` に保存しない？** `.env` が漏れても、一方向ハッシュは元のパスワードに戻しにくいためです。
- **オンライン bcrypt 生成器は？** 非推奨 — パスワードが外部送信される恐れ。必ずローカル（または自サーバーの一時ファイル）で生成を。

---

## 共有レンタルサーバーへのデプロイ

多くの共有レンタルサーバーは公開ディレクトリ（`public_html`・`htdocs`・`www`）が固定で変更できません。HBP リポジトリ全体をそこに置くと、`core/`・`.env`・`storage/` が Web に露出し、`https://example.com/.env` から DB 認証情報やスーパーユーザーのパスワードハッシュが漏れます。

HBP は2つのデプロイ方式を提供します。**方式B を推奨します。**

### 方式B — 本体を公開ルート外に配置（推奨）

`public/` の中身だけを公開ルートに置き、それ以外はすべて外に置きます。

```
/home/user/
├── hbp-app/                ← core/, storage/, plugins/, themes/, .env（Web到達不可）
└── public_html/            ← public/ の中身のみ
    ├── index.php           ← HBP_BASE_PATH で hbp-app を参照
    ├── .htaccess
    └── admin/
        └── index.php
```

`public/index.php`（および `public/admin/index.php`）の冒頭付近にこの行を追加：

```php
define('HBP_BASE_PATH', '/home/user/hbp-app');
```

または、サーバー環境変数 `HBP_BASE_PATH=/home/user/hbp-app` を設定。

### 方式A — `.htaccess` リライト（Apache / LiteSpeed のみ）

同梱の `deploy/htaccess.root` を公開ルート直下に `.htaccess` として配置します。全リクエストを `public/` へリライトし、`core/`・`storage/`・`.env` への直アクセスを拒否します。

> **重要**：サーバーで `AllowOverride` が有効な場合のみ動作します。無効なら方式B を使ってください。

> **503「Dangerous placement detected」？** 方式A では本体が公開ルート内に入るため、bootstrap の自己防衛が既定で停止します。`https://your-site/.env` が 403/404 を返すことを確認（下記）したうえで、`.env` に `HBP_ALLOW_NESTED_ROOT=true` を設定して停止を解除してください。`.env` がまだ到達可能（200）なら絶対に true にせず、方式B に切り替えてください。

### デプロイ後の確認

いずれの方式でも、次のURLが **403 または 404**（200 ではない）を返すことを確認：

```bash
curl -o /dev/null -s -w "%{http_code}\n" https://example.com/.env
curl -o /dev/null -s -w "%{http_code}\n" https://example.com/core/
curl -o /dev/null -s -w "%{http_code}\n" https://example.com/storage/
```

いずれかが 200 を返したら、ただちに停止してデプロイ方式を見直してください。

手順の詳細は `deploy/README-shared-hosting.md` を参照。

---

## CLIリファレンス

### セットアップ & マイグレーション

| コマンド | 説明 |
|---|---|
| `tool:key` | HMAC・暗号化用の `base64:` エンコード `APP_KEY` を生成 |
| `tool:hash "pw"` | `SUPER_USER_PASSWORD_HASH` 用の bcrypt/argon2id ハッシュを生成 |
| `db:install` | **新規インストール。** `schema.{driver}.sql`（フルスキーマ＋シード）を適用。末尾の baseline INSERT が `V001000000` を記録し、DBは即「最新」状態になる |
| `db:migrate` | **アップグレード（公開後）。** `schema_migrations` に未記録の `V…` のみを昇順・前進専用で適用し、各々を実 SHA-256 で記録 |
| `db:status` | レポート：**DBの版 vs `SCHEMA_VERSION`（コード）vs ドライバ**、未適用マイグレーション一覧＋実行コマンド、checksum 検証（`BASELINE` はスキップ） |

### キャッシュ & ビルド

| コマンド | 説明 |
|---|---|
| `system:init` | 全キャッシュをコンパイル：ルート・メニュー・カテゴリ・タグ・サイドバー・SEO・プラグイン・著者シングルトン・セキュリティヘッダ |
| `plugins:rebuild` | プラグインレジストリ＋優先度ソート済みフックマップを再コンパイル、ルート同期 |
| `lang:rebuild [locale]` | 指定ロケール（または全ロケール）のMUIビューキャッシュを削除し再焼き付けを強制 |
| `assets:rebuild` | テーマCSS/JS編集後、全FPC＋ビューキャッシュをパージし CSP ハッシュを再計算 |
| `authors:rebuild` | `authors.cache.php` を再生成し、影響を受ける記事FPCをパージ |
| `posts:recompile` | 全記事で Markdown → HTML ＋ サニタイザを再実行（サニタイザ規則更新後に使用） |
| `media:gc [--days=N]` | `attachments` のどの行も参照しておらず N 日以上古い `storage/upload/` の孤児ドラフトメディアを回収（既定7日）。`{ab}/{cd}/` シャードを再帰走査 |
| `media:reshard [--dry-run]` | 既存のフラット配置を `{ab}/{cd}/` 2階層へ移行：ファイル移動＋本文/アイキャッチの公開URLをフラット→シャードへ書換し、検索インデックス・一覧FPC・サイトマップ・フィードを再生成。冪等 |
| `cache:purge-category {id}` | あるカテゴリの全記事FPCをパージ（精密クリーンアップ） |
| `config:rebuild` | `env.cache.php` を明示的に再生成 |
| `feed:rebuild` | 全言語の Atom/RSS フィードを再生成（`FEED_ENABLED=true`） |
| `posts:publish-due` | `published_at` が到来した `scheduled` 記事を昇格（cron / 手動トリガー） |
| `schedule:recompile` | `next_schedule.php`（次の擬似cron発火時刻）を再計算 |

> **SSHなし？** **管理画面 → メンテナンス**ページが同じ再生成をブラウザから実行します — sitemap・feeds・plugins・**全キャッシュ**（`system:init` 相当）を再構築。`maintenance:run` 権限（Administrator または Super User、後述）が必要。CLI が使えない共有レンタルに最適。

### セキュリティ操作

| コマンド | 説明 |
|---|---|
| `tfa:reencrypt` | 既存の平文TOTPシークレットを `Crypto::encrypt()` で暗号化 |
| `admin:ip-show` | 現在の管理画面IP許可リスト設定を表示 |
| `admin:ip-disable` | 許可リストを即時クリア（締め出し時の SSH 脱出口） |
| `comment:block-ip {ip}` | IP/CIDR を手動で POST ブロックリストに追加 |
| `comment:unblock-ip {ip}` | IP をブロックリストから削除 |
| `comment:blocklist` | ブロック済みIPを理由・期限・ヒット数つきで一覧 |
| `site:deny-ip {ip}` | IP/CIDR をページ閲覧拒否リストに追加 |
| `site:allow-ip {ip}` | ページ閲覧拒否リストから削除 |
| `site:denylist` | `site_denylist.cache.php` を再コンパイルして表示 |

---

## アーキテクチャ

### 事前コンパイルアーキテクチャ

高コストな計算はすべて**保存時**に行います。フロントエンドのリクエストは何も払いません。

| 対象 | コンパイル契機 | 保存先 |
|---|---|---|
| Markdown → HTML | 記事保存 | `posts.content_html` |
| ルートマップ | プラグイン / 言語 / 設定変更 *（記事保存では発火しない）* | `cache/routes.cache.php` |
| メニュー | メニュー保存 | `cache/menus.cache.php` |
| サイドバー | ウィジェット保存またはトリガー | `cache/sidebar_{loc}_{lang}.cache.php` |
| 著者プロフィール | プロフィール編集または `authors:rebuild` | `cache/authors.cache.php` |
| hreflang リンク | 記事保存（翻訳グループ変更） | `posts.hreflang_cache` |
| CSP ハッシュ | `assets:rebuild` | `cache/security_headers.cache.php` |
| テーマ CSS/JS | ビューコンパイル | ビューキャッシュ HTML にインライン化 |
| サイトマップ | 記事公開 / 非公開 | `public/sitemap.xml`（＋`sitemaps/`） |
| フィード（Atom/RSS） | 記事公開 / 非公開 | `public/feed.atom.xml`、`public/{lang}/feed.rss.xml` |
| 次回スケジュール発火時刻 | 記事保存 / publish-due | `cache/next_schedule.php`（整数1個） |

### オンデマンドFPC

```
GET /post/hello-world
  ↓ キャッシュヒット?
  YES → セキュリティヘッダ送出 → readfile() → exit        ← DBゼロ
  NO  → flock 取得 → DB取得 → レンダ → atomic rename → 送出 → readfile
```

FPC ファイル命名：`{lang}_{type}_{slug}.html` · ページネーション：`{lang}_home_p2.html`

### ルーター：O(1) パターンマッチング

ルートキャッシュは**最小マップ**だけを保持します — プラグイン・特殊エンドポイント・利用可能言語・既定言語。個々の記事やカテゴリは列挙しません。記事保存は `RouterCompiler::compile()` を**発火しません**。

```
URL → 言語プレフィックス除去 → プラグインマップ照合 → パターン照合（post|page|category|tag|archive|home）
                                                       ↓
                                            FPCキー構築 → ヒット? → readfile
                                                                  → ミス: DB + レンダ + 保存
```

### 精密なFPC無効化

`CacheManager::purgePost()` は、変更された記事に依存するものだけを無効化します：

| パージ対象 | 条件 |
|---|---|
| `{lang}_post_{slug}.html` | 常に |
| `{lang}_home.html` + `_p{n}.html` | post タイプ |
| `{lang}_list_category_{cat}.html` + ページ | カテゴリあり |
| `{lang}_list_tag_{tag}.html` + ページ | タグあり |
| `{lang}_list_archive_{YYYY-MM}.html` + ページ | 日付あり |

全再構築なし。TTLなし。古い読み出しは構造的に発生不能。

### 著者シングルトン

```php
// コンパイル時（プロフィール保存 / CLI）
// authors.cache.php → [0 => SuperUser, 3 => YamadaTaro, ...]

// レンダ時（JOINゼロ）
$author = AuthorCompiler::get($post['user_id'] ?? 0);
```

bio を変更すると、次のリクエストが来た瞬間に全記事ページへ反映 — 1記事ずつキャッシュを無効化する必要はありません。

### マルチロケール（2軸）

```
コンテンツ言語 (lang)    → URL構造        → FPCキー
管理画面UI言語 (locale)  → .env / users.locale → ViewCompiler キャッシュディレクトリ
```

既定言語は `/` を占有、他はすべて `/{lang}/`。
言語追加：`core/lang/` に `admin_{code}.php` ＋ `frontend_{code}.php` を置いて `system:init`。コード変更不要。

### 予約公開

記事は3状態のいずれか — `published`（今）・`draft`・`scheduled`（未来の `published_at`）。`published_at`（UTC）が投稿日時の**単一の真実**で、一覧・関連記事・フィード・sitemap・アーカイブはすべてこれでソート・表示します。初公開時に確定し、再編集では変わりません（`updated_at` が編集を追跡）。

発火方式は `.env` の `SCHEDULE_MODE` で選び、**FPC高速パスには一切触れません**：

```
SCHEDULE_MODE=pseudo  (既定) — index.php が cache/next_schedule.php（整数1個）を読み、
                                time() と1回だけ比較。未来/なし → no-op・DBゼロ。
                                到来 → Scheduler::runDue() が記事を昇格し、
                                通常の公開パイプライン（FPCパージ・フィード・sitemap）へ合流。
SCHEDULE_MODE=cron             — OS cron が `php core/cli.php posts:publish-due` を実行。
SCHEDULE_MODE=manual           — 外部モニタが GET /cron を叩く（例：cron-job.org）。
```

予約記事が保存または発火すると、`Scheduler::recompileNext()` が次の発火タイムスタンプを `cache/next_schedule.php` に焼き付け — ホットパスは整数1回比較のままです。

---

## ロールと権限

HBP は `core/permissions.php` に定義し `core/Gate.php` で強制する**静的・事前コンパイル RBAC** を備えます — チェック時の**DBクエリはゼロ**（事前コンパイルアーキテクチャと一貫）。**管理画面 → ユーザー**で作成したスタッフアカウントには、ちょうど1つのロールを割り当てます。

### ロール

| ロール | 想定 | 概要 |
|---|---|---|
| **Super User** | サイト所有者 | `.env` のみ。`Gate::can()` は常に `true` を返す — 全権限・無条件。DBには保存しない。 |
| **Administrator** | 信頼された運用者 | サイト全運用：ユーザー・設定・プラグイン・IP制御・メンテ・全コンテンツ。 |
| **Editor** | 編集責任者 | 全コンテンツ（任意の著者）・コメントモデレーション・メディア・カテゴリ/タグ。サイト/ユーザー/プラグイン管理は不可。 |
| **Author** | 通常の執筆者 | **自分の**記事を作成・公開・予約・削除。**自分の**メディアを管理。 |
| **Contributor** | ゲスト / 見習い執筆者 | **自分の下書きのみ**作成・編集。公開は不可 — 投稿は Editor/Admin の承認待ち。 |

### 権限マトリクス

各能力は `Gate::can('post:publish')` で確認する権限キーです。`✅ (own)` は、ユーザーが所有するレコード（`posts.user_id`）に限定されることを示します。

| 権限キー | 意味 | Administrator | Editor | Author | Contributor |
|---|---|:--:|:--:|:--:|:--:|
| `post:create` | 記事/ページの新規作成 | ✅ | ✅ | ✅ | ✅ |
| `post:publish` | 公開または予約（`scheduled` + `published_at` 含む） | ✅ | ✅ | ✅ (own) | ❌ |
| `post:edit_other` | 他人所有の記事を編集 | ✅ | ✅ | ❌ | ❌ |
| `post:delete` | 記事の削除 | ✅ | ✅ | ✅ (own) | ✅ (own drafts) |
| `post:raw_html` | 生HTMLをサニタイザに通す（埋め込み） | ✅ | ❌ | ❌ | ❌ |
| `comment:moderate` | コメントの承認 / スパム化 / 削除 | ✅ | ✅ | ❌ | ❌ |
| `media:manage` | メディアのアップロード / 削除 | ✅ | ✅ | ✅ (own) | ❌ |
| `taxonomy:manage` | カテゴリ＆タグの作成 / 編集 / 削除 | ✅ | ✅ | ❌ | ❌ |
| `nav:manage` | ナビメニュー＆サイドバーウィジェットの編集 | ✅ | ❌ | ❌ | ❌ |
| `maintenance:run` | キャッシュ/sitemap/feeds の再構築（管理画面 → メンテナンス） | ✅ | ❌ | ❌ | ❌ |
| `ip:manage` | 管理許可リスト＆公開ブロックリスト（A/B系）の管理 | ✅ | ❌ | ❌ | ❌ |
| `plugin:manage` | プラグインのインストール / 有効化 / 無効化 / 削除 | ✅ | ❌ | ❌ | ❌ |
| `theme:manage` | 公開テーマの切替 / ZIP導入 / 削除 / 再ビルド | ✅ | ❌ | ❌ | ❌ |
| `user:manage` | スタッフアカウントの作成 / 編集 / 削除 | ✅ | ❌ | ❌ | ❌ |
| `site:setting` | グローバルサイト設定の編集 | ✅ | ❌ | ❌ | ❌ |

### 注記

- **単一の真実。** マトリクスが存在する場所は `core/permissions.php` のみ。`Gate::can()` がそれを読みます。DBに対して権限を照会することはありません。
- **所有権。** `✅ (own)` 権限は `posts.user_id`（およびメディアは attachments の所有権）で所有を解決します。Super User のコンテンツは `user_id = NULL`（id `0`）。
- **予約は公開。** 未来の `published_at` の予約（`status='scheduled'`）には `post:publish` が必要で、専用権限はありません。したがって Contributor は予約できません。
- **Contributor のワークフロー。** `post:publish` が無いため Contributor の記事は `draft` のまま。Editor/Administrator がレビューして公開します。
- **ブラウザからのメンテナンス。** **管理画面 → メンテナンス**ページ（SSH不要のキャッシュ再構築）には `maintenance:run` が必要。
- **Super User はロール行ではない。** `.env` で認証され、マトリクスを完全にバイパスします。`users` テーブルには絶対に作成しないこと。

---

## ディレクトリ構成

```
hayabusapress/
├── .env                        # 実行時設定（git管理外）
├── .env.example                # 設定テンプレート
├── tailwind.config.js          # 管理画面CSSビルド用 Tailwind 設定
│
├── deploy/                     # デプロイ補助
│   ├── htaccess.root           # 共有レンタル用ルート .htaccess（方式A）
│   ├── nginx.sample.conf       # Nginx リファレンス設定
│   └── README-shared-hosting.md  # 共有レンタル配置ガイド（方式A/B）
│
├── core/                       # システムカーネル — Web到達不可
│   ├── bootstrap.php           # 環境初期化・危険配置の自己防衛・セッション起動
│   ├── Env.php                 # 独自 .env パーサ（bcrypt安全・parse_ini_file不使用）
│   ├── DB.php                  # PDO シングルトン（MySQL + PostgreSQL）
│   ├── Hash.php                # bcrypt / argon2id 可変コストハッシュ
│   ├── Crypto.php              # APP_KEY ベースの対称暗号（sodium / OpenSSL）
│   ├── Auth.php                # Super User 優先認証 + スロットル + 2FA リプレイガード
│   ├── TwoFactorAuth.php       # ネイティブ TOTP — hash_hmac + pack() のみ
│   ├── Throttle.php            # auth_throttle: 指数バックオフ + ロックアウト
│   ├── Session.php             # マルチバックエンドセッションハンドラ（file/DB/Redis/Memcached）
│   ├── Gate.php                # 静的 RBAC — DBクエリゼロ（Gate::can(); Super User は常に true）
│   ├── Security.php            # CSRF トークン生成 + 検証
│   ├── HtmlSanitizer.php       # DOM 許可リストサニタイザ（ext-dom; ruby/rt/rp + em.class=hbp-bouten 許可）
│   ├── Locale.php              # 言語パックスキャナ + フロント辞書ローダ
│   ├── RouterCompiler.php      # 最小マップルートコンパイラ（O(1)・記事保存では発火しない）
│   ├── ViewCompiler.php        # テンプレート → PHP コンパイラ（MUI焼き込み + アセットインライン）
│   ├── View.php                # オートヒーリング内蔵テンプレートレンダラ
│   ├── AssetCompiler.php       # CSS/JS ミニファイア＆インラインパッカ
│   ├── MarkdownCompiler.php    # Markdown → HTML（保存時・ロール別 raw HTML・ruby/bouten）
│   ├── CacheManager.php        # 精密FPC無効化（記事 + ページネーション派生）
│   ├── AuthorCompiler.php      # 著者シングルトン（authors.cache.php）
│   ├── TranslationCompiler.php # 翻訳グループの hreflang_cache 同期
│   ├── SecurityHeaderCompiler.php  # CSP + HSTS + 補助ヘッダ（事前コンパイル）
│   ├── MenuCompiler.php        # 6ロケーションナビHTMLコンパイラ
│   ├── SidebarCompiler.php     # サイドバーウィジェットHTMLコンパイラ（言語別）
│   ├── CommentCompiler.php     # 承認済みコメントキャッシュ + FPC + サイドバー同期
│   ├── SeoCompiler.php         # SEO/解析タグコンパイラ
│   ├── SiteFileCompiler.php    # sitemap.xml（インデックス + 分割）/ robots.txt 生成
│   ├── FeedCompiler.php        # Atom 1.0 / RSS 2.0 言語別フィード生成
│   ├── Scheduler.php           # 予約公開：runDue + next_schedule.php
│   ├── CategoryCompiler.php    # カテゴリメタデータキャッシュ
│   ├── TagCompiler.php         # タグメタデータキャッシュ
│   ├── PluginManager.php       # プラグインレジストリ + 優先度ソート済みフックマップコンパイラ
│   ├── PluginInstaller.php     # zip-slip 安全 ZIP 展開（+ Ed25519 署名検証）
│   ├── AccessControl.php       # クライアントIP解決（信頼プロキシ対応） + IP許可リスト
│   ├── IpBlocklist.php         # blocked_ips テーブル：POST型スパムIP管理
│   ├── SiteDenylistCompiler.php  # コンパイル済みページ閲覧拒否リスト（チェック時DBゼロ）
│   ├── Schema.php              # M系: SCHEMA_VERSION（コードが期待するスキーマ版）
│   ├── Migrator.php            # スキーマ移行ランナー（db:install / db:migrate / db:status; baseline V001000000）
│   ├── Installer.php           # 組み込みインストーラ（隠蔽型・.env 不在時のみ起動）
│   ├── AdminPostController.php # 記事保存オーケストレータ
│   ├── AdminUserController.php # スタッフ + 2FA 管理
│   ├── permissions.php         # 静的 RBAC マトリクス（15権限 × 4ロール）
│   ├── cli.php                 # CLI エントリポイント
│   │
│   ├── controllers/
│   │   └── CommentController.php   # コメント受付：IpBlocklist → フックパイプライン → 二重ロック → DB
│   ├── commands/
│   │   ├── AssetsRebuild.php
│   │   ├── LangRebuild.php
│   │   └── PluginsRebuild.php
│   ├── database/
│   │   ├── schema.mysql.sql        # フルスキーマ（末尾に baseline V001000000 INSERT）
│   │   ├── schema.pgsql.sql
│   │   └── migrations/             # 公開後の差分のみ（V<major><minor><patch>__*.{driver}.sql）
│   ├── lang/
│   │   ├── admin_ja.php            # 管理画面UI — 日本語
│   │   ├── admin_en.php            # 管理画面UI — 英語
│   │   ├── frontend_ja.php         # 公開テーマUI — 日本語
│   │   └── frontend_en.php         # 公開テーマUI — 英語
│   ├── admin_views/                # 管理画面テンプレート（Tailwind + Lucide インラインSVG）
│   │   ├── admin.src.css           # Tailwind ソース（ビルド → public/admin/assets/admin.css）
│   │   ├── login.tpl.php
│   │   ├── two_factor.tpl.php
│   │   ├── index.tpl.php           # 記事一覧
│   │   ├── edit.tpl.php            # 記事/固定ページエディタ
│   │   ├── profile.tpl.php         # プロフィール + 2FAセットアップ + ロケール選択
│   │   ├── users.tpl.php
│   │   ├── comments.tpl.php
│   │   ├── menu.tpl.php
│   │   ├── sidebar.tpl.php
│   │   ├── categories.tpl.php
│   │   ├── tags.tpl.php
│   │   ├── plugins.tpl.php
│   │   ├── seo.tpl.php
│   │   └── badwords.tpl.php
│
├── themes/
│   └── default/                    # 公開テーマ（手書きCSS・フレームワークなし）
│       ├── header.tpl.php          # hreflangタグ出力 + 言語スイッチャー
│       ├── footer.tpl.php
│       ├── index.tpl.php           # トップ / 一覧ページ
│       ├── post.tpl.php            # 記事詳細（著者サイドバー・関連記事）
│       ├── page.tpl.php            # 固定ページ
│       ├── category.tpl.php
│       ├── tag.tpl.php
│       ├── archive.tpl.php
│       ├── search.tpl.php
│       ├── 404.tpl.php
│       └── assets/
│           ├── style.css           # {# inline_css 'style.css' #} → FPCに焼き込み
│           └── app.js              # {# inline_js 'app.js' #}  → FPCに焼き込み
│
├── plugins/                        # プラグインディレクトリ（Web到達不可）
│   ├── hbp-helloworld/             # 同梱：最小ルートプラグインのチュートリアル
│   ├── hbp-contact/                # 同梱：問い合わせフォーム（DB INSERT なし・直接メール）
│   ├── hbp-antispam/               # 同梱：ハニーポット + DNS-BL + 外部API（3層）
│   ├── hbp-badwords/               # 同梱：コメント禁止語フィルタ
│   ├── sample-related-posts/       # 同梱：関連記事の実装サンプル
│   └── DEVELOPER.md                # プラグインAPIガイド：3つの鉄則・フックプロトコル・マニフェスト仕様
│
├── storage/                        # 非公開領域 — Webサーバー設定で全拒否
│   ├── upload/                     # 下書きメディア（Web到達不可）
│   ├── logs/                       # spam.log, csp.log, plugin-audit.log
│   ├── comments/                   # {post_id}.cache.php — コンパイル済み承認コメント
│   └── cache/
│       ├── routes.cache.php
│       ├── plugins.cache.php
│       ├── hooks.cache.php
│       ├── menus.cache.php
│       ├── categories.cache.php
│       ├── tags.cache.php
│       ├── authors.cache.php       # 著者シングルトン
│       ├── security_headers.cache.php
│       ├── site_denylist.cache.php
│       ├── seo_head.cache.php
│       ├── seo_global.cache.php
│       ├── two_factor_global.cache.php
│       ├── next_schedule.php       # 次回予約発火時刻（整数1個）
│       ├── sidebar_{location}_{lang}.cache.php
│       ├── widget_archive_{lang}.cache.php
│       ├── widget_comments_{lang}.cache.php
│       ├── pages/                  # FPC: {lang}_{type}_{slug}.html（+ _p{n} 派生）
│       │   └── .locks/             # スタンピードロックファイル
│       └── views/                  # MUI焼き込み済みビューキャッシュ
│           ├── admin/{locale}/{md5}.cache.php
│           └── frontend/{locale}/{md5}.cache.php
│
└── public/                         # Webサーバーのドキュメントルート
    ├── index.php                   # フロントエンドエントリポイント（HBP_BASE_PATH 対応）
    ├── sitemap.xml                 # サイトマップインデックス（小規模なら単一ファイル）
    ├── sitemaps/                   # 分割サブサイトマップ（posts-1.xml, pages.xml, …）
    ├── robots.txt
    ├── .htaccess                   # Apache リライト規則（クリーンURL）
    ├── comment_post.php
    ├── assets/
    │   ├── site/avatars/           # 著者アバター（常時公開）。統一アップロードAPIで {ab}/{cd}/ シャード化＋EXIF除去。スーパーユーザーのアバターもここ — SUPER_USER_AVATAR はファイル名のみ指定
    │   ├── site/avatar-default.svg # 同梱コメント者アバターのシルエット（fill="currentColor"）。外部Gravatar不使用
    │   ├── articles/               # 公開済み記事メディア（公開時に storage から移動）
    │   └── theme/                  # 外部CSS（CSS_DELIVERY=external モード）
    └── admin/
        ├── index.php               # 管理画面エントリポイント（HBP_BASE_PATH 対応）
        └── assets/                 # 管理画面静的アセット（Tailwind ローカルビルド）
            └── admin.css           # ビルド: ./tailwindcss -i core/admin_views/admin.src.css -o ...
```

---

## データベーススキーマ

### スキーマバージョニング & マイグレーション（M系）

HBP はスキーマ状態を `schema_migrations` テーブルで追跡し、システムが常に**あるDBが正確にどのスキーマ版にいるか** — したがって（あれば）何をまだ適用すべきか — を把握できるようにします。単一の真実は、3つの仕組みで確立されます。

#### バージョン識別子の形式

バージョンは `V` ＋ ゼロ詰めの **major(3)·minor(3)·patch(3)** = 固定幅10文字の文字列で、辞書順がそのまま意味的バージョン順になります：

| セマンティックバージョン | 移行ID |
|---|---|
| 1.0.0 | `V001000000` |
| 1.2.5 | `V001002005` |
| 2.0.0 | `V002000000` |

#### baseline：フルスキーマのみ・1.0.0 固定

**1.0.0 はフルスキーマファイル（`schema.mysql.sql` / `schema.pgsql.sql`）だけを配布し、差分マイグレーションを持ちません。** 最初のリリースであり、前進させるべき旧版のDBが存在しないためです。baseline は **`V001000000`（version 1.0.0）固定**。各フルスキーマファイルの末尾に、この行を記録する **baseline INSERT** が入っています：

```sql
-- schema.{driver}.sql の末尾
INSERT ... INTO schema_migrations (version, name, checksum, applied_at)
  VALUES ('V001000000', 'baseline: full schema (HBP 1.0.0)', 'BASELINE', <now>);
```

- `checksum = 'BASELINE'` は「この行は差分マイグレーションではなくフルスキーマ由来」を示す番兵値 — checksum 検証は `BASELINE` 行をスキップします。
- `db:install` はこの INSERT を**含めて**フルスキーマを適用するため、新規DBは即座に `V001000000` = 完全に最新と報告します。`db:migrate` は正しく**未適用なし**を返します（既存カラムを再 `ADD` しようとして事故ることがない）。

#### 3つの保証

| 仕組み | 役割 |
|---|---|
| 各フルスキーマの **baseline INSERT** | 新規DBが自身の版（`V001000000`）をスキーマと原子的に記録 — 「不明な版」の隙間が生じない。 |
| **`SCHEMA_VERSION` 定数**（`core/Schema.php`） | *コード*が期待する版。起動時/CLI で最新適用行と比較し、不一致（コード vs DB ドリフト）を警告。 |
| **`db:status` レポート** | DBの版・コードが期待する版・ドライバ・未適用マイグレーション（実行すべきコマンド付き）を表示 — 「どこまで移行すればよいか」が一目で分かる。 |

#### 1.0.1 以降の差分マイグレーション

スキーマ変更を伴うリリースでは、差分ファイルを `migrations/` 配下に **`V<major><minor><patch>__description.<driver>.sql`**（例：`V001001000__add_x.mysql.sql`）の名前で追加し、`V…` の昇順・前進専用で適用します。`db:migrate` は `schema_migrations` に無い `V…` を適用し、各々をファイルの実 SHA-256 で記録します（後の改竄を検知可能）。固定 `V001000000` baseline は下限として据え置かれます。

> これは HBP の憲法の範囲内に収まります：純PHP + PDO、生SQL、外部ツールゼロ（Flyway/Liquibase なし）。新規インストールの正規経路は常にフルスキーマファイルです。

### テーブル一覧

| # | テーブル | 用途 |
|---|---|---|
| 1 | `users` | スタッフアカウント（Super User は `.env` のみ） |
| 2 | `categories` | カテゴリ（`type` = post \| page、`lang` カラム） |
| 3 | `tags` | タグ（`type` = post \| page、`lang` カラム） |
| 4 | `posts` | 全コンテンツ — 記事と固定ページを `type` で統合 |
| 5 | `post_tags` | タグ↔記事 中間テーブル |
| 6 | `attachments` | メディアファイル（ハッシュ化ファイル名）。**公開/非公開の移動と `media:gc` の正** |
| 7 | `sessions` | DBセッション保存 |
| 8 | `comments` | 承認ワークフロー付きコメント（`author_email` は NULL 許容＝メールは任意） |
| 9 | `navigation_menus` | 6ロケーションのナビゲーション項目 |
| 10 | `sidebar_widgets` | サイドバーウィジェット構成 |
| 11 | `auth_throttle` | ログイン / 2FA レート制限 |
| 12 | `blocked_ips` | POST型スパムIPブロックリスト |
| 13 | `schema_migrations` | checksum 検証付きマイグレーション追跡 |

### `posts` テーブルの主要カラム

| カラム | 用途 |
|---|---|
| `type` | `post` または `page` |
| `lang` | コンテンツ言語コード（`ja`、`en`、…） |
| `status` | `published` / `draft` / `scheduled` |
| `published_at` | 投稿日時 — 単一の真実（UTC）。公開時 `NOW()`、予約時は未来時刻、下書きは `NULL`。全ソート/表示を駆動 |
| `content_html` | 保存時に Markdown からコンパイルされたサニタイズ済み HTML |
| `tags_cache` | JSON — レンダ時の `post_tags` JOIN を不要にする |
| `trans_group` | 翻訳兄弟を連結する UUID |
| `hreflang_cache` | 兄弟URLの JSON、保存時に焼き付け |
| `featured_image` | アイキャッチ相対パス。`NULL` ならフォールバック（本文先頭画像 → サイトロゴ）を**保存時に解決**。`og:image` を駆動 |
| `featured_image_alt` | アイキャッチ代替テキスト（a11y/SEO） |
| `user_id` | `NULL` = Super User。`ON DELETE RESTRICT` — スタッフ削除前に再割り当てが必要 |

> インデックス `idx_posts_schedule (status, published_at)` が `WHERE status='scheduled' AND published_at <= NOW()` の検索を駆動。全一覧インデックスは `published_at DESC` でソート。

---

## セキュリティ

| 脅威 | 防御 |
|---|---|
| SQLインジェクション | パラメータ化 PDO クエリ（ORMなし・文字列補間なし） |
| 蓄積型XSS | 保存時の DOM 許可リストサニタイザ（`ext-dom`）＋ テンプレートの `{{ }}` 自動エスケープ。ルビ/傍点記法は属性値を許可リスト化したうえで `<ruby>`/`<em class=hbp-bouten>` にコンパイル |
| CSRF | **すべて**の管理 POST フォームに `Security::csrfField()` — ログイン・2FA 画面も含む（ソフト検証：トークン不一致はハード403ではなくフォーム再描画）＋ `SameSite=Lax` Cookie |
| 入力バリデーション | 全管理保存パスにスキーマ制約由来のサーバー側検証：列挙値の許可リスト（`type`・`status`・`role`・メニュー/サイドバーのロケーション・ウィジェットキー）、`VARCHAR(n)` の長さ上限、`NOT NULL` 必須項目、`UNIQUE` 衝突を 500 にせず捕捉。メニュー/SNS の URL は `http(s)`/相対のみ許可（`javascript:` 排除）、メールは形式検証 |
| ログイン総当たり | `auth_throttle`：IP単位＋アカウント単位の指数バックオフ。UIにロックアウトのカウントダウンを表示 |
| 2FA リプレイ | `users.tfa_last_step` が同一 time-step の再利用を防止（RFC 6238） |
| 2FA 総当たり | 5回失敗で仮認証セッションを破棄 |
| セッション固定化 | ログイン成功ごとに `session_regenerate_id(true)` |
| タイミング攻撃 | ユーザー不在時はダミーハッシュ検証で応答時間を一定化 |
| パストラバーサル（メディア） | 全アップロードに `basename()` ＋ 拡張子許可リスト |
| パストラバーサル（プラグインZIP） | `PluginInstaller` が全エントリを検証 — `../`・絶対パス・シンボリックリンク・zip爆弾を拒否 |
| コメントスパム | ハニーポット項目 ＋ `before_comment_create` フックパイプライン ＋ IPブロックリスト |
| CSPバイパス | 事前コンパイルのハッシュベース CSP；`report-only` → `enforce` の段階的ロールアウト |
| 管理画面の発見 | `.env` の `ADMIN_PATH` でログインURLを秘匿 — 任意の値が有効（フロントコントローラルーティング）。物理ディレクトリ名は裏口として露出しない |
| 管理アクセス（任意） | `ADMIN_IP_ALLOWLIST` ＋ `REQUIRE_ADMIN_2FA` |
| 下書きメディア漏洩 | `storage/upload/` — Webサーバーが全拒否、PHP仲介配信のみ。アップロードも同じ隔離 ＋ `media:manage` ゲートに従う。**非公開化でメディアを `storage/upload/` へ逆移動**し、非公開記事のメディアが Web到達不可になる |
| `.env` 漏洩（共有レンタル） | 方式B（本体を公開ルート外）/ 方式A（`.htaccess`）＋ bootstrap 自己防衛 ＋ インストーラ自己診断 |
| XML-RPC 攻撃 | ファイルが存在しない — 一行も書いていない |
| 権限昇格 | 静的 RBAC（`Gate::can()`・`permissions.php`） — 15権限 × 4ロール；所有権スコープのチェック；Super User は `.env` のみ |
| Super User の DB 露出 | Super User は `.env` のみ；DB侵害でルート認証情報は得られない |

### コンテンツセキュリティポリシー（CSP）

HBP は FPC 互換の**事前コンパイル・ハッシュベース CSP**（リクエストごとの nonce 生成なし）を使います。

```ini
# .env — 段階的ロールアウトを推奨
CSP_MODE=report-only   # ステップ1: /csp-report → storage/logs/csp.log で違反を監視
CSP_MODE=enforce       # ステップ2: ログがクリーンになってから
```

テーマ CSS/JS 変更後：

```bash
php core/cli.php assets:rebuild   # SHA-256 ハッシュを再計算 ＋ FPC をパージ
```

### 管理画面CSS（本番）

本番では、厳格な `style-src 'self'` CSP のために Tailwind をローカルビルドします：

```bash
./tailwindcss -i core/admin_views/admin.src.css -o public/admin/assets/admin.css --minify
```

このファイルが無い場合、管理画面は Tailwind Play CDN（開発用のみ）にフォールバックします。

---

## プラグイン開発

### マニフェスト（`manifest.json`）

```json
{
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "Does something useful.",
  "license": "AGPL-3.0-or-later",
  "status": "enabled",
  "hooks": {
    "before_comment_create": {
      "file": "plugins/my-plugin/plugin.php",
      "class": "MyPlugin",
      "method": "checkComment",
      "priority": 10
    }
  }
}
```

### 3つの鉄則

1. **実行時I/Oスキャン禁止** — `glob()` / `scandir()` をリクエストごとに走らせない。発見はすべてコンパイル時（`plugins:rebuild`）。
2. **設定のためのDBクエリ禁止** — `.env` または静的キャッシュのみから読む。
3. **グローバル状態・コアパッチ禁止** — 自分のプラグインディレクトリと宣言したURI空間の中だけで動作する。

### フックプロトコル（`&$context` 参照渡し）

```php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

class MyPlugin
{
    // コアは参照を渡す — プラグインは横取り（interceptor）もフィルタもできる
    public static function checkComment(array &$context): void
    {
        $ip = $context['ip_address'];
        if (isSpam($ip)) {
            http_response_code(403);
            exit;  // Interceptor: DB に触れる前に中断
        }
        // Filter: コンテキストを書き換えて次のフックへ渡す
        $context['post_data']['content'] = censor($context['post_data']['content']);
    }
}
```

### 利用可能なフック

| フック | 発火タイミング |
|---|---|
| `before_comment_create` | コメントコントローラ、DB INSERT 前 |
| `before_contact_send` | 問い合わせフォームプラグイン、`mb_send_mail()` 前 |

### 同梱プラグイン

| プラグイン | ライセンス | 説明 |
|---|---|---|
| `hbp-helloworld` | AGPL-3.0-or-later | 最小ルートプラグイン — 3分チュートリアル兼スキャフォールド |
| `hbp-contact` | AGPL-3.0-or-later | 問い合わせフォーム：DB INSERT なし、Super User メールへ直接 `mb_send_mail()` |
| `hbp-antispam` | AGPL-3.0-or-later | 3層防御：ハニーポット → DNS-BL（UDP・タイムアウト制御・fail-open）→ 外部API |
| `hbp-badwords` | AGPL-3.0-or-later | `before_comment_create` と `before_contact_send` にフックする禁止語フィルタ |
| `sample-related-posts` | AGPL-3.0-or-later | 関連記事の実装サンプル（FPCフック統合のデモ） |

プラグインのインストール・切り替え後：

```bash
php core/cli.php plugins:rebuild
```

---

## テンプレート構文

HBP は独自の軽量テンプレート構文をプレーンPHPにコンパイルします — 外部エンジンなし。

| 構文 | コンパイル結果 |
|---|---|
| `{{ $var }}` | `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` |
| `{{{ $var }}}` | `echo $var`（生HTML出力） |
| `{@ key @}` | 言語文字列をコンパイル時に焼き込み — 実行時ルックアップゼロ |
| `{% if $cond %}` … `{% endif %}` | `if / endif` |
| `{% foreach $arr as $v %}` … `{% endforeach %}` | `foreach / endforeach` |
| `{% include 'name' %}` | `View::render('name', get_defined_vars())` |
| `{# inline_css 'file.css' #}` | `<style>` ＋ ミニファイ済みCSS |
| `{# inline_js 'file.js' #}` | `<script>` ＋ ミニファイ済みJS |

`{@ key @}` の MUI システムは、`ViewCompiler::compile()` 時に言語文字列をコンパイル済みPHPキャッシュへ直接焼き込みます。レンダ時にはテンプレートは既に翻訳済み — 配列ルックアップも関数呼び出しもありません。

---

## フリガナ（ルビ）& 傍点

日本語の小説や散文向けに、HBP は日本の小説投稿サイト（カクヨム・小説家になろう）で普及した記法を使って**ルビ（フリガナ）**と**傍点（圏点）**をサポートします。記法は `MarkdownCompiler::compile()` 内で**保存時に1回だけ HTML へコンパイル**され `posts.content_html` に焼き付けられます — FPC高速パスでの**実行時コストはゼロ**。記事・固定ページの**両方**に適用されます（エディタは統合されており、`type` は post か page かを選ぶだけで本文パイプラインは同一）。

### 入力記法

| 目的 | 記法 | 備考 |
|---|---|---|
| ルビ（明示ベース） | `｜漢字《かんじ》` | `｜`（全角）または `\|`（半角）がベース文字の開始を示す |
| ルビ（自動ベース） | `漢字《かんじ》` | `｜` を省くと、直前の漢字連続をベースに採る（なろう互換） |
| 傍点（圏点） | `《《文字》》` | 二重山括弧で囲んだ文字の上に傍点を付ける |
| リテラルの `《` | `\《` | バックスラッシュエスケープでリテラルの括弧を出力 |

**解析順（重要）：** まずコードスパン/ブロックを除外 → `《《 》》`（傍点）→ `《 》`（ルビ）。傍点をルビより先に照合するため、二重括弧形がルビと誤読されることはありません。フェンス付きコード/インラインコード内の記法は無加工のまま。

### コンパイル結果のHTML

```html
<!-- ｜漢字《かんじ》 -->
<ruby>漢字<rp>(</rp><rt>かんじ</rt><rp>)</rp></ruby>

<!-- 《《文字》》 -->
<em class="hbp-bouten">文字</em>
```

- `<rp>` の括弧は、ルビ非対応リーダーでの優雅なフォールバックを提供します。
- 傍点は**インライン `style` ではなくクラス**を使います — インライン `style` はサニタイザ（蓄積型XSS防御）で除去され、CSP とも衝突するためです。見た目はテーマCSSが供給します。

### サニタイザ許可リスト（必須）

`HtmlSanitizer` は生成された記法を許可する必要があります。さもないと除去されます：

- 許可タグ：`ruby`、`rt`、`rp`（テキストのみ、属性なし）
- `em` は `class` 属性を持てますが、**値は許可リスト化**（`hbp-bouten` のみ）— 任意のクラス値は拒否され、クラスベースの注入経路を塞ぎます。

この「タグは許可、属性は厳格に制限」の規則が、この機能を XSS 防御と両立させます。結果は `content_html` に凍結されるため、規則変更後は `posts:recompile` で記法を再適用します。

### テーマCSS

```css
.hbp-bouten {
  font-style: normal;                 /* italic にしない */
  text-emphasis: filled sesame;       /* ゴマ点。丸点は 'filled dot' */
  -webkit-text-emphasis: filled sesame;
  text-emphasis-position: over right; /* 縦書きテーマは 'right' でも可 */
}
rt { font-size: 0.5em; }              /* ルビ文字サイズ・テーマで調整可 */
```

### エディタ & プレビュー（記事・固定ページ）

- **新規作成・編集画面**（`type = post | page` 共通の統合エディタ）には、選択範囲を囲む **ルビ** と **傍点** ボタン（`｜sel《》` / `《《sel》》`）と短いインラインヘルプを備えたツールバーが付きます。
- **ライブプレビュー**は**同じ** `MarkdownCompiler::compile()`（プレビューエンドポイント経由）を走らせるので、表示が保存後の出力と完全一致します — クライアント側の別のルビパーサがズレることはありません。プレビューペインはテーマの `.hbp-bouten` / `rt` CSS を読み込み、公開ページと同一に見えます。

### 公開ページ

公開された記事/ページは、`content_html` に既に焼き付けられた `<ruby>` / `<em class="hbp-bouten">` をそのままレンダします — 実行時処理なし。見た目はテーマCSSが供給します。

### スコープと制限

- ネスト（例：ルビのベース内に傍点）は**非対応** — 設計上シンプルに保ちます。
- フィードでは：全文フィードを有効化した場合（`FEED_FULL_CONTENT=true`）、`<ruby>` は含まれ多くのリーダーで表示されますが、**傍点は CSS 依存のため、フィードリーダーでは通常点が表示されません** — 既知の制限として受け入れます。

---

## 記事エディタ

新規作成・編集画面は**1つの統合エディタ**を共有します。`type`（post | page）はコンテンツ種別を選びますが、本文パイプラインは同一です。設計は明確な哲学に従います：**プレーンテキストを入れる、驚きなし。**

### タイトル / 本文の分離

タイトルと本文は**別々の入力欄**で、別々のカラム（`posts.title`・`posts.content_markdown`）に永続化されます。これによりデータモデルがクリーンに保たれ、テンプレートがセマンティクスを所有できます：

- **タイトル**欄はページの `<title>` と唯一の `<h1>` としてレンダされます。
- **本文**は `##`（`<h2>`）から始まる Markdown で書くため、`<h1>` が複数になることはありません — 構造的にSEO正しい。
- タイトル入力は大きく太字（≈24–30px）で公開後の `<h1>` を反映。タイトルで **Enter** を押すと本文へフォーカス移動（フォーム送信はしない）。

### プレーンテキスト本文 + 分離ライブプレビュー

HBP はあえて note/Notion 風のその場リッチ変換（`##` を打つと即座にテキストが変形する類）を**使いません**。本文は**等幅で視覚的に安定したプレーンテキスト領域**のまま — 入力中に下で再フローしません。**別のプレビューペイン**が結果をレンダし、任意で**スクロール同期**も可能です。

- プレビューは**同じサーバー側 `MarkdownCompiler::compile()`**（プレビューエンドポイント経由）を呼ぶ — 保存される出力と同一で、ルビ/傍点も含む。ズレるクライアントパーサなし。
- これは HBP の透明性原則に合致します：著者は常に自分が書いた Markdown を正確に見ます。

### レイアウト（PC）

3カラムの「コックピット」：折りたたみ可能な左ナビ（240px ↔ 60px）· 中央エディタ（~720px に制約し、公開本文幅に合わせて改行を正確にプレビュー）· 開閉する右設定ドロワー（ステータス・予約・カテゴリ/タグ・アイキャッチ・SEO）。タブレットでは左ナビがアイコンに、スマホではナビがドロワー・設定パネルがボトムシートになり、書式ツールバーがソフトキーボードの上に浮きます。

### 画像ドラッグ&ドロップ

本文に画像をドロップすると：

1. `POST /admin/api/upload`（`media:manage` ＋ CSRF でガード）へ非同期アップロード。サーバーは拡張子許可リストを検証し、ハッシュ化ファイル名（`bin2hex(random_bytes(16)).ext`）を生成、`storage/upload/`（Web到達不可）に保存し、**`attachments` に行を記録**（正 — メディアライフサイクル参照）。
2. カーソル位置に**本番URL** `![alt](/assets/articles/{file})` を挿入。**DBに保存される Markdown は常に本番URLを持ち**、公開時に書き換えられません。
3. 最初にアップロードした画像をアイキャッチ候補として提示（右ドロワー）。

> 本文が常に本番パスを持つため、公開時に記事テキストへ触れる必要は一切なく、ファイルが移動するだけです。これにより `content_html`（ひいては FPC）が安定します。

### 下書き画像のライブプレビュー

アップロード直後の画像はまだ `storage/upload/` にあり（`public/` 配下ではない）、本番URLはプレビューで 404 になります。そこで**プレビューコンパイラ（管理画面専用）**が、未公開ファイルについて `/assets/articles/{file}` → `/admin/media/draft-image?file={file}` に書き換え、その管理専用エンドポイントが `storage/upload/` から `readfile()` します。この書き換えは**管理プレビュー経路でのみ**行われ — 公開FPC高速パスは一切関与せず、DBの Markdown もクリーンなままです。

### オートセーブ

下書きは定期的に自動保存されます（サーバー側 `status='draft'`、加えて任意でブラウザのローカルストレージを一時バッファに）。保存エンドポイントは CSP `connect-src` で許可されている必要があります。

### 添付画像の一覧・削除

本文テキストエリアの**直下**に、**いま編集中のコンテンツに添付された画像の一覧**を表示します。一覧の正は `attachments`（この `post_id` の行）。本文 Markdown から `![]()` を手で消しただけではファイルと `attachments` 行が残るため、能動的に消す手段をエディタに用意します（`media:gc` の自動回収を待たずに削除できる）。

- **表示：** サムネイル＋ファイル名。下書き中の画像は管理専用ルート（`storage/upload/`）、公開済みは `public/`（シャードパスは `shardPath()` で解決）から表示。スマホはグリッド。
- **本文へ挿入：** カーソル位置へ `![alt](/assets/articles/{ab}/{cd}/{file})` を挿入し、再アップロードなしで使い回す。
- **物理削除（確認必須）：** `attachments` 行＋実ファイル（`storage`/`public`・シャードパス）を物理削除。実行前に**確認ダイアログ必須**。削除対象が**本文中で参照されている**場合（`content_markdown` をクライアント走査）は**警告を強めて**から確認する。本文の `![]()` は**自動書き換えしない**（リンク切れ除去はユーザー手動・安全側）。API は `POST /admin/api/media/delete`、`media:manage` ＋ CSRF、`post:edit_other` 無しなら自分のコンテンツのみ。
- **`media:gc` との関係：** 手動削除は即時、放置された孤児は `media:gc` が後日回収する二段構え。どちらも `attachments` を正とする。

### 文字数カウント

タイトル入力欄の**左上**と本文入力欄の**左上**に、現在の文字数をリアルタイム表示します。

- **数え方：** **コードポイント単位**（`[...str].length`）で1文字を数えるため、和文（マルチバイト）も半角も等しく1文字。`str.length`（UTF-16 単位・絵文字を2と数える）は使いません。クライアント側のみで計算し、サーバー負荷・FPC への影響はゼロ。
- **タイトルのハード上限：** `posts.title` は `VARCHAR(255)`。255 コードポイントをハード上限（`maxlength`）とし、230 超で注意色・255 で警告色。
- **本文のソフトガイド：** ハード上限なし。代わりに推奨レンジの目安（〜1,000=薄め／2,000〜3,000=標準／3,000〜5,000=網羅）を表示。これは制限ではなくガイド。

---

## メディアライフサイクル

メディアは、記事のステータスに歩調を合わせて、Web到達不可の下書き領域と公開領域の間を移動します。記事にどのファイルが属するかの**単一の真実は `attachments` テーブル**で、`content_markdown` への本文正規表現は補助的な相互チェックにのみ使います。

### 真実の源

- アップロードされた各ファイルには `attachments` 行（`post_id`、`filename`、…）があります。公開/非公開の移動は、本文の解析ではなく**その記事の `attachments` を問い合わせて**駆動します。本文編集・改竄・コピペに対して堅牢です。
- 補助の正規表現（`!\[..\]\(/assets/articles/([a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp|gif))\)`）が、参照ファイルが網羅されているかを検証することはありますが、テーブルを上書きすることはありません。

### 公開時（`draft`/`scheduled` → `published`）

現在 `storage/upload/` にある各 `attachments` ファイルを、`public/assets/articles/` へ**原子的に `rename()`**（同一パーティション → O(1)・壊れた中間状態なし）。冪等：すでに公開済みのファイルはスキップ（`file_exists` ガード）。アイキャッチのフォールバック（本文先頭画像 → サイトロゴ）は**移動前に解決**され、焼き付けたパスが最終的な公開位置と一致します。

### 非公開化時（`published` → `draft`/`scheduled`）

厳格な物理隔離を保つため、逆方向の移動を行います：`public/assets/articles/` にある記事の各 `attachments` ファイルを、`storage/upload/` へ**原子的に `rename()`** で戻します。これにより、非公開記事に属するメディアが Web到達可能になることは決してありません。（共有ファイルは `attachments` により記事ごとに所有されます。他の*公開中*記事が参照していない場合にのみ戻されます。）

### 孤児ガベージコレクション（`media:gc`）

下書きファイルは孤児になり得ます（アップロード後、公開前に本文から削除した／放棄した下書き）。`media:gc [--days=N]` は、`storage/upload/` 内で**どの `attachments` 行も参照しておらず**、N 日（既定7）以上古いファイルを削除して領域を回収します。`public/assets/articles/` には一切触れず、`attachments` がまだ参照しているファイルも削除しません。 以降は `{ab}/{cd}/` シャード階層（および残存するフラットファイル）を再帰走査し、突合の正は引き続き `attachments` です。

### ディレクトリ階層化と自動確保

3つのメディア親（`storage/upload/`・`public/assets/articles/`・`public/assets/site/avatars/`）はすべて、ファイル名先頭から導出する単一の `shardPath()` で `{ab}/{cd}/` サブディレクトリに分散します（`shardPath("abcd….jpg") → "ab/cd/abcd….jpg"`）。DB はパスを保持せず**ファイル名のみ**を持つため、保存・配信・公開/非公開の atomic 移動・GC・検索インデックスはすべて導出されます。公開URLは `/assets/articles/{ab}/{cd}/{file}` になります。

書き込みの直前に `ensureDir()` が保存先（親＋シャード中間）を再帰作成します（mode は `MEDIA_DIR_MODE`・既定 `0755`）。順序は **ディレクトリ確保 → ファイル保存成功 → DB記録** で、権限・容量の失敗時は明確なエラーを返し、孤児の `attachments` 行を残しません。`php core/cli.php db:status` とブラウザインストーラが3メディア親の作成・書き込み可否を事前検証します。

既存のフラット配置を移行するには `php core/cli.php media:reshard`（まず `--dry-run` 推奨）を実行します。ファイルをシャードへ移動し、記事本文とアイキャッチの公開URLをフラット→シャードへ書き換え、検索インデックス・一覧FPC・サイトマップ・フィードを再生成します。冪等です。

### 順序と原子性

公開シーケンスは固定です：**(1) アイキャッチのフォールバック解決 → (2) `attachments` に従いファイル移動 → (3) 記事行を保存 → (4) 精密FPCパージ**。全ファイル移動は同一パーティションの `rename()`（原子的）。このライフサイクルのいずれも公開FPC高速パスでは走りません。

---

## アイキャッチ画像 & OGP

各記事/ページは**アイキャッチ画像**（`posts.featured_image` ＋ `featured_image_alt`）を持てます。ソーシャルシェアカード（`og:image` / `twitter:image`）と一覧サムネイルに使われます。

### 設定と保存

- **右側の設定ドロワー**で設定：ドラッグ&ドロップアップロード、サムネイルプレビュー、シェアカードの見え方を示す **OGPプレビュー**。
- `featured_image` に相対パスとして保存（汎用の `attachments` テーブルとは別 — アイキャッチは単一・役割特化のスロット）。

### フォールバック連鎖（保存時に解決）

`featured_image` が `NULL` のとき、HBP は**保存時に1回だけ**フォールバックを解決して結果を焼き付けます — リクエスト時に本文を走査することは決してありません（実行時コストゼロの FPC 高速パスを維持）：

1. 記事の**本文先頭画像**、なければ
2. **サイトロゴ**（シェアカードが空にならない妥当な既定）。

### OGP出力

`<meta property="og:image">`・`og:title`・`og:description`・`twitter:card` は、焼き付けた値から `<head>` に出力されます（実行時DBルックアップなし）。事前コンパイルアーキテクチャと一貫しています。

---

## レスポンシブレイアウト & タイポグラフィ

これは**テーマCSSの指針**です（スキーマへの影響なし）。HBP はすでにテーマCSSをインラインパックまたは外部配信しており、本節はその意図を定義します。公開面と管理面は正反対の目標を持ちます — *快適な消費* vs *高密度な制作*。

### 公開サイト（読者向け）

| 観点 | 指針 |
|---|---|
| コンテナ | 最大 1100–1200px・中央寄せ；ヘッダ/ヒーローは100%幅でも可 |
| 本文カラム | 最大 **~720px**（視覚的快適さ；目が短い行を追いやすい） |
| 本文フォント | `clamp(1rem, 1vw + 0.75rem, 1.125rem)` → 16→18px・行間 ~1.8 |
| 見出し | H1 は PC で 32–36px；スマホで 24–28px |
| ブレークポイント | 2つのアンカー（~768px・~1024px）；間は流動的（`%`/`vw`/`clamp()`）— デバイス固定の区切りを避ける |

### 管理画面（運用者向け）

| 観点 | 指針 |
|---|---|
| 幅 | リキッド全幅（画面全体を使う） |
| レイアウト | 固定左ナビ 240px ↔ 折りたたみ 60px；スマホ = 隠しドロワー |
| 密度 | ラベル/テキスト **14px**・表データ 12px — 高い情報密度 |
| エディタ入力 | 本文テキストエリアは快適な執筆のため ~16px を維持 |
| モバイル入力 | **全フォーム入力欄 ≥16px** — 16px 未満だと iOS が自動ズームし操作性を壊す |

流動的タイポグラフィは純CSS — CSP への影響なし。CSS がインラインパックされる場合、既存の `SecurityHeaderCompiler` が再ハッシュします。

---

## favicon

favicon 画像は **`public/assets/site/`**（`/assets/site/…` で配信・アバターと同じ場所）に置きます。`<link rel="icon">` / `<link rel="apple-touch-icon">` タグは各ヘッダーテンプレートで宣言しています：

- 公開テーマ：`themes/default/header.tpl.php`
- 管理画面：`core/admin_views/_layout_head.tpl.php`・`login.tpl.php`・`two_factor.tpl.php`

**これらのテンプレートを確認し、参照されているファイル名に合わせて `public/assets/site/` に画像を配置してください。** 出荷時点でテンプレートが期待するファイルは次の通りです：

```
public/assets/site/favicon-16x16.png
public/assets/site/favicon-32x32.png
public/assets/site/favicon-96x96.png
public/assets/site/apple-touch-icon-180x180.png
```

> 動作確認用として **HayabusaPress の favicon を暫定で同梱**しています — 同じファイル名で自分の画像に差し替える（または上記テンプレートの `<link>` タグをファイル名に合わせて変更する）。テンプレート編集後は `php core/cli.php assets:rebuild`（公開側）/ `php core/cli.php lang:rebuild`（管理側）を実行して、キャッシュ済みビュー・FPC に反映してください。

---

## 多言語化

### URL構造

```
SITE_DEFAULT_LANG=ja の場合

日本語: /post/hello-world             （既定 — プレフィックスなし）
英語:   /en/post/hello-world          （非既定 — /en/ プレフィックス）
```

### 言語の追加

`core/lang/` に2ファイルを置いて `system:init` を実行。コード変更不要。

```
core/lang/
├── admin_ja.php       ← 同梱
├── admin_en.php       ← 同梱
├── frontend_ja.php    ← 同梱
├── frontend_en.php    ← 同梱
├── admin_es.php       ← これを追加するとスペイン語が有効化
└── frontend_es.php    ←
```

### 翻訳グループ & hreflang

エディタで翻訳記事同士を連結（`trans_group` UUID）。すると HBP は：

- 保存時に `posts.hreflang_cache`（兄弟URLの JSON）を生成
- `<head>` に `<link rel="alternate" hreflang="...">` タグを出力
- *存在する*翻訳だけにリンクする可視の言語スイッチャーを表示

`hreflang` タグとスイッチャーリンクは1つの真実を共有 — 設定の重複なし。

---

## 意図的に作らないもの

HBP はあえて次を除外します：

- ORM（Eloquent、Doctrine など）
- Composer パッケージ（バックエンド）
- 重量級テンプレートエンジン（Twig、Smarty、Blade）
- XML-RPC（一行も書かない）
- 一般ユーザー登録（フロントに会員登録なし；RBAC は**スタッフ**アカウントのみを統治）
- リクエストごとの全プラグインロード
- 実行時の Markdown パース
- 外部 2FA ライブラリ（Google2FA など）
- reCAPTCHA / 外部 CAPTCHA
- 公開ページのセッションベース言語選択（FPC非互換）
- WordPress 風の汎用メタテーブル（EAVパターン）

---

---

## ライセンス

HayabusaPress は **[AGPLv3 ＋ プラグイン/テーマ例外規定](./LICENSE)** で提供されるオープンソースソフトウェアです。

```
SPDX-License-Identifier: AGPL-3.0-or-later
```

各ソースファイルはこの SPDX 識別子を持ちます。

### 🟢 本体・公式プラグイン — GNU AGPL v3

本体、公式プラグイン（`hbp-*`・HayabusaPress Official）、公式テーマは、すべて GNU Affero General Public License v3.0 以降で提供されます。

- **ネットワーク・コピーレフト：** AGPLv3 第13条は GPL の「ASPループホール」（サーバー上で動かすだけならソース公開義務が生じない抜け道）を塞ぎます。HayabusaPress を改変してネットワーク越しに利用者へ提供する場合、その改変ソースを利用者に提供する義務が生じます。
- **無改変での利用：** 素のまま自分のサイトを運営する分には、公開義務は発生しません。

### 🔵 サードパーティ製プラグイン・テーマ — ライセンスは自由

標準のフック・API・クラス経由で本体と結合するだけの独立した拡張機能は、**AGPLv3 に従う必要がありません**（例外規定）。

- **ライセンス選択の自由：** MIT、Apache 2.0、GPLv3、独自の商用/プロプライエタリライセンスなど、任意のライセンスで配布・**販売**できます。
- **ソース公開義務なし：** 独立した拡張機能には開示義務が及びません。
- **ブランド表記：** 例外規定は「HayabusaPress Official」の名称や `hbp-` 接頭辞の使用を許諾するものではありません。

> ⚠️ **例外規定の対象は独立した拡張機能のみです。** コアの改変、fork、公式プラグイン/テーマの派生物は、従来どおり AGPLv3 の適用対象です。

### 🟡 商用ライセンス — AGPLv3 が合わない場合

AGPLv3（特に第13条）が想定する運用形態と合わない場合は、著作権者から別途**商用ライセンス**を取得できます。お問い合わせは **support@2bo-velocity.com**へどうぞ。

- **必要になるケース：** コアを改変して変更を公開したくない／製品に組み込んで配布する／改変版をネットワークサービスとして運用する。
- **不要なケース：** 無改変での自サイト運用、およびプロプライエタリなサードパーティ製プラグイン・テーマの開発と販売（上記の例外規定により無償で可能です）。

全文と例外規定の正確な条文は [LICENSE](LICENSE)、解説と FAQ は [hayabusapress.org/ja/docs/legal/license](https://hayabusapress.org/ja/docs/legal/license) を参照してください。

### コントリビューション

プルリクエストを歓迎します。まず **[CONTRIBUTING.ja.md](CONTRIBUTING.ja.md)** をお読みください。HayabusaPress は商用ライセンスも提供しているため、寄稿には明示的なライセンス許諾が伴います。同意いただく内容を同文書に明記しています。

### サードパーティ通知

| ライブラリ | ライセンス | 用途 |
|---|---|---|
| [Tailwind CSS](https://tailwindcss.com/) | MIT | 管理画面CSS |
| [Lucide Icons](https://lucide.dev/) | ISC | 管理画面アイコン（インラインSVG） |
| [marked.js](https://marked.js.org/) *(任意)* | MIT | 管理画面 Markdown ライブプレビュー（CDN・管理画面のみ） |

サードパーティ著作権表示の全文：[COPYING.THIRD-PARTY](COPYING.THIRD-PARTY)
