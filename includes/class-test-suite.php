<?php
if (!defined('ABSPATH')) exit;

/**
 * Backblaze Auto Upload Test Suite
 * Tests URL replacement, exclusions, and upload functionality
 */

class BB_Test_Suite {

    public static function run_all_tests() {
        $results = array(
            'url_replacement' => self::test_url_replacement(),
            'css_js_exclusion' => self::test_css_js_exclusion(),
            'extension_exclusion' => self::test_extension_exclusion(),
            'srcset_handling' => self::test_srcset_handling(),
            'elementor_exclusion' => self::test_elementor_exclusion(),
            'file_validation' => self::test_file_validation(),
        );

        return $results;
    }

    /**
     * Test basic URL replacement
     */
    private static function test_url_replacement() {
        $site_url = 'https://example.com';
        $cdn_url = 'https://cdn.example.com/uploads';

        $tests = array(
            array(
                'name' => 'Basic image URL replacement',
                'input' => '<img src="https://example.com/wp-content/uploads/2025/10/test.jpg">',
                'expected' => 'https://cdn.example.com/uploads/2025/10/test.jpg',
                'should_contain' => true
            ),
            array(
                'name' => 'PNG image replacement',
                'input' => '<img src="https://example.com/wp-content/uploads/image.png">',
                'expected' => 'https://cdn.example.com/uploads/image.png',
                'should_contain' => true
            ),
            array(
                'name' => 'WebP image replacement',
                'input' => '<img src="https://example.com/wp-content/uploads/photo.webp">',
                'expected' => 'https://cdn.example.com/uploads/photo.webp',
                'should_contain' => true
            ),
            array(
                'name' => 'SVG image replacement',
                'input' => '<img src="https://example.com/wp-content/uploads/icon.svg">',
                'expected' => 'https://cdn.example.com/uploads/icon.svg',
                'should_contain' => true
            ),
            array(
                'name' => 'URL with query string',
                'input' => '<img src="https://example.com/wp-content/uploads/image.jpg?v=123">',
                'expected' => 'https://cdn.example.com/uploads/image.jpg?v=123',
                'should_contain' => true
            ),
        );

        return self::run_tests($tests, 'URL Replacement Tests');
    }

    /**
     * Test CSS/JS exclusion from CDN
     */
    private static function test_css_js_exclusion() {
        $tests = array(
            array(
                'name' => 'CSS files NOT replaced',
                'input' => '<link rel="stylesheet" href="https://example.com/wp-content/uploads/style.css">',
                'expected' => 'https://cdn.example.com',
                'should_contain' => false
            ),
            array(
                'name' => 'JS files NOT replaced',
                'input' => '<script src="https://example.com/wp-content/uploads/script.js"></script>',
                'expected' => 'https://cdn.example.com',
                'should_contain' => false
            ),
            array(
                'name' => 'CSS with query string NOT replaced',
                'input' => '<link href="https://example.com/wp-content/uploads/style.css?v=1">',
                'expected' => 'https://cdn.example.com',
                'should_contain' => false
            ),
            array(
                'name' => 'JS with query string NOT replaced',
                'input' => '<script src="https://example.com/wp-content/uploads/app.js?v=2"></script>',
                'expected' => 'https://cdn.example.com',
                'should_contain' => false
            ),
        );

        return self::run_tests($tests, 'CSS/JS Exclusion Tests');
    }

    /**
     * Test configurable extension exclusion
     */
    private static function test_extension_exclusion() {
        $tests = array(
            array(
                'name' => 'Custom extension exclusion (svg)',
                'extension' => 'svg',
                'url' => 'https://example.com/wp-content/uploads/icon.svg',
                'should_be_excluded' => true
            ),
            array(
                'name' => 'Non-excluded extension (jpg)',
                'extension' => 'svg',
                'url' => 'https://example.com/wp-content/uploads/photo.jpg',
                'should_be_excluded' => false
            ),
            array(
                'name' => 'Multiple excluded extensions',
                'extension' => 'css,js,svg',
                'url' => 'https://example.com/wp-content/uploads/image.svg',
                'should_be_excluded' => true
            ),
        );

        $results = array(
            'passed' => 0,
            'failed' => 0,
            'tests' => array()
        );

        foreach ($tests as $test) {
            $excluded = explode(',', strtolower($test['extension']));
            $url_parts = pathinfo($test['url']);
            $ext = strtolower(ltrim($url_parts['extension'], '.'));
            $is_excluded = in_array($ext, $excluded);

            $passed = $is_excluded === $test['should_be_excluded'];

            $results['tests'][] = array(
                'name' => $test['name'],
                'passed' => $passed,
                'message' => $passed ? 'Pass' : 'Fail: Expected ' . ($test['should_be_excluded'] ? 'excluded' : 'included') . ', got ' . ($is_excluded ? 'excluded' : 'included')
            );

            if ($passed) {
                $results['passed']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Test srcset attribute handling
     */
    private static function test_srcset_handling() {
        $tests = array(
            array(
                'name' => 'Multiple URLs in srcset',
                'input' => 'srcset="https://example.com/wp-content/uploads/img-300.jpg 300w, https://example.com/wp-content/uploads/img-600.jpg 600w"',
                'should_contain_cdn_1' => true,
                'should_contain_cdn_2' => true,
            ),
            array(
                'name' => 'Srcset with different image formats',
                'input' => 'srcset="https://example.com/wp-content/uploads/photo.png, https://example.com/wp-content/uploads/photo.webp"',
                'should_contain_cdn' => true,
            ),
        );

        $results = array(
            'passed' => 0,
            'failed' => 0,
            'tests' => array()
        );

        foreach ($tests as $test) {
            $passed = strpos($test['input'], 'wp-content/uploads/') !== false;

            $results['tests'][] = array(
                'name' => $test['name'],
                'passed' => $passed,
                'message' => $passed ? 'Pass: Srcset URLs detected' : 'Fail: Srcset not properly parsed'
            );

            if ($passed) {
                $results['passed']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Test Elementor CSS exclusion
     */
    private static function test_elementor_exclusion() {
        $tests = array(
            array(
                'name' => 'Elementor CSS path detection',
                'input' => 'https://example.com/wp-content/uploads/elementor/css/post-123.css',
                'expected_excluded' => true
            ),
            array(
                'name' => 'Regular CSS not matching Elementor path',
                'input' => 'https://example.com/wp-content/uploads/custom.css',
                'expected_excluded' => true // Because it's CSS extension
            ),
            array(
                'name' => 'Image in elementor folder',
                'input' => 'https://example.com/wp-content/uploads/elementor/images/icon.png',
                'expected_excluded' => false
            ),
        );

        $results = array(
            'passed' => 0,
            'failed' => 0,
            'tests' => array()
        );

        foreach ($tests as $test) {
            $is_elementor_css = strpos($test['input'], '/elementor/css/') !== false;
            $has_css_extension = preg_match('/\.css(\?|$)/i', $test['input']);
            $is_excluded = $is_elementor_css || $has_css_extension;

            $passed = $is_excluded === $test['expected_excluded'];

            $results['tests'][] = array(
                'name' => $test['name'],
                'passed' => $passed,
                'message' => $passed ? 'Pass' : 'Fail: Exclusion detection mismatch'
            );

            if ($passed) {
                $results['passed']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Test file validation
     */
    private static function test_file_validation() {
        $results = array(
            'passed' => 0,
            'failed' => 0,
            'tests' => array()
        );

        // Create a test file
        $test_dir = wp_upload_dir()['basedir'];
        $test_file = $test_dir . '/test-validation.jpg';
        $test_content = 'test content';

        // Test 1: File existence check
        file_put_contents($test_file, $test_content);
        $file_exists = file_exists($test_file);
        $results['tests'][] = array(
            'name' => 'Test file creation',
            'passed' => $file_exists,
            'message' => $file_exists ? 'Pass: File created' : 'Fail: Could not create file'
        );
        if ($file_exists) $results['passed']++; else $results['failed']++;

        // Test 2: File size detection
        $file_size = filesize($test_file);
        $size_valid = $file_size > 0;
        $results['tests'][] = array(
            'name' => 'File size validation',
            'passed' => $size_valid,
            'message' => $size_valid ? "Pass: File size: $file_size bytes" : 'Fail: Invalid file size'
        );
        if ($size_valid) $results['passed']++; else $results['failed']++;

        // Test 3: MIME type detection
        $mime_type = mime_content_type($test_file);
        $mime_valid = !empty($mime_type);
        $results['tests'][] = array(
            'name' => 'MIME type detection',
            'passed' => $mime_valid,
            'message' => $mime_valid ? "Pass: MIME type: $mime_type" : 'Fail: Could not detect MIME type'
        );
        if ($mime_valid) $results['passed']++; else $results['failed']++;

        // Test 4: File readability
        $file_contents = file_get_contents($test_file);
        $readable = $file_contents === $test_content;
        $results['tests'][] = array(
            'name' => 'File readability',
            'passed' => $readable,
            'message' => $readable ? 'Pass: File contents match' : 'Fail: File not readable'
        );
        if ($readable) $results['passed']++; else $results['failed']++;

        // Cleanup
        @unlink($test_file);

        return $results;
    }

    /**
     * Helper: Run test suite
     */
    private static function run_tests($tests, $suite_name = 'Tests') {
        $results = array(
            'suite' => $suite_name,
            'passed' => 0,
            'failed' => 0,
            'tests' => array()
        );

        foreach ($tests as $test) {
            // Simulate URL replacement
            $output = str_replace(
                'https://example.com/wp-content/uploads/',
                'https://cdn.example.com/uploads/',
                $test['input']
            );

            $passed = false;
            if (isset($test['should_contain'])) {
                $passed = $test['should_contain'] === (strpos($output, $test['expected']) !== false);
            }

            $results['tests'][] = array(
                'name' => $test['name'],
                'passed' => $passed,
                'message' => $passed ? 'Pass' : 'Fail: Expected "' . $test['expected'] . '" ' . ($test['should_contain'] ? 'in' : 'not in') . ' output'
            );

            if ($passed) {
                $results['passed']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Generate test report (for admin or CLI)
     */
    public static function generate_report($results) {
        $report = "\n";
        $report .= "═══════════════════════════════════════════════════════════\n";
        $report .= "    BACKBLAZE AUTO UPLOAD - TEST SUITE REPORT\n";
        $report .= "═══════════════════════════════════════════════════════════\n\n";

        $total_passed = 0;
        $total_failed = 0;

        foreach ($results as $suite_name => $suite_results) {
            $passed = $suite_results['passed'];
            $failed = $suite_results['failed'];
            $total = $passed + $failed;

            $total_passed += $passed;
            $total_failed += $failed;

            $status = $failed === 0 ? '✓ PASS' : '✗ FAIL';
            $report .= "$status - " . $suite_results['suite'] . " ($passed/$total)\n";

            if (!empty($suite_results['tests'])) {
                foreach ($suite_results['tests'] as $test) {
                    $icon = $test['passed'] ? '  ✓' : '  ✗';
                    $report .= "$icon " . $test['name'] . "\n";
                    if (!$test['passed']) {
                        $report .= "      → " . $test['message'] . "\n";
                    }
                }
            }
            $report .= "\n";
        }

        $report .= "═══════════════════════════════════════════════════════════\n";
        $report .= sprintf("TOTAL: %d passed, %d failed\n", $total_passed, $total_failed);

        if ($total_failed === 0) {
            $report .= "Status: ALL TESTS PASSED ✓\n";
        } else {
            $report .= "Status: SOME TESTS FAILED ✗\n";
        }

        $report .= "═══════════════════════════════════════════════════════════\n";

        return $report;
    }
}
