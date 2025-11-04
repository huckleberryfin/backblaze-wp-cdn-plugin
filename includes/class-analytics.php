<?php
if (!defined('ABSPATH')) exit;

class BB_Analytics {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bb_analytics';

        // Create table on activation
        add_action('admin_init', array($this, 'maybe_create_table'));

        // REST API endpoint for tracking image views
        add_action('rest_api_init', array($this, 'register_tracking_endpoint'));

        // Add tracking script to frontend
        add_action('wp_footer', array($this, 'add_tracking_script'));
    }

    public function maybe_create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");

        if ($table_exists != $this->table_name) {
            $sql = "CREATE TABLE {$this->table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                attachment_id bigint(20) NOT NULL,
                image_url varchar(500) NOT NULL,
                file_size bigint(20) DEFAULT 0,
                view_date datetime DEFAULT CURRENT_TIMESTAMP,
                user_agent varchar(255) DEFAULT '',
                ip_address varchar(45) DEFAULT '',
                PRIMARY KEY (id),
                KEY attachment_id (attachment_id),
                KEY view_date (view_date)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    public function register_tracking_endpoint() {
        register_rest_route('backblaze/v1', '/track-view', array(
            'methods' => 'POST',
            'callback' => array($this, 'track_image_view'),
            'permission_callback' => '__return_true',
            'args' => array(
                'url' => array(
                    'required' => true,
                    'type' => 'string'
                )
            )
        ));
    }

    public function track_image_view($request) {
        global $wpdb;

        $image_url = $request->get_param('url');
        $cdn_url = get_option('bb_cdn_url');

        if (!$cdn_url || strpos($image_url, $cdn_url) === false) {
            return new WP_Error('invalid_url', 'Not a CDN URL', array('status' => 400));
        }

        // Extract relative path from CDN URL
        $relative_path = str_replace($cdn_url . '/', '', $image_url);

        // Find attachment by relative path
        $attachment_id = $this->get_attachment_by_path($relative_path);

        if (!$attachment_id) {
            return new WP_Error('not_found', 'Attachment not found', array('status' => 404));
        }

        // Get file size
        $file_path = get_attached_file($attachment_id);
        $file_size = file_exists($file_path) ? filesize($file_path) : 0;

        // Insert tracking record
        $wpdb->insert(
            $this->table_name,
            array(
                'attachment_id' => $attachment_id,
                'image_url' => $image_url,
                'file_size' => $file_size,
                'view_date' => current_time('mysql'),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s')
        );

        return rest_ensure_response(array('success' => true));
    }

    private function get_attachment_by_path($relative_path) {
        global $wpdb;

        // Try to find by exact path match in _wp_attached_file
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value = %s
             LIMIT 1",
            $relative_path
        ));

        if ($attachment_id) {
            return $attachment_id;
        }

        // Try to find by thumbnail (check if path contains the base filename)
        $filename = basename($relative_path);
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like($filename) . '%'
        ));

        return $attachment_id;
    }

    public function add_tracking_script() {
        $cdn_url = get_option('bb_cdn_url');
        if (!$cdn_url) return;

        ?>
        <script>
        (function() {
            // Track CDN image views
            const cdnUrl = <?php echo json_encode($cdn_url); ?>;
            const apiUrl = '<?php echo rest_url('backblaze/v1/track-view'); ?>';

            // Debounce function to avoid excessive tracking
            const trackedImages = new Set();

            function trackImageView(img) {
                const src = img.src || img.currentSrc;
                if (!src || !src.startsWith(cdnUrl) || trackedImages.has(src)) return;

                trackedImages.add(src);

                // Use sendBeacon for non-blocking tracking
                if (navigator.sendBeacon) {
                    const formData = new FormData();
                    formData.append('url', src);
                    navigator.sendBeacon(apiUrl, formData);
                } else {
                    // Fallback to fetch
                    fetch(apiUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({url: src})
                    }).catch(() => {});
                }
            }

            // Use Intersection Observer for efficient tracking
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting && entry.intersectionRatio > 0.5) {
                            trackImageView(entry.target);
                        }
                    });
                }, { threshold: 0.5 });

                // Observe all images from CDN
                document.querySelectorAll('img').forEach(img => {
                    if (img.src && img.src.startsWith(cdnUrl)) {
                        observer.observe(img);
                    }
                });

                // Handle dynamically added images
                const mutationObserver = new MutationObserver((mutations) => {
                    mutations.forEach(mutation => {
                        mutation.addedNodes.forEach(node => {
                            if (node.tagName === 'IMG' && node.src && node.src.startsWith(cdnUrl)) {
                                observer.observe(node);
                            }
                        });
                    });
                });

                mutationObserver.observe(document.body, { childList: true, subtree: true });
            } else {
                // Fallback: track on load
                document.querySelectorAll('img').forEach(img => {
                    if (img.complete) {
                        trackImageView(img);
                    } else {
                        img.addEventListener('load', () => trackImageView(img));
                    }
                });
            }
        })();
        </script>
        <?php
    }

    public function get_bandwidth_stats($days = 30) {
        global $wpdb;

        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_views,
                COUNT(DISTINCT attachment_id) as unique_images,
                SUM(file_size) as total_bandwidth,
                AVG(file_size) as avg_file_size
             FROM {$this->table_name}
             WHERE view_date >= %s",
            $date_from
        ));

        return $stats;
    }

    public function get_popular_images($limit = 10, $days = 30) {
        global $wpdb;

        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                attachment_id,
                COUNT(*) as view_count,
                SUM(file_size) as total_bandwidth,
                image_url
             FROM {$this->table_name}
             WHERE view_date >= %s
             GROUP BY attachment_id
             ORDER BY view_count DESC
             LIMIT %d",
            $date_from,
            $limit
        ));

        return $results;
    }

    public function get_daily_stats($days = 30) {
        global $wpdb;

        $date_from = date('Y-m-d', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(view_date) as date,
                COUNT(*) as views,
                SUM(file_size) as bandwidth
             FROM {$this->table_name}
             WHERE view_date >= %s
             GROUP BY DATE(view_date)
             ORDER BY date ASC",
            $date_from
        ));

        return $results;
    }

    public function analytics_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $period = isset($_GET['period']) ? intval($_GET['period']) : 30;
        $stats = $this->get_bandwidth_stats($period);
        $popular = $this->get_popular_images(10, $period);
        $daily = $this->get_daily_stats($period);

        ?>
        <div class="wrap">
            <h1>📊 CDN Analytics Dashboard</h1>

            <div style="margin: 20px 0;">
                <label for="period-select"><strong>Time Period:</strong></label>
                <select id="period-select" onchange="window.location.href='?page=backblaze-analytics&period='+this.value">
                    <option value="7" <?php selected($period, 7); ?>>Last 7 days</option>
                    <option value="30" <?php selected($period, 30); ?>>Last 30 days</option>
                    <option value="90" <?php selected($period, 90); ?>>Last 90 days</option>
                    <option value="365" <?php selected($period, 365); ?>>Last year</option>
                </select>
            </div>

            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Total Views</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #2271b1;">
                        <?php echo number_format($stats->total_views ?? 0); ?>
                    </p>
                </div>

                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Unique Images</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #2271b1;">
                        <?php echo number_format($stats->unique_images ?? 0); ?>
                    </p>
                </div>

                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Total Bandwidth</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #2271b1;">
                        <?php echo $this->format_bytes($stats->total_bandwidth ?? 0); ?>
                    </p>
                </div>

                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px 0; color: #666;">Estimated Cost</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #2271b1;">
                        <?php echo $this->estimate_cost($stats->total_bandwidth ?? 0); ?>
                    </p>
                    <p style="font-size: 12px; color: #666; margin: 5px 0 0 0;">Based on $0.01/GB</p>
                </div>
            </div>

            <!-- Daily Chart -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px; margin: 20px 0;">
                <h2>Daily Bandwidth Usage</h2>
                <canvas id="bandwidth-chart" width="400" height="100"></canvas>
            </div>

            <!-- Popular Images Table -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px; margin: 20px 0;">
                <h2>Most Popular Images</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Preview</th>
                            <th>Image</th>
                            <th style="width: 100px;">Views</th>
                            <th style="width: 120px;">Bandwidth</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($popular)): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px;">No data available yet. Images will appear here once tracking begins.</td></tr>
                        <?php else: ?>
                            <?php foreach ($popular as $image): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $thumb = wp_get_attachment_image($image->attachment_id, 'thumbnail', false, array('style' => 'max-width: 60px; height: auto;'));
                                        echo $thumb ? $thumb : '<span style="color: #999;">No preview</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html(get_the_title($image->attachment_id)); ?></strong><br>
                                        <small style="color: #666;"><?php echo esc_html(basename($image->image_url)); ?></small>
                                    </td>
                                    <td><?php echo number_format($image->view_count); ?></td>
                                    <td><?php echo $this->format_bytes($image->total_bandwidth); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('post.php?post=' . $image->attachment_id . '&action=edit'); ?>" class="button button-small">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
        <script>
        const ctx = document.getElementById('bandwidth-chart').getContext('2d');
        const data = <?php echo json_encode($daily); ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.date),
                datasets: [{
                    label: 'Bandwidth (MB)',
                    data: data.map(d => (d.bandwidth / 1024 / 1024).toFixed(2)),
                    borderColor: '#2271b1',
                    backgroundColor: 'rgba(34, 113, 177, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + ' MB';
                            }
                        }
                    }
                }
            }
        });
        </script>
        <?php
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

    private function estimate_cost($bytes) {
        $gb = $bytes / 1073741824;
        $cost = $gb * 0.01; // $0.01 per GB
        return '$' . number_format($cost, 4);
    }
}
