<?php
/**
 * HayabusaPress - Front-end dictionary (English).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);
return [
    'search_placeholder' => 'Search articles...',
    'search_button'      => 'Search',
    'recent_comments'    => 'Recent Comments',
    'no_comments'        => 'No comments yet.',
    'read_more'          => 'Read More',
    'archive'            => 'Archive',
    'categories'         => 'Categories',
    'tags'               => 'Tags',
    'comment_title'      => 'Comments',
    'comment_form_title' => 'Leave a Comment',
    'comment_author'     => 'Name *',
    'comment_email'      => 'Email (optional)',
    'comment_url'        => 'Website (optional)',
    'comment_content'    => 'Comment *',
    'comment_submit'     => 'Submit',
    'comment_thanks'     => 'Thank you for your comment. It will be published after approval.',
    'writer'             => 'WRITER',
    'related_posts'      => 'Related Posts',
    'recent_posts'       => 'Recent Posts',
    'toc_title'          => 'Contents',
    'sponsored_notice'   => 'This article contains promotional content.',
    'no_posts'           => 'No posts found.',
    'prev_page'          => '← Previous',
    'next_page'          => 'Next →',
    'page_of'            => 'Page %d of %d',
    // Listings show the current page only. A real total would need a COUNT(*) over the whole
    // listing, which is the per-request full scan this engine avoids. %d is the current page.
    'page_current'       => 'Page %d',
    'pagination_aria'    => 'Pagination',

    // Additional keys
    'share_label'            => 'Share',
    'share_hatena'           => 'Hatena',
    'invalid_request'        => 'Invalid request.',
    'not_found'              => 'Page not found.',
    'back_to_top'            => '← Back to Top',
    'category_prefix'        => 'Category: ',
    'archive_title'          => 'Archive',
    'menu_aria'              => 'Menu',
    // Server-side search. %s is the query, %d the minimum term length.
    'search_results_heading' => 'Search results for “%s”',
    'search_no_results'      => 'No articles matched “%s”.',
    'search_too_short'       => 'Please enter a search term of at least %d characters.',

    'back_home'              => '← Back to Home',

    'scheduled'              => 'Scheduled',
];
