# Backblaze Auto Upload - LXD Staging Deployment Guide

**Target:** staging.luxuriousdwelling.com (LXD)
**Backblaze Bucket:** lxd-uploads
**Region:** us-east-005
**Credentials:** Already configured (see below)

---

## Pre-Deployment Checklist

### 1. Verify Credentials
- [x] **Key ID:** `005c8a1e69f60c80000000003`
- [x] **Key Name:** `ecom-backup`
- [x] **Application Key:** `K005kDG+xSJQpZrqbfAN1ZFirNjtuhI`
- [x] **Bucket Name:** `lxd-uploads`
- [x] **S3 Endpoint:** `s3.us-east-005.backblazeb2.com`
- [ ] **CDN Domain:** Must configure (see below)
- [ ] **Bucket visibility:** Verify bucket is Public on Backblaze

### 2. Cloudflare CDN Setup (Required)

1. **Add CNAME record in Cloudflare:**
   - Name: `cdn` (or preferred subdomain)
   - Target: `f005.backblazeb2.com`
   - Proxy status: Proxied (orange cloud)
   - TTL: Auto

2. **Create Transform Rule for path rewriting:**
   - Go to: Rules → Transform Rules → Modify Request URL
   - **If:** Hostname equals `cdn.staging.luxuriousdwelling.com`
   - **Then:** Rewrite path to `/file/lxd-uploads` + dynamic path
   - **Example rule:**
     ```
     If request path matches: ^/(.*)$
     Then rewrite path to: /file/lxd-uploads/$1
     ```

3. **Verify setup:**
   ```bash
   curl -I https://cdn.staging.luxuriousdwelling.com/backblaze-test/test.txt
   # Should return 200 OK (if test file exists in Backblaze)
   ```

---

## Installation Steps

### Step 1: Upload Plugin Files

```bash
# Copy plugin to LXD staging
scp -r /var/www/staging.deckandco.com/wp-content/plugins/backblaze-auto-upload \
    user@lxd-staging:/var/www/staging.luxuriousdwelling.com/wp-content/plugins/
```

Or manually:

```bash
mkdir -p /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload
cp -r /var/www/staging.deckandco.com/wp-content/plugins/backblaze-auto-upload/* \
    /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload/
```

### Step 2: Install Composer Dependencies

```bash
cd /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload
composer install
```

Expected output:
```
Installing dependencies from lock file
Package operations: ... packages installed
```

### Step 3: Activate Plugin in WordPress Admin

1. Go to WordPress Admin → Plugins
2. Find "Backblaze Auto Upload"
3. Click "Activate"

### Step 4: Configure Plugin Settings

1. Go to WordPress Admin → **Backblaze CDN** → **Settings**
2. Fill in the following:

| Setting | Value |
|---------|-------|
| **Bucket Name** | `lxd-uploads` |
| **S3 Endpoint** | `s3.us-east-005.backblazeb2.com` |
| **CDN URL** | `https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads` |
| **Key ID** | `005c8a1e69f60c80000000003` |
| **Application Key** | `K005kDG+xSJQpZrqbfAN1ZFirNjtuhI` |
| **Excluded Extensions** | `css,js` (default, adjust as needed) |

3. Click **Save Settings**

### Step 5: Test Connection

#### Via WordPress Admin:
1. Go to **Backblaze CDN** → **Settings**
2. Scroll to "Test Upload"
3. Click **Run Upload Test**
4. Should see: "✓ Upload Successful!" with a CDN URL

#### Via WP-CLI:
```bash
wp bb-sync test
# Output: Connection successful!
# Test file uploaded to: backblaze-test/test-XXXXXX.txt
# CDN URL: https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/backblaze-test/test-XXXXXX.txt
```

### Step 6: Run Test Suite

```bash
wp bb-sync test-urls
```

This will verify:
- ✓ Basic image URL replacement
- ✓ PNG, WebP, SVG image replacement
- ✓ CSS/JS file exclusion
- ✓ Elementor CSS exclusion
- ✓ Srcset attribute handling
- ✓ File validation

---

## Syncing Existing Media (30GB+)

### Option A: CLI with Progress Bar (Recommended for 30GB+)

```bash
# Start sync in background with nohup
nohup wp bb-sync start > /var/log/bb-sync.log 2>&1 &

# Monitor progress
tail -f /var/log/bb-sync.log

# Check status anytime
wp bb-sync status
```

**Performance expectations for 30GB:**
- Batch size: 5 files per request
- Network speed: ~50 files/minute (depends on server/CDN bandwidth)
- **Estimated time: 5-10 hours for 30GB**

### Option B: WordPress Admin UI

1. Go to **Backblaze CDN** → **Bulk Sync**
2. (Optional) Check **Force re-upload** if re-syncing
3. Click **Start Bulk Sync**
4. Monitor progress bar and log

### Option C: Scheduled via WP-Cron (Best for Long Syncs)

```bash
# Create a custom cron job to restart syncs on timeout
cat > /tmp/sync-cron.sh << 'EOF'
#!/bin/bash
LOGFILE="/var/log/bb-sync.log"
PID_FILE="/tmp/bb-sync.pid"

# Check if sync is already running
if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
    echo "[$(date)] Sync already running with PID $(cat $PID_FILE)" >> $LOGFILE
    exit 0
fi

# Start sync and save PID
cd /var/www/staging.luxuriousdwelling.com
wp bb-sync start >> $LOGFILE 2>&1 &
echo $! > $PID_FILE

# Wait for completion
wait $!
rm -f $PID_FILE
EOF

chmod +x /tmp/sync-cron.sh

# Add to crontab (runs every hour, but only if not already running)
(crontab -l 2>/dev/null; echo "0 * * * * /tmp/sync-cron.sh") | crontab -
```

---

## Monitoring & Troubleshooting

### Check Sync Status

```bash
wp bb-sync status
```

Output example:
```
Backblaze Sync Status:
─────────────────────────────────────
Total Files       : 15234
Synced           : 8921
Failed           : 45
Pending          : 6268
─────────────────────────────────────
Completion: 58.35%
```

### Retry Failed Uploads

```bash
# Retry all failed uploads
wp bb-sync retry

# Retry only first 10 failures
wp bb-sync retry --count=10
```

### View Sync Logs

```bash
# CLI log
tail -f /var/log/bb-sync.log

# WordPress error log
tail -f /var/www/staging.luxuriousdwelling.com/wp-content/debug.log

# Check specific failures
wp db query "SELECT post_id, meta_value FROM wp_postmeta
    WHERE meta_key = '_bb_uploaded' AND meta_value = 'failed' LIMIT 10;"
```

### Reset Sync Status

```bash
# Clear all sync flags and start fresh
wp bb-sync reset

# Confirm when prompted
```

---

## Validation: URL Replacement Verification

### Test 1: Check a page source

```bash
curl -s https://staging.luxuriousdwelling.com/sample-page/ | grep -oE 'src="[^"]*\.(jpg|png|webp)"' | head -5
```

Expected output:
```
src="https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/2025/10/image.jpg"
src="https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/2025/11/photo.png"
```

### Test 2: Verify CSS files NOT from CDN

```bash
curl -s https://staging.luxuriousdwelling.com/sample-page/ | grep -oE 'href="[^"]*\.css"' | head -5
```

Expected output (local URLs):
```
href="https://staging.luxuriousdwelling.com/wp-content/themes/..."
href="https://staging.luxuriousdwelling.com/wp-content/plugins/..."
```

### Test 3: Verify Elementor CSS protected

```bash
curl -s https://staging.luxuriousdwelling.com/sample-page/ | grep -i 'elementor' | grep 'css' | head -3
```

Should show:
- ✓ Elementor CSS loading from local server
- ✗ NO Elementor CSS from CDN

### Test 4: Download image to verify content

```bash
# Get a synced image from CDN
curl -I https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/2025/10/test.jpg

# Should return 200 OK and correct size
# HTTP/2 200
# content-length: 123456
```

---

## Performance Tuning

### Optimize Batch Size

For 30GB of images, adjust batch size based on server capacity:

```bash
# Large images or slower servers: 3 files/batch
wp bb-sync start --batch-size=3

# Fast servers: 10 files/batch
wp bb-sync start --batch-size=10

# Monitor memory usage and adjust
watch 'free -h && wp bb-sync status'
```

### Monitor During Sync

```bash
# Terminal 1: Watch sync progress
watch -n 5 'wp bb-sync status'

# Terminal 2: Monitor server resources
watch -n 5 'free -h && ps aux | grep php'

# Terminal 3: Monitor CDN hits
tail -f /var/log/bb-sync.log
```

---

## Post-Sync Checklist

After syncing completes:

- [ ] Verify sync completion: `wp bb-sync status` (100%)
- [ ] Test page loading: Open staging site, check images load
- [ ] Inspect Network tab: Verify images from CDN, CSS from local
- [ ] Check failed files: `wp bb-sync status` (Failed: 0 or acceptable)
- [ ] Clear caches:
  ```bash
  wp cache flush
  wp rewrite flush
  # Clear Cloudflare cache in dashboard if applicable
  ```
- [ ] Run URL replacement tests: `wp bb-sync test-urls`
- [ ] Spot check 10+ pages for CDN URLs
- [ ] Monitor error logs for 24 hours: `tail -f wp-content/debug.log`

---

## Reverting/Troubleshooting

### If images not loading from CDN:

```bash
# 1. Check plugin is active
wp plugin list | grep backblaze

# 2. Verify settings saved
wp option get bb_bucket
wp option get bb_cdn_url

# 3. Test connection
wp bb-sync test

# 4. Check post meta
wp db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_bb_uploaded' AND meta_value='1';"

# 5. Clear object cache
wp cache flush

# 6. Check debug log
tail -50 wp-content/debug.log
```

### If sync stalled/timed out:

```bash
# Check if process is still running
ps aux | grep wp-cli

# Check last sync status
wp bb-sync status

# Retry failed uploads
wp bb-sync retry

# Or restart from scratch
wp bb-sync reset
wp bb-sync start
```

### Disable plugin safely:

```bash
wp plugin deactivate backblaze-auto-upload

# This will stop syncing but won't break existing CDN URLs
# Re-enable with:
wp plugin activate backblaze-auto-upload
```

---

## Important Notes

1. **Local files retained:** Plugin does NOT delete local files after upload. This ensures fallback if CDN fails.

2. **Database tracking:** Each uploaded file is tracked in post meta:
   - `_bb_uploaded` = `1` → Successfully synced
   - `_bb_uploaded` = `failed` → Upload failed, will retry
   - *(no meta)* → Not yet uploaded

3. **Bandwidth costs:** First 10GB/day downloads are free from Backblaze. After that: $0.01/GB
   - Monitor Backblaze dashboard for bandwidth usage
   - Consider bandwidth caps if high traffic expected

4. **Large file uploads:** For files > 100MB, consider increasing PHP timeout:
   ```bash
   # In php.ini
   max_execution_time = 600
   upload_max_filesize = 1000M
   post_max_size = 1000M
   ```

5. **Cloudflare cache:** Add Backblaze URLs to cache rules for optimal performance:
   - Cache on: `cdn.staging.luxuriousdwelling.com/*`
   - TTL: 30 days
   - Bypass: `backblaze-test/` (test files)

---

## Support & Documentation

- **Backblaze B2 Docs:** https://www.backblaze.com/docs/
- **Cloudflare Transform Rules:** https://developers.cloudflare.com/rules/transform/
- **WP-CLI Reference:** https://developer.wordpress.org/cli/commands/
- **Plugin README:** See `/path/to/plugin/README.md`

---

**Version:** 1.0.0 Enhanced
**Last Updated:** November 2025
**Estimated Sync Time (30GB):** 5-10 hours
**Success Criteria:** All images load from CDN, CSS/JS from local server
