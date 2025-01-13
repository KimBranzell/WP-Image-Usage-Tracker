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

        // Clear existing tracking data
        $this->clear_image_tracking($image_id);

        // Store new tracking data
        $this->store_usage_data($image_id, $usage_data);

        // Update last scan time
        update_post_meta($image_id, '_iut_last_scan', current_time('mysql'));
    }

    /**
     * Store usage data in the database
     */
    private function store_usage_data($image_id, $usage_data) {
        global $wpdb;

        foreach ($usage_data as $usage_type => $instances) {
            foreach ($instances as $instance) {
                $wpdb->insert(
                    $this->table_name,
                    [
                        'image_id' => $image_id,
                        'post_id' => isset($instance['post_id']) ? $instance['post_id'] : 0,
                        'usage_type' => $usage_type,
                        'location' => $this->prepare_location_data($instance)
                    ],
                    ['%d', '%d', '%s', '%s']
                );
            }
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
