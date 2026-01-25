<?php
/**
 * Plugin Updater Class
 * 
 * Handles automatic updates from the 3AG Update API.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSSC_Updater {
    
    /**
     * API Base URL
     */
    const API_URL = 'https://3ag.app/api/v3';
    
    /**
     * Product slug for API
     */
    const PRODUCT_SLUG = 'woo-stock-sync-from-csv';
    
    /**
     * Cache key for update data
     */
    const CACHE_KEY = 'wssc_update_data';
    
    /**
     * Cache expiration in seconds (12 hours)
     */
    const CACHE_EXPIRATION = 43200;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
        
        // Add custom update message
        add_action('in_plugin_update_message-' . WSSC_PLUGIN_BASENAME, [$this, 'update_message'], 10, 2);
        
        // Schedule periodic update check
        add_action('wssc_update_check', [$this, 'scheduled_check']);
        if (!wp_next_scheduled('wssc_update_check')) {
            wp_schedule_event(time(), 'twicedaily', 'wssc_update_check');
        }
    }
    
    /**
     * Get current domain
     */
    private function get_domain() {
        return wssc_get_domain();
    }
    
    /**
     * Check for updates via API
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get update data (cached or fresh)
        $update_data = $this->get_update_data();
        
        if (!$update_data || empty($update_data['version'])) {
            return $transient;
        }
        
        // Get current version
        $current_version = WSSC_VERSION;
        
        // Compare versions
        if (version_compare($current_version, $update_data['version'], '<')) {
            $transient->response[WSSC_PLUGIN_BASENAME] = (object) [
                'slug' => self::PRODUCT_SLUG,
                'plugin' => WSSC_PLUGIN_BASENAME,
                'new_version' => $update_data['version'],
                'url' => 'https://3ag.app/products/' . self::PRODUCT_SLUG,
                'package' => $update_data['download_url'],
                'icons' => [
                    '1x' => WSSC_PLUGIN_URL . 'assets/images/icon-128x128.png',
                    '2x' => WSSC_PLUGIN_URL . 'assets/images/icon-256x256.png',
                ],
                'banners' => [
                    'low' => WSSC_PLUGIN_URL . 'assets/images/banner-772x250.png',
                    'high' => WSSC_PLUGIN_URL . 'assets/images/banner-1544x500.png',
                ],
                'tested' => '6.4',
                'requires_php' => '7.4',
                'requires' => '5.8',
            ];
        } else {
            // No update available, but still add to no_update list
            $transient->no_update[WSSC_PLUGIN_BASENAME] = (object) [
                'slug' => self::PRODUCT_SLUG,
                'plugin' => WSSC_PLUGIN_BASENAME,
                'new_version' => $current_version,
                'url' => 'https://3ag.app/products/' . self::PRODUCT_SLUG,
            ];
        }
        
        return $transient;
    }
    
    /**
     * Get update data from API or cache
     */
    private function get_update_data($force = false) {
        // Check cache first
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get license key
        $license_key = get_option('wssc_license_key');
        
        if (empty($license_key)) {
            return null;
        }
        
        // Make API request
        $response = wp_remote_post(self::API_URL . '/update/check', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'license_key' => $license_key,
                'product_slug' => self::PRODUCT_SLUG,
                'domain' => $this->get_domain(),
            ]),
        ]);
        
        if (is_wp_error($response)) {
            // Log error but don't break
            error_log('WSSC Update Check Error: ' . $response->get_error_message());
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($code !== 200 || empty($data['data'])) {
            // Log non-200 responses
            if ($code !== 200) {
                error_log('WSSC Update Check HTTP ' . $code . ': ' . ($data['message'] ?? 'Unknown error'));
            }
            return null;
        }
        
        $update_data = [
            'version' => $data['data']['version'] ?? null,
            'download_url' => $data['data']['download_url'] ?? null,
            'checked' => time(),
        ];
        
        // Cache the result
        set_transient(self::CACHE_KEY, $update_data, self::CACHE_EXPIRATION);
        
        return $update_data;
    }
    
    /**
     * Provide plugin info for the WordPress plugin info popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if (!isset($args->slug) || $args->slug !== self::PRODUCT_SLUG) {
            return $result;
        }
        
        $update_data = $this->get_update_data();
        
        $plugin_info = (object) [
            'name' => 'Woo Stock Sync from CSV',
            'slug' => self::PRODUCT_SLUG,
            'version' => $update_data['version'] ?? WSSC_VERSION,
            'author' => '<a href="https://3ag.app">3AG</a>',
            'author_profile' => 'https://3ag.app',
            'homepage' => 'https://3ag.app/products/' . self::PRODUCT_SLUG,
            'requires' => '5.8',
            'tested' => '6.4',
            'requires_php' => '7.4',
            'downloaded' => 0,
            'last_updated' => date('Y-m-d H:i:s'),
            'sections' => [
                'description' => $this->get_plugin_description(),
                'installation' => $this->get_installation_instructions(),
                'changelog' => $this->get_changelog(),
            ],
            'download_link' => $update_data['download_url'] ?? '',
            'banners' => [
                'low' => WSSC_PLUGIN_URL . 'assets/images/banner-772x250.png',
                'high' => WSSC_PLUGIN_URL . 'assets/images/banner-1544x500.png',
            ],
        ];
        
        return $plugin_info;
    }
    
    /**
     * Get plugin description
     */
    private function get_plugin_description() {
        return '<p>Woo Stock Sync from CSV is a premium WordPress plugin that automatically synchronizes your WooCommerce product stock levels from a CSV file hosted at any URL.</p>
        <h4>Key Features:</h4>
        <ul>
            <li><strong>Automatic Sync</strong> - Schedule stock updates from every 5 minutes to weekly</li>
            <li><strong>Large Catalog Support</strong> - Optimized batch processing for 4000+ products</li>
            <li><strong>Custom Column Mapping</strong> - Map any CSV column to SKU and quantity fields</li>
            <li><strong>Auto Delimiter Detection</strong> - Supports comma, semicolon, tab, and pipe delimiters</li>
            <li><strong>Missing SKU Actions</strong> - Choose what happens when products are not in CSV</li>
            <li><strong>Watchdog Cron</strong> - Automatic recovery if scheduled sync stops working</li>
            <li><strong>Detailed Logs</strong> - Complete history of all sync operations</li>
            <li><strong>Modern UI</strong> - Clean, intuitive admin interface</li>
        </ul>';
    }
    
    /**
     * Get installation instructions
     */
    private function get_installation_instructions() {
        return '<ol>
            <li>Upload the plugin files to <code>/wp-content/plugins/woo-stock-sync-from-csv</code></li>
            <li>Activate the plugin through the \'Plugins\' screen in WordPress</li>
            <li>Go to Stock Sync → License to activate your license key</li>
            <li>Configure your CSV URL and column mappings in Stock Sync → Settings</li>
            <li>Enable scheduled sync or run a manual sync from the Dashboard</li>
        </ol>';
    }
    
    /**
     * Get changelog from readme.txt
     */
    private function get_changelog() {
        $readme_file = WSSC_PLUGIN_DIR . 'readme.txt';
        
        if (!file_exists($readme_file)) {
            return '<p>See the plugin readme for full changelog.</p>';
        }
        
        $readme = file_get_contents($readme_file);
        
        // Extract changelog section
        if (preg_match('/== Changelog ==(.+?)(?:== |$)/s', $readme, $matches)) {
            $changelog = trim($matches[1]);
            
            // Convert to HTML
            $changelog = preg_replace('/^= (.+?) =$/m', '<h4>$1</h4>', $changelog);
            $changelog = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $changelog);
            $changelog = preg_replace('/(<li>.+<\/li>\n?)+/s', '<ul>$0</ul>', $changelog);
            
            return $changelog;
        }
        
        return '<p>See the plugin readme for full changelog.</p>';
    }
    
    /**
     * After plugin install, rename directory if needed
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        // Only handle our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== WSSC_PLUGIN_BASENAME) {
            return $response;
        }
        
        // Initialize WP_Filesystem if not already
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Verify filesystem is available
        if (empty($wp_filesystem) || !is_object($wp_filesystem)) {
            return $response;
        }
        
        // Move plugin to correct directory
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname(WSSC_PLUGIN_BASENAME);
        
        if ($wp_filesystem->exists($result['destination']) && $result['destination'] !== $plugin_dir) {
            $wp_filesystem->move($result['destination'], $plugin_dir);
            $result['destination'] = $plugin_dir;
        }
        
        // Reactivate plugin
        activate_plugin(WSSC_PLUGIN_BASENAME);
        
        return $response;
    }
    
    /**
     * Display update message on plugins page
     */
    public function update_message($plugin_data, $response) {
        // Check if license is valid
        if (!WSSC()->license->is_valid()) {
            echo '<br><span style="color: #d63638; font-weight: 600;">';
            echo esc_html__('Please activate a valid license to enable automatic updates.', 'woo-stock-sync');
            echo ' <a href="' . esc_url(admin_url('admin.php?page=woo-stock-sync-license')) . '">';
            echo esc_html__('Activate License', 'woo-stock-sync');
            echo '</a></span>';
        }
    }
    
    /**
     * Scheduled update check
     */
    public function scheduled_check() {
        // Force refresh update data
        $this->get_update_data(true);
    }
    
    /**
     * Clear update cache
     */
    public function clear_cache() {
        delete_transient(self::CACHE_KEY);
    }
    
    /**
     * Force check for updates
     */
    public function force_check() {
        $this->clear_cache();
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
}
