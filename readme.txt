=== Backblaze Auto Upload ===
Contributors: Dimitri Nain
Tags: backblaze, cdn, media, upload, b2, cloud-storage
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically upload WordPress media files to Backblaze B2 and serve them via your custom CDN domain.

== Description ==

Backblaze Auto Upload seamlessly integrates your WordPress media library with Backblaze B2 cloud storage, allowing you to serve images and media files from a custom CDN domain powered by Cloudflare.

= Key Features =

* **Automatic uploads** - New media files are instantly uploaded to Backblaze B2
* **Bulk sync** - Sync all existing media library files with progress tracking
* **Custom CDN domain** - Serve images from your own domain (e.g., cdn.yourdomain.com)
* **Thumbnail support** - Automatically uploads all WordPress thumbnail sizes
* **Retry logic** - Failed uploads can be retried with force re-upload option
* **Clean URLs** - Images served with clean CDN URLs
* **WooCommerce compatible** - Works with product images

= Use Cases =

* Reduce server bandwidth by offloading media to Backblaze B2
* Improve page load times with CDN delivery
* Scale your media storage affordably ($6/TB/month)
* Protect against server storage limitations

= Requirements =

* Backblaze B2 account
* Cloudflare account (for custom CDN domain)
* PHP Composer access for dependency installation
* SSH access to install AWS SDK

== Installation ==

= Automatic Installation =

1. Upload the plugin files to `/wp-content/plugins/backblaze-auto-upload`
2. SSH into your server: `cd /path/to/plugin && composer install`
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Go to Backblaze CDN → Settings to configure

= Manual Installation =

1. Download the plugin zip file
2. Extract to `/wp-content/plugins/backblaze-auto-upload`
3. Run `composer install` in the plugin directory
4. Activate in WordPress admin

= Configuration =

**Step 1: Backblaze B2 Setup**
1. Create a Backblaze B2 bucket (set to Public)
2. Generate an Application Key
3. Note your bucket name, region, and credentials

**Step 2: Cloudflare CDN Setup**
1. Add CNAME: `cdn.yourdomain.com` → `f005.backblazeb2.com`
2. Create Transform Rule to rewrite paths
3. Enable Cloudflare proxy (orange cloud)

**Step 3: Plugin Configuration**
1. Navigate to Backblaze CDN → Settings
2. Enter bucket name, endpoint, CDN URL, and credentials
3. Save settings

**Step 4: Sync Existing Media**
1. Go to Backblaze CDN → Bulk Sync
2. Click Start Bulk Sync
3. Wait for completion

== Frequently Asked Questions ==

= Does this delete files from my server? =

No, files remain on your server. This plugin only copies them to Backblaze B2.

= What happens if Backblaze is down? =

Images will still load from your server if CDN delivery fails.

= Can I use this without Cloudflare? =

Yes, but you'll need another CDN provider or use Backblaze's native CDN. Cloudflare Transform Rules provide clean URLs.

= Will this work with WooCommerce? =

Yes! All WooCommerce product images are automatically synced.

= How much does Backblaze cost? =

As of 2025: $6/TB/month storage, $0.01/GB downloads. First 10GB daily downloads are free.

= Can I migrate from another CDN plugin? =

Yes. Deactivate your old plugin, configure this one, then run Bulk Sync.

= Why do I need Composer? =

The plugin uses AWS SDK for PHP to communicate with Backblaze's S3-compatible API.

== Screenshots ==

1. Settings page - Configure Backblaze credentials and CDN domain
2. Bulk Sync page - Sync existing media with progress tracking
3. Dashboard overview - View sync status and statistics

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic upload on media upload
* Bulk sync with progress tracking
* Custom CDN domain support
* Retry logic for failed uploads
* WooCommerce compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release of Backblaze Auto Upload plugin.

== Additional Info ==

= Support =

For support, please visit the plugin's support forum or check the detailed README.md file included with the plugin.

= Requirements =

* WordPress 5.0+
* PHP 7.4+
* Composer (for AWS SDK installation)
* Backblaze B2 account
* Cloudflare account (recommended)

= Privacy =

This plugin does not collect any user data. Files are uploaded to your own Backblaze B2 account.
