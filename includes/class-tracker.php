<?php
namespace ImageUsageTracker;

class Tracker {
    private $detector;
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->detector = new Detector();
        $this->table_name = $wpdb->prefix . 'image_usage';
    }

    /**
     * Track image usage and store results
     */
    public function track_image($image_id) {
        // Get all usage instances
        $usage_data = $this->detector->detect_image_usage($image_id);

        // Add ACF usage data if it exists
        if (function_exists('get_fields')) {
            $usage_data['acf_field'] = $this->detect_acf_usage($image_id);
        }

        // Clear existing tracking data
        $this->clear_image_tracking($image_id);

        // Store new tracking data
        $this->store_usage_data($image_id, $usage_data);

        // Update last scan time
        update_post_meta($image_id, '_iut_last_scan', current_time('mysql'));
    }

    private function detect_acf_usage($image_id) {
        global $wpdb;

        // Get posts that have this image in their meta
        $posts = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id
                FROM {$wpdb->postmeta}
                WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like($image_id) . '%'
            )
        );

        $usage_instances = [];

        foreach ($posts as $post_id) {
            $post_type = get_post_type($post_id);
            $is_revision = ($post_type === 'revision');

            // For revisions, get the parent post ID for better context
            $parent_id = 0;
            if ($is_revision) {
                $parent_id = wp_get_post_parent_id($post_id);
                if (!$parent_id) continue; // Skip orphaned revisions
            }

            $fields = get_fields($post_id);
            if (!$fields) continue;

            // Track each unique field usage only once
            $field_usages = [];

            foreach ($fields as $field_name => $field_value) {
                if (isset($field_value['ID']) && $field_value['ID'] == $image_id) {
                    $field_usages[$field_name] = true;
                }
                elseif (is_array($field_value)) {
                    foreach ($field_value as $sub_field_name => $sub_field) {
                        if (isset($sub_field['ID']) && $sub_field['ID'] == $image_id) {
                            $field_usages[$field_name . ':' . $sub_field_name] = true;
                        }
                    }
                }
            }

            // Add only unique field usages
            foreach (array_keys($field_usages) as $field_name) {
                // Add type label to the field name
                $labeled_field_name = $field_name;
                if ($is_revision) {
                    $labeled_field_name .= ' (revision)';

                    // Store with the actual revision ID to maintain proper linking
                    $usage_instances[] = [
                        'post_id' => $post_id, // Keep the actual revision ID for linking
                        'field_name' => $labeled_field_name,
                        'is_revision' => true,
                        'parent_id' => $parent_id // Store the parent ID for reference
                    ];
                } else {
                    $labeled_field_name .= ' (post)';
                    $usage_instances[] = [
                        'post_id' => $post_id,
                        'field_name' => $labeled_field_name,
                        'is_revision' => false
                    ];
                }
            }
        }

        return $usage_instances;
    }

    /**
     * Store usage data in the database
     */
    private function store_usage_data($image_id, $usage_data) {
        global $wpdb;

        // First, clear existing entries for this image
        $this->clear_image_tracking($image_id);

        // Create a normalized data structure
        $unique_entries = [];

        foreach ($usage_data as $usage_type => $instances) {
            foreach ($instances as $instance) {
                $location = $this->prepare_location_data($instance);
                $post_id = isset($instance['post_id']) ? $instance['post_id'] : 0;

                // Create unique key using all relevant data including field paths
                $unique_key = md5(serialize([
                    'image_id' => $image_id,
                    'post_id' => $post_id,
                    'usage_type' => $usage_type,
                    'location' => $location
                ]));

                $unique_entries[$unique_key] = [
                    'image_id' => $image_id,
                    'post_id' => $post_id,
                    'usage_type' => $usage_type,
                    'location' => $location
                ];
            }
        }

        // Single batch insert for all unique entries
        foreach ($unique_entries as $entry) {
            $wpdb->insert(
                $this->table_name,
                $entry,
                ['%d', '%d', '%s', '%s']
            );
        }
    }



    /**
     * Clear existing tracking data for an image
     */
    private function clear_image_tracking($image_id) {
        global $wpdb;

        $wpdb->delete(
            $this->table_name,
            ['image_id' => $image_id],
            ['%d']
        );
    }

    /**
     * Get all usage instances for an image
     */
    public function get_image_usage($image_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE image_id = %d",
            $image_id
        ));
    }

    /**
     * Prepare location data for storage
     */
    private function prepare_location_data($instance) {
        return json_encode(array_diff_key($instance, array_flip(['post_id', 'usage_type'])));
    }

    /**
     * Bulk track multiple images
     */
    public function bulk_track_images($image_ids, $batch_size = 20) {
        foreach (array_chunk($image_ids, $batch_size) as $batch) {
            foreach ($batch as $image_id) {
                $this->track_image($image_id);
            }
        }
    }

    /**
     * Check if image needs scanning
     */
    public function needs_scan($image_id) {
        $last_scan = get_post_meta($image_id, '_iut_last_scan', true);

        if (empty($last_scan)) {
            return true;
        }

        $scan_frequency = get_option('iut_scan_frequency', 86400); // Default 24 hours
        return (strtotime($last_scan) + $scan_frequency) < time();
    }
}
