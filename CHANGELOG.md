# Changelog - Backblaze Auto Upload

## [1.0.0-Enhanced] - November 2025

### Added
- **WP-CLI Commands** (`class-cli-commands.php`)
  - `wp bb-sync start` - Bulk sync with live progress bar
  - `wp bb-sync status` - Show current sync statistics
  - `wp bb-sync retry` - Retry failed uploads
  - `wp bb-sync reset` - Clear all sync metadata
  - `wp bb-sync test` - Test Backblaze connection
  - `wp bb-sync test-urls` - Run comprehensive validation tests

- **Automated Test Suite** (`class-test-suite.php`)
  - URL replacement validation (5 tests)
  - CSS/JS exclusion verification (4 tests)
  - File extension exclusion testing (3 tests)
  - Srcset attribute handling (2 tests)
  - Elementor CSS protection (3 tests)
  - File validation checks (4 tests)
  - Total: 21 test cases with detailed reporting

- **Configurable File Exclusions**
  - Admin setting: "Excluded File Extensions"
  - Default: `css,js`
  - Customizable via WordPress admin or WP-CLI
  - Applied across all URL filtering layers

- **Enhanced Error Handling**
  - File existence validation
  - File size verification
  - MIME type detection
  - Timeout handling (300s for large files)
  - Retry logic with exponential backoff (3 attempts)
  - Better error messages for debugging

- **Comprehensive Documentation**
  - `DEPLOYMENT_LXD.md` - Complete deployment guide
  - `IMPROVEMENTS.md` - Detailed improvements list
  - `QUICK_START.md` - 8-step quick setup
  - `MIGRATION_SUMMARY.md` - Project summary
  - `CHANGELOG.md` - This file

### Fixed
- **Critical:** SQL Injection vulnerability in reset sync status
  - Changed from raw SQL query to `$wpdb->delete()`
  - Proper prepared statement implementation
  - File: `class-bulk-sync.php:360`

- **Security:** Missing AJAX rate limiting
  - Added 10 requests/minute per IP limit
  - Uses WordPress transients for tracking
  - Prevents DOS and abuse
  - File: `class-bulk-sync.php:212-221`

### Improved
- **Upload reliability** (`class-uploader.php`)
  - Added 300-second timeout for large files
  - Automatic retry on network failures (3 attempts)
  - Exponential backoff between retries
  - SSL certificate verification
  - Curl error capture and reporting

- **URL filtering** (`class-media-handler.php`)
  - Refactored exclusion logic into reusable methods
  - `get_excluded_extensions()` - Fetch settings
  - `should_exclude_url()` - Unified exclusion check
  - Applied to all filter hooks consistently

- **Settings management** (`class-settings.php`)
  - Added extension exclusion field to admin UI
  - Sanitization function for user input
  - Default values with documentation

### Security
- ✅ Fixed SQL injection (critical)
- ✅ Added rate limiting (DOS prevention)
- ✅ Added file validation (corruption prevention)
- ✅ Added SSL verification
- ✅ Proper input sanitization
- ✅ Output escaping everywhere
- ✅ Nonce verification maintained
- ✅ Capability checks enforced

### Performance
- Batch processing optimized for 30GB datasets
- Periodic cache flushing during sync
- Minimal CLI overhead
- Zero performance impact when not syncing

### Compatibility
- ✅ 100% backward compatible
- ✅ No breaking changes
- ✅ No database schema changes
- ✅ Works with WordPress 5.0-6.7+
- ✅ Works with PHP 7.4-8.3+
- ✅ WP-CLI 2.0+ required for CLI commands

### Testing
- 21 automated test cases
- URL replacement validation
- CSS/JS exclusion verification
- File handling tests
- Extension filtering tests
- Srcset attribute handling tests
- Elementor CSS protection tests

### Migration
- Ready for production deployment
- Tested on D&Co staging environment
- Ready for LXD staging environment
- All documentation provided

---

## [1.0.0] - October 2025

### Initial Release
- Automatic media uploads to Backblaze B2
- Bulk sync with progress tracking
- Custom CDN domain support
- Thumbnail size handling
- URL filtering and replacement
- WooCommerce compatibility
- Error tracking with retry logic
- WordPress admin settings page
- Basic test upload functionality

---

## Version Comparison

| Feature | v1.0.0 | v1.0.0-Enhanced |
|---------|--------|-----------------|
| Auto upload | ✓ | ✓ |
| Bulk sync | ✓ | ✓ (improved) |
| URL filtering | ✓ | ✓ (improved) |
| CLI commands | ✗ | ✓ (NEW) |
| Test suite | ✗ | ✓ (NEW) |
| Configurable exclusions | ✗ | ✓ (NEW) |
| Progress bar | ✗ | ✓ (NEW) |
| Rate limiting | ✗ | ✓ (NEW) |
| Retry logic | ✗ | ✓ (NEW) |
| SQL injection fix | ✗ | ✓ (NEW) |
| Error handling | Basic | Enhanced |
| Documentation | Basic | Comprehensive |

---

## Upgrade Path

### From v1.0.0 to v1.0.0-Enhanced

**No breaking changes!**

```bash
# 1. Upload new plugin files
# 2. No need to change settings
# 3. New features available immediately

# Activate new features with:
wp bb-sync start                 # Start syncing with CLI
wp bb-sync test-urls            # Validate URL replacement
wp option update bb_excluded_extensions "css,js,svg"  # Add SVG to exclusions
```

**Backward compatibility:**
- All existing settings preserved
- Existing synced files continue working
- Optional new features
- Can disable CLI if not needed

---

## Known Issues

None currently reported.

## Future Roadmap

### In Planning
- Dashboard widget with sync statistics
- Email notifications on sync completion
- Scheduled background syncs (WP-Cron)
- Selective media sync (by category, size, date)
- CDN URL analytics and statistics

### In Discussion
- Multi-bucket support
- Rollback functionality
- Bandwidth throttling
- Advanced caching rules

---

## Credits

**Original Plugin:** Dimitri Nain  
**Enhancement & Testing:** November 2025  
**Tested On:** WordPress 6.7, PHP 8.3, WP-CLI 2.5+

---

## License

GPLv2 or later - Same as WordPress

---

**For more information, see:**
- `README.md` - Feature overview
- `DEPLOYMENT_LXD.md` - Deployment guide
- `IMPROVEMENTS.md` - Detailed improvements
- `QUICK_START.md` - Quick setup guide
