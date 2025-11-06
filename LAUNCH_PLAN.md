# LXD Deployment Launch Plan

**Status:** Ready to execute
**Timeline:** Setup (15 min) + Sync (5-10 hours) + Validation (30 min)
**Credentials:** Pre-configured (see below)

---

## 🚀 LAUNCH SEQUENCE

### Phase 1: File Transfer & Installation (15 minutes)

**Step 1: Copy plugin files to LXD**
```bash
# Option A: Via SCP (if different servers)
scp -r /var/www/staging.deckandco.com/wp-content/plugins/backblaze-auto-upload \
    user@lxd:/var/www/staging.luxuriousdwelling.com/wp-content/plugins/

# Option B: Via local filesystem (if same system)
cp -r /var/www/staging.deckandco.com/wp-content/plugins/backblaze-auto-upload \
    /var/www/staging.luxuriousdwelling.com/wp-content/plugins/
```

**Verify files copied:**
```bash
ls -la /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload/
# Should show: backblaze-auto-upload.php, includes/, vendor/, README.md, etc.
```

**Step 2: Install Composer dependencies**
```bash
cd /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload
composer install
# Expected: "... packages installed"
```

**Step 3: Activate plugin in WordPress**
```bash
wp plugin activate backblaze-auto-upload
# Or go to WordPress Admin → Plugins → Activate
```

**Verify activation:**
```bash
wp plugin list | grep backblaze
# Should show: "backblaze-auto-upload | 1.0 | active"
```

---

### Phase 2: Configuration & Testing (15 minutes)

**Step 1: Configure Backblaze credentials**

Via WP-CLI (recommended):
```bash
wp option update bb_bucket lxd-uploads
wp option update bb_endpoint s3.us-east-005.backblazeb2.com
wp option update bb_cdn_url https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads
wp option update bb_key_id 005c8a1e69f60c80000000003
wp option update bb_app_key K005kDG+xSJQpZrqbfAN1ZFirNjtuhI
wp option update bb_excluded_extensions css,js
```

Or via WordPress Admin:
- Go to **Backblaze CDN** → **Settings**
- Enter all 5 credentials (as shown above)
- Click **Save Settings**

**Verify settings saved:**
```bash
wp option get bb_bucket          # Should return: lxd-uploads
wp option get bb_cdn_url         # Should return: https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads
```

**Step 2: Test Backblaze connection**
```bash
wp bb-sync test
```

**Expected output:**
```
Testing Backblaze connection...
✓ Connection successful!
Test file uploaded to: backblaze-test/test-1699999999.txt
CDN URL: https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/backblaze-test/test-1699999999.txt
```

**If fails:** Check credentials are exactly correct

**Step 3: Run URL replacement test suite**
```bash
wp bb-sync test-urls
```

**Expected output:**
```
═══════════════════════════════════════════════════════════
TOTAL: 21 passed, 0 failed
Status: ALL TESTS PASSED ✓
═══════════════════════════════════════════════════════════
```

**⚠️ If any tests fail:**
- This indicates an issue with URL filtering
- Check excluded extensions setting
- Verify media handler filters are properly loaded
- Contact support with test output

---

### Phase 3: Verify Cloudflare Setup (5 minutes)

**IMPORTANT:** This must be done BEFORE starting sync!

**Check DNS:**
```bash
nslookup cdn.staging.luxuriousdwelling.com
# Should resolve to Cloudflare IP, not direct Backblaze
```

**Check CNAME record:**
- Target should be: `f005.backblazeb2.com`
- Proxy status should be: Orange cloud (Proxied)

**Check Transform Rule:**
- Rule name: "Backblaze B2 Path Rewrite"
- If: `cdn.staging.luxuriousdwelling.com`
- Then: Rewrite path to `/file/lxd-uploads$1`

**Test CDN domain:**
```bash
curl -I https://cdn.staging.luxuriousdwelling.com/test.txt
# Should return 403 or 404 (domain works, file doesn't exist)
# NOT: DNS error or connection refused
```

**⚠️ If Cloudflare domain not working:**
- Inform me immediately
- Do NOT proceed with sync
- I'll provide Cloudflare troubleshooting steps

---

### Phase 4: Launch Bulk Sync (5-10 hours)

**Step 1: Check current file count**
```bash
wp post list --post-type=attachment --post_mime_type=image --field=ID | wc -l
# Should show: ~15,000-20,000 files
```

**Step 2: Check initial sync status**
```bash
wp bb-sync status
# Should show:
# Total Files: 15000
# Synced: 0
# Failed: 0
# Pending: 15000
```

**Step 3: Start bulk sync with live progress**
```bash
wp bb-sync start
```

**You'll see:**
```
Starting bulk sync: 15000 files to process
Batch size: 5 | Force re-upload: No

Uploading files [=====>        ] 45%
✓ Synced: 6,750/15,000 | Failed: 0 | Pending: 8,250
```

**The process will:**
- Upload 5 files per batch
- Show live progress bar
- Update file counts in real-time
- Continue until completion
- Show summary with stats

**⚠️ If progress stalls:**
- Press Ctrl+C to stop
- Check: `wp bb-sync status`
- Look for error messages
- Contact me if issues persist

**Step 4: Monitor progress (recommended)**

While sync is running, in a separate terminal:
```bash
# Monitor every 30 seconds
watch -n 30 'wp bb-sync status'

# Or use tail to watch log
tail -f /var/log/bb-sync.log  # if using nohup background method
```

**Expected to see:**
- Synced count increasing every 30-60 seconds
- Progress percentage rising
- Failed count staying < 50

---

### Phase 5: Monitor for Cloudflare Issues

**During the sync, monitor for any Cloudflare-related errors:**

```bash
# Watch WordPress error log for CDN issues
tail -f wp-content/debug.log | grep -i cdn
# OR
tail -f wp-content/debug.log | grep -i cloudflare
```

**Common Cloudflare issues to watch for:**

1. **403 Forbidden errors**
   - Symptom: Uploads fail with 403
   - Cause: Cloudflare blocking requests to Backblaze
   - Fix: Disable Cloudflare's Web Application Firewall (WAF) for CDN domain

2. **502 Bad Gateway errors**
   - Symptom: CDN domain returning 502
   - Cause: Transform Rule misconfiguration
   - Fix: Verify Transform Rule path rewrite is correct

3. **Timeout errors**
   - Symptom: Uploads timing out
   - Cause: Cloudflare rate limiting
   - Fix: Increase Cloudflare rate limit or add IP to whitelist

4. **DNS resolution issues**
   - Symptom: Cannot connect to cdn.staging.luxuriousdwelling.com
   - Cause: DNS not resolving
   - Fix: Verify CNAME record in Cloudflare DNS

**If you see any errors:**
- Note the exact error message
- Note the timestamp
- Let me know immediately
- Provide log snippet

**I will:**
- Analyze the error
- Provide Cloudflare fix instructions
- Help you implement the fix
- Resume sync afterward

---

### Phase 6: After Sync Completes (30 minutes)

**Step 1: Check final status**
```bash
wp bb-sync status
```

**Expected results:**
```
Backblaze Sync Status:
─────────────────────────────────────
Total Files       : 15000
Synced           : 14950  ← Should be 99%+
Failed           : 45     ← Should be < 100
Pending          : 5      ← Should be < 50
─────────────────────────────────────
Completion: 99.67%
```

**Step 2: Verify URL replacement on live site**

**Test 1: Check page source for CDN URLs**
```bash
curl -s https://staging.luxuriousdwelling.com/ | grep -oE 'src="[^"]*cdn.staging[^"]*"' | head -3
# Should show:
# src="https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/2025/10/image.jpg"
```

**Test 2: Verify CSS NOT from CDN**
```bash
curl -s https://staging.luxuriousdwelling.com/ | grep -oE 'href="[^"]*\.css"' | head -3
# Should show:
# href="https://staging.luxuriousdwelling.com/wp-content/plugins/..."
# NOT: href="https://cdn.staging.luxuriousdwelling.com/..."
```

**Test 3: Test image download**
```bash
curl -I https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads/2025/10/sample.jpg
# Should return: HTTP/2 200 OK
# NOT: 403, 404, or connection error
```

**Test 4: Run validation tests**
```bash
wp bb-sync test-urls
# Should show: All 21 tests pass
```

**Test 5: Browser inspection (visual verification)**
1. Open https://staging.luxuriousdwelling.com in browser
2. Right-click page → Inspect → Network tab
3. Reload page
4. Filter by images: Look for requests to `cdn.staging.luxuriousdwelling.com`
5. Verify CSS/JS requests go to `staging.luxuriousdwelling.com` (not CDN)
6. Check that all resources loaded (no 404s)

---

## 🔍 SUCCESS CRITERIA

**After sync and validation complete:**

✅ **All images from CDN:**
- Verify: 99%+ of images have CDN URLs
- Command: `curl -s https://staging.luxuriousdwelling.com | grep -c 'cdn.staging'` should return > 100

✅ **CSS/JS from server (NOT CDN):**
- Verify: 0 CSS files from CDN
- Command: `curl -s https://staging.luxuriousdwelling.com | grep 'href.*cdn.staging.*\.css'` should return nothing

✅ **No 404 or 403 errors:**
- Verify: Page loads without errors
- Check: Browser console has no red errors
- Check: Network tab shows no 4xx/5xx responses

✅ **Sync completion:**
- Verify: 99%+ files synced
- Command: `wp bb-sync status` shows Completion 99%+

✅ **Test suite passes:**
- Verify: All 21 tests pass
- Command: `wp bb-sync test-urls` shows "ALL TESTS PASSED"

✅ **No Cloudflare issues:**
- Verify: No CDN-related errors in logs
- Command: `tail -20 wp-content/debug.log | grep -i cdn` returns nothing

---

## 📊 COMMANDS TO RUN

**Quick reference (run in order):**

```bash
# 1. Copy and install
scp -r /var/www/staging.deckandco.com/wp-content/plugins/backblaze-auto-upload \
    user@lxd:/var/www/staging.luxuriousdwelling.com/wp-content/plugins/
cd /var/www/staging.luxuriousdwelling.com/wp-content/plugins/backblaze-auto-upload
composer install
wp plugin activate backblaze-auto-upload

# 2. Configure
wp option update bb_bucket lxd-uploads
wp option update bb_endpoint s3.us-east-005.backblazeb2.com
wp option update bb_cdn_url https://cdn.staging.luxuriousdwelling.com/file/lxd-uploads
wp option update bb_key_id 005c8a1e69f60c80000000003
wp option update bb_app_key K005kDG+xSJQpZrqbfAN1ZFirNjtuhI
wp option update bb_excluded_extensions css,js

# 3. Test
wp bb-sync test
wp bb-sync test-urls

# 4. Sync (5-10 hours)
wp bb-sync start

# 5. Monitor (in separate terminal)
watch -n 30 'wp bb-sync status'

# 6. After sync: Validate
curl -s https://staging.luxuriousdwelling.com | grep -oE 'src="[^"]*cdn.staging[^"]*"' | head -3
curl -s https://staging.luxuriousdwelling.com | grep -oE 'href="[^"]*\.css"' | head -3
wp bb-sync test-urls
```

---

## 📋 TROUBLESHOOTING QUICK REFERENCE

| Issue | Check | Fix |
|-------|-------|-----|
| Connection fails | `wp bb-sync test` | Verify credentials in settings |
| Tests fail | `wp bb-sync test-urls` | Check media handler filters |
| Sync slow | Monitor resources | Try `--batch-size=10` |
| Some files fail | `wp bb-sync status` | Run `wp bb-sync retry` |
| Images not from CDN | Page source | Check Cloudflare domain setup |
| CSS from CDN (bad) | Inspect in browser | Check excluded extensions setting |
| Cloudflare errors | `tail wp-content/debug.log` | Contact me immediately |

---

## ❌ STOP & NOTIFY ME IF:

1. **Connection test fails** → `wp bb-sync test` shows error
2. **URL tests fail** → `wp bb-sync test-urls` shows failed tests
3. **Cloudflare domain not working** → CDN domain returns error
4. **Sync errors appear** → Check logs for CDN/Cloudflare errors
5. **Images not from CDN after sync** → Unexpected URL replacement failure
6. **CSS files served from CDN** → Extension exclusion not working
7. **Excessive failed files** → Sync success rate below 95%

**When any of these occur:**
- Stop the process
- Provide me with:
  - Error message (exact)
  - Relevant command output
  - Log snippets if applicable
  - Timestamp of when it occurred
- I will provide fix instructions

---

## 🎯 EXPECTED TIMELINE

```
Setup:              0-15 min  (file copy, install, config, test)
Bulk sync:          5-10 hrs  (monitor with watch command)
Verification:       15-30 min (URL checks, tests, browser inspection)
─────────────────────────────────────────────────
Total:              5-11 hours (mostly waiting for sync)
```

---

## ✅ FINAL CHECKLIST

Before considering deployment complete:

- [ ] All files copied to LXD
- [ ] Composer installed successfully
- [ ] Plugin activated
- [ ] All 5 credentials configured
- [ ] Connection test passes (`wp bb-sync test`)
- [ ] URL replacement tests pass (21/21)
- [ ] Cloudflare DNS and Rules verified
- [ ] Bulk sync started
- [ ] Sync completed (99%+)
- [ ] Images loading from CDN
- [ ] CSS/JS loading from server (NOT CDN)
- [ ] No errors in logs
- [ ] No Cloudflare issues encountered
- [ ] Browser inspection shows correct URLs
- [ ] All success criteria met

---

## 🚀 YOU'RE READY TO LAUNCH!

Follow the phases in order. If any issues arise, stop and notify me with details.

The plugin is production-ready and thoroughly tested.

Let's deploy! 🎯
