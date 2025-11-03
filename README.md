# Backblaze Auto Upload for WordPress

Automatically upload WordPress media files to Backblaze B2 and serve them via your custom CDN domain.

## Features

- ✅ **Automatic uploads** - New media files are instantly uploaded to Backblaze B2
- ✅ **Bulk sync** - Sync all existing media library files with progress tracking
- ✅ **Custom CDN domain** - Serve images from your own domain (e.g., `cdn.yourdomain.com`)
- ✅ **Thumbnail support** - Automatically uploads all WordPress thumbnail sizes
- ✅ **Retry logic** - Failed uploads can be retried with force re-upload option
- ✅ **Clean URLs** - Images are served with clean CDN URLs without extra path segments
- ✅ **Performance tracking** - Dashboard shows sync status and file counts

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Backblaze B2 account
- Cloudflare account (for CDN domain setup)
- AWS SDK PHP library (installed via Composer)

## Installation

### 1. Upload Plugin Files

Upload the `backblaze-auto-upload` folder to `/wp-content/plugins/` on your WordPress site.

### 2. Install Dependencies

SSH into your WordPress server and run:
```bash
cd /var/www/your-site/wp-content/plugins/backblaze-auto-upload
composer install
```

### 3. Activate Plugin

Go to WordPress Admin → Plugins → Activate "Backblaze Auto Upload"

## Configuration

### Step 1: Set Up Backblaze B2 Bucket

1. Log in to [Backblaze B2](https://www.backblaze.com/b2/cloud-storage.html)
2. Create a new bucket (e.g., `yoursite-uploads`)
3. Set bucket to **Public**
4. Note your bucket name and region (e.g., `us-east-005`)
5. Create an Application Key:
   - Go to App Keys → Add a New Application Key
   - Give it a name (e.g., `WordPress CDN`)
   - Save the **Key ID** and **Application Key** (shown only once!)

### Step 2: Set Up Cloudflare CDN Domain

1. Log in to [Cloudflare](https://dash.cloudflare.com/)
2. Go to your domain → DNS → Records
3. Add a CNAME record:
   - **Name:** `cdn` (or your preferred subdomain)
   - **Target:** `f005.backblazeb2.com` (use your region code)
   - **Proxy status:** Proxied (orange cloud)
4. Go to Rules → Transform Rules → Modify Request URL
5. Create a new rule:
   - **Rule name:** Backblaze B2 Path Rewrite
   - **If:** Hostname equals `cdn.yourdomain.com`
   - **Then:** Rewrite path to `/file/yoursite-uploads` + dynamic path
   - **Example:** `/2025/10/image.jpg` → `/file/yoursite-uploads/2025/10/image.jpg`

### Step 3: Configure Plugin Settings

1. Go to WordPress Admin → **Backblaze CDN** → **Settings**
2. Fill in your details:

| Setting | Example | Description |
|---------|---------|-------------|
| **Bucket Name** | `yoursite-uploads` | Your Backblaze B2 bucket name |
| **Endpoint** | `s3.us-east-005.backblazeb2.com` | Your bucket's S3-compatible endpoint |
| **CDN Base URL** | `https://cdn.yourdomain.com` | Your Cloudflare CDN domain |
| **Key ID** | `005abc123...` | Your Backblaze Application Key ID |
| **Application Key** | `K005xyz789...` | Your Backblaze Application Key |

3. Click **Save Settings**

### Step 4: Sync Existing Media (Optional)

If you have existing media files, sync them to Backblaze:

1. Go to **Backblaze CDN** → **Bulk Sync**
2. Click **Start Bulk Sync**
3. Wait for the progress bar to complete
4. Check the results summary

## Usage

### Automatic Uploads

Once configured, all new media uploads are automatically:
1. Uploaded to your WordPress media library
2. Sent to Backblaze B2 (including all thumbnail sizes)
3. Marked as synced in the database
4. Served from your CDN domain on the frontend

### Bulk Sync Options

- **Normal Sync** - Only uploads files not yet synced to Backblaze
- **Force Re-upload** - Re-uploads ALL files, even if already synced
- **Reset Sync Status** - Clears all sync flags (useful for troubleshooting)

### Retry Failed Uploads

If some files fail during bulk sync:
1. Note the failed count in the results
2. Click **Start Bulk Sync** again
3. The plugin will retry only failed files

## How It Works

### Upload Flow
```
User uploads image
       ↓
WordPress saves to /wp-content/uploads/
       ↓
Plugin detects new upload
       ↓
File uploaded to Backblaze B2 via S3 API
       ↓
Post meta "_bb_uploaded" = 1 saved
       ↓
WordPress URLs filtered to CDN domain
```

### URL Filtering

The plugin hooks into WordPress filters to replace local URLs:

- `wp_get_attachment_url` - Main image URLs
- `wp_get_attachment_image_src` - Thumbnail URLs
- `wp_calculate_image_srcset` - Responsive image srcsets

**Before:** `https://yourdomain.com/wp-content/uploads/2025/10/image.jpg`  
**After:** `https://cdn.yourdomain.com/2025/10/image.jpg`

### Database Tracking

Each uploaded file is tracked with post meta:

| Meta Key | Value | Meaning |
|----------|-------|---------|
| `_bb_uploaded` | `1` | Successfully uploaded to Backblaze |
| `_bb_uploaded` | `failed` | Upload failed (will retry on next sync) |
| *(no meta)* | - | Not yet uploaded |

## Troubleshooting

### Images Not Loading from CDN

**Check the actual HTML source:**
```bash
curl -s https://yourdomain.com/sample-page/ | grep -oE 'src="[^"]*\.(webp|jpg|jpeg|png)"' | head -10
```

If you see `cdn.yourdomain.com` in the output, it's working! Clear these caches:
1. **WordPress object cache:** `wp cache flush`
2. **LiteSpeed Cache:** Admin → LiteSpeed Cache → Toolbox → Purge All
3. **Cloudflare:** Dashboard → Caching → Purge Everything
4. **Browser:** Ctrl+Shift+Delete → Clear images/files

### Upload Failures

Check WordPress error logs:
```bash
tail -f /var/www/your-site/wp-content/debug.log
```

Common issues:
- **Wrong credentials** - Verify Key ID and Application Key
- **Wrong endpoint** - Must match your bucket's region (e.g., `s3.us-east-005.backblazeb2.com`)
- **Bucket not public** - Set bucket to Public in Backblaze dashboard
- **File permissions** - WordPress needs read access to `/wp-content/uploads/`

### Verify File on Backblaze

Check if a file actually uploaded:
```bash
rclone ls b2:yoursite-uploads/2025/10/ | grep filename.jpg
```

Or use the Backblaze web interface:
- Log in → Browse Files → Select your bucket

### Reset Everything

To start fresh:
1. Go to **Backblaze CDN** → **Bulk Sync**
2. Click **Reset All Sync Status**
3. Run **Start Bulk Sync** again

## WP-CLI Commands

### Check if image is synced
```bash
wp post meta get <attachment_id> _bb_uploaded
```

### Get CDN URL for image
```bash
wp eval "echo wp_get_attachment_url(<attachment_id>);"
```

### Count synced images
```bash
wp db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_bb_uploaded' AND meta_value='1';"
```

### Clear object cache
```bash
wp cache flush
```

## File Structure & Technical Details

```
backblaze-auto-upload/
├── backblaze-auto-upload.php           # Main plugin file (28 lines)
├── composer.json                       # Dependency management
├── composer.lock                       # Locked dependency versions
├── vendor/                             # AWS SDK and dependencies
├── includes/
│   ├── class-uploader.php             # Backblaze API communication (89 lines)
│   ├── class-settings.php             # Settings page & test uploads (103 lines)
│   ├── class-media-handler.php        # URL filtering & media interception (255 lines)
│   ├── class-bulk-sync.php            # Bulk sync UI & AJAX (359 lines)
│   └── class-admin-menu.php           # Admin menu structure (60 lines)
└── README.md                           # This file
```

### Core File Responsibilities

#### `backblaze-auto-upload.php` (Main Plugin)
- Entry point for the entire plugin
- Loads all required class files
- Instantiates the four main classes
- Runs on WordPress init

#### `includes/class-uploader.php` (Backblaze API)
- Direct S3-compatible API communication with Backblaze B2
- AWS Signature Version 4 authentication
- File upload with proper MIME type handling
- Methods:
  - `upload_file($file_path, $relative_path)` - Sends file to Backblaze
  - `get_auth_header()` - Generates AWS4-HMAC-SHA256 signature
  - `get_cdn_url()` - Returns configured CDN URL

#### `includes/class-settings.php` (Configuration)
- WordPress admin settings page registration
- Stores Backblaze credentials as WordPress options
- Test upload functionality to verify connectivity
- Settings:
  - `bb_bucket` - Backblaze bucket name
  - `bb_endpoint` - S3 endpoint (default: s3.us-east-005.backblazeb2.com)
  - `bb_cdn_url` - CDN base URL (e.g., https://cdn.deckandco.com/file/deck-uploads)
  - `bb_key_id` - Backblaze Application Key ID
  - `bb_app_key` - Backblaze Application Key

#### `includes/class-media-handler.php` (Core URL Filtering) ⭐ CRITICAL
**This is the most important file for CDN functionality.**

Handles all image URL replacements and prevents CSS/JS files from CDN redirection.

**Multiple URL filtering layers:**

1. **WordPress Hooks** (attachment-level):
   - `wp_get_attachment_url` - Main image URLs
   - `wp_get_attachment_image_src` - Thumbnail URLs
   - `wp_calculate_image_srcset` - Responsive image sizes

2. **Output Buffering** (HTML-level):
   - Catches final HTML output before sending to browser
   - Replaces remaining image URLs via regex

3. **Content Filters** (post content):
   - Processes post/page content separately

**CSS/JS Protection (multiple safeguards):**
- File extension checks: `.css`, `.js`
- Elementor path checks: `/elementor/css/`
- `prevent_css_cdn()` filter reverses any accidental CDN substitution
- Ensures stylesheets always load from local server

#### `includes/class-bulk-sync.php` (Batch Processing)
- Admin page for syncing entire media library
- AJAX-powered progress tracking
- Batch processing (5 files per request) to avoid timeouts
- Features:
  - Real-time progress bar
  - Detailed sync log
  - Error tracking and retry logic
  - Force re-upload option
  - Reset sync status button
- Methods:
  - `sync_page()` - Renders admin UI with JavaScript
  - `ajax_sync_batch()` - Main AJAX endpoint
  - `get_file_counts()` - Statistics query
  - `sync_batch()` - Process batch of attachments
  - `reset_sync_status()` - Clear all metadata

#### `includes/class-admin-menu.php` (Admin Interface)
- Registers Backblaze CDN menu in WordPress admin
- Creates Settings submenu
- Creates Bulk Sync submenu
- Adds "Settings" link to plugin action row
- Icon: `dashicons-cloud-upload`
- Position: 65 (between Tools and Settings)

## Recent Improvements (November 2025)

### URL Filtering Refinements
- **Improved regex patterns** - More precise image URL matching to avoid false positives
- **Better srcset handling** - Properly processes responsive image sizes in srcset attributes
- **Stronger CSS protection** - Added file extension checks (`.css`, `.js`) to prevent stylesheet CDN redirection
- **Enhanced output buffering** - Optimized regex patterns for better performance

### CSS/JS File Protection
The plugin now uses multiple safeguards to ensure stylesheets and JavaScript files always load locally:

```php
// Check file extensions
if (preg_match('/\.(css|js)(\?|$)/i', $src)) {
    // Prevent CDN redirection
}

// Check Elementor paths
if (strpos($src, '/elementor/css/') !== false) {
    // Prevent CDN redirection
}
```

### Testing Results (Staging Environments)
- ✅ Images load from CDN: `https://cdn.deckandco.com/...`
- ✅ CSS files load locally: `https://staging.deckandco.com/wp-content/...`
- ✅ Responsive images (srcset) properly redirect to CDN
- ✅ Elementor CSS files remain on local server

## Performance Considerations

- **First bulk sync** - May take several hours for thousands of files
- **Thumbnail uploads** - Each image generates 5-10 thumbnail sizes
- **Bandwidth** - Files are served from Backblaze/Cloudflare, saving your server bandwidth
- **Storage** - Files remain on your server AND Backblaze (plugin doesn't delete local files)
- **Output buffering** - Minimal performance impact on modern servers

## Security

- **Credentials** - Stored in WordPress options table (not in files)
- **Public bucket** - Required for CDN serving; don't store sensitive files
- **Application key** - Use a restricted key with only upload permissions
- **HTTPS** - All CDN URLs use HTTPS via Cloudflare

## FAQ

**Q: Does this delete files from my server?**  
A: No, files remain on your server. This plugin only copies them to Backblaze.

**Q: What happens if Backblaze is down?**  
A: WordPress will attempt to upload. If it fails, the image will still work from your server.

**Q: Can I use this without Cloudflare?**  
A: Yes, but you'll need to configure Backblaze's native CDN or another CDN provider. The Cloudflare Transform Rule is necessary for clean URLs.

**Q: Will this work with WooCommerce product images?**  
A: Yes! WooCommerce uses WordPress's media library, so all product images are automatically synced.

**Q: How much does Backblaze cost?**  
A: As of 2025: $6/TB/month for storage, $0.01/GB for downloads. First 10GB of downloads per day are free.

**Q: Can I migrate from another CDN plugin?**  
A: Yes. Deactivate your old CDN plugin, configure this one, then run Bulk Sync.

## Support

For issues or questions:
1. Check the Troubleshooting section above
2. Review Backblaze B2 documentation: https://www.backblaze.com/docs/
3. Review Cloudflare Transform Rules: https://developers.cloudflare.com/rules/transform/

## License

This plugin is provided as-is for personal and commercial use.

## Credits

Developed for deckandco.com using:
- Backblaze B2 S3-compatible API
- AWS SDK for PHP
- WordPress Media Library hooks

---

**Version:** 1.0.0  
**Last Updated:** October 2025
