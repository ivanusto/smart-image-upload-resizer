<?php
/**
 * Plugin Name: Smart Image Upload Resizer
 * Plugin URI:https://yblog.org/smart-image-upload-resizer 
 * Description: 自動調整上傳圖片尺寸的 WordPress 外掛，支援 WebP 轉換
 * Version: 1.0.0
 * Plugin Name (EN): Smart Image Upload Resizer
 * Author: Ivan Lin
 * Author URI:https://yblog.org/
 * Text Domain: smart-image-upload-resizer
 * License: Apache-2.0
 * License URI: https://opensource.org/license/apache-2-0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SmartImageUploadResizer {
    private $options;

    public function __construct() {
        $this->options = get_option('sir_settings', [
            'max_width' => 1920,
            'max_height' => 1080,
            'quality' => 80
        ]);

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'settingsInit']);
        add_filter('wp_handle_upload_prefilter', [$this, 'preHandleUpload']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'addSettingsLink']);
    }

    private function increaseMemoryLimit() {
        if (function_exists('wp_raise_memory_limit')) {
            return wp_raise_memory_limit('image');
        }
        return false;
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

    private function resizeImage($source_image, $source_width, $source_height, $target_width, $target_height) {
        $target_image = imagecreatetruecolor($target_width, $target_height);
        if (!$target_image) {
            return false;
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
                $png_quality = floor((100 - $quality) / 10);
                return imagepng($image, $file_path, $png_quality);
            case 'image/gif':
                return imagegif($image, $file_path);
            case 'image/webp':
                return imagewebp($image, $file_path, $quality);
            default:
                return false;
        }
    }

    public function preHandleUpload($file) {
        if (!preg_match('!^image/!', $file['type'])) {
            return $file;
        }

        try {
            $this->increaseMemoryLimit();
            
            $image_size = getimagesize($file['tmp_name']);
            if (!$image_size) {
                throw new Exception('無法獲取圖片尺寸');
            }

            list($orig_width, $orig_height) = $image_size;

            if ($orig_width <= $this->options['max_width'] && $orig_height <= $this->options['max_height']) {
                return $file;
            }

            $ratio_orig = $orig_width / $orig_height;

            if ($this->options['max_width'] / $this->options['max_height'] > $ratio_orig) {
                $new_width = min($this->options['max_height'] * $ratio_orig, 2560);
                $new_height = min($this->options['max_height'], 2560);
            } else {
                $new_width = min($this->options['max_width'], 2560);
                $new_height = min($this->options['max_width'] / $ratio_orig, 2560);
            }

            $source_image = $this->createImageFromFile($file['tmp_name'], $file['type']);
            if (!$source_image) {
                throw new Exception('無法建立圖片資源');
            }

            $resized_image = $this->resizeImage(
                $source_image,
                $orig_width,
                $orig_height,
                (int)$new_width,
                (int)$new_height
            );

            if (!$resized_image) {
                throw new Exception('無法調整圖片大小');
            }

            if (!$this->saveImage($resized_image, $file['tmp_name'], $file['type'], $this->options['quality'])) {
                throw new Exception('無法儲存圖片');
            }

            imagedestroy($source_image);
            imagedestroy($resized_image);

            $file['size'] = filesize($file['tmp_name']);

        } catch (Exception $e) {
            $file['error'] = $e->getMessage();
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
        register_setting('sir_plugin', 'sir_settings');

        add_settings_section(
            'sir_plugin_section',
            'Image Size Option/圖片尺寸設定',
            [$this, 'settingsSectionCallback'],
            'sir_plugin'
        );

        add_settings_field(
            'max_width',
            'Max Width/最大寬度',
            [$this, 'maxWidthRender'],
            'sir_plugin',
            'sir_plugin_section'
        );

        add_settings_field(
            'max_height',
            'Max Height/最大高度',
            [$this, 'maxHeightRender'],
            'sir_plugin',
            'sir_plugin_section'
        );

        add_settings_field(
            'quality',
            'Image Quality/圖片品質',
            [$this, 'qualityRender'],
            'sir_plugin',
            'sir_plugin_section'
        );
    }

    public function maxWidthRender() {
        $options = get_option('sir_settings');
        ?>
        <input type='number' name='sir_settings[max_width]' 
               value='<?php echo esc_attr(isset($options['max_width']) ? $options['max_width'] : 1920); ?>'>
        <span>Pixel/像素 (Max 2560)</span>
        <?php
    }

    public function maxHeightRender() {
        $options = get_option('sir_settings');
        ?>
        <input type='number' name='sir_settings[max_height]' 
               value='<?php echo esc_attr(isset($options['max_height']) ? $options['max_height'] : 1080); ?>'>
        <span>Pixel/像素 (Max 2560)</span>
        <?php
    }

    public function qualityRender() {
        $options = get_option('sir_settings');
        ?>
        <input type='number' min='1' max='100' name='sir_settings[quality]' 
               value='<?php echo esc_attr(isset($options['quality']) ? $options['quality'] : 80); ?>'>
        <span>%</span>
        <?php
    }

    public function settingsSectionCallback() {
        echo 'Configuring maximum upload dimensions and quality for images (For compatibility reasons, the maximum dimension is restricted to 2560 pixels). 設定上傳圖片時的最大尺寸與品質（為確保相容性，最大尺寸限制為 2560 像素）';
    }

    public function optionsPage() {
        ?>
        <div class="wrap">
            <h2>Smart Image Upload Resizer/圖片上傳自動縮圖器設定</h2>
            <form action='options.php' method='post'>
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
        $settings_link = '<a href="options-general.php?page=smart-image-upload-resizer">設定</a>';
        array_push($links, $settings_link);
        return $links;
    }
}

$smartImageResizer = new SmartImageUploadResizer();

register_activation_hook(__FILE__, function() {
    if (!get_option('sir_settings')) {
        add_option('sir_settings', [
            'max_width' => 1920,
            'max_height' => 1080,
            'quality' => 80
        ]);
    }
});
