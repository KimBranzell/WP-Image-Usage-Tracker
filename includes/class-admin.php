<?php
namespace ImageUsageTracker;

class Admin {
    private $tracker;

    public function __construct() {
        $this->tracker = new Tracker();
        $this->init_hooks();
    }

    public function enqueue_assets() {
        $screen = get_current_screen();

        if ($screen->base === 'upload' || $screen->base === 'media_page_image-usage-tracker') {
            wp_enqueue_style(
                'iut-admin-styles',
                IUT_PLUGIN_URL . 'assets/css/admin.css',
                [],
                IUT_VERSION
            );

            wp_enqueue_script(
                'iut-admin-script',
                IUT_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                IUT_VERSION,
                true
            );

            wp_localize_script('iut-admin-script', 'iutSettings', [
                'nonce' => wp_create_nonce('iut_scan_image')
            ]);
        }
    }

    private function init_hooks() {
        // Add Media Library column
        add_filter('manage_media_columns', [$this, 'add_usage_column']);
        add_action('manage_media_custom_column', [$this, 'display_usage_column'], 10, 2);

        // Add action links
        add_filter('media_row_actions', [$this, 'add_action_links'], 10, 2);

        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Add AJAX handlers
        add_action('wp_ajax_iut_scan_image', [$this, 'ajax_scan_image']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('load-media_page_image-usage-tracker', [$this, 'add_screen_options']);
        add_filter('set-screen-option', [$this, 'set_screen_option'], 10, 3);

        add_filter('attachment_fields_to_edit', [$this, 'add_usage_to_attachment_fields'], 10, 2);
    }

    public function add_screen_options() {
        $screen = get_current_screen();

        if ($screen->id === 'media_page_image-usage-tracker') {
            add_screen_option('per_page', [
                'label' => __('Images per page', 'image-usage-tracker'),
                'default' => 20,
                'option' => 'iut_images_per_page'
            ]);
        }
    }

    public function set_screen_option($status, $option, $value) {
        if ('iut_images_per_page' === $option) {
            return $value;
        }
        return $status;
    }

    public function add_usage_column($columns) {
        $columns['image_usage'] = __('Usage', 'image-usage-tracker');
        return $columns;
    }

    public function display_usage_column($column_name, $attachment_id) {
        if ($column_name !== 'image_usage') {
            return;
        }

        $usage = $this->tracker->get_image_usage($attachment_id);
        $count = count($usage);

        if ($count > 0) {
            printf(
                '<a href="#" class="iut-usage-count" data-image-id="%d">%s</a>',
                $attachment_id,
                sprintf(_n('%d location', '%d locations', $count, 'image-usage-tracker'), $count)
            );
        } else {
            echo '<span class="iut-no-usage">' . __('Not used', 'image-usage-tracker') . '</span>';
        }
    }

    public function add_action_links($actions, $post) {
        $actions['scan_usage'] = sprintf(
            '<a href="#" class="iut-scan-image" data-image-id="%d">%s</a>',
            $post->ID,
            __('Scan Usage', 'image-usage-tracker')
        );
        return $actions;
    }

    public function ajax_scan_image() {
        check_ajax_referer('iut_scan_image', 'nonce');

        $image_id = intval($_POST['image_id']);
        if (!current_user_can('upload_files') || empty($image_id)) {
            wp_send_json_error('Invalid request');
        }

        // Track the image
        $this->tracker->track_image($image_id);

        // Get fresh usage data
        $usage = $this->tracker->get_image_usage($image_id);

        // Send back the results
        wp_send_json_success([
            'count' => count($usage),
            'usage' => $this->format_usage_data($usage),
            'message' => 'Scan complete'
        ]);
    }

    public function add_usage_to_attachment_fields($form_fields, $post) {
        if (!wp_attachment_is_image($post->ID)) {
            return $form_fields;
        }

        $usage = $this->tracker->get_image_usage($post->ID);
        $usage_html = '<div class="iut-attachment-usage">';

        if (!empty($usage)) {
            $usage_html .= '<ul class="iut-usage-list">';
            foreach ($this->format_usage_data($usage) as $usage_item) {
                $usage_html .= "<li>{$usage_item}</li>";
            }
            $usage_html .= '</ul>';
        } else {
            $usage_html .= '<p>' . __('No usage found', 'image-usage-tracker') . '</p>';
        }

        $usage_html .= sprintf(
            '<button type="button" class="button iut-scan-image" data-image-id="%d">%s</button>',
            $post->ID,
            __('Scan Now', 'image-usage-tracker')
        );

        $usage_html .= '</div>';

        $form_fields['image_usage'] = [
            'label' => __('Image Usage', 'image-usage-tracker'),
            'input' => 'html',
            'html'  => $usage_html
        ];

        return $form_fields;
    }

    public function add_admin_menu() {
      add_submenu_page(
          'upload.php',                          // Parent slug (Media)
          __('Image Usage Tracker', 'image-usage-tracker'),  // Page title
          __('Image Usage', 'image-usage-tracker'),         // Menu title
          'manage_options',                      // Capability
          'image-usage-tracker',                 // Menu slug
          [$this, 'render_settings_page']        // Callback function
      );
    }

    public function render_settings_page() {
        // Get all images
        $images = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        // Load the template
        include IUT_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    private function format_usage_data($usage) {
        $formatted = [];
        foreach ($usage as $item) {
            $formatted[] = $this->format_usage_item($item);
        }
        return $formatted;
    }

    private function format_usage_item($item) {
        $location = json_decode($item->location, true);

        if ($item->post_id) {
            $post = get_post($item->post_id);

            // Special handling for ACF fields
            if ($item->usage_type === 'acf_field' && isset($location['field_name'])) {
                return sprintf(
                    '%s: <a href="%s">%s</a> (Field: %s)',
                    ucfirst($item->usage_type),
                    get_edit_post_link($item->post_id),
                    $post->post_title,
                    $location['field_name']
                );
            }

            return sprintf(
                '%s: <a href="%s">%s</a>',
                ucfirst($item->usage_type),
                get_edit_post_link($item->post_id),
                $post->post_title
            );
        }

        return sprintf(
            '%s: %s',
            ucfirst($item->usage_type),
            $location['location'] ?? 'Unknown'
        );
    }
}
