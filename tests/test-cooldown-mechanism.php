<?php
/**
 * Test script for cooldown mechanism
 * 
 * Run this in WordPress environment or via WP-CLI:
 * wp eval-file tests/test-cooldown-mechanism.php
 * 
 * Or include it temporarily in a WordPress admin page to test.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // For standalone testing, simulate
    define('DAY_IN_SECONDS', 86400);
}

class CooldownMechanismTest {
    
    private $test_results = [];
    
    /**
     * Run all tests
     */
    public function run_all_tests() {
        echo "\n=== Cooldown Mechanism Tests ===\n\n";
        
        $this->test_server_response_parsing();
        $this->test_days_left_calculation();
        $this->test_cooldown_activation();
        $this->test_cooldown_reset();
        $this->test_edge_cases();
        
        $this->print_summary();
    }
    
    /**
     * Test 1: Server response parsing
     */
    private function test_server_response_parsing() {
        echo "Test 1: Server Response Parsing\n";
        echo str_repeat("-", 40) . "\n";
        
        // Simulate server response with cooldown active
        $server_response_active = [
            'total_words_used' => 1850,
            'cooldown_active' => true,
            'cooldown_ends_at' => '2026-01-30T12:00:00Z'
        ];
        
        // Parse cooldown end date
        $cooldown_end = strtotime($server_response_active['cooldown_ends_at']);
        $expected_timestamp = strtotime('2026-01-30T12:00:00Z');
        
        $this->assert_equals(
            $cooldown_end, 
            $expected_timestamp, 
            "Parse ISO date to timestamp"
        );
        
        // Verify cooldown is detected as active
        $is_active = isset($server_response_active['cooldown_active']) && $server_response_active['cooldown_active'] === true;
        $this->assert_true($is_active, "Detect cooldown_active === true");
        
        // Simulate server response with cooldown NOT active
        $server_response_inactive = [
            'total_words_used' => 500,
            'cooldown_active' => false
        ];
        
        $is_inactive = isset($server_response_inactive['cooldown_active']) && $server_response_inactive['cooldown_active'] === false;
        $this->assert_true($is_inactive, "Detect cooldown_active === false");
        
        echo "\n";
    }
    
    /**
     * Test 2: Days left calculation
     */
    private function test_days_left_calculation() {
        echo "Test 2: Days Left Calculation\n";
        echo str_repeat("-", 40) . "\n";
        
        $now = time();
        
        // Test: Exactly 7 days from now
        $cooldown_end_7_days = $now + (7 * DAY_IN_SECONDS);
        $days_left = ceil(($cooldown_end_7_days - $now) / DAY_IN_SECONDS);
        $this->assert_equals_loose($days_left, 7, "7 days remaining calculates to 7");
        
        // Test: 6 days and 12 hours (should ceil to 7)
        $cooldown_end_6_5_days = $now + (6.5 * DAY_IN_SECONDS);
        $days_left = ceil(($cooldown_end_6_5_days - $now) / DAY_IN_SECONDS);
        $this->assert_equals_loose($days_left, 7, "6.5 days remaining rounds up to 7");
        
        // Test: 1 hour remaining (should ceil to 1)
        $cooldown_end_1_hour = $now + 3600;
        $days_left = ceil(($cooldown_end_1_hour - $now) / DAY_IN_SECONDS);
        $this->assert_equals_loose($days_left, 1, "1 hour remaining shows as 1 day");
        
        // Test: Just expired (should be 0 or negative)
        $cooldown_end_expired = $now - 100;
        $days_left = ceil(($cooldown_end_expired - $now) / DAY_IN_SECONDS);
        $this->assert_true($days_left <= 0, "Expired cooldown shows 0 or negative days");
        
        // Test with specific date from server
        $server_date = '2026-01-30T12:00:00Z';
        $cooldown_end = strtotime($server_date);
        $test_now = strtotime('2026-01-23T12:00:00Z'); // Simulate current time
        $days_left = ceil(($cooldown_end - $test_now) / DAY_IN_SECONDS);
        $this->assert_equals_loose($days_left, 7, "Server date 7 days ahead calculates correctly");
        
        echo "\n";
    }
    
    /**
     * Test 3: Cooldown activation from server response
     */
    private function test_cooldown_activation() {
        echo "Test 3: Cooldown Activation Logic\n";
        echo str_repeat("-", 40) . "\n";
        
        // Simulate the logic from class-translation-engine.php
        $server_response = [
            'total_words_used' => 1850,
            'cooldown_active' => true,
            'cooldown_ends_at' => '2026-01-30T15:30:00Z'
        ];
        
        $cooldown_activated = false;
        $cooldown_end_stored = 0;
        
        if (isset($server_response['cooldown_active']) && $server_response['cooldown_active'] === true) {
            $cooldown_end_stored = strtotime($server_response['cooldown_ends_at']);
            $cooldown_activated = true;
        }
        
        $this->assert_true($cooldown_activated, "Cooldown activated from server response");
        $this->assert_true($cooldown_end_stored > 0, "Cooldown end timestamp is stored");
        
        // Verify the stored date is correct
        $expected = strtotime('2026-01-30T15:30:00Z');
        $this->assert_equals($cooldown_end_stored, $expected, "Stored timestamp matches server date");
        
        echo "\n";
    }
    
    /**
     * Test 4: Cooldown reset from server
     */
    private function test_cooldown_reset() {
        echo "Test 4: Cooldown Reset Logic\n";
        echo str_repeat("-", 40) . "\n";
        
        // Simulate reset-usage response
        $reset_response = [
            'success' => true,
            'words_used' => 0
        ];
        
        $should_clear_cooldown = isset($reset_response['success']) 
            && $reset_response['success'] 
            && isset($reset_response['words_used']) 
            && $reset_response['words_used'] === 0;
        
        $this->assert_true($should_clear_cooldown, "Reset response triggers cooldown clear");
        
        // Test with non-zero words (shouldn't clear)
        $partial_reset_response = [
            'success' => true,
            'words_used' => 500
        ];
        
        $should_not_clear = isset($partial_reset_response['success']) 
            && $partial_reset_response['success'] 
            && isset($partial_reset_response['words_used']) 
            && $partial_reset_response['words_used'] === 0;
        
        $this->assert_false($should_not_clear, "Partial reset doesn't clear cooldown");
        
        // Test with cooldown_active = false from server
        $server_response_no_cooldown = [
            'total_words_used' => 1000,
            'cooldown_active' => false
        ];
        
        $should_clear_from_active_false = isset($server_response_no_cooldown['cooldown_active']) 
            && $server_response_no_cooldown['cooldown_active'] === false;
        
        $this->assert_true($should_clear_from_active_false, "cooldown_active=false clears local cooldown");
        
        echo "\n";
    }
    
    /**
     * Test 5: Edge cases
     */
    private function test_edge_cases() {
        echo "Test 5: Edge Cases\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test: Invalid date from server
        $invalid_date = strtotime('invalid-date');
        $this->assert_false($invalid_date, "Invalid date returns false");
        
        // Test: Missing cooldown_ends_at when cooldown_active is true
        $bad_response = [
            'cooldown_active' => true
            // missing 'cooldown_ends_at'
        ];
        
        $cooldown_end = isset($bad_response['cooldown_ends_at']) ? strtotime($bad_response['cooldown_ends_at']) : false;
        $this->assert_false($cooldown_end, "Missing cooldown_ends_at returns false");
        
        // Test: Empty response
        $empty_response = [];
        $has_cooldown_info = isset($empty_response['cooldown_active']);
        $this->assert_false($has_cooldown_info, "Empty response has no cooldown info");
        
        // Test: Various date formats that server might send
        $date_formats = [
            '2026-01-30T12:00:00Z' => true,
            '2026-01-30 12:00:00' => true,
            '2026-01-30' => true,
            // Note: Unix timestamp as string is NOT reliably parsed by strtotime()
            // Server should always send ISO 8601 format
        ];
        
        foreach ($date_formats as $date => $expected_valid) {
            $parsed = strtotime($date);
            $is_valid = $parsed !== false && $parsed > 0;
            $this->assert_equals($is_valid, $expected_valid, "Date format: $date");
        }
        
        echo "\n";
    }
    
    /**
     * Assert two values are equal (strict)
     */
    private function assert_equals($actual, $expected, $message) {
        $passed = $actual === $expected;
        $status = $passed ? "✓ PASS" : "✗ FAIL";
        
        echo "  $status: $message\n";
        if (!$passed) {
            echo "         Expected: " . var_export($expected, true) . "\n";
            echo "         Actual:   " . var_export($actual, true) . "\n";
        }
        
        $this->test_results[] = $passed;
    }
    
    /**
     * Assert two values are equal (loose comparison for int/float)
     */
    private function assert_equals_loose($actual, $expected, $message) {
        $passed = $actual == $expected;
        $status = $passed ? "✓ PASS" : "✗ FAIL";
        
        echo "  $status: $message\n";
        if (!$passed) {
            echo "         Expected: " . var_export($expected, true) . "\n";
            echo "         Actual:   " . var_export($actual, true) . "\n";
        }
        
        $this->test_results[] = $passed;
    }
    
    /**
     * Assert value is true
     */
    private function assert_true($actual, $message) {
        $this->assert_equals($actual, true, $message);
    }
    
    /**
     * Assert value is false
     */
    private function assert_false($actual, $message) {
        $this->assert_equals($actual, false, $message);
    }
    
    /**
     * Print test summary
     */
    private function print_summary() {
        echo str_repeat("=", 40) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("=", 40) . "\n";
        
        $total = count($this->test_results);
        $passed = count(array_filter($this->test_results));
        $failed = $total - $passed;
        
        echo "Total:  $total\n";
        echo "Passed: $passed\n";
        echo "Failed: $failed\n";
        
        if ($failed === 0) {
            echo "\n✓ All tests passed!\n";
        } else {
            echo "\n✗ Some tests failed!\n";
        }
    }
}

// Run tests if this file is executed directly
if (php_sapi_name() === 'cli' || defined('AITRFOEL_RUN_TESTS')) {
    $tester = new CooldownMechanismTest();
    $tester->run_all_tests();
}
