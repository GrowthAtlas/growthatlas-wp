<?php

namespace GrowthAtlas;

/**
 * Bridges GrowthAtlas meta fields into Yoast SEO, RankMath, and AIOSEO.
 */
class SeoBridge
{
    public static function apply(int $post_id, array $params): void
    {
        $meta_title = sanitize_text_field($params['meta_title'] ?? '');
        $meta_desc = sanitize_textarea_field($params['meta_description'] ?? '');
        $keyword = sanitize_text_field($params['target_keyword'] ?? '');

        // Yoast SEO
        if (defined('WPSEO_VERSION')) {
            if ($meta_title) update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
            if ($meta_desc) update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            if ($keyword) update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
        }

        // RankMath
        if (defined('RANK_MATH_VERSION')) {
            if ($meta_title) update_post_meta($post_id, 'rank_math_title', $meta_title);
            if ($meta_desc) update_post_meta($post_id, 'rank_math_description', $meta_desc);
            if ($keyword) update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
        }

        // AIOSEO
        if (class_exists('\AIOSEO\Plugin\Common\Models\Post')) {
            if ($meta_title) update_post_meta($post_id, '_aioseo_title', $meta_title);
            if ($meta_desc) update_post_meta($post_id, '_aioseo_description', $meta_desc);
            if ($keyword) update_post_meta($post_id, '_aioseo_keywords', $keyword);
        }
    }
}
