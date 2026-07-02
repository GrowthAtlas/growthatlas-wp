<?php

namespace GrowthAtlas;

class ApiRouter
{
    public static function register_routes(): void
    {
        $namespace = 'growthatlas/v1';

        register_rest_route($namespace, '/health', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'health'],
            'permission_callback' => [__CLASS__, 'authenticate'],
        ]);

        register_rest_route($namespace, '/site-profile', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'site_profile'],
            'permission_callback' => [__CLASS__, 'authenticate'],
        ]);

        register_rest_route($namespace, '/pages', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'pages'],
            'permission_callback' => [__CLASS__, 'authenticate'],
        ]);

        register_rest_route($namespace, '/entities', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'entities'],
            'permission_callback' => [__CLASS__, 'authenticate'],
        ]);

        register_rest_route($namespace, '/content-drafts', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_content_draft'],
            'permission_callback' => [__CLASS__, 'authenticate'],
        ]);

        register_rest_route($namespace, '/content-drafts/(?P<external_id>[^/]+)', [
            'methods' => 'PUT, PATCH',
            'callback' => [__CLASS__, 'update_content_draft'],
            'permission_callback' => [__CLASS__, 'authenticate'],
        ]);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public static function authenticate(\WP_REST_Request $request): bool|\WP_Error
    {
        $stored_key = get_option('growthatlas_api_key', '');
        if (empty($stored_key)) {
            return new \WP_Error('no_api_key', 'API key not configured', ['status' => 503]);
        }

        $auth = $request->get_header('Authorization') ?? '';
        if (! preg_match('/^Bearer (.+)$/i', $auth, $m)) {
            return new \WP_Error('missing_token', 'Missing Bearer token', ['status' => 401]);
        }

        if (! hash_equals($stored_key, $m[1])) {
            return new \WP_Error('invalid_token', 'Invalid API key', ['status' => 401]);
        }

        // Optional HMAC signature verification
        $secret = get_option('growthatlas_signing_secret', '');
        if ($secret) {
            $sig_header = $request->get_header('X-GrowthAtlas-Signature') ?? '';
            $body = $request->get_body();
            $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
            if (! hash_equals($expected, $sig_header)) {
                return new \WP_Error('invalid_signature', 'Signature verification failed', ['status' => 401]);
            }
        }

        return true;
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public static function health(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'status' => 'ok',
                'connector' => 'wordpress',
                'connector_version' => GROWTHATLAS_VERSION,
                'platform' => 'wordpress',
                'platform_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'growthatlas_api_version' => 'v1',
                'supports_update' => true,
            ],
        ], 200);
    }

    public static function site_profile(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'name' => get_bloginfo('name'),
                'url' => get_site_url(),
                'description' => get_bloginfo('description'),
                'language' => get_bloginfo('language'),
                'platform' => 'wordpress',
                'timezone' => get_option('timezone_string') ?: 'UTC',
                'post_types' => array_values(get_post_types(['public' => true])),
                'taxonomies' => array_values(get_taxonomies(['public' => true])),
            ],
        ], 200);
    }

    public static function pages(\WP_REST_Request $request): \WP_REST_Response
    {
        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $per_page = min(100, max(1, (int) ($request->get_param('per_page') ?? 100)));

        $query = new \WP_Query([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $items[] = [
                'url' => get_permalink($post->ID),
                'title' => $post->post_title,
                'h1' => $post->post_title,
                'meta_description' => get_post_meta($post->ID, '_yoast_wpseo_metadesc', true)
                    ?: get_post_meta($post->ID, 'rank_math_description', true)
                    ?: '',
                'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
                'published_at' => $post->post_date_gmt,
            ];
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => (int) $query->found_posts,
                'last_page' => (int) $query->max_num_pages,
            ],
        ], 200);
    }

    public static function entities(\WP_REST_Request $request): \WP_REST_Response
    {
        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $per_page = min(100, max(1, (int) ($request->get_param('per_page') ?? 100)));

        $terms = get_terms([
            'taxonomy' => ['category', 'post_tag'],
            'hide_empty' => false,
            'number' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]);

        $items = [];
        if (! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $items[] = [
                    'id' => (string) $term->term_id,
                    'type' => $term->taxonomy === 'category' ? 'category' : 'topic',
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description,
                    'url' => get_term_link($term),
                    'priority' => $term->count,
                ];
            }
        }

        $total = wp_count_terms(['taxonomy' => ['category', 'post_tag']]);

        return new \WP_REST_Response([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => is_wp_error($total) ? 0 : (int) $total,
                'last_page' => is_wp_error($total) ? 1 : (int) ceil((int) $total / $per_page),
            ],
        ], 200);
    }

    public static function create_content_draft(\WP_REST_Request $request): \WP_REST_Response
    {
        return ContentHandler::handle($request);
    }

    public static function update_content_draft(\WP_REST_Request $request): \WP_REST_Response
    {
        return ContentHandler::handle_update($request, (string) $request->get_param('external_id'));
    }
}
