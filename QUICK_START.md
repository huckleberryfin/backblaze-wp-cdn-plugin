# Backblaze Auto Upload - Quick Start for LXD

**Time to Deploy:** ~15 minutes (+ sync time for media)
**Sync Time (30GB):** 5-10 hours

---

## 1. Copy Plugin to LXD

```bash
cd /var/www/staging.deckandco.com/wp-content/plugins/backblaze-auto-upload

# Or if deploying fresh:
scp -r . user@lxd:/var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload/
```

---

## 2. Install Dependencies

```bash
cd /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload
composer install
```

---

## 3. Activate Plugin

**Via WordPress Admin:**
1. Plugins → Backblaze Auto Upload → Activate

**Via WP-CLI:**
```bash
wp plugin activate backblaze-auto-upload
```

---

## 4. Configure Settings

**Via WordPress Admin:**
1. Backblaze CDN → Settings
2. Enter these values:

| Field | Value |
|-------|-------|
| Bucket Name | `lxd-uploads` |
| S3 Endpoint | `s3.us-east-005.backblazeb2.com` |
| CDN URL | `https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads` |
| Key ID | `005c8a1e69f60c80000000003` |
| Application Key | `K005kDG+xSJQpZrqbfAN1ZFirNjtuhI` |
| Excluded Extensions | `css,js` |

3. Click **Save Settings**

**Via WP-CLI:**
```bash
wp option update bb_bucket lxd-uploads
wp option update bb_endpoint s3.us-east-005.backblazeb2.com
wp option update bb_cdn_url https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads
wp option update bb_key_id 005c8a1e69f60c80000000003
wp option update bb_app_key K005kDG+xSJQpZrqbfAN1ZFirNjtuhI
wp option update bb_excluded_extensions css,js
```

---

## 5. Test Connection

```bash
wp bb-sync test
```

Expected output:
```
Testing Backblaze connection...
✓ Connection successful!
Test file uploaded to: backblaze-test/test-1699999999.txt
CDN URL: https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/backblaze-test/test-1699999999.txt
```

---

## 6. Run Test Suite

```bash
wp bb-sync test-urls
```

Expected output:
```
═══════════════════════════════════════════════════════════
TOTAL: 24 passed, 0 failed
Status: ALL TESTS PASSED ✓
═══════════════════════════════════════════════════════════
```

---

## 7. Start Syncing Media (30GB)

### Quick Start (Recommended):

```bash
# Start sync with progress bar
wp bb-sync start

# Shows real-time progress:
# Uploading files [=====>        ] 45%
# ✓ Synced: 6734/15000 files
```

### For 30GB (Background with Monitoring):

```bash
# Terminal 1: Start sync in background
nohup wp bb-sync start > /tmp/bb-sync.log 2>&1 &

# Terminal 2: Monitor progress every 30 seconds
watch -n 30 'wp bb-sync status'

# Output updates live:
# Total Files       : 15000
# Synced           : 4521  ← Updates every 30s
# Failed           : 12
# Pending          : 10467
# Completion: 30.14%
```

---

## 8. Verify It's Working

### Check current status anytime:

```bash
wp bb-sync status
```

### After sync completes:

```bash
# 1. View page source
curl -s https://staging.luxuriousdwelling.com/page/ | grep 'cdn.staging'
# Should show CDN URLs

# 2. Verify CSS from local
curl -s https://staging.luxuriousdwelling.com/page/ | grep 'wp-content.*css'
# Should show local server URLs (not CDN)

# 3. Run tests again
wp bb-sync test-urls
# Should pass all tests
```

---

## Essential Commands

```bash
# Check sync progress (use multiple times)
wp bb-sync status

# Retry any failed uploads
wp bb-sync retry

# Reset and start over
wp bb-sync reset

# Test URL replacement logic
wp bb-sync test-urls

# Test Backblaze connection
wp bb-sync test
```

---

## Troubleshooting (1 minute solutions)

### Images not loading from CDN?
```bash
wp bb-sync test
# If fails: Check Key ID and Application Key are correct
```

### Sync very slow?
```bash
# Increase batch size for faster uploads
wp bb-sync start --batch-size=10
```

### Some files failed?
```bash
wp bb-sync status
# Shows how many failed
# Retry with: wp bb-sync retry
```

### Want to start over?
```bash
wp bb-sync reset
# Clears all sync metadata, then:
wp bb-sync start
```

---

## Expected Results After 5-10 Hours

✅ Check these to confirm success:

```bash
# 1. Nearly all files synced
wp bb-sync status
# Synced: 14,900+/15,000
# Failed: <100

# 2. Pages load fast
# Visit https://staging.luxuriousdwelling.com in browser
# Images load from CDN (check developer tools Network tab)

# 3. CSS/JS still local
curl -s https://staging.luxuriousdwelling.com | grep -c 'staging.luxuriousdwelling.com.*\.css'
# Should show CSS files loading locally

# 4. All tests pass
wp bb-sync test-urls
# All tests PASS
```

---

## Cloudflare Setup (One-time)

If not already done:

1. **Add DNS CNAME:**
   - Name: `cdn`
   - Target: `f005.backblazeb2.com`
   - Proxy: Orange cloud

2. **Add Transform Rule:**
   - Rules → Transform Rules → Modify Request URL
   - If hostname = `cdn.staging.luxuriousdwelling.com`
   - Rewrite path: `/file/lxd-uploads$1` (or use UI)

3. **Test it:**
   ```bash
   curl -I https://cdn.staging.luxuriousdwelling.com/test.txt
   # Should return 403 or 404 (file doesn't exist, but domain works)
   ```

---

## That's It!

Your 30GB media library will sync to Backblaze B2 over the next 5-10 hours.

**Monitor with:**
```bash
watch -n 30 'wp bb-sync status'
```

**Questions?** See:
- `DEPLOYMENT_LXD.md` for detailed guide
- `IMPROVEMENTS.md` for what changed
- `README.md` for full feature documentation

---

**Happy syncing! 🚀**
