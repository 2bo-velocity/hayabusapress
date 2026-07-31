<?php
/**
 * HayabusaPress - Front-end dictionary (Japanese).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);
return [
    'search_placeholder' => '記事を検索...',
    'search_button'      => '検索',
    'recent_comments'    => '最近のコメント',
    'no_comments'        => 'コメントはまだありません。',
    'read_more'          => '続きを読む',
    'archive'            => 'アーカイブ',
    'categories'         => 'カテゴリ',
    'tags'               => 'タグ',
    'comment_title'      => 'コメント',
    'comment_form_title' => 'コメントを書く',
    'comment_author'     => '名前 *',
    'comment_email'      => 'メールアドレス（任意）',
    'comment_url'        => 'ウェブサイト（任意）',
    'comment_content'    => 'コメント *',
    'comment_submit'     => '送信する',
    'comment_thanks'     => 'コメントありがとうございます。承認後に公開されます。',
    'writer'             => 'WRITER',
    'related_posts'      => '関連記事',
    'no_posts'           => '記事がありません。',
    'prev_page'          => '← 前のページ',
    'next_page'          => '次のページ →',
    'page_of'            => '%d / %d ページ',
    // Listings show the current page only. A real total would need a COUNT(*) over the whole
    // listing, which is the per-request full scan this engine avoids. %d is the current page.
    'page_current'       => '%d ページ目',
    'pagination_aria'    => 'ページ送り',

    // Additional keys
    'share_label'            => 'シェアする',
    'share_hatena'           => 'はてブ',
    'invalid_request'        => '不正なリクエストです。',
    'not_found'              => 'ページが見つかりませんでした。',
    'back_to_top'            => '← トップへ戻る',
    'category_prefix'        => 'カテゴリ: ',
    'archive_title'          => 'アーカイブ',
    'menu_aria'              => 'メニュー',
    // Server-side search. %s is the query, %d the minimum term length.
    'search_results_heading' => '「%s」の検索結果',
    'search_no_results'      => '「%s」に一致する記事は見つかりませんでした。',
    'search_too_short'       => '検索語は %d 文字以上で入力してください。',

    'back_home'              => '← ホームへ戻る',

    'scheduled'              => '予約',
];
