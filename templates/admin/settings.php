<?php
// Search handling
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Get all images first
if (!empty($search_query)) {
    $images = array_filter($images, function($image) use ($search_query) {
        return (
            stripos($image->post_title, $search_query) !== false ||
            stripos($image->post_name, $search_query) !== false ||
            stripos($image->post_content, $search_query) !== false
        );
    });
}

// Get total count AFTER filtering
$total_images = count($images);

$user_per_page = get_user_meta(get_current_user_id(), 'iut_images_per_page', true);
$per_page = $user_per_page ? $user_per_page : 3;


// Pagination settings
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Get paginated subset of images
$images = array_slice($images, $offset, $per_page);

?>

<div class="wrap">
    <h1><?php _e('Image Usage Tracker', 'image-usage-tracker'); ?></h1>

    <div class="tablenav top">
        <div class="alignleft actions">
            <button class="button button-primary iut-scan-all">
                <?php _e('Scan All Images', 'image-usage-tracker'); ?>
            </button>
        </div>
        <form class="search-box" method="get">
            <!-- Keep the current page -->
            <input type="hidden" name="page" value="image-usage-tracker">

            <input type="search"
                   name="s"
                   value="<?php echo esc_attr($search_query); ?>"
                   placeholder="<?php esc_attr_e('Search images...', 'image-usage-tracker'); ?>"
            >
            <input type="submit" class="button" value="<?php esc_attr_e('Search', 'image-usage-tracker'); ?>">

            <?php if (!empty($search_query)): ?>
                <a href="<?php echo admin_url('upload.php?page=image-usage-tracker'); ?>"
                class="button show-all-images">
                    <?php esc_html_e('Show All Images', 'image-usage-tracker'); ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <table class="wp-list-table widefat fixed striped iut-table">
        <thead>
            <tr>
                <th><?php _e('Image', 'image-usage-tracker'); ?></th>
                <th><?php _e('Usage Count', 'image-usage-tracker'); ?></th>
                <th><?php _e('Last Scan', 'image-usage-tracker'); ?></th>
                <th><?php _e('Actions', 'image-usage-tracker'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($images as $image): ?>
                <?php $usage = $this->tracker->get_image_usage($image->ID); ?>
                <tr>
                    <td>
                        <?php echo wp_get_attachment_image($image->ID, 'thumbnail'); ?>
                        <strong><?php echo esc_html($image->post_title); ?></strong>
                    </td>
                    <td class="usage-count" data-image-id="<?php echo $image->ID; ?>">
                        <?php echo count($usage); ?>
                    </td>
                    <td>
                        <?php
                        $last_scan = get_post_meta($image->ID, '_iut_last_scan', true);
                        echo $last_scan ? date_i18n(get_option('date_format'), strtotime($last_scan)) : __('Never', 'image-usage-tracker');
                        ?>
                    </td>
                    <td>
                        <button class="button iut-scan-image" data-image-id="<?php echo $image->ID; ?>">
                            <?php _e('Scan Now', 'image-usage-tracker'); ?>
                        </button>
                    </td>
                </tr>
                <?php if (!empty($usage)): ?>
                <tr class="iut-usage-details">
                    <td colspan="4">
                        <ul class="iut-usage-list">
                            <?php foreach ($this->format_usage_data($usage) as $usage_item): ?>
                                <li><?php echo $usage_item; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            echo paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => ceil($total_images / $per_page),
                'current' => $current_page
            ]);
            ?>
        </div>
    </div>
</div>
