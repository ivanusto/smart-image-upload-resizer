<?php
/**
 * Plugin Name: Smart Image Upload Resizer
 * Plugin URI: https://yblog.org/smart-image-upload-resizer
 * Description: 自動調整上傳圖片尺寸的 WordPress 外掛，支援 WebP 轉換
 * Version: 1.1.0
 * Plugin Name (EN): Smart Image Upload Resizer
 * Author: Ivan Lin
 * Author URI: https://yblog.org/
 * Text Domain: smart-image-upload-resizer
 * License: Apache-2.0
 * License URI: https://opensource.org/license/apache-2-0
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

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'settingsInit']);
        add_filter('wp_handle_upload_prefilter', [$this, 'preHandleUpload']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'addSettingsLink']);
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
                return imagecreatefromwebp($file_path);
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
                return imagewebp($image, $file_path, $quality);
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

    public function preHandleUpload($file) {
        if (!preg_match('!^image/!', $file['type'])) {
            return $file;
        }

        $source_image  = false;
        $resized_image = false;

        try {
            $this->increaseMemoryLimit();

            $image_size = getimagesize($file['tmp_name']);
            if (!$image_size) {
                throw new Exception('無法獲取圖片尺寸');
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
                throw new Exception('無法建立圖片資源');
            }

            $resized_image = $this->resizeImage(
                $source_image,
                $orig_width, $orig_height,
                $new_width, $new_height,
                $file['type']
            );

            if (!$resized_image) {
                throw new Exception('無法調整圖片大小');
            }

            if (!$this->saveImage($resized_image, $file['tmp_name'], $file['type'], (int) $this->options['quality'])) {
                throw new Exception('無法儲存圖片');
            }

            $file['size'] = filesize($file['tmp_name']);

        } catch (Exception $e) {
            $file['error'] = $e->getMessage();
        } finally {
            if ($source_image)  imagedestroy($source_image);
            if ($resized_image) imagedestroy($resized_image);
        }

        return $file;
    }

    public function addAdminMenu() {
        add_options_page(
            '圖片上傳自動縮圖器設定',
            '圖片上傳自動縮圖器',
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
            'Image Size Option / 圖片尺寸設定',
            [$this, 'settingsSectionCallback'],
            'sir_plugin'
        );

        add_settings_field('max_width',  'Max Width / 最大寬度',    [$this, 'maxWidthRender'],  'sir_plugin', 'sir_plugin_section');
        add_settings_field('max_height', 'Max Height / 最大高度',   [$this, 'maxHeightRender'], 'sir_plugin', 'sir_plugin_section');
        add_settings_field('quality',    'Image Quality / 圖片品質', [$this, 'qualityRender'],   'sir_plugin', 'sir_plugin_section');
    }

    public function sanitizeSettings($input) {
        $sanitized = [];
        $sanitized['max_width']  = min(SIR_MAX_DIMENSION, max(1, (int) ($input['max_width']  ?? SIR_DEFAULTS['max_width'])));
        $sanitized['max_height'] = min(SIR_MAX_DIMENSION, max(1, (int) ($input['max_height'] ?? SIR_DEFAULTS['max_height'])));
        $sanitized['quality']    = min(100, max(1, (int) ($input['quality']    ?? SIR_DEFAULTS['quality'])));
        return $sanitized;
    }

    public function maxWidthRender() {
        $value = (int) ($this->options['max_width'] ?? SIR_DEFAULTS['max_width']);
        echo '<input type="number" min="1" max="' . SIR_MAX_DIMENSION . '" name="sir_settings[max_width]" value="' . esc_attr($value) . '">';
        echo '<span> px (Max ' . SIR_MAX_DIMENSION . ')</span>';
    }

    public function maxHeightRender() {
        $value = (int) ($this->options['max_height'] ?? SIR_DEFAULTS['max_height']);
        echo '<input type="number" min="1" max="' . SIR_MAX_DIMENSION . '" name="sir_settings[max_height]" value="' . esc_attr($value) . '">';
        echo '<span> px (Max ' . SIR_MAX_DIMENSION . ')</span>';
    }

    public function qualityRender() {
        $value = (int) ($this->options['quality'] ?? SIR_DEFAULTS['quality']);
        echo '<input type="number" min="1" max="100" name="sir_settings[quality]" value="' . esc_attr($value) . '">';
        echo '<span> %</span>';
    }

    public function settingsSectionCallback() {
        echo 'Configuring maximum upload dimensions and quality for images (maximum dimension is restricted to ' . SIR_MAX_DIMENSION . ' pixels). '
           . '設定上傳圖片時的最大尺寸與品質（最大尺寸限制為 ' . SIR_MAX_DIMENSION . ' 像素）。';
    }

    public function optionsPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h2>Smart Image Upload Resizer / 圖片上傳自動縮圖器設定</h2>
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
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=smart-image-upload-resizer')) . '">設定</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

new SmartImageUploadResizer();

register_activation_hook(__FILE__, function () {
    if (!extension_loaded('gd')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Smart Image Upload Resizer 需要 PHP GD 擴充功能，請聯絡主機商啟用後再安裝。');
    }

    if (!get_option('sir_settings')) {
        add_option('sir_settings', SIR_DEFAULTS);
    }
});
