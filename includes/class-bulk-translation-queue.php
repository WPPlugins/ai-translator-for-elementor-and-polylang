<?php
/**
 * Bulk Translation Queue Manager
 * Handles job creation, status tracking, and queue management
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Bulk_Translation_Queue {
    
    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aitrfoel_bulk_jobs';
        
        // Create table on activation if it doesn't exist
        add_action('init', [$this, 'maybe_create_table']);
    }

    /**
     * Create database table for bulk jobs
     */
    public function maybe_create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(32) UNIQUE NOT NULL,
            user_id INT NOT NULL DEFAULT 0,
            status ENUM('pending','processing','paused','completed','failed','cancelled') DEFAULT 'pending',
            page_ids TEXT NOT NULL,
            target_languages TEXT NOT NULL,
            source_language VARCHAR(10) DEFAULT 'auto',
            total_items INT DEFAULT 0,
            completed_items INT DEFAULT 0,
            errors INT DEFAULT 0,
            progress DECIMAL(5,2) DEFAULT 0,
            current_item TEXT,
            current_step VARCHAR(255),
            details TEXT,
            settings TEXT,
            results TEXT,
            error_log TEXT,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Check if new columns exist and add them if not
        $this->maybe_add_new_columns();
    }

    /**
     * Add new columns to existing table
     */
    private function maybe_add_new_columns() {
        global $wpdb;
        
        // Check if current_item column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'current_item'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN current_item INT DEFAULT 0 AFTER completed_items");
        }
        
        // Check if current_step column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'current_step'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN current_step VARCHAR(255) DEFAULT 'pending' AFTER current_item");
        }
        
        // Check if details column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'details'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN details TEXT AFTER current_step");
        }

        // Check if stage column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'stage'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN stage INT DEFAULT 1 AFTER details");
        }

        // Check if stage_description column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'stage_description'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN stage_description TEXT AFTER stage");
        }

        // Check if current_page_id column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'current_page_id'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN current_page_id INT DEFAULT NULL AFTER stage_description");
        }

        // Check if current_language column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'current_language'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN current_language VARCHAR(10) DEFAULT NULL AFTER current_page_id");
        }

        // Check if completed_page_id column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'completed_page_id'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN completed_page_id INT DEFAULT NULL AFTER current_language");
        }

        // Check if completed_language column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'completed_language'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN completed_language VARCHAR(10) DEFAULT NULL AFTER completed_page_id");
        }

        // Check if error_type column exists (NEW)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'error_type'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN error_type VARCHAR(50) DEFAULT NULL AFTER error_log");
        }

        // Check if error_details column exists (NEW)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'error_details'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN error_details TEXT DEFAULT NULL AFTER error_type");
        }

        // Ensure the table uses utf8mb4 so 4-byte emoji in current_step etc. don't fail.
        $table_status = $wpdb->get_row("SELECT `CHARACTER_SET_NAME` FROM `information_schema`.`COLUMNS`
            WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = '{$this->table_name}' AND `COLUMN_NAME` = 'current_step' LIMIT 1");
        if ($table_status && $table_status->CHARACTER_SET_NAME === 'utf8') {
            $wpdb->query("ALTER TABLE {$this->table_name} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    /**
     * Create a new bulk translation job
     */
    public function create_job($job_id, $job_data = []) {
        global $wpdb;

        // Extract data from job_data array
        $page_ids = $job_data['page_ids'] ?? [];
        $target_languages = $job_data['target_languages'] ?? [];
        $source_language = $job_data['source_language'] ?? 'auto';
        $settings = $job_data['settings'] ?? [];
        $user_id = $job_data['user_id'] ?? get_current_user_id();
        $total_items = $job_data['total_items'] ?? (count($page_ids) * count($target_languages));

        // Validate inputs
        if (empty($job_id) || empty($page_ids) || empty($target_languages)) {
            return new WP_Error('invalid_data', __('Job ID, page IDs, and target languages are required.', 'ai-translator-for-elementor-and-polylang'));
        }

        // Prepare data for actual table structure
        $data = [
            'job_id' => sanitize_text_field($job_id),
            'user_id' => intval($user_id),
            'status' => 'pending',
            'page_ids' => maybe_serialize($page_ids),
            'target_languages' => maybe_serialize($target_languages),
            'source_language' => sanitize_text_field($source_language),
            'total_items' => intval($total_items),
            'completed_items' => 0,
            'errors' => 0,
            'progress' => 0.00,
            'settings' => maybe_serialize($settings),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $result = $wpdb->insert($this->table_name, $data, [
            '%s', // job_id
            '%d', // user_id
            '%s', // status  
            '%s', // page_ids
            '%s', // target_languages
            '%s', // source_language
            '%d', // total_items
            '%d', // completed_items
            '%d', // errors
            '%f', // progress
            '%s', // settings
            '%s', // created_at
            '%s'  // updated_at
        ]);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create bulk translation job.', 'ai-translator-for-elementor-and-polylang'));
        }

        AITRFOEL_Logger::log("Bulk translation job created: {$job_id}", 'info', $data);
        
        // Track bulk translation feature usage with PostHog
        if (function_exists('aitrfoel_analytics')) {
            aitrfoel_analytics()->track_feature_used('bulk_translation', [
                'page_count' => count($page_ids),
                'target_language_count' => count($target_languages),
                'total_items' => $total_items,
            ]);
            
            aitrfoel_analytics()->track_translation_started(
                count($page_ids),
                $source_language,
                $target_languages,
                'bulk'
            );
        }

        return $wpdb->insert_id;
    }

    /**
     * Get job status and details
     */
    public function get_job($job_id) {
        global $wpdb;

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE job_id = %s",
            $job_id
        ));

        if (!$job) {
            return null;
        }

        // Handle real table structure - unserialize serialized fields
        if (isset($job->page_ids)) {
            $job->page_ids = maybe_unserialize($job->page_ids);
        }
        if (isset($job->target_languages)) {
            $job->target_languages = maybe_unserialize($job->target_languages);
        }
        if (isset($job->settings)) {
            $job->settings = maybe_unserialize($job->settings);
        }
        if (isset($job->results)) {
            $job->results = maybe_unserialize($job->results);
        }
        if (isset($job->error_log)) {
            $job->error_log = maybe_unserialize($job->error_log);
        }
        
        // Set defaults for missing fields to avoid warnings
        $job->page_ids = $job->page_ids ?? [];
        $job->settings = $job->settings ?? [];
        $job->results = $job->results ?? [];
        $job->error_log = $job->error_log ?? [];

        return $job;
    }

    /**
     * Get job status with progress information
     */
    public function get_job_status($job_id) {
        $job = $this->get_job($job_id);
        
        if (!$job) {
            return null;
        }
        
        return [
            'status' => $job->status,
            'progress' => floatval($job->progress),
            'total_items' => intval($job->total_items),
            'completed_items' => intval($job->completed_items),
            'errors' => intval($job->errors ?? 0),
            'current_step' => $job->current_step ?? '',
            'details' => $job->details ?? '',
            'stage' => intval($job->stage ?? 1),
            'stage_description' => $job->stage_description ?? 'Processing...',
            'created_at' => $job->created_at,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at
        ];
    }

    /**
     * Update job status and progress
     */
    public function update_job($job_id, $updates) {
        global $wpdb;

        if (empty($job_id) || !is_array($updates) || empty($updates)) {
            return 0;
        }

        // Prepare data with timestamps
        if (isset($updates['status'])) {
            if ($updates['status'] === 'processing' && !isset($updates['started_at'])) {
                $updates['started_at'] = current_time('mysql');
            } elseif (in_array($updates['status'], ['completed', 'failed', 'cancelled'])) {
                $updates['completed_at'] = current_time('mysql');
            }
        }

        // Calculate progress if completed_items is updated
        if (isset($updates['completed_items'])) {
            $job = $this->get_job($job_id);
            if ($job && $job->total_items > 0) {
                $updates['progress'] = round(($updates['completed_items'] / $job->total_items) * 100, 2);
            }
        }

        // Serialize arrays
        foreach (['settings', 'results', 'error_log'] as $field) {
            if (isset($updates[$field]) && is_array($updates[$field])) {
                $updates[$field] = maybe_serialize($updates[$field]);
            }
        }

        // Keep high-churn fields within DB column limits to avoid SQL failures.
        if (isset($updates['status'])) {
            $updates['status'] = sanitize_text_field($updates['status']);
        }
        if (isset($updates['current_step'])) {
            $updates['current_step'] = mb_substr(sanitize_text_field((string) $updates['current_step']), 0, 250);
        }
        if (isset($updates['current_language'])) {
            $updates['current_language'] = mb_substr(sanitize_text_field((string) $updates['current_language']), 0, 10);
        }
        if (isset($updates['completed_language'])) {
            $updates['completed_language'] = mb_substr(sanitize_text_field((string) $updates['completed_language']), 0, 10);
        }
        if (isset($updates['error_type'])) {
            $updates['error_type'] = mb_substr(sanitize_text_field((string) $updates['error_type']), 0, 50);
        }

        // Strip 4-byte unicode characters (emoji etc.) from string fields to prevent failures
        // on databases that use the utf8 charset (max 3 bytes per character) instead of utf8mb4.
        $string_fields = ['current_step', 'details', 'stage_description', 'current_step', 'error_type', 'error_details'];
        foreach ($string_fields as $field) {
            if (isset($updates[$field]) && is_string($updates[$field])) {
                $updates[$field] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $updates[$field]);
            }
        }

        // Filter out unknown columns for older tables that may miss newer schema fields.
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}", 0);
        if (is_array($columns) && !empty($columns)) {
            $updates = array_intersect_key($updates, array_flip($columns));
        }

        if (empty($updates)) {
            return 0;
        }

        // Ensure the DB connection is alive — long-running bulk requests can cause
        // the MySQL connection to be silently dropped (wait_timeout on hosting).
        if ( ! $wpdb->check_connection( false ) ) {
            AITRFOEL_Logger::log(
                'Bulk job update skipped: DB connection unavailable',
                'warning',
                ['job_id' => $job_id]
            );
            return false;
        }

        $result = $wpdb->update(
            $this->table_name,
            $updates,
            ['job_id' => $job_id],
            null,
            ['%s']
        );

        if ($result === false) {
            // Log the failure but DO NOT throw — a status-update failure must never abort
            // the bulk translation batch. Individual translations already in progress
            // should complete regardless of whether we can persist progress to the DB.
            AITRFOEL_Logger::log(
                'Bulk job update failed (non-fatal): ' . $wpdb->last_error,
                'warning',
                ['job_id' => $job_id, 'updates' => array_keys($updates)]
            );
            return false;
        }

        return $result;
    }

    /**
     * Update detailed progress tracking
     */
    public function update_job_progress($job_id, $current_item, $current_step, $details = null) {
        $updates = [
            'current_item' => $current_item,
            'current_step' => $current_step
        ];
        
        if ($details !== null) {
            $updates['details'] = $details;
        }
        
        return $this->update_job($job_id, $updates);
    }

    /**
     * Set current page being translated
     */
    public function set_current_page($job_id, $page_id, $language = null) {
        $updates = [
            'current_page_id' => intval($page_id)
        ];
        
        if ($language !== null) {
            $updates['current_language'] = sanitize_text_field($language);
        }
        
        return $this->update_job($job_id, $updates);
    }

    /**
     * Set completed page translation
     */
    public function set_completed_page($job_id, $page_id, $language = null) {
        $updates = [
            'completed_page_id' => intval($page_id)
        ];
        
        if ($language !== null) {
            $updates['completed_language'] = sanitize_text_field($language);
        }
        
        return $this->update_job($job_id, $updates);
    }

    /**
     * Delete job
     */
    public function delete_job($job_id) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            ['job_id' => $job_id],
            ['%s']
        );

        if ($result === false) {
            throw new Exception(esc_html__('Failed to delete bulk translation job.', 'ai-translator-for-elementor-and-polylang'));
        }

        AITRFOEL_Logger::log("Bulk translation job deleted: {$job_id}", 'info');

        return $result;
    }

    /**
     * Get all pending jobs for processing
     */
    public function get_pending_jobs($limit = 10) {
        global $wpdb;

        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = 'pending' 
             ORDER BY created_at ASC 
             LIMIT %d",
            $limit
        ));

        // Unserialize data for each job
        foreach ($jobs as $job) {
            $job->page_ids = maybe_unserialize($job->page_ids);
            $job->target_languages = maybe_unserialize($job->target_languages);
            $job->settings = maybe_unserialize($job->settings) ?: [];
        }

        return $jobs;
    }

    /**
     * Get jobs by status
     */
    public function get_jobs_by_status($status, $limit = 50) {
        global $wpdb;

        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = %s 
             ORDER BY created_at DESC 
             LIMIT %d",
            $status,
            $limit
        ));

        return $jobs;
    }

    /**
     * Clean up old completed jobs
     */
    public function cleanup_old_jobs($days = 30) {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE status IN ('completed', 'failed', 'cancelled') 
             AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        AITRFOEL_Logger::log("Cleaned up {$result} old bulk translation jobs", 'info');

        return $result;
    }

    /**
     * Get job statistics
     */
    public function get_statistics() {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as active_jobs,
                SUM(completed_items) as total_translations,
                AVG(CASE WHEN status = 'completed' THEN 
                    TIMESTAMPDIFF(SECOND, started_at, completed_at) 
                    ELSE NULL END) as avg_completion_time
            FROM {$this->table_name}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return $stats;
    }

    /**
     * Add error to job log
     */
    public function add_error($job_id, $error_message, $context = []) {
        $job = $this->get_job($job_id);
        if (!$job) {
            return false;
        }

        $error_entry = [
            'timestamp' => current_time('mysql'),
            'message' => $error_message,
            'context' => $context
        ];

        $error_log = $job->error_log ?: [];
        $error_log[] = $error_entry;

        $this->update_job($job_id, [
            'error_log' => $error_log
        ]);

        AITRFOEL_Logger::log("Error in bulk job {$job_id}: {$error_message}", 'error', $context);

        return true;
    }

    /**
     * Add translation result to job
     */
    public function add_result($job_id, $result) {
        $job = $this->get_job($job_id);
        if (!$job) {
            return false;
        }

        $results = $job->results ?: [];
        $results[] = [
            'timestamp' => current_time('mysql'),
            'result' => $result
        ];

        // Update completed items count
        $completed_items = $job->completed_items + 1;

        $updates = [
            'results' => $results,
            'completed_items' => $completed_items
        ];

        // Mark as completed if all items are done
        if ($completed_items >= $job->total_items) {
            $updates['status'] = 'completed';
        }

        $this->update_job($job_id, $updates);

        return true;
    }
    
    /**
     * Update job progress with detailed information
     */
    public function update_progress($job_id, $progress_data) {
        $updates = [
            'completed_items' => $progress_data['completed_items'] ?? 0,
            'errors' => $progress_data['errors'] ?? 0,
            'results' => maybe_serialize($progress_data['results'] ?? []),
            'updated_at' => current_time('mysql')
        ];
        
        // Update status based on progress
        $total_items = $progress_data['total_items'] ?? 1;
        $completed_items = $progress_data['completed_items'] ?? 0;
        
        if ($completed_items >= $total_items) {
            $updates['status'] = 'completed';
        } else if ($completed_items > 0) {
            $updates['status'] = 'processing';
        }
        
        return $this->update_job($job_id, $updates);
    }
}
