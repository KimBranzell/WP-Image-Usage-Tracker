jQuery(document).ready(function($) {
    $(document).on('click', '.iut-scan-image', function(e) {
        e.preventDefault();

        const $button = $(this);
        const $container = $button.closest('.iut-attachment-usage');
        const imageId = $button.data('image-id');

        $button.prop('disabled', true).text('Scanning...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'iut_scan_image',
                image_id: imageId,
                nonce: iutSettings.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update usage list in modal
                    if ($container.length) {
                        let usageHtml = '<ul class="iut-usage-list">';
                        response.data.usage.forEach(function(item) {
                            usageHtml += `<li>${item}</li>`;
                        });
                        usageHtml += '</ul>';
                        $container.find('.iut-usage-list').replaceWith(usageHtml);
                    }

                    $button.text('Scan Complete!');
                    setTimeout(() => {
                        $button.text('Scan Now').prop('disabled', false);
                    }, 2000);
                }
            }
        });
    });
    // Add bulk scan functionality
    $('.iut-scan-all').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);

        // Get all image IDs from the table
        const imageIds = $('.iut-scan-image').map(function() {
            return $(this).data('image-id');
        }).get();

        $button.prop('disabled', true).text('Scanning All Images...');

        // Track progress
        let processed = 0;

        // Process images sequentially
        function processNext() {
            if (processed >= imageIds.length) {
                $button.text('All Images Scanned!');
                setTimeout(() => {
                    $button.text('Scan All Images').prop('disabled', false);
                }, 2000);
                return;
            }

            const imageId = imageIds[processed];

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'iut_scan_image',
                    image_id: imageId,
                    nonce: iutSettings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update UI for this image
                        const $row = $(`.iut-scan-image[data-image-id="${imageId}"]`).closest('tr');
                        $row.find('.usage-count').text(response.data.count);
                        $row.next('.iut-usage-details').find('.iut-usage-list').html(
                            response.data.usage.map(item => `<li>${item}</li>`).join('')
                        );
                    }

                    // Update progress
                    processed++;
                    $button.text(`Scanning... (${processed}/${imageIds.length})`);

                    // Process next image
                    processNext();
                }
            });
        }

        // Start processing
        processNext();
    });
});
