<?php
if (!defined('ABSPATH')) exit;

class BB_WebP_Admin {

    public function __construct() {
        add_action('wp_ajax_bb_webp_bulk_convert', array($this, 'ajax_bulk_convert'));
        add_action('wp_ajax_bb_webp_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_bb_webp_get_stats', array($this, 'ajax_get_stats'));
    }

    public function webp_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        require_once plugin_dir_path(__FILE__) . 'class-webp-converter.php';
        $converter = new BB_WebP_Converter();

        $stats = $converter->get_webp_stats();
        $is_enabled = $converter->is_webp_enabled();

        ?>
        <div class="wrap">
            <h1>🖼️ WebP Conversion Manager</h1>

            <?php if (!function_exists('imagewebp')): ?>
                <div class="notice notice-error">
                    <p><strong>WebP Not Supported!</strong> Your server's GD library doesn't have WebP support. Contact your hosting provider to enable it.</p>
                </div>
            <?php endif; ?>

            <?php if (!$is_enabled): ?>
                <div class="notice notice-warning">
                    <p><strong>WebP conversion is currently disabled.</strong> Enable it in <a href="<?php echo admin_url('admin.php?page=backblaze-cdn'); ?>">Settings</a> to start converting images.</p>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Total Images</h3>
                    <p id="stat-total" style="font-size: 32px; font-weight: bold; margin: 0; color: #2271b1;">
                        <?php echo number_format($stats['total_images']); ?>
                    </p>
                </div>

                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Converted</h3>
                    <p id="stat-converted" style="font-size: 32px; font-weight: bold; margin: 0; color: #46b450;">
                        <?php echo number_format($stats['converted']); ?>
                    </p>
                </div>

                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Remaining</h3>
                    <p id="stat-remaining" style="font-size: 32px; font-weight: bold; margin: 0; color: #f0b849;">
                        <?php echo number_format($stats['remaining']); ?>
                    </p>
                </div>

                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Space Saved</h3>
                    <p id="stat-savings" style="font-size: 32px; font-weight: bold; margin: 0; color: #2271b1;">
                        <?php echo $stats['savings_percent']; ?>%
                    </p>
                    <p id="stat-savings-bytes" style="font-size: 12px; color: #666; margin: 5px 0 0 0;">
                        <?php echo $this->format_bytes($stats['savings_bytes']); ?>
                    </p>
                </div>
            </div>

            <!-- Bulk Conversion Tool -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px; margin: 20px 0;">
                <h2>Bulk Convert Existing Images</h2>
                <p>Convert all existing JPEG and PNG images in your media library to WebP format.</p>

                <div id="convert-status" style="margin: 20px 0;">
                    <p><strong>Progress:</strong> <span id="converted-count">0</span> / <span id="total-count"><?php echo $stats['remaining']; ?></span></p>
                </div>

                <button id="start-convert" class="button button-primary button-large" <?php echo !$is_enabled || !function_exists('imagewebp') ? 'disabled' : ''; ?>>
                    <?php echo $stats['remaining'] > 0 ? 'Start Bulk Conversion' : 'All Images Converted'; ?>
                </button>
                <button id="stop-convert" class="button button-secondary" style="display:none;">Stop Conversion</button>

                <div id="convert-progress" style="margin-top: 20px; display:none;">
                    <div style="background: #f0f0f1; border: 1px solid #ccc; border-radius: 4px; height: 30px; position: relative; overflow: hidden;">
                        <div id="convert-progress-bar" style="background: #46b450; height: 100%; width: 0%; transition: width 0.3s;"></div>
                        <span id="convert-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; color: #000;">0%</span>
                    </div>
                </div>

                <div id="convert-log" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ccc; border-radius: 4px; max-height: 300px; overflow-y: auto; display:none;">
                    <h3>Conversion Log</h3>
                    <div id="convert-log-content" style="font-family: monospace; font-size: 12px;"></div>
                </div>
            </div>

            <!-- Info Section -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px; margin: 20px 0;">
                <h2>How WebP Conversion Works</h2>
                <ul style="line-height: 1.8;">
                    <li><strong>Automatic Conversion:</strong> New uploads are automatically converted to WebP when enabled</li>
                    <li><strong>Browser Support:</strong> WebP is served to supported browsers (Chrome, Edge, Firefox, Safari 14+)</li>
                    <li><strong>Fallback:</strong> Original formats (JPEG/PNG) remain available for older browsers</li>
                    <li><strong>File Size:</strong> WebP typically reduces file size by 25-35% compared to JPEG/PNG</li>
                    <li><strong>Quality:</strong> Adjust quality in settings (recommended: 75-85%)</li>
                    <li><strong>Thumbnails:</strong> All WordPress thumbnail sizes are also converted</li>
                    <li><strong>CDN Integration:</strong> WebP files are automatically uploaded to Backblaze</li>
                </ul>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const ajaxNonce = '<?php echo wp_create_nonce('wp_ajax'); ?>';
            let isRunning = false;
            let shouldStop = false;
            let totalRemaining = <?php echo $stats['remaining']; ?>;
            let convertedCount = 0;
            let currentOffset = 0;

            function refreshStats() {
                $.post(ajaxurl, {
                    action: 'bb_webp_get_stats',
                    _ajax_nonce: ajaxNonce
                }, function(response) {
                    if (response.success) {
                        const stats = response.data;
                        $('#stat-total').text(stats.total_images.toLocaleString());
                        $('#stat-converted').text(stats.converted.toLocaleString());
                        $('#stat-remaining').text(stats.remaining.toLocaleString());
                        $('#stat-savings').text(stats.savings_percent + '%');
                        $('#stat-savings-bytes').text(formatBytes(stats.savings_bytes));
                    }
                });
            }

            function formatBytes(bytes) {
                if (bytes >= 1073741824) {
                    return (bytes / 1073741824).toFixed(2) + ' GB';
                } else if (bytes >= 1048576) {
                    return (bytes / 1048576).toFixed(2) + ' MB';
                } else if (bytes >= 1024) {
                    return (bytes / 1024).toFixed(2) + ' KB';
                } else {
                    return bytes + ' B';
                }
            }

            $('#start-convert').on('click', function() {
                if (isRunning || totalRemaining === 0) return;

                isRunning = true;
                shouldStop = false;
                convertedCount = 0;
                currentOffset = 0;

                $(this).hide();
                $('#stop-convert').show();
                $('#convert-progress').show();
                $('#convert-log').show();
                $('#convert-log-content').html('');

                log('🚀 Starting WebP bulk conversion...', '#2271b1');
                processBatch();
            });

            $('#stop-convert').on('click', function() {
                shouldStop = true;
                $(this).prop('disabled', true).text('Stopping...');
                log('⏸ Stopping conversion...', 'orange');
            });

            function processBatch() {
                if (shouldStop) {
                    isRunning = false;
                    $('#start-convert').show();
                    $('#stop-convert').hide().prop('disabled', false).text('Stop Conversion');
                    log('✓ Conversion stopped by user', 'green');
                    refreshStats();
                    return;
                }

                $.post(ajaxurl, {
                    action: 'bb_webp_bulk_convert',
                    batch_size: 10,
                    offset: currentOffset,
                    _ajax_nonce: ajaxNonce
                }, function(response) {
                    if (response.success) {
                        const data = response.data;

                        convertedCount += data.converted;
                        currentOffset += 10;
                        $('#converted-count').text(convertedCount);

                        // Update progress
                        const percentage = totalRemaining > 0 ? Math.round((convertedCount / totalRemaining) * 100) : 100;
                        $('#convert-progress-bar').css('width', percentage + '%');
                        $('#convert-progress-text').text(percentage + '%');

                        // Log detailed file-by-file results
                        if (data.details && data.details.length > 0) {
                            data.details.forEach(function(detail) {
                                const statusEmoji = detail.status === 'converted' ? '✅' : '⏭️';
                                const color = detail.status === 'converted' ? '#46b450' : '#f0b849';
                                const message = statusEmoji + ' ' + detail.file + ' (' + detail.title + ') - ' + detail.reason;
                                log(message, color);
                            });
                        } else {
                            log('✓ Converted ' + data.converted + ' images, skipped ' + data.skipped, 'green');
                        }

                        // Refresh stats after each batch
                        refreshStats();

                        // Continue if more files
                        if (data.has_more && !shouldStop) {
                            setTimeout(processBatch, 500);
                        } else {
                            isRunning = false;
                            $('#start-convert').show().text('All Images Converted');
                            $('#stop-convert').hide();
                            log('✓ Conversion complete! Converted ' + convertedCount + ' images total.', 'green');

                            // Final stats refresh
                            setTimeout(refreshStats, 1000);
                        }
                    } else {
                        isRunning = false;
                        $('#start-convert').show();
                        $('#stop-convert').hide();
                        log('✗ Error: ' + (response.data || 'Unknown error'), 'red');
                    }
                }).fail(function(jqXHR, textStatus) {
                    log('⚠ Connection error: ' + textStatus + ' - Retrying in 3 seconds...', 'orange');
                    setTimeout(processBatch, 3000);
                });
            }

            function log(message, color) {
                const timestamp = new Date().toLocaleTimeString();
                $('#convert-log-content').append(
                    '<div style="color: ' + color + '">[' + timestamp + '] ' + message + '</div>'
                );
                $('#convert-log-content').scrollTop($('#convert-log-content')[0].scrollHeight);
            }
        });
        </script>
        <?php
    }

    public function ajax_bulk_convert() {
        check_ajax_referer('wp_ajax');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        require_once plugin_dir_path(__FILE__) . 'class-webp-converter.php';
        $converter = new BB_WebP_Converter();

        $batch_size = intval($_POST['batch_size'] ?? 10);
        $offset = intval($_POST['offset'] ?? 0);

        $result = $converter->bulk_convert_existing_images($batch_size, $offset);

        wp_send_json_success($result);
    }

    public function ajax_clear_cache() {
        check_ajax_referer('wp_ajax');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        delete_transient('bb_webp_stats');
        wp_send_json_success(array('message' => 'Cache cleared'));
    }

    public function ajax_get_stats() {
        check_ajax_referer('wp_ajax');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        require_once plugin_dir_path(__FILE__) . 'class-webp-converter.php';
        $converter = new BB_WebP_Converter();

        // Force refresh stats by clearing cache first
        delete_transient('bb_webp_stats');
        $stats = $converter->get_webp_stats();

        wp_send_json_success($stats);
    }

    private function format_bytes($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
}
