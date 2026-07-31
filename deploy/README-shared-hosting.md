# HayabusaPress — 共有レンタルサーバー配置手順

HBP は `public/` をドキュメントルートにする設計です。  
VPS・専用サーバーでは Web サーバー設定でドキュメントルートを `public/` に指定するだけです。

**共有レンタルサーバー**（さくらインターネット・ロリポップ・エックスサーバー等）では
公開ディレクトリを変更できない場合があります。そのまま配置すると `core/`・`.env`・`storage/` が
全公開され、`https://example.com/.env` で DB パスワードやパスワードハッシュが漏洩します。

以下の 2 種類の方式から選んでください。**方式B が推奨・最堅牢です。**

---

## 方式B（推奨・最堅牢）: 公開ルート外配置

機密（`core`・`.env`・`storage`・`plugins`・`themes`）を公開ルートの**外**に置き、
`public/` の中身だけを公開領域（`public_html` 等）に置きます。

```
/home/username/
├── hbp-app/               ← Web 非公開（機密）
│   ├── core/
│   ├── storage/
│   ├── plugins/
│   ├── themes/
│   └── .env
└── public_html/           ← Web 公開領域
    ├── index.php          ← HBP_BASE_PATH で hbp-app を参照
    ├── .htaccess
    └── admin/
        └── index.php
```

### 手順

```bash
# 1) 本体を公開ルート外に配置
git clone <repo> ~/hbp-app

# 2) public/ の中身を公開ルートへコピー
cp -r ~/hbp-app/public/* ~/public_html/

# 3) public/index.php と public/admin/index.php の冒頭で HBP_BASE_PATH を定義
#    （またはサーバー環境変数 HBP_BASE_PATH=/home/username/hbp-app を設定）
```

`public/index.php` 冒頭：

```php
define('HBP_BASE_PATH', '/home/username/hbp-app');
// HBP_BASE_PATH 定義後、通常のブートストラップが自動的に本体を参照します
```

---

## 方式A: `.htaccess` による Rewrite（Apache / LiteSpeed 限定）

本体をそのまま公開ルート（`public_html/`）に配置し、
`deploy/htaccess.root` を `public_html/.htaccess` として置きます。

> **注意**: `AllowOverride None` の環境（サーバー側設定）では `.htaccess` が無効です。  
> その場合は必ず**方式B**を選んでください。

```bash
# リポジトリを公開ルートに配置
git clone <repo> ~/public_html/

# deploy/htaccess.root を .htaccess として公開ルート直下に配置
cp ~/public_html/deploy/htaccess.root ~/public_html/.htaccess
```

配置後、必ず以下を確認してください：

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://example.com/.env
# → 403 または 404 であること（200 は漏洩）

curl -s -o /dev/null -w "%{http_code}\n" https://example.com/core/bootstrap.php
# → 403 または 404 であること
```

### ⚠️ 503「Dangerous placement detected」が出る場合

方式Aでは本体が公開ルート内にあるため、bootstrap の危険配置チェックが既定で
503 停止します（方式Aでは正常な配置です）。**上の `curl` で `.env` が 403/404 を返すことを
確認したうえで**、`.env` に次を追加して停止を解除してください：

```dotenv
HBP_ALLOW_NESTED_ROOT=true
```

> `.env` が 200 で読めてしまう（＝ `AllowOverride None` 等で `.htaccess` が無効）場合は、
> このフラグを **true にしてはいけません**。`.env` が公開されたままになります。
> その環境では必ず**方式B（公開ルート外配置）**へ切り替えてください。

---

## 配置後の確認（共通）

インストール完了後、以下の URL が **403 / 404** を返すことを必ず確認してください。
**200 で中身が見えた場合は即座にサイトを停止**し、方式を見直してください。

| URL | 期待するレスポンス |
|-----|------------------|
| `https://example.com/.env` | 403 / 404 |
| `https://example.com/core/` | 403 / 404 |
| `https://example.com/storage/` | 403 / 404 |
| `https://example.com/plugins/` | 403 / 404 |

`.env` が一度でも公開された場合は、以下を再生成してください：

```bash
php core/cli.php tool:key        # APP_KEY を再生成して .env に設定
php core/cli.php tool:hash "新しいパスワード"  # Super User パスワードを再生成
# DB パスワードも MySQL / PostgreSQL 側で変更する
```

---

## 参考リンク

- 仕様書 第3.5節「共有レンタルサーバーへの配置」
- 仕様書 第21.7節「インストーラの公開URL自己診断」
