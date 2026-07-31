[![English](https://img.shields.io/badge/lang-English-blue)](README.md)

# データベースマイグレーション

このディレクトリには、**リリース済みバージョン間のスキーマ差分**を「前進専用マイグレーション」として置く。

## 規約
- ファイル名: `V{nnn}__{説明}.{driver}.sql`
  - 例: `V001__add_something.mysql.sql` / `V001__add_something.pgsql.sql`
- `core/Migrator.php` がバージョン昇順・冪等に適用する。`down`（巻き戻し）は持たない（鉄則）。誤りは修正版を**前方追加**で直す。
- 各 SQL は `IF NOT EXISTS` 等で冪等に書く（MySQL の DDL は非トランザクショナルのため、部分適用が残っても再実行で収束させる）。

## 現在の差分

**1.0.0 時点では差分ファイルは無い（このディレクトリはこの README だけ）。** `db:migrate` は「未適用なし」を返す。

1.0.0 は最初のリリースであり、前進させるべき旧版のDBが存在しないため。1.0.0 のスキーマは
`schema.mysql.sql` / `schema.pgsql.sql` が完全な形で持っている。**1.0.0 より後にスキーマが変わった版から、
このディレクトリに差分が入る。**

## schema.*.sql とこのディレクトリの役割分担
新規インストールの**完全なテーブル定義は親ディレクトリの唯一の真実** `schema.mysql.sql` / `schema.pgsql.sql` に集約されている。
1.0.0 のスキーマはすべてそこにあり、差分ファイルは置いていない
（適用先となる既存環境が存在しないため、差分として保持する意味がない）。

役割分担は 「`schema.*.sql` = 最新の完成形（新規インストール用）」「このディレクトリ = 差分履歴（既存環境のアップグレード用）」となる。
**スキーマを変更したら両方に反映すること。** 片方だけだと、新規インストールと既存環境でスキーマが食い違う。

## 差分を追加するときのチェックリスト（4箇所）

1. `core/database/migrations/V{nnn}__{説明}.{mysql,pgsql}.sql` — 差分本体（冪等に書く）
2. `core/database/schema.{mysql,pgsql}.sql` のテーブル定義 — 同じ変更を**畳み込む**（新規インストールの完成形）
3. `core/database/schema.{mysql,pgsql}.sql` 末尾の `schema_migrations` INSERT — **その版を「適用済み」として1行刻む**（checksum は `'BASELINE'`）
4. `core/Schema.php` の `SCHEMA_VERSION` — 最新の `V…` に上げる（`BASELINE_VERSION` は動かさない）

**3 を忘れると、SQL を直接取り込んだDBが誤検知される。** フルスキーマは中身が最新でも、記録が
`V001000000` のままだと `Migrator::schemaDriftWarning()` が `SCHEMA_VERSION` との不一致を報告し、
管理画面に「Schema version mismatch」が出続ける。`db:install` は SQL 適用後に
`Migrator::baseline()` が全差分を刻むため気づけないが、**phpMyAdmin 等で `schema.*.sql` を直接取り込むのは
共有レンタルサーバーでの正当な導入手順**であり、その経路には PHP 側の補正が無い。フルスキーマ1本で
記録まで完結していなければならない。

> `'BASELINE'` を使うのは、その版が「差分適用ではなくフルスキーマ由来」であることを表すため。
> `db:status` と `db:migrate` はこの番兵値の行を checksum 照合の対象外にするので、
> 実ファイルの SHA-256 をここに手書きする必要はない（書くと migration ファイルに触れた瞬間に陳腐化する）。

## バージョン番号の2つの定数

`core/Schema.php` に2つある。混同すると `db:status` が誤検知する。

| 定数 | 意味 | 動き |
|---|---|---|
| `SCHEMA_VERSION` | **コードが期待する版** | 差分を追加するたびに、その最新 `V…` に合わせて上げる。差分が無い間は `BASELINE_VERSION` と同値（`V001000000`） |
| `BASELINE_VERSION` | フルスキーマ由来であることを示す起点マーカー | `V001000000` に固定。**上げない**（上げると同版の差分行と主キーが衝突する） |

`db:status` は「`schema_migrations` の最大版」と `SCHEMA_VERSION` を比較する。

## コマンド
- `php core/cli.php db:install` — 新規: `schema.{driver}.sql` 投入 + 初期データ + 既知 migration を baseline 記録
- `php core/cli.php db:migrate` — 既存: 未適用 migration を昇順・冪等に適用
- `php core/cli.php db:status`  — 適用状況の確認

### 派生データの再構築（差分適用後に必要になることがある）
- `php core/cli.php tags:reindex`     — `post_tags` の非正規化列（＋カウンタ列）を `posts` から作り直す
- `php core/cli.php counters:rebuild` — `categories.post_count` / `tags.post_count` のみ作り直す
- `php core/cli.php sitemap:rebuild`  — `sitemap.xml` を即時再生成（`--if-pending` で保留時のみ）
