<?php
namespace ImageUsageTracker;

class Detector {
    /**
     * Stores all possible image sizes and their URLs for an image
     * @var array
     */
    private $image_urls = [];

    /**
     * Detect all usage instances of a specific image
     *
     * @param int $image_id The attachment ID to check
     * @return array Array of usage instances
     */
    public function detect_image_usage($image_id) {
        $this->prepare_image_urls($image_id);

        return [
            'content' => $this->find_in_post_content($image_id),
            'featured' => $this->find_as_featured_image($image_id),
            'blocks' => $this->find_in_gutenberg_blocks($image_id),
            'galleries' => $this->find_in_galleries($image_id),
            'widgets' => $this->find_in_widgets($image_id),
            'customizer' => $this->find_in_customizer($image_id)
        ];
    }

    /**
     * Prepare all possible URLs for the image including different sizes
     */
    private function prepare_image_urls($image_id) {
        $this->image_urls = [];

        // Get original image URL
        $this->image_urls[] = wp_get_attachment_url($image_id);

        // Get all registered image sizes
        $sizes = get_intermediate_image_sizes();
        foreach ($sizes as $size) {
            $image_data = wp_get_attachment_image_src($image_id, $size);
            if ($image_data) {
                $this->image_urls[] = $image_data[0];
            }
        }

        // Remove duplicates
        $this->image_urls = array_unique($this->image_urls);
    }

    /**
     * Find image usage in post content
     */
    private function find_in_post_content($image_id) {
        global $wpdb;

        $usage = [];

        // Create URL patterns for SQL LIKE
        $url_patterns = array_map(function($url) use ($wpdb) {
            return $wpdb->esc_like($url);
        }, $this->image_urls);

        // Build the query
        $where_clauses = array_map(function($pattern) {
            return "post_content LIKE '%" . esc_sql($pattern) . "%'";
        }, $url_patterns);

        $query = "SELECT ID, post_type, post_title
                 FROM {$wpdb->posts}
                 WHERE (" . implode(' OR ', $where_clauses) . ")
                 AND post_status = 'publish'";

        $results = $wpdb->get_results($query);

        foreach ($results as $post) {
            $usage[] = [
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'usage_type' => 'content'
            ];
        }

        return $usage;
    }

    /**
     * Find image usage as featured images
     */
    private function find_as_featured_image($image_id) {
        global $wpdb;

        $usage = [];

        $query = $wpdb->prepare(
            "SELECT p.ID, p.post_type, p.post_title
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
             ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
             AND pm.meta_value = %d
             AND p.post_status = 'publish'",
            $image_id
        );

        $results = $wpdb->get_results($query);

        foreach ($results as $post) {
            $usage[] = [
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'usage_type' => 'featured'
            ];
        }

        return $usage;
    }

    /**
     * Find image usage in Gutenberg blocks
     */
    private function find_in_gutenberg_blocks($image_id) {
        global $wpdb;

        $usage = [];

        // Look for the image ID in serialized block attributes
        $query = $wpdb->prepare(
            "SELECT ID, post_type, post_title
             FROM {$wpdb->posts}
             WHERE post_content LIKE '%\"id\":{$image_id}}%'
             AND post_status = 'publish'"
        );

        $results = $wpdb->get_results($query);

        foreach ($results as $post) {
            $usage[] = [
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'usage_type' => 'block'
            ];
        }

        return $usage;
    }

        /**
     * Find image usage in galleries
     */
    private function find_in_galleries($image_id) {
      global $wpdb;
      $usage = [];

      // Check for gallery shortcodes
      $query = $wpdb->prepare(
          "SELECT ID, post_type, post_title
           FROM {$wpdb->posts}
           WHERE post_content LIKE '%[gallery%ids=\"%{$image_id}%\"]%'
           OR post_content LIKE '%[gallery%ids=\"%,{$image_id},%\"]%'
           OR post_content LIKE '%[gallery%ids=\"%,{$image_id}\"]%'
           AND post_status = 'publish'"
      );

      $results = $wpdb->get_results($query);

        if($results) {
            foreach ($results as $post) {
                $usage[] = [
                    'post_id' => $post->ID,
                    'post_type' => $post->post_type,
                    'post_title' => $post->post_title,
                    'usage_type' => 'gallery'
                ];
            }
        }

      return $usage;
    }

    /**
     * Find image usage in widgets
     */
    private function find_in_widgets($image_id) {
        $usage = [];
        $widgets = get_option('widget_media_image');

        if (!empty($widgets) && is_array($widgets)) {
            foreach ($widgets as $widget_id => $widget) {
                if (is_array($widget) && isset($widget['attachment_id']) && $widget['attachment_id'] == $image_id) {
                    $usage[] = [
                        'widget_id' => $widget_id,
                        'widget_type' => 'media_image',
                        'usage_type' => 'widget',
                        'location' => 'sidebar'
                    ];
                }
            }
        }

        // Check for images in custom HTML widgets
        $html_widgets = get_option('widget_custom_html');
        if (!empty($html_widgets) && is_array($html_widgets)) {
            foreach ($html_widgets as $widget_id => $widget) {
                if (is_array($widget) && isset($widget['content'])) {
                    foreach ($this->image_urls as $url) {
                        if (strpos($widget['content'], $url) !== false) {
                            $usage[] = [
                                'widget_id' => $widget_id,
                                'widget_type' => 'custom_html',
                                'usage_type' => 'widget',
                                'location' => 'sidebar'
                            ];
                            break;
                        }
                    }
                }
            }
        }

        return $usage;
    }

    /**
     * Find image usage in theme customizer
     */
    private function find_in_customizer($image_id) {
        $usage = [];
        $theme_mods = get_theme_mods();

        if (!empty($theme_mods)) {
            foreach ($theme_mods as $mod_key => $mod_value) {
                // Check for direct image IDs
                if ($mod_value == $image_id) {
                    $usage[] = [
                        'mod_key' => $mod_key,
                        'usage_type' => 'customizer',
                        'location' => 'theme_mod'
                    ];
                }

                // Check for image URLs in string values
                if (is_string($mod_value)) {
                    foreach ($this->image_urls as $url) {
                        if (strpos($mod_value, $url) !== false) {
                            $usage[] = [
                                'mod_key' => $mod_key,
                                'usage_type' => 'customizer',
                                'location' => 'theme_mod'
                            ];
                            break;
                        }
                    }
                }
            }
        }

        return $usage;
    }

    /**
     * Helper method to check if a string contains any of the image URLs
     */
    private function contains_image_url($content) {
        foreach ($this->image_urls as $url) {
            if (strpos($content, $url) !== false) {
                return true;
            }
        }
        return false;
    }

}
