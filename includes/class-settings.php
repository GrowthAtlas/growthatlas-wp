<?php

namespace GrowthAtlas;

class Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_settings_page(): void
    {
        add_options_page(
            __('GrowthAtlas', 'growthatlas'),
            __('GrowthAtlas', 'growthatlas'),
            'manage_options',
            'growthatlas',
            [__CLASS__, 'settings_page'],
        );
    }

    public static function register_settings(): void
    {
        register_setting('growthatlas', 'growthatlas_api_key', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('growthatlas', 'growthatlas_signing_secret', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('growthatlas', 'growthatlas_default_post_status', ['type' => 'string', 'default' => 'draft']);
        register_setting('growthatlas', 'growthatlas_default_author_id', ['type' => 'integer']);
        register_setting('growthatlas', 'growthatlas_default_category_id', ['type' => 'integer']);
        register_setting('growthatlas', 'growthatlas_enable_indexnow', ['type' => 'boolean', 'default' => true]);
    }

    public static function settings_page(): void
    {
        $api_key = get_option('growthatlas_api_key', '');
        $endpoint = rest_url('growthatlas/v1/health');

        if (isset($_POST['growthatlas_regenerate_key']) && check_admin_referer('growthatlas_regen_key')) {
            $api_key = bin2hex(random_bytes(24));
            update_option('growthatlas_api_key', $api_key);
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('GrowthAtlas Settings', 'growthatlas'); ?></h1>

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-bottom:24px;max-width:600px;">
                <h3 style="margin-top:0;"><?php esc_html_e('Connection Details', 'growthatlas'); ?></h3>
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <td style="padding:6px 0;font-weight:600;width:40%;"><?php esc_html_e('API Key', 'growthatlas'); ?></td>
                        <td>
                            <code style="background:#f5f5f5;padding:4px 8px;border-radius:4px;word-break:break-all;"><?php echo esc_html($api_key); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;font-weight:600;"><?php esc_html_e('Base URL', 'growthatlas'); ?></td>
                        <td><code style="background:#f5f5f5;padding:4px 8px;border-radius:4px;"><?php echo esc_url(get_site_url()); ?></code></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;font-weight:600;"><?php esc_html_e('Health Endpoint', 'growthatlas'); ?></td>
                        <td><code style="background:#f5f5f5;padding:4px 8px;border-radius:4px;"><?php echo esc_url($endpoint); ?></code></td>
                    </tr>
                </table>
                <form method="post" action="" style="margin-top:12px;">
                    <?php wp_nonce_field('growthatlas_regen_key'); ?>
                    <button type="submit" name="growthatlas_regenerate_key" class="button button-secondary">
                        <?php esc_html_e('Regenerate API Key', 'growthatlas'); ?>
                    </button>
                </form>
                <p style="margin-top:8px;color:#666;font-size:13px;">
                    <?php esc_html_e('Copy these details into your GrowthAtlas dashboard to connect this website.', 'growthatlas'); ?>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('growthatlas'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="growthatlas_signing_secret"><?php esc_html_e('HMAC Signing Secret (optional)', 'growthatlas'); ?></label></th>
                        <td>
                            <input type="text" id="growthatlas_signing_secret" name="growthatlas_signing_secret" value="<?php echo esc_attr(get_option('growthatlas_signing_secret', '')); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Enable payload signature verification. Must match the secret set in GrowthAtlas.', 'growthatlas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="growthatlas_default_post_status"><?php esc_html_e('Default Post Status', 'growthatlas'); ?></label></th>
                        <td>
                            <select id="growthatlas_default_post_status" name="growthatlas_default_post_status">
                                <option value="draft" <?php selected(get_option('growthatlas_default_post_status', 'draft'), 'draft'); ?>><?php esc_html_e('Draft', 'growthatlas'); ?></option>
                                <option value="publish" <?php selected(get_option('growthatlas_default_post_status', 'draft'), 'publish'); ?>><?php esc_html_e('Published', 'growthatlas'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('GrowthAtlas publish_status field overrides this per-request.', 'growthatlas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('IndexNow', 'growthatlas'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="growthatlas_enable_indexnow" value="1" <?php checked(get_option('growthatlas_enable_indexnow', true)); ?> />
                                <?php esc_html_e('Ping Bing/IndexNow when content is published', 'growthatlas'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php self::render_received_content(); ?>
        </div>
        <?php
    }

    /**
     * List every post received from GrowthAtlas with links to edit it locally,
     * view it live, and open the originating draft in the GrowthAtlas dashboard.
     */
    private static function render_received_content(): void
    {
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_growthatlas_draft_id',
            'posts_per_page' => 50,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-top:24px;max-width:900px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Content from GrowthAtlas', 'growthatlas'); ?></h3>
            <?php if (empty($posts)) : ?>
                <p style="color:#666;"><?php esc_html_e('No content received yet. Publish a draft from GrowthAtlas to see it here.', 'growthatlas'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="border:none;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Title', 'growthatlas'); ?></th>
                            <th><?php esc_html_e('Status', 'growthatlas'); ?></th>
                            <th><?php esc_html_e('SEO', 'growthatlas'); ?></th>
                            <th><?php esc_html_e('Updated', 'growthatlas'); ?></th>
                            <th><?php esc_html_e('Links', 'growthatlas'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post) :
                            $seo = get_post_meta($post->ID, '_growthatlas_seo_score', true);
                            $ga_url = get_post_meta($post->ID, '_growthatlas_url', true);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html(get_the_title($post->ID) ?: '(untitled)'); ?></strong></td>
                                <td><?php echo esc_html(ucfirst(get_post_status($post->ID))); ?></td>
                                <td><?php echo $seo !== '' ? esc_html($seo . '%') : '&mdash;'; ?></td>
                                <td><?php echo esc_html(get_the_modified_date('M j, Y', $post->ID)); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php esc_html_e('Edit', 'growthatlas'); ?></a>
                                    &middot;
                                    <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'growthatlas'); ?></a>
                                    <?php if ($ga_url) : ?>
                                        &middot;
                                        <a href="<?php echo esc_url($ga_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('GrowthAtlas', 'growthatlas'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
