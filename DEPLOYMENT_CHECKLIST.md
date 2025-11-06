# LXD Staging Deployment Checklist

**Target:** staging.luxuriousdwelling.com
**Bucket:** lxd-uploads
**Data Volume:** 30GB+ (~15,000-20,000 files)
**Estimated Sync Time:** 5-10 hours

---

## Phase 1: Pre-Deployment (D&Co Server)

- [x] Code review completed
- [x] Security audit completed
- [x] All PHP files syntax checked
- [x] All improvements documented
- [x] Test suite created (21 tests)
- [x] CLI commands implemented (6 commands)
- [x] Documentation complete (5 files):
  - [x] README.md (original)
  - [x] IMPROVEMENTS.md
  - [x] DEPLOYMENT_LXD.md
  - [x] QUICK_START.md
  - [x] MIGRATION_SUMMARY.md
  - [x] CHANGELOG.md
  - [x] This checklist

---

## Phase 2: Cloudflare Setup (One-time)

**⚠️ IMPORTANT: Complete this BEFORE deploying plugin**

### DNS Configuration
- [ ] Log in to Cloudflare dashboard for LXD domain
- [ ] Go to DNS → Records
- [ ] Add CNAME record:
  - Name: `cdn`
  - Target: `f005.backblazeb2.com`
  - Proxy status: **Proxied** (orange cloud)
  - TTL: Auto

### Transform Rule (Path Rewriting)
- [ ] Go to Rules → Transform Rules → Modify Request URL
- [ ] Create new rule:
  - Rule name: "Backblaze B2 Path Rewrite"
  - If: Hostname equals `cdn.staging.luxuriousdwelling.com`
  - Then: Rewrite request path
  - Rewrite to: `/file/lxd-uploads$1` (where $1 = original path)

### Verification
- [ ] Test DNS: `nslookup cdn.staging.luxuriousdwelling.com`
  - Should resolve to Cloudflare IP
- [ ] Test HTTPS: `curl -I https://cdn.staging.luxuriousdwelling.com/`
  - Should return 403 or similar (domain working, no content yet)

---

## Phase 3: Plugin Deployment

### File Transfer
- [ ] Copy plugin to LXD server:
  ```bash
  scp -r /var/www/staging.deckandco.com/wp-content/plugins/backblaze-auto-upload \
      user@lxd-staging:/var/www/staging.luxuriousdwelling.com/wp-content/plugins/
  ```
  OR
  ```bash
  cp -r /path/to/plugin /var/www/staging.luxuriousdwelling.com/wp-content/plugins/
  ```

- [ ] Verify files copied:
  ```bash
  ls -la /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload/
  # Should show: backblaze-auto-upload.php, includes/, vendor/, README.md, etc.
  ```

### Composer Installation
- [ ] SSH to LXD server
- [ ] Install dependencies:
  ```bash
  cd /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload
  composer install
  ```
  - [ ] Wait for completion: "... packages installed"
  - [ ] Verify `/vendor` directory created

### Plugin Activation
- [ ] Activate via WordPress Admin:
  1. Go to Plugins
  2. Find "Backblaze Auto Upload"
  3. Click "Activate"

OR via WP-CLI:
- [ ] Run: `wp plugin activate backblaze-auto-upload`
- [ ] Verify: `wp plugin list | grep backblaze` (shows "active")

---

## Phase 4: Configuration

### Settings via WordPress Admin
1. [ ] Navigate to: **Backblaze CDN** → **Settings**
2. [ ] Enter Backblaze credentials:
   - [ ] Bucket Name: `lxd-uploads`
   - [ ] S3 Endpoint: `s3.us-east-005.backblazeb2.com`
   - [ ] CDN URL: `https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads`
   - [ ] Key ID: `005c8a1e69f60c80000000003`
   - [ ] Application Key: `K005kDG+xSJQpZrqbfAN1ZFirNjtuhI`
   - [ ] Excluded Extensions: `css,js` (default is fine)
3. [ ] Click **Save Settings**
4. [ ] Settings saved message appears

### Settings via WP-CLI (Optional)
```bash
wp option update bb_bucket lxd-uploads
wp option update bb_endpoint s3.us-east-005.backblazeb2.com
wp option update bb_cdn_url https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads
wp option update bb_key_id 005c8a1e69f60c80000000003
wp option update bb_app_key K005kDG+xSJQpZrqbfAN1ZFirNjtuhI
wp option update bb_excluded_extensions css,js
```

---

## Phase 5: Testing

### Connection Test
- [ ] Via WordPress Admin:
  1. Go to **Backblaze CDN** → **Settings**
  2. Scroll to "Test Upload"
  3. Click **Run Upload Test**
  4. Verify: "✓ Upload Successful!" message appears
  5. Note CDN URL shown

OR via WP-CLI:
```bash
wp bb-sync test
```
- [ ] Output shows: "Connection successful!"
- [ ] Test file uploaded to Backblaze
- [ ] CDN URL accessible

### URL Replacement Test
```bash
wp bb-sync test-urls
```
- [ ] All tests pass (21/21)
- [ ] Report shows green checkmarks
- [ ] No failures reported

### Backblaze Verification
- [ ] Log in to Backblaze B2 dashboard
- [ ] Navigate to lxd-uploads bucket
- [ ] Browse Files
- [ ] Look for `backblaze-test/` folder
- [ ] Verify test file exists with correct timestamp

---

## Phase 6: Bulk Sync (30GB - The Long Part)

### Pre-Sync Verification
- [ ] Check file count:
  ```bash
  wp post list --post-type=attachment --post_mime_type=image --field=ID | wc -l
  # Should show ~15,000-20,000
  ```

- [ ] Check current sync status:
  ```bash
  wp bb-sync status
  # Should show:
  # Total Files: 15000
  # Synced: 0
  # Failed: 0
  # Pending: 15000
  ```

### Start Sync (Choose one method)

**Method A: Interactive CLI with Progress Bar (Recommended)**
```bash
wp bb-sync start
# Shows: Uploading files [======>    ] 45%
# Shows: ✓ Synced: 6734/15000 files
```
- [ ] Monitor completes
- [ ] All files processed
- [ ] Failed count is acceptable (< 100)

**Method B: Background Sync with Monitoring**
```bash
# Terminal 1: Start in background
nohup wp bb-sync start > /var/log/bb-sync.log 2>&1 &

# Terminal 2: Monitor progress every 30 seconds
watch -n 30 'wp bb-sync status'

# Terminal 3: Monitor system resources
watch -n 5 'free -h && ps aux | grep php | grep -v grep'
```

**Method C: Scheduled (if interrupted)**
```bash
# Create cron job that restarts sync if needed
cat > /tmp/bb-sync-cron.sh << 'SCRIPT'
#!/bin/bash
LOGFILE="/var/log/bb-sync.log"
PID_FILE="/tmp/bb-sync.pid"

if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
    exit 0
fi

cd /var/www/staging.luxuriousdwelling.com
wp bb-sync start >> $LOGFILE 2>&1 &
echo $! > $PID_FILE
SCRIPT

chmod +x /tmp/bb-sync-cron.sh
(crontab -l 2>/dev/null; echo "0 * * * * /tmp/bb-sync-cron.sh") | crontab -
```

### During Sync Monitoring
- [ ] Monitor progress every 30 minutes:
  ```bash
  wp bb-sync status
  ```
  Check:
  - [ ] Synced count increasing
  - [ ] Failed count low (< 50)
  - [ ] Percentage > 0

- [ ] Check system resources:
  ```bash
  free -h          # Memory usage
  df -h            # Disk space
  top -b -n 1 | head -20  # CPU usage
  ```

- [ ] Monitor error log:
  ```bash
  tail -20 wp-content/debug.log
  # Should be minimal errors
  ```

- [ ] Watch CDN bandwidth (optional):
  - Log in to Backblaze dashboard
  - Check API activity in last hour
  - Should see consistent uploads

### Expected Progress Timeline
```
Time:       Progress:     Expected:
Start       0%           ~0 files
1 hour      ~10%         ~1,500 files
2 hours     ~20%         ~3,000 files
5 hours     ~50%         ~7,500 files
8 hours     ~80%         ~12,000 files
10 hours    ~100%        ~15,000 files (COMPLETE)
```

### Sync Completion
- [ ] Status shows 100% or near 100%
- [ ] Synced count ≈ Total Files (e.g., 14,950/15,000)
- [ ] No new files being processed
- [ ] All thumbnails uploaded

---

## Phase 7: Post-Sync Validation

### Quick Validation (5 minutes)
```bash
# 1. Check final status
wp bb-sync status
# Verify: Completion 99-100%

# 2. View HTML source
curl -s https://staging.luxuriousdwelling.com/sample-page/ | grep 'cdn.staging' | head -3
# Should show CDN URLs

# 3. Verify CSS not from CDN
curl -s https://staging.luxuriousdwelling.com/sample-page/ | grep -c 'wp-content.*\.css'
# Should show > 0 (CSS loading from server, not CDN)
```

### Comprehensive Validation (15 minutes)

#### Test 1: Image URLs
```bash
# Should show CDN URLs
curl -s https://staging.luxuriousdwelling.com/ | grep -oE 'src="[^"]*cdn.staging[^"]*\.(jpg|png|webp)"' | head -5
# Expected:
# src="https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/..."
```
- [ ] Images loading from CDN (check URLs)
- [ ] File paths correct
- [ ] No errors in response

#### Test 2: CSS/JS Files
```bash
# CSS should NOT be from CDN
curl -s https://staging.luxuriousdwelling.com/ | grep -oE 'href="[^"]*\.css"' | head -5
# Expected: https://staging.luxuriousdwelling.com/wp-content/...
# NOT: https://cdn.staging.luxuriousdwelling.com/...
```
- [ ] CSS from local server (not CDN)
- [ ] JavaScript from local server (not CDN)
- [ ] Elementor CSS from local server

#### Test 3: Download Test
```bash
# Verify image actually downloads from CDN
curl -I https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/2025/10/sample.jpg
# Expected: HTTP/2 200 OK
```
- [ ] Status: 200 OK
- [ ] Content-Type: image/*
- [ ] Content-Length: > 0

#### Test 4: Run Test Suite
```bash
wp bb-sync test-urls
```
- [ ] All 21 tests pass
- [ ] No failures reported
- [ ] Report shows green checkmarks

#### Test 5: Browser Testing
- [ ] Open https://staging.luxuriousdwelling.com in browser
- [ ] Open Developer Tools → Network tab
- [ ] Reload page
- [ ] Check network requests:
  - [ ] Images from: `cdn.staging.luxuriousdwelling.com` ✓
  - [ ] CSS from: `staging.luxuriousdwelling.com` ✓
  - [ ] JS from: `staging.luxuriousdwelling.com` ✓
- [ ] No 404/503 errors in console
- [ ] Page loads in < 5 seconds

#### Test 6: Spot Check Pages (10+ samples)
- [ ] Homepage loads correctly
- [ ] Product pages show images from CDN
- [ ] Blog posts have CDN images
- [ ] Elementor pages load properly
- [ ] Category pages working
- [ ] Search results working

### Error Log Review (24 hours)
- [ ] Monitor error logs:
  ```bash
  tail -100 wp-content/debug.log
  ```
- [ ] Look for:
  - [ ] No Backblaze auth errors
  - [ ] No CDN connection errors
  - [ ] No 404s for CSS/JS files
  - [ ] Acceptable number of warnings

---

## Phase 8: Troubleshooting (If Needed)

### Images NOT loading from CDN?

**Step 1: Verify settings**
```bash
wp option get bb_bucket
wp option get bb_cdn_url
wp option get bb_key_id
```
- [ ] All values correct
- [ ] No typos in URLs

**Step 2: Test connection**
```bash
wp bb-sync test
```
- [ ] Returns "Connection successful!"
- [ ] If fails: Check credentials

**Step 3: Check post meta**
```bash
wp db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_bb_uploaded' AND meta_value='1';"
```
- [ ] Count > 0 (files marked as synced)
- [ ] If 0: Sync may still be running

**Step 4: Clear caches**
```bash
wp cache flush
wp rewrite flush
```
- [ ] Log out and back in
- [ ] Hard refresh browser (Ctrl+F5)

**Step 5: Check Cloudflare**
- [ ] Test CDN domain: `curl -I https://cdn.staging.luxuriousdwelling.com/test`
- [ ] Should return 403/404, not connection error
- [ ] If fails: Fix Cloudflare DNS/rules

### Sync Completed but Some Files Failed?

**Check failed count:**
```bash
wp bb-sync status
# Shows: Failed: 45
```

**Retry failed uploads:**
```bash
wp bb-sync retry
# Or retry first 50:
wp bb-sync retry --count=50
```

### Sync Very Slow?

**Check batch size:**
```bash
# Increase batch size for faster processing
wp bb-sync start --batch-size=10  # Instead of default 5
```

**Monitor resources:**
```bash
watch -n 5 'free -h && ps aux | grep php'
```
- [ ] Memory: < 500MB available (good)
- [ ] CPU: < 80% usage (good)
- [ ] PHP process running

### Want to Reset and Start Over?

```bash
# WARNING: This clears all sync metadata
wp bb-sync reset
# Confirm when prompted

# Then start fresh:
wp bb-sync start
```

---

## Phase 9: Sign-Off

### Technical Sign-Off
- [ ] All 21 tests passing
- [ ] Sync completion: 99-100%
- [ ] Failed files: < 100
- [ ] No errors in logs (past 24 hours)
- [ ] Images loading from CDN
- [ ] CSS/JS loading from server
- [ ] Page load speed acceptable

### Functionality Sign-Off
- [ ] Homepage displays correctly
- [ ] All product images visible
- [ ] Search functionality working
- [ ] Filters/categories working
- [ ] Cart functionality (if ecommerce)
- [ ] No broken links or 404s
- [ ] Mobile responsive working

### Performance Sign-Off
- [ ] Page load time: < 5 seconds
- [ ] Image load time: < 2 seconds
- [ ] No console errors
- [ ] Cloudflare cache working
- [ ] CDN bandwidth usage expected

---

## Rollback Plan (If Needed)

If deployment fails catastrophically:

```bash
# 1. Deactivate plugin
wp plugin deactivate backblaze-auto-upload

# 2. Original local files still intact
# All images still load from /wp-content/uploads/

# 3. To revert to old domain
# Edit Cloudflare: Remove CDN domain
# Sites still work with local uploads
```

---

## Final Checklist

- [ ] All phases 1-8 complete
- [ ] All tests passing
- [ ] All validations done
- [ ] Documentation reviewed
- [ ] Team notified
- [ ] Monitoring set up
- [ ] Rollback plan confirmed
- [ ] Ready for production monitoring

---

## Post-Deployment Monitoring

**Week 1:**
- [ ] Monitor sync logs daily
- [ ] Check error logs: `tail -20 wp-content/debug.log`
- [ ] Monitor Backblaze dashboard for bandwidth
- [ ] Check CDN response times
- [ ] Monitor server resources

**Week 2-4:**
- [ ] Monitor weekly
- [ ] Check for any new media uploads syncing
- [ ] Review Backblaze costs
- [ ] Gather stakeholder feedback

**Ongoing:**
- [ ] Monthly check: `wp bb-sync status`
- [ ] Monitor new uploads sync automatically
- [ ] Keep Backblaze credentials secure
- [ ] Plan future enhancements

---

## Support Contacts

- **WordPress Admin:** See Backblaze CDN → Settings
- **WP-CLI Help:** `wp bb-sync --help`
- **Error Logs:** `/var/www/staging.luxuriousdwelling.com/wp-content/debug.log`
- **Documentation:** See DEPLOYMENT_LXD.md, QUICK_START.md

---

## Questions During Deployment?

1. Check **QUICK_START.md** (quick answers)
2. Check **DEPLOYMENT_LXD.md** (detailed guide)
3. Check **IMPROVEMENTS.md** (technical details)
4. Run diagnostics:
   ```bash
   wp bb-sync test
   wp bb-sync test-urls
   wp option list | grep bb_
   ```

---

**Status:** Ready for Deployment ✅

**Estimated Total Time:**
- Setup & config: 15 minutes
- Bulk sync: 5-10 hours
- Validation: 15 minutes
- **Total: ~5-10 hours**

Good luck! 🚀
