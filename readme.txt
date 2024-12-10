=== Smart Image Upload Resizer ===
Contributors: ivanusto
Tags: image, resize, upload, optimization, webp
Requires at least: 5.0
Tested up to: 6.7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: Apache-2.0
License URI: https://opensource.org/license/apache-2-0
Plugin URI: https://yblog.org/smart-image-upload-resizer 
Plugin Name (EN): Smart Image Upload Resizer
Author: Ivan Lin
Author URI: https://yblog.org/

A lightweight WordPress plugin that automatically resizes uploaded images, supports WebP conversion, and optimizes website loading speed.

== Description ==
Smart Image Upload Resizer is a simple yet powerful WordPress plugin that automatically adjusts image dimensions and quality during upload, with support for WebP format through WordPress's official advanced image format support plugin. It helps optimize your website's image resources.

= Key Features =
* Automatically reduces uploaded image dimensions
* Customizable maximum width and height (up to 2560 pixels)
* Adjustable image quality (compression rate)
* Supports JPEG, PNG, GIF, and WebP formats
* Compatible with WordPress's official advanced image format support plugin
* Automatically maintains image aspect ratio
* Optimized memory management for low-spec cloud hosting environments

= How to Use =
1. Install and activate the plugin through the WordPress plugins page
2. Go to "Settings" > "Smart Image Upload Resizer Settings"
3. Configure your desired maximum width, height, and image quality
4. Start uploading images, the plugin will process them automatically

= Ideal Use Cases =
* Websites needing to limit uploaded image sizes
* Storage space optimization
* Website loading speed optimization
* Websites requiring unified image dimension management

== Installation ==
1. Download and install "Smart Image Upload Resizer" from the WordPress plugin directory
2. Or upload the zip file via WordPress admin panel
3. Activate the plugin
4. Go to "Settings" > "Image Resizer Settings" to configure

== Frequently Asked Questions ==
= Will this plugin affect already uploaded images? =
No. The plugin only processes new images uploaded after activation.

= What image formats are supported? =
JPEG, PNG, GIF, and WebP formats are supported.

= What's the maximum supported image size? =
For stability on older cloud hosting specifications, the maximum supported dimension is 2560 pixels.

= How's the image quality after adjustment? =
You can customize image quality (1-100%) in settings, default is 80%. This value typically provides a good balance between file size and visual quality. For better visual quality, you can increase it to 85.

== Screenshots ==
1. Plugin settings page
2. Image upload effect demonstration

== Changelog ==
= 1.0.0 =
* Initial release

== Upgrade Notice ==
= 1.0.0 =
This version adds WebP support and improves memory usage efficiency. Recommended update for all users.

== Additional Info ==
* For optimal performance, it's recommended to configure appropriate settings before uploading images
* If your website uses caching plugins, you may need to clear the cache after changing settings