<?php

namespace GrowthAtlas;

class ContentHandler
{
    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        $draft_id = (int) ($params['growthatlas_draft_id'] ?? 0);

        // ── Idempotency: check if already published ──────────────────────────
        if ($draft_id > 0) {
            $existing = get_posts([
                'post_type' => 'any',
                'post_status' => 'any',
                'meta_key' => '_growthatlas_draft_id',
                'meta_value' => $draft_id,
                'posts_per_page' => 1,
            ]);

            if (! empty($existing)) {
                $post = $existing[0];

                return new \WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'external_id' => 'wp-post-' . $post->ID,
                        'url' => get_permalink($post->ID),
                        'status' => $post->post_status,
                        'created' => false,
                    ],
                ], 200);
            }
        }

        // ── Map publish_status ────────────────────────────────────────────────
        $requested_status = $params['publish_status'] ?? 'draft';
        $default_status = get_option('growthatlas_default_post_status', 'draft');
        $wp_status = ($requested_status === 'published') ? 'publish' : $default_status;

        // ── Resolve categories ────────────────────────────────────────────────
        $category_ids = [];
        foreach ((array) ($params['categories'] ?? []) as $cat_name) {
            $term = get_term_by('name', $cat_name, 'category')
                ?: wp_insert_term($cat_name, 'category');

            if (! is_wp_error($term)) {
                $category_ids[] = is_array($term) ? $term['term_id'] : $term->term_id;
            }
        }

        // ── Resolve tags ──────────────────────────────────────────────────────
        $tag_ids = [];
        foreach ((array) ($params['tags'] ?? []) as $tag_name) {
            $term = get_term_by('name', $tag_name, 'post_tag')
                ?: wp_insert_term($tag_name, 'post_tag');

            if (! is_wp_error($term)) {
                $tag_ids[] = is_array($term) ? $term['term_id'] : $term->term_id;
            }
        }

        // ── Build body content ────────────────────────────────────────────────
        $body_format = $params['body_format'] ?? 'markdown';
        if ($body_format === 'markdown' && ! empty($params['body'])) {
            // Use body_html if available, otherwise use body as-is (let WP render it)
            $content = ! empty($params['body_html']) ? $params['body_html'] : wpautop(wp_kses_post($params['body']));
        } else {
            $content = ! empty($params['body_html']) ? $params['body_html'] : ($params['body'] ?? '');
        }

        // ── Resolve slug (handle conflicts) ───────────────────────────────────
        $slug = ! empty($params['slug']) ? sanitize_title($params['slug']) : sanitize_title($params['title'] ?? '');
        if ($slug && get_page_by_path($slug, OBJECT, ['post', 'page'])) {
            $slug .= '-' . time();
        }

        // ── Insert post ────────────────────────────────────────────────────────
        $post_data = [
            'post_title' => sanitize_text_field($params['title'] ?? ''),
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field($params['excerpt'] ?? ''),
            'post_name' => $slug,
            'post_status' => $wp_status,
            'post_category' => $category_ids,
            'tags_input' => array_map('sanitize_text_field', (array) ($params['tags'] ?? [])),
        ];

        $author_id = get_option('growthatlas_default_author_id');
        if ($author_id) {
            $post_data['post_author'] = (int) $author_id;
        }

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $post_id->get_error_message(),
            ], 500);
        }

        self::apply_meta_and_media($post_id, $params, $draft_id, $wp_status);

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'external_id' => 'wp-post-' . $post_id,
                'url' => get_permalink($post_id),
                'status' => get_post_status($post_id),
                'created' => true,
            ],
        ], 201);
    }

    /**
     * Update an already-published post from a refreshed GrowthAtlas draft.
     * Falls back to create when the target post can no longer be found.
     */
    public static function handle_update(\WP_REST_Request $request, string $external_id): \WP_REST_Response
    {
        $params = $request->get_json_params();
        $draft_id = (int) ($params['growthatlas_draft_id'] ?? 0);

        $post_id = self::resolve_post_id($external_id, $draft_id);

        if (! $post_id) {
            // Nothing to update — create it so the refresh still lands.
            return self::handle($request);
        }

        $requested_status = $params['publish_status'] ?? 'draft';
        $default_status = get_option('growthatlas_default_post_status', 'draft');
        $wp_status = ($requested_status === 'published') ? 'publish' : $default_status;

        // ── Build body content ────────────────────────────────────────────────
        $body_format = $params['body_format'] ?? 'markdown';
        if ($body_format === 'markdown' && ! empty($params['body'])) {
            $content = ! empty($params['body_html']) ? $params['body_html'] : wpautop(wp_kses_post($params['body']));
        } else {
            $content = ! empty($params['body_html']) ? $params['body_html'] : ($params['body'] ?? '');
        }

        $post_data = [
            'ID' => $post_id,
            'post_title' => sanitize_text_field($params['title'] ?? get_the_title($post_id)),
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field($params['excerpt'] ?? ''),
            'post_status' => $wp_status,
        ];

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500);
        }

        self::apply_meta_and_media($post_id, $params, $draft_id, $wp_status);

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'external_id' => 'wp-post-' . $post_id,
                'url' => get_permalink($post_id),
                'status' => get_post_status($post_id),
                'created' => false,
                'updated' => true,
            ],
        ], 200);
    }

    /**
     * Resolve a WordPress post ID from a GrowthAtlas external id (wp-post-{ID})
     * or, failing that, the growthatlas_draft_id meta.
     */
    private static function resolve_post_id(string $external_id, int $draft_id): ?int
    {
        if (preg_match('/(\d+)$/', $external_id, $m)) {
            $candidate = (int) $m[1];
            if ($candidate > 0 && get_post($candidate)) {
                return $candidate;
            }
        }

        if ($draft_id > 0) {
            $existing = get_posts([
                'post_type' => 'any',
                'post_status' => 'any',
                'meta_key' => '_growthatlas_draft_id',
                'meta_value' => $draft_id,
                'posts_per_page' => 1,
            ]);

            if (! empty($existing)) {
                return (int) $existing[0]->ID;
            }
        }

        return null;
    }

    /**
     * Apply GrowthAtlas meta, SEO plugin bridge, schema, featured image and
     * IndexNow ping. Shared by create and update.
     */
    private static function apply_meta_and_media(int $post_id, array $params, int $draft_id, string $wp_status): void
    {
        if ($draft_id > 0) {
            update_post_meta($post_id, '_growthatlas_draft_id', $draft_id);
        }
        if (! empty($params['growthatlas_brief_id'])) {
            update_post_meta($post_id, '_growthatlas_brief_id', (int) $params['growthatlas_brief_id']);
        }
        if (! empty($params['seo_score'])) {
            update_post_meta($post_id, '_growthatlas_seo_score', (int) $params['seo_score']);
        }
        if (! empty($params['target_keyword'])) {
            update_post_meta($post_id, '_growthatlas_target_keyword', sanitize_text_field($params['target_keyword']));
        }
        // Link back to the originating draft in the GrowthAtlas dashboard.
        if (! empty($params['growthatlas_url'])) {
            update_post_meta($post_id, '_growthatlas_url', esc_url_raw($params['growthatlas_url']));
        }

        // ── SEO Plugin meta (Yoast / RankMath / AIOSEO) ───────────────────────
        SeoBridge::apply($post_id, $params);

        // ── Schema JSON-LD ────────────────────────────────────────────────────
        if (! empty($params['schema_json'])) {
            update_post_meta($post_id, '_growthatlas_schema_json', wp_json_encode($params['schema_json']));
        }

        // ── Featured Image ────────────────────────────────────────────────────
        if (! empty($params['featured_image_url']) && ! has_post_thumbnail($post_id)) {
            self::attach_featured_image($post_id, $params['featured_image_url'], $params['featured_image_alt'] ?? $params['title'] ?? '');
        }

        // ── IndexNow ──────────────────────────────────────────────────────────
        if ($wp_status === 'publish' && get_option('growthatlas_enable_indexnow', true)) {
            self::ping_indexnow(get_permalink($post_id));
        }
    }

    private static function attach_featured_image(int $post_id, string $url, string $alt): void
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return;
        }

        $file = [
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        ];

        $attach_id = media_handle_sideload($file, $post_id, $alt);
        if (is_wp_error($attach_id)) {
            @unlink($tmp);

            return;
        }

        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        set_post_thumbnail($post_id, $attach_id);
    }

    private static function ping_indexnow(string $url): void
    {
        wp_remote_post('https://api.indexnow.org/indexnow', [
            'body' => json_encode(['url' => $url, 'key' => 'growthatlas']),
            'headers' => ['Content-Type' => 'application/json'],
            'blocking' => false,
        ]);
    }
}
