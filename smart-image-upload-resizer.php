<?php
/**
 * Plugin Name: Smart Image Upload Resizer
 * Plugin URI: https://github.com/ivanusto/smart-image-upload-resizer
 * Description: Automatically resizes uploaded images to configurable maximum dimensions, with WebP and AVIF support.
 * Version: 1.4.0
 * Author: Ivan Lin
 * Author URI: https://yblog.org/
 * Text Domain: smart-image-upload-resizer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SIR_MAX_DIMENSION', 2560);
define('SIR_DEFAULTS', [
    'max_width'  => 1920,
    'max_height' => 1080,
    'quality'    => 80,
]);

class SmartImageUploadResizer {
    private $options;

    public function __construct() {
        $this->options = get_option('sir_settings', SIR_DEFAULTS);

        add_action('init', [$this, 'loadTextdomain']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'settingsInit']);
        add_filter('wp_handle_upload_prefilter', [$this, 'preHandleUpload']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'addSettingsLink']);

        // WordPress 7.1 client-side media processing moves scaling and sub-size
        // generation into the browser: the initial POST /wp/v2/media stores the
        // untouched original and the client later sideloads its own scaled copy
        // and sub-sizes generated from the full-resolution file. A server-side
        // resize of the original in that flow either breaks the upload outright
        // (an 'original' sideload is validated against the stored dimensions and
        // gets a 400) or silently voids the configured bounds (the client's
        // 2560px 'scaled' copy replaces the main file). Keeping processing
        // server-side is the only way the configured maximums reliably apply.
        add_filter('wp_client_side_media_processing_enabled', [$this, 'maybeDisableClientSideProcessing']);
    }

    /**
     * Disable WordPress 7.1+ client-side media processing while this plugin can
     * actually resize, so uploads keep flowing through the server-side pipeline
     * where the configured bounds are enforced.
     *
     * Without GD this plugin is a no-op, so the browser pipeline is left alone.
     */
    public function maybeDisableClientSideProcessing($enabled) {
        return extension_loaded('gd') ? false : $enabled;
    }

    /**
     * Whether the current request is the WordPress 7.1+ REST sub-size sideload
     * endpoint (POST /wp/v2/media/{id}/sideload), which uploads derivative
     * files for an existing attachment rather than new media.
     */
    private static function isRestMediaSideloadRequest() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }

        $route = '';
        if (isset($GLOBALS['wp']->query_vars['rest_route']) && is_string($GLOBALS['wp']->query_vars['rest_route'])) {
            $route = $GLOBALS['wp']->query_vars['rest_route'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $path  = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
            $route = is_string($path) ? $path : '';
        }

        return (bool) preg_match('#/wp/v2/media/\d+/sideload/?$#', $route);
    }

    /**
     * Whether the current request is a REST media create that opted out of
     * server-side sub-size generation (generate_sub_sizes=false), i.e. the
     * first request of the WordPress 7.1+ client-side processing flow.
     */
    private static function isClientSideMediaCreateRequest() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }

        // The client sends the parameter as a form field (multipart) or query
        // arg (raw body), so $_REQUEST covers both transports.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feature detection, no state change.
        if (!isset($_REQUEST['generate_sub_sizes'])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = sanitize_text_field(wp_unslash($_REQUEST['generate_sub_sizes']));

        return false === rest_sanitize_boolean($value);
    }

    public function loadTextdomain() {
        // This plugin is distributed via GitHub, not WordPress.org, so the bundled
        // /languages translations must be loaded manually.
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
        load_plugin_textdomain('smart-image-upload-resizer', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    private function increaseMemoryLimit() {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }
    }

    private function createImageFromFile($file_path, $mime_type) {
        switch ($mime_type) {
            case 'image/jpeg':
                return imagecreatefromjpeg($file_path);
            case 'image/png':
                return imagecreatefrompng($file_path);
            case 'image/gif':
                return imagecreatefromgif($file_path);
            case 'image/webp':
                return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($file_path) : false;
            case 'image/avif':
                return function_exists('imagecreatefromavif') ? imagecreatefromavif($file_path) : false;
            default:
                return false;
        }
    }

    private function resizeImage($source_image, $source_width, $source_height, $target_width, $target_height, $mime_type) {
        $target_image = imagecreatetruecolor($target_width, $target_height);
        if (!$target_image) {
            return false;
        }

        // Preserve transparency for PNG and GIF
        if ($mime_type === 'image/png' || $mime_type === 'image/gif') {
            imagealphablending($target_image, false);
            imagesavealpha($target_image, true);
            $transparent = imagecolorallocatealpha($target_image, 0, 0, 0, 127);
            imagefilledrectangle($target_image, 0, 0, $target_width, $target_height, $transparent);
            imagealphablending($target_image, true);
        }

        imagecopyresampled(
            $target_image, $source_image,
            0, 0, 0, 0,
            $target_width, $target_height,
            $source_width, $source_height
        );

        return $target_image;
    }

    private function saveImage($image, $file_path, $mime_type, $quality) {
        switch ($mime_type) {
            case 'image/jpeg':
                return imagejpeg($image, $file_path, $quality);
            case 'image/png':
                $png_quality = (int) floor((100 - $quality) / 10);
                return imagepng($image, $file_path, $png_quality);
            case 'image/gif':
                return imagegif($image, $file_path);
            case 'image/webp':
                return function_exists('imagewebp') ? imagewebp($image, $file_path, $quality) : false;
            case 'image/avif':
                return function_exists('imageavif') ? imageavif($image, $file_path, $quality) : false;
            default:
                return false;
        }
    }

    private function calculateDimensions($orig_width, $orig_height) {
        $max_width  = (int) $this->options['max_width'];
        $max_height = (int) $this->options['max_height'];
        $ratio      = $orig_width / $orig_height;

        if ($max_width / $max_height > $ratio) {
            $new_width  = min((int) round($max_height * $ratio), SIR_MAX_DIMENSION);
            $new_height = min($max_height, SIR_MAX_DIMENSION);
        } else {
            $new_width  = min($max_width, SIR_MAX_DIMENSION);
            $new_height = min((int) round($max_width / $ratio), SIR_MAX_DIMENSION);
        }

        return [$new_width, $new_height];
    }

    /**
     * Resize the uploaded image in its temporary location when it exceeds
     * the configured bounds. Any failure returns the file untouched so the
     * upload always succeeds with the original image.
     */
    public function preHandleUpload($file) {
        // GD may have been removed after activation (e.g. PHP version change)
        if (!extension_loaded('gd')) {
            return $file;
        }

        // Defense in depth for the WordPress 7.1 client-side media processing
        // flow (normally already prevented by maybeDisableClientSideProcessing,
        // but another plugin may force the flag back on, and REST clients can
        // send generate_sub_sizes=false regardless of the flag):
        //
        // - /wp/v2/media/{id}/sideload uploads derivative files for an existing
        //   attachment. Resizing an 'original' sideload fails core's dimension
        //   validation (400), and sub-sizes were already sized by the client.
        // - A media create with generate_sub_sizes=false stores the original the
        //   client will validate later 'original'/'scaled' sideloads against, so
        //   it must be stored untouched.
        if (self::isRestMediaSideloadRequest() || self::isClientSideMediaCreateRequest()) {
            return $file;
        }

        if (empty($file['type']) || strpos($file['type'], 'image/') !== 0) {
            return $file;
        }

        $supported_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        if (!in_array($file['type'], $supported_types, true)) {
            return $file;
        }

        $source_image  = false;
        $resized_image = false;

        try {
            $this->increaseMemoryLimit();

            $image_size = getimagesize($file['tmp_name']);
            if (!$image_size) {
                return $file;
            }

            [$orig_width, $orig_height] = $image_size;

            $max_width  = (int) $this->options['max_width'];
            $max_height = (int) $this->options['max_height'];

            if ($orig_width <= $max_width && $orig_height <= $max_height) {
                return $file;
            }

            [$new_width, $new_height] = $this->calculateDimensions($orig_width, $orig_height);

            $source_image = $this->createImageFromFile($file['tmp_name'], $file['type']);
            if (!$source_image) {
                return $file;
            }

            $resized_image = $this->resizeImage(
                $source_image,
                $orig_width, $orig_height,
                $new_width, $new_height,
                $file['type']
            );

            if (!$resized_image) {
                return $file;
            }

            if (!$this->saveImage($resized_image, $file['tmp_name'], $file['type'], (int) $this->options['quality'])) {
                return $file;
            }

            $file['size'] = filesize($file['tmp_name']);

        } finally {
            if ($source_image)  imagedestroy($source_image);
            if ($resized_image) imagedestroy($resized_image);
        }

        return $file;
    }

    public function addAdminMenu() {
        add_options_page(
            __('Smart Image Upload Resizer Settings', 'smart-image-upload-resizer'),
            __('Image Upload Resizer', 'smart-image-upload-resizer'),
            'manage_options',
            'smart-image-upload-resizer',
            [$this, 'optionsPage']
        );
    }

    public function settingsInit() {
        register_setting('sir_plugin', 'sir_settings', [
            'sanitize_callback' => [$this, 'sanitizeSettings'],
        ]);

        add_settings_section(
            'sir_plugin_section',
            __('Image Size Options', 'smart-image-upload-resizer'),
            [$this, 'settingsSectionCallback'],
            'sir_plugin'
        );

        add_settings_field('max_width',  __('Max Width', 'smart-image-upload-resizer'),     [$this, 'maxWidthRender'],  'sir_plugin', 'sir_plugin_section');
        add_settings_field('max_height', __('Max Height', 'smart-image-upload-resizer'),    [$this, 'maxHeightRender'], 'sir_plugin', 'sir_plugin_section');
        add_settings_field('quality',    __('Image Quality', 'smart-image-upload-resizer'), [$this, 'qualityRender'],   'sir_plugin', 'sir_plugin_section');
    }

    public function sanitizeSettings($input) {
        $sanitized = [];
        $sanitized['max_width']  = min(SIR_MAX_DIMENSION, max(1, (int) ($input['max_width']  ?? SIR_DEFAULTS['max_width'])));
        $sanitized['max_height'] = min(SIR_MAX_DIMENSION, max(1, (int) ($input['max_height'] ?? SIR_DEFAULTS['max_height'])));
        $sanitized['quality']    = min(100, max(1, (int) ($input['quality']    ?? SIR_DEFAULTS['quality'])));
        return $sanitized;
    }

    private function dimensionFieldRender($key) {
        $value = (int) ($this->options[$key] ?? SIR_DEFAULTS[$key]);
        echo '<input type="number" min="1" max="' . esc_attr(SIR_MAX_DIMENSION) . '" name="sir_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
        echo '<span> ';
        printf(
            /* translators: %s: hard maximum dimension in pixels. */
            esc_html__('px (max %s)', 'smart-image-upload-resizer'),
            esc_html(SIR_MAX_DIMENSION)
        );
        echo '</span>';
    }

    public function maxWidthRender() {
        $this->dimensionFieldRender('max_width');
    }

    public function maxHeightRender() {
        $this->dimensionFieldRender('max_height');
    }

    public function qualityRender() {
        $value = (int) ($this->options['quality'] ?? SIR_DEFAULTS['quality']);
        echo '<input type="number" min="1" max="100" name="sir_settings[quality]" value="' . esc_attr($value) . '">';
        echo '<span> %</span>';
    }

    public function settingsSectionCallback() {
        printf(
            /* translators: %s: hard maximum dimension in pixels. */
            esc_html__('Configure the maximum dimensions and quality for uploaded images (each dimension is capped at %s pixels). Oversized images are resized proportionally at upload time; if resizing fails, the original image is uploaded unchanged.', 'smart-image-upload-resizer'),
            esc_html(SIR_MAX_DIMENSION)
        );
    }

    public function optionsPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php if (!extension_loaded('gd')) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('The PHP GD extension is not available on this server, so image resizing is currently skipped. Ask your hosting provider to enable GD.', 'smart-image-upload-resizer'); ?></p>
                </div>
            <?php endif; ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('sir_plugin');
                do_settings_sections('sir_plugin');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function addSettingsLink($links) {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=smart-image-upload-resizer')) . '">' . esc_html__('Settings', 'smart-image-upload-resizer') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

new SmartImageUploadResizer();

register_activation_hook(__FILE__, function () {
    if (!extension_loaded('gd')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html__('Smart Image Upload Resizer requires the PHP GD extension. Please ask your hosting provider to enable it, then activate the plugin again.', 'smart-image-upload-resizer'));
    }

    if (!get_option('sir_settings')) {
        add_option('sir_settings', SIR_DEFAULTS);
    }
});
