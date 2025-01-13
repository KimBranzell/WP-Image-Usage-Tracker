<?php
namespace ImageUsageTracker;

class Core {
    private $detector;
    private $tracker;
    private $admin;

    public function init() {
        // Initialize components
        $this->init_components();
        $this->init_auto_tracking_hooks();

        // Register activation/deactivation hooks
        register_activation_hook(IUT_PLUGIN_DIR . 'image-usage-tracker.php', [$this, 'activate']);
        register_deactivation_hook(IUT_PLUGIN_DIR . 'image-usage-tracker.php', [$this, 'deactivate']);
    }

    private function init_components() {
        $this->detector = new Detector();
        $this->tracker = new Tracker();
        $this->admin = new Admin();
    }

    public function activate() {
        // Create database tables
        $this->create_tables();

        // Set default options
        $this->set_default_options();
    }

    public function deactivate() {
        // Cleanup if needed
    }

    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}image_usage (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            image_id bigint(20) NOT NULL,
            post_id bigint(20) NOT NULL,
            usage_type varchar(50) NOT NULL,
            location text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY image_id (image_id),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function set_default_options() {
        add_option('iut_scan_frequency', 'daily');
        add_option('iut_last_scan', '');
    }

    private function init_auto_tracking_hooks() {
        // Post/Page/CPT saves
        add_action('save_post', [$this, 'track_on_content_save'], 999, 3);

        // Also catch Gutenberg saves
        add_action('rest_after_insert_post', [$this, 'track_on_content_save'], 999, 3);
        add_action('transition_post_status', [$this, 'track_on_status_change'], 999, 3);
        add_action('rest_after_insert_post', [$this, 'track_on_content_save'], 999, 3);
        add_action('rest_after_save_widget', [$this, 'track_on_content_save'], 999, 3);

        // When switching themes (for customizer usage)
        add_action('after_switch_theme', [$this, 'track_all_images']);

        // Widget updates
        add_action('update_option_widget_media_image', [$this, 'track_widget_changes'], 10, 2);
        add_action('update_option_widget_custom_html', [$this, 'track_widget_changes'], 10, 2);

        // Menu item updates (for menu images)
        add_action('wp_update_nav_menu', [$this, 'track_menu_changes']);

        // ACF field updates
        add_action('acf/save_post', [$this, 'track_acf_changes'], 20);
    }


    public function track_on_status_change($new_status, $old_status, $post) {
        if ($new_status === 'publish') {
            $this->track_on_content_save($post->ID, $post, true);
        }
    }

    public function track_menu_changes($menu_id) {
        $menu_items = wp_get_nav_menu_items($menu_id);
        foreach ($menu_items as $item) {
            // Check for menu item images (some themes support this)
            $menu_image_id = get_post_meta($item->ID, '_menu_item_image', true);
            if ($menu_image_id) {
                $this->tracker->track_image($menu_image_id);
            }
        }
    }

    public function track_acf_changes($post_id) {
        if (!function_exists('get_fields')) {
            return;
        }

        $fields = get_fields($post_id);
        if (!$fields) {
            return;
        }

        $this->track_acf_fields_recursive($fields);
    }

    private function track_acf_fields_recursive($fields) {
        foreach ($fields as $field) {
            if (is_array($field)) {
                // Handle repeater and flexible content fields
                $this->track_acf_fields_recursive($field);
            } elseif (is_numeric($field) && $this->is_image_attachment($field)) {
                $this->tracker->track_image($field);
            }
        }
    }

    private function is_image_attachment($attachment_id) {
        return wp_attachment_is_image($attachment_id);
    }

    public function track_all_images() {
        $images = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        foreach ($images as $image_id) {
            $this->tracker->track_image($image_id);
        }
    }


    public function track_on_content_save($post_id, $post = null, $update = null) {
        // Get post object if needed
        if ($post instanceof \WP_Post) {
            $post_id = $post->ID;
            $content = $post->post_content;
        } else {
            $post = get_post($post_id);
            $content = $post ? $post->post_content : '';
        }

        if (empty($content)) {
            return;
        }

        // First, get all current images in this post
        $pattern = '/(?:wp-image-(\d+)|"id":(\d+).*?"type":"core\/image")/';
        preg_match_all($pattern, $content, $matches);

        // Get unique image IDs from current content
        $current_image_ids = array_unique(array_merge(
            array_filter($matches[1]),
            array_filter($matches[2])
        ));

        // Get previously tracked images for this post
        global $wpdb;
        $previous_images = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT image_id FROM {$wpdb->prefix}image_usage
            WHERE post_id = %d AND usage_type = 'content'",
            $post_id
        ));

        // Remove tracking for images no longer in content
        $removed_images = array_diff($previous_images, $current_image_ids);
        foreach ($removed_images as $image_id) {
            $wpdb->delete(
                $wpdb->prefix . 'image_usage',
                [
                    'image_id' => $image_id,
                    'post_id' => $post_id,
                    'usage_type' => 'content'
                ],
                ['%d', '%d', '%s']
            );
        }

        // Track current images
        foreach ($current_image_ids as $image_id) {
            $this->tracker->track_image($image_id);
        }

        // Handle featured image
        if (has_post_thumbnail($post_id)) {
            $this->tracker->track_image(get_post_thumbnail_id($post_id));
        }
    }

    public function track_widget_changes($old_value, $new_value) {
        // Track images in media widgets
        foreach ($new_value as $widget) {
            if (isset($widget['attachment_id'])) {
                $this->tracker->track_image($widget['attachment_id']);
            }
        }
    }
}
