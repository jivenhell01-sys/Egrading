<?php
/**
 * HORIZON offline / PWA sync configuration.
 *
 * Central place for the hosted sync target. The local (XAMPP or on-device
 * installed) copy syncs its SQLite3 database with the hosted server:
 *
 *     https://essuc-academic.yzz.me/
 *
 * Setup (do once on BOTH copies — local and hosted):
 *   1. Copy sync_config.example values below or define env var HORIZON_SYNC_TOKEN
 *      with the SAME secret on both ends.
 *   2. On first run api_sync.php auto-creates the `sync_log` table.
 *
 * How it works:
 *   - api_sync.php?action=pull   → JSON snapshot of all syncable SQLite tables.
 *   - api_sync.php?action=push   → accepts a snapshot, upserts with
 *                                    INSERT OR REPLACE (last-writer-wins).
 *   - api_sync.php?action=apply  → same as push but intended for applying a
 *                                    remote snapshot to the LOCAL database.
 *   - js/offline-sync.js orchestrates: push local → pull remote → apply
 *     remote locally, plus IndexedDB caching + outbox queue for true-offline
 *     (no server reachable at all) operation.
 */

if (!defined('HORIZON_SYNC_SERVER_URL')) {
    define('HORIZON_SYNC_SERVER_URL', 'https://essuc-academic.yzz.me/');
}

if (!defined('HORIZON_SYNC_TOKEN')) {
    // Shared secret — MUST match on local + hosted. Prefer env var.
    $envToken = getenv('HORIZON_SYNC_TOKEN');
    if ($envToken === false || $envToken === '') {
        // Default placeholder: change this immediately in production.
        // Both ends must use the identical string.
        $envToken = 'change-me-horizon-sync-2026';
    }
    define('HORIZON_SYNC_TOKEN', $envToken);
}

if (!defined('HORIZON_SYNC_ENABLED')) {
    define('HORIZON_SYNC_ENABLED', true);
}

// Tables synced between SQLite3 files. `pk` may be a string or array (composite).
if (!function_exists('horizon_sync_tables')) {
    function horizon_sync_tables()
    {
        return [
            'subjects'                      => ['pk' => 'subject_id'],
            'grading_components'            => ['pk' => 'component_id'],
            'component_subject_percentage'  => ['pk' => 'csp_id'],
            'class_list'                    => ['pk' => 'class_id'],
            'quarter_list'                  => ['pk' => 'quarter_id'],
            'student_list'                  => ['pk' => 'student_id'],
            'assessment_list'               => ['pk' => 'assessment_id'],
            // composite primary identity (no single PK column)
            'mark_list'                     => ['pk' => ['assessment_id', 'student_id']],
            'attendance'                    => ['pk' => 'attendance_id'],
            'attendance_sessions'           => ['pk' => 'session_id'],
            'attendance_requests'           => ['pk' => 'id'],
            'student_initial_grade'         => ['pk' => 'id'],
            'student_grades'                => ['pk' => 'id'],
            'published_grades'              => ['pk' => 'id'],
            'transmutation_table'           => ['pk' => 'trans_id'],
            'system_settings'               => ['pk' => 'id'],
            'signatories'                   => ['pk' => 'signatory_id'],
            'header_footer_settings'        => ['pk' => 'id'],
        ];
    }
}
