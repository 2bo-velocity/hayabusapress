<?php
/**
 * HayabusaPress - Sample related-posts (post_saved hook) plugin.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Sample Related Posts Plugin
 *
 * Reference implementation of the post_saved event, which the core fires at the very end of
 * AdminPostController::save() — the row is committed and every cache has been rebuilt by then.
 *
 * post_saved is a notification, not an interceptor: returning early or throwing cannot undo the
 * save. Writing to $context has no effect either, because nothing downstream reads it back.
 *
 * $context:
 *   post_id  int     the saved post's ID (already assigned for a brand-new post)
 *   type     string  'post' | 'page'
 *   lang     string  content language
 *   slug     string  final slug (the core may have adjusted the requested one)
 *   status   string  'published' | 'draft' | 'scheduled'
 *   is_new   bool    true on the first save, false on an edit
 *
 * Keep the work here short. It runs inside the author's save request, so anything slow is felt
 * directly in the editor.
 */
class SampleRelatedPostsPlugin
{
    public static function onPostSaved(array &$context): void
    {
        // Only published posts are worth reacting to in this example.
        if (($context['type'] ?? '') !== 'post' || ($context['status'] ?? '') !== 'published') {
            return;
        }

        // Real work would go here — warm a custom cache, ping an external service, and so on.
        // The core already maintains posts.related_posts_cache, so this sample only logs.
        error_log(sprintf(
            '[sample-related-posts] post_saved: id=%d lang=%s slug=%s (%s)',
            (int)($context['post_id'] ?? 0),
            (string)($context['lang'] ?? ''),
            (string)($context['slug'] ?? ''),
            ($context['is_new'] ?? false) ? 'new' : 'update'
        ));
    }
}
