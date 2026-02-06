<?php
date_default_timezone_set('Asia/Jakarta');

// =======================================================
// HELPER FUNCTIONS
// =======================================================

// Debug function (opsional)
function dd($data) {
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    exit();
}

// =======================================================
// SETTING FUNCTIONS
// =======================================================

function getSetting($db, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        error_log("Error getting setting '$key': " . $e->getMessage());
        return $default;
    }
}

function updateSetting($db, $key, $value) {
    try {
        // Check if setting exists
        $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            // Update existing
            $stmt = $db->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            // Insert new
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
        return true;
    } catch (Exception $e) {
        error_log("Error updating setting '$key': " . $e->getMessage());
        return false;
    }
}

function getAllSettings($db) {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    } catch (Exception $e) {
        error_log("Error getting all settings: " . $e->getMessage());
        return [];
    }
}

// =======================================================
// FUNGSI TAMBAHAN UNTUK HANDLE NULL
// =======================================================
function safeHtmlSpecialChars($input) {
    if ($input === null) {
        return '';
    }
    return htmlspecialchars($input);
}

// =======================================================
// FLASH MESSAGE
// =======================================================
function displayMessage() {
    $html = '';
    if (isset($_SESSION['message'])) {
        $html .= "<div class='alert alert-success'>".$_SESSION['message']."</div>";
        unset($_SESSION['message']);
    }

    if (isset($_SESSION['error'])) {
        $html .= "<div class='alert alert-danger'>".$_SESSION['error']."</div>";
        unset($_SESSION['error']);
    }

    if (isset($_SESSION['error_message'])) {
        $html .= "<div class='alert alert-danger'>".$_SESSION['error_message']."</div>";
        unset($_SESSION['error_message']);
    }
    
    return $html;
}

// =======================================================
// ROLE CHECK
// =======================================================
function requireAdmin() {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
        $_SESSION['error'] = "Akses ditolak. Halaman ini khusus admin.";
        header("Location: login.php");
        exit();
    }
}

function requireSuperadmin() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
        $_SESSION['error'] = "Akses ditolak. Hanya superadmin.";
        header("Location: dashboard.php");
        exit();
    }
}

// Fungsi baru untuk cek superadmin
function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin';
}

// =======================================================
// VALIDASI JADWAL (waktu)
// =======================================================
function validateScheduleTime($start, $end) {
    if (strtotime($end) <= strtotime($start)) {
        return "Waktu selesai harus lebih besar dari waktu mulai.";
    }
    return null;
}

// =======================================================
// CEK KONFLIK JADWAL
// =======================================================
function checkScheduleConflict($db, $kelas, $hari, $mulai, $selesai, $semester, $tahun, $dosen, $ruang, $exclude_id = null) {

    $query = "SELECT * FROM schedules 
              WHERE kelas = ?
              AND hari = ?
              AND semester = ?
              AND tahun_akademik = ?";

    $params = [$kelas, $hari, $semester, $tahun];

    if ($exclude_id) {
        $query .= " AND id != ?";
        $params[] = $exclude_id;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $conflicts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        list($existing_mulai, $existing_selesai) = array_map('trim', explode('-', $row['waktu']));

        if (
            ($mulai >= $existing_mulai && $mulai < $existing_selesai) ||
            ($selesai > $existing_mulai && $selesai <= $existing_selesai) ||
            ($mulai <= $existing_mulai && $selesai >= $existing_selesai)
        ) {
            $conflicts[] = "Bentrok dengan jadwal kelas {$kelas} jam {$row['waktu']}";
        }
    }

    return $conflicts;
}

// =======================================================
// LOG ACTIVITY
// =======================================================
function logActivity($db, $user_id, $action, $description) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$user_id, $action, $description, $ip_address, $user_agent]);
    } catch (Exception $e) {
        error_log("Log error: " . $e->getMessage());
        return false;
    }
}

// =======================================================
// GET USER ACTIVITY LOGS
// =======================================================
function getUserActivityLogs($db, $user_id, $page = 1, $limit = 20, $filters = []) {
    try {
        $offset = ($page - 1) * $limit;
        
        $query = "SELECT * FROM activity_logs WHERE user_id = ?";
        $params = [$user_id];
        
        // Apply filters
        if (!empty($filters['action'])) {
            $query .= " AND action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['date_from'])) {
            $query .= " AND DATE(created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $query .= " AND DATE(created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get user activity logs error: " . $e->getMessage());
        return [];
    }
}

// =======================================================
// COUNT USER ACTIVITY LOGS
// =======================================================
function countUserActivityLogs($db, $user_id, $filters = []) {
    try {
        $query = "SELECT COUNT(*) FROM activity_logs WHERE user_id = ?";
        $params = [$user_id];
        
        // Apply filters
        if (!empty($filters['action'])) {
            $query .= " AND action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['date_from'])) {
            $query .= " AND DATE(created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $query .= " AND DATE(created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Count user activity logs error: " . $e->getMessage());
        return 0;
    }
}

// =======================================================
// GET USER ACTIVITY STATS
// =======================================================
function getUserActivityStats($db, $user_id) {
    try {
        $query = "SELECT 
                    COUNT(*) as total_activities,
                    COUNT(DISTINCT DATE(created_at)) as active_days,
                    MIN(created_at) as first_activity,
                    MAX(created_at) as last_activity
                  FROM activity_logs 
                  WHERE user_id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get user activity stats error: " . $e->getMessage());
        return [
            'total_activities' => 0,
            'active_days' => 0,
            'first_activity' => null,
            'last_activity' => null
        ];
    }
}

// =======================================================
// GET USER DISTINCT ACTIONS
// =======================================================
function getUserDistinctActions($db, $user_id) {
    try {
        $query = "SELECT DISTINCT action FROM activity_logs WHERE user_id = ? ORDER BY action";
        $stmt = $db->prepare($query);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log("Get user distinct actions error: " . $e->getMessage());
        return [];
    }
}

// =======================================================
// SEMESTER SETTINGS
// =======================================================

function getActiveSemester($db) {
    try {
        $stmt = $db->query("SELECT * FROM semester_settings WHERE is_active = TRUE LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            $stmt = $db->query("SELECT * FROM semester_settings ORDER BY created_at DESC LIMIT 1");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return [
                    'tahun_akademik' => '2024/2025',
                    'semester' => 'Ganjil'
                ];
            }
        }

        return $result;

    } catch (Exception $e) {
        error_log("Get active semester error: " . $e->getMessage());
        return ['tahun_akademik' => '2024/2025', 'semester' => 'Ganjil'];
    }
}

function getAllTahunAkademik($db) {
    try {
        $stmt = $db->query("SELECT DISTINCT tahun_akademik FROM semester_settings ORDER BY tahun_akademik DESC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return ["2024/2025"];
    }
}

// =======================================================
// SEMESTER FUNCTIONS
// =======================================================

function getAllSemesters($db) {
    try {
        $stmt = $db->query("SELECT * FROM semester_settings ORDER BY tahun_akademik DESC, semester DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get all semesters error: " . $e->getMessage());
        return [];
    }
}

function addSemester($db, $tahun_akademik, $semester) {
    try {
        // Cek apakah semester sudah ada
        $stmt = $db->prepare("SELECT id FROM semester_settings WHERE tahun_akademik = ? AND semester = ?");
        $stmt->execute([$tahun_akademik, $semester]);
        
        if ($stmt->fetch()) {
            $_SESSION['error_message'] = "Semester sudah ada!";
            return false;
        }
        
        // Tambah semester baru
        $stmt = $db->prepare("INSERT INTO semester_settings (tahun_akademik, semester) VALUES (?, ?)");
        return $stmt->execute([$tahun_akademik, $semester]);
    } catch (Exception $e) {
        error_log("Add semester error: " . $e->getMessage());
        return false;
    }
}

function setActiveSemester($db, $tahun_akademik, $semester) {
    try {
        // Nonaktifkan semua semester terlebih dahulu
        $stmt = $db->prepare("UPDATE semester_settings SET is_active = FALSE");
        $stmt->execute();
        
        // Aktifkan semester yang dipilih
        $stmt = $db->prepare("UPDATE semester_settings SET is_active = TRUE WHERE tahun_akademik = ? AND semester = ?");
        return $stmt->execute([$tahun_akademik, $semester]);
    } catch (Exception $e) {
        error_log("Set active semester error: " . $e->getMessage());
        return false;
    }
}

function deleteSemester($db, $id) {
    try {
        // Cek apakah semester aktif
        $stmt = $db->prepare("SELECT is_active FROM semester_settings WHERE id = ?");
        $stmt->execute([$id]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($semester && $semester['is_active']) {
            return false; // Tidak bisa menghapus semester aktif
        }
        
        // Hapus semester
        $stmt = $db->prepare("DELETE FROM semester_settings WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (Exception $e) {
        error_log("Delete semester error: " . $e->getMessage());
        return false;
    }
}

// =======================================================
// USER MANAGEMENT FUNCTIONS
// =======================================================

function countActiveUsers($db) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = TRUE");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error counting active users: " . $e->getMessage());
        return 0;
    }
}

function validateUserAction($db, $current_user_id, $current_user_role, $target_user_id, $action) {
    try {
        // Ambil data user target
        $stmt = $db->prepare("SELECT role, is_active FROM users WHERE id = ?");
        $stmt->execute([$target_user_id]);
        $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$target_user) {
            return "User tidak ditemukan.";
        }
        
        // Jika user mencoba menghapus/mengedit diri sendiri
        if ($target_user_id == $current_user_id) {
            if ($action == 'delete') {
                return "Tidak dapat menghapus akun sendiri.";
            }
        }
        
        // Admin biasa tidak bisa mengedit/menghapus superadmin
        if ($current_user_role !== 'superadmin' && $target_user['role'] === 'superadmin') {
            if ($action == 'delete') {
                return "Admin biasa tidak dapat menghapus superadmin.";
            }
            if ($action == 'edit') {
                return "Admin biasa tidak dapat mengedit superadmin.";
            }
        }
        
        // Cek apakah ini adalah akun aktif terakhir
        if ($action == 'disable' || $action == 'delete') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = TRUE AND id != ?");
            $stmt->execute([$target_user_id]);
            $other_active_count = $stmt->fetchColumn();
            
            if ($target_user['is_active'] && $other_active_count == 0) {
                return "Tidak dapat menonaktifkan/hapus akun aktif terakhir.";
            }
        }
        
        return null; // Tidak ada error
    } catch (Exception $e) {
        error_log("Error validating user action: " . $e->getMessage());
        return "Terjadi kesalahan saat validasi.";
    }
}

// =======================================================
// PASSWORD VALIDATION FUNCTIONS
// =======================================================

function canChangePassword($current_user_id, $current_user_role, $target_user_id) {
    // Superadmin dapat mengubah semua password
    if ($current_user_role === 'superadmin') {
        return true;
    }
    
    // Admin biasa hanya dapat mengubah password milik sendiri
    if ($current_user_role === 'admin' && $current_user_id == $target_user_id) {
        return true;
    }
    
    return false;
}

function validatePasswordChange($current_user_id, $current_user_role, $target_user_id, $new_password) {
    if (!empty($new_password) && !canChangePassword($current_user_id, $current_user_role, $target_user_id)) {
        return "Anda tidak memiliki izin untuk mengubah password admin lain.";
    }
    
    return null;
}

// =======================================================
// GENERAL HELPER FUNCTIONS
// =======================================================

function sanitizeInput($input) {
    if ($input === null) {
        return '';
    }
    return htmlspecialchars(strip_tags(trim($input)));
}

function formatDateTime($datetime) {
    if (empty($datetime)) {
        return '-';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

function redirectWithError($url, $message) {
    $_SESSION['error_message'] = $message;
    header("Location: $url");
    exit();
}

function redirectWithSuccess($url, $message) {
    $_SESSION['message'] = $message;
    header("Location: $url");
    exit();
}

function checkPermission($required_role, $current_role) {
    $hierarchy = ['user' => 1, 'admin' => 2, 'superadmin' => 3];
    
    $required_level = isset($hierarchy[$required_role]) ? $hierarchy[$required_role] : 0;
    $current_level = isset($hierarchy[$current_role]) ? $hierarchy[$current_role] : 0;
    
    return $current_level >= $required_level;
}

// =======================================================
// USER ACTIVITY LOG FUNCTIONS
// =======================================================

function getActivityBadgeClass($action) {
    if (stripos($action, 'tambah') !== false) return 'badge-success';
    if (stripos($action, 'edit') !== false) return 'badge-warning';
    if (stripos($action, 'hapus') !== false) return 'badge-danger';
    if (stripos($action, 'login') !== false) return 'badge-primary';
    return 'badge-secondary';
}

function getActivityIcon($action) {
    if (stripos($action, 'tambah') !== false) return 'fa-plus-circle';
    if (stripos($action, 'edit') !== false) return 'fa-edit';
    if (stripos($action, 'hapus') !== false) return 'fa-trash-alt';
    if (stripos($action, 'login') !== false) return 'fa-sign-in-alt';
    return 'fa-history';
}

// =======================================================
// NEW FUNCTIONS FOR ADMIN ACTIVITY MONITORING
// =======================================================

function canViewAdminActivity($viewer_role, $viewer_id, $target_id) {
    // Hanya superadmin yang bisa melihat aktivitas admin lain
    if ($viewer_role !== 'superadmin') {
        return false;
    }
    
    // Superadmin tidak bisa melihat aktivitas sendiri di halaman ini
    // (karena sudah ada di dashboard)
    if ($viewer_id == $target_id) {
        return false;
    }
    
    return true;
}

function getAdminActivitySummary($db, $user_id, $days = 30) {
    try {
        $query = "SELECT 
                    DATE(created_at) as activity_date,
                    COUNT(*) as total_actions,
                    GROUP_CONCAT(DISTINCT action) as actions_list
                  FROM activity_logs 
                  WHERE user_id = ? 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  GROUP BY DATE(created_at)
                  ORDER BY activity_date DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$user_id, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get admin activity summary error: " . $e->getMessage());
        return [];
    }
}

function getTopActions($db, $user_id, $limit = 5) {
    try {
        $query = "SELECT 
                    action,
                    COUNT(*) as count,
                    MAX(created_at) as last_performed
                  FROM activity_logs 
                  WHERE user_id = ?
                  GROUP BY action
                  ORDER BY count DESC
                  LIMIT ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get top actions error: " . $e->getMessage());
        return [];
    }
}

function getActivityTimeRange($db, $user_id) {
    try {
        $query = "SELECT 
                    MIN(DATE(created_at)) as first_date,
                    MAX(DATE(created_at)) as last_date,
                    DATEDIFF(MAX(created_at), MIN(created_at)) as days_range
                  FROM activity_logs 
                  WHERE user_id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get activity time range error: " . $e->getMessage());
        return [
            'first_date' => null,
            'last_date' => null,
            'days_range' => 0
        ];
    }
}

// =======================================================
// CSRF PROTECTION FUNCTIONS
// =======================================================

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// =======================================================
// AUDIT LOG FUNCTIONS
// =======================================================

function logAdminAudit($db, $admin_id, $target_admin_id, $action, $description) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        $stmt = $db->prepare("
            INSERT INTO admin_audit_logs (admin_id, target_admin_id, action, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$admin_id, $target_admin_id, $action, $description, $ip_address, $user_agent]);
    } catch (Exception $e) {
        error_log("Admin audit log error: " . $e->getMessage());
        return false;
    }
}

// =======================================================
// DATA EXPORT FUNCTIONS
// =======================================================

function prepareActivityDataForExport($logs, $admin_info) {
    $export_data = [
        'export_info' => [
            'user_id' => $admin_info['id'] ?? 0,
            'username' => $admin_info['username'] ?? '',
            'role' => $admin_info['role'] ?? '',
            'export_date' => date('Y-m-d H:i:s'),
            'total_records' => count($logs ?? []),
            'exported_by' => $_SESSION['username'] ?? 'Unknown'
        ],
        'logs' => []
    ];
    
    if (!empty($logs)) {
        foreach ($logs as $log) {
            $export_data['logs'][] = [
                'id' => $log['id'] ?? 0,
                'date' => !empty($log['created_at']) ? date('Y-m-d', strtotime($log['created_at'])) : '',
                'time' => !empty($log['created_at']) ? date('H:i:s', strtotime($log['created_at'])) : '',
                'action' => $log['action'] ?? '',
                'description' => $log['description'] ?? '',
                'ip_address' => $log['ip_address'] ?? '',
                'user_agent' => $log['user_agent'] ?? ''
            ];
        }
    }
    
    return $export_data;
}

// =======================================================
// MODIFIED LOCKOUT FUNCTIONS (COUNTDOWN HANYA PADA PERCOBAAN TERAKHIR) - DIUBAH KE DETIK
// =======================================================

/**
 * Handle failed login attempts - Modified version: countdown only starts on last attempt
 */
function handleFailedLoginModified($db, $user_id) {
    try {
        // Get settings
        $max_attempts = (int)getSetting($db, 'max_login_attempts', 5);
        $initial_duration = (int)getSetting($db, 'lockout_initial_duration', 15); // SEKARANG DALAM DETIK
        
        // Get current user data
        $stmt = $db->prepare("SELECT failed_attempts FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return false;
        
        $failed_attempts = $user['failed_attempts'] + 1;
        
        // Only lock account when reaching max attempts (last attempt)
        if ($failed_attempts >= $max_attempts) {
            // Set lockout time (in SECONDS) - DIUBAH
            $lockout_seconds = $initial_duration; // Langsung gunakan detik
            $locked_until = date('Y-m-d H:i:s', strtotime("+{$lockout_seconds} seconds")); // DIUBAH: seconds bukan minutes
            
            $updateQuery = "UPDATE users SET 
                           failed_attempts = ?,
                           locked_until = ?,
                           lockout_multiplier = 1,
                           last_failed_attempt = NOW()
                           WHERE id = ?";
            
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([$failed_attempts, $locked_until, $user_id]);
            
            return [
                'locked' => true,
                'duration' => $lockout_seconds, // Sudah dalam detik
                'locked_until' => $locked_until,
                'attempts_left' => 0,
                'is_final_attempt' => true
            ];
        } else {
            // Update only failed attempts (no lockout yet)
            $updateQuery = "UPDATE users SET 
                           failed_attempts = ?,
                           last_failed_attempt = NOW()
                           WHERE id = ?";
            
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([$failed_attempts, $user_id]);
            
            $attempts_left = $max_attempts - $failed_attempts;
            $is_warning = ($attempts_left <= 2); // Warning on last 2 attempts
            
            return [
                'locked' => false,
                'attempts_left' => $attempts_left,
                'is_warning' => $is_warning,
                'is_final_attempt' => false
            ];
        }
    } catch (Exception $e) {
        error_log("Error handling failed login (modified): " . $e->getMessage());
        return false;
    }
}

/**
 * Get remaining attempts info
 */
function getRemainingAttemptsInfo($db, $user_id) {
    try {
        $max_attempts = (int)getSetting($db, 'max_login_attempts', 5);
        
        $stmt = $db->prepare("SELECT failed_attempts, locked_until FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return null;
        
        $failed_attempts = $user['failed_attempts'];
        $attempts_left = $max_attempts - $failed_attempts;
        $is_locked = $user['locked_until'] && strtotime($user['locked_until']) > time();
        
        return [
            'failed_attempts' => $failed_attempts,
            'attempts_left' => $attempts_left,
            'is_locked' => $is_locked,
            'locked_until' => $user['locked_until'],
            'max_attempts' => $max_attempts,
            'percentage' => ($failed_attempts / $max_attempts) * 100
        ];
    } catch (Exception $e) {
        error_log("Error getting remaining attempts info: " . $e->getMessage());
        return null;
    }
}

/**
 * Get progressive lockout status (visual indicator)
 */
function getProgressiveLockoutStatus($attempts_info) {
    if (!$attempts_info) return 'safe';
    
    if ($attempts_info['is_locked']) return 'locked';
    
    $percentage = $attempts_info['percentage'];
    
    if ($percentage >= 80) return 'danger';
    if ($percentage >= 60) return 'warning';
    if ($percentage >= 40) return 'caution';
    
    return 'safe';
}

/**
 * Reset failed attempts after successful login
 */
function resetFailedAttempts($db, $user_id) {
    try {
        $stmt = $db->prepare("UPDATE users SET 
                             failed_attempts = 0,
                             last_failed_attempt = NULL
                             WHERE id = ?");
        return $stmt->execute([$user_id]);
    } catch (Exception $e) {
        error_log("Error resetting failed attempts: " . $e->getMessage());
        return false;
    }
}

/**
 * Cleanup old failed attempts
 */
function cleanupOldFailedAttempts($db) {
    try {
        $lockout_reset_hours = (int)getSetting($db, 'lockout_reset_hours', 24);
        
        $query = "UPDATE users SET 
                  failed_attempts = 0,
                  last_failed_attempt = NULL
                  WHERE last_failed_attempt IS NOT NULL 
                  AND locked_until IS NULL
                  AND last_failed_attempt <= DATE_SUB(NOW(), INTERVAL ? HOUR)";
        
        $stmt = $db->prepare($query);
        return $stmt->execute([$lockout_reset_hours]);
    } catch (Exception $e) {
        error_log("Error cleaning up old failed attempts: " . $e->getMessage());
        return false;
    }
}

// =======================================================
// LOGIN SECURITY FUNCTIONS (DIPERBAIKI) - DIUBAH KE DETIK
// =======================================================

/**
 * Check if an account is locked out (real-time check)
 * Returns remaining lockout time in seconds if locked, false otherwise
 * DIPERBAIKI: Reset failed_attempts ketika lockout berakhir
 */
function checkAccountLockout($db, $user) {
    try {
        if (!$user) return false;
        
        $current_time = time();
        $locked_until = $user['locked_until'] ? strtotime($user['locked_until']) : null;
        
        // If account is locked and lockout time hasn't expired
        if ($locked_until && $locked_until > $current_time) {
            return $locked_until - $current_time; // Return remaining seconds
        }
        
        // If lockout time has expired, reset lockout and failed attempts
        if ($locked_until && $locked_until <= $current_time) {
            resetLoginAttempts($db, $user['id']);
            return false;
        }
        
        // Reset lockout if the reset period has passed (only for failed attempts)
        $lockout_reset_hours = (int)getSetting($db, 'lockout_reset_hours', 24);
        $last_failed_attempt = strtotime($user['last_failed_attempt'] ?? ''); 

        if ($last_failed_attempt && 
            ($current_time - $last_failed_attempt) > ($lockout_reset_hours * 3600)) {
            resetLoginAttempts($db, $user['id']);
            return false;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error checking account lockout: " . $e->getMessage());
        return false;
    }
}

/**
 * Handle failed login attempts and lock account if necessary - DIUBAH KE DETIK
 */
function handleFailedLogin($db, $user_id) {
    try {
        // Get settings
        $max_attempts = (int)getSetting($db, 'max_login_attempts', 5);
        $initial_duration = (int)getSetting($db, 'lockout_initial_duration', 15); // SEKARANG DALAM DETIK
        $max_multiplier = (int)getSetting($db, 'lockout_max_multiplier', 24);
        
        // Get current user data
        $stmt = $db->prepare("SELECT failed_attempts, lockout_multiplier, locked_until FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return false;
        
        $failed_attempts = $user['failed_attempts'] + 1;
        $current_multiplier = $user['lockout_multiplier'];
        $is_locked = $user['locked_until'] && strtotime($user['locked_until']) > time();
        
        // If account is already locked, don't increase attempts
        if ($is_locked) {
            return [
                'locked' => true,
                'duration' => strtotime($user['locked_until']) - time(),
                'locked_until' => $user['locked_until']
            ];
        }
        
        // Check if max attempts exceeded
        if ($failed_attempts >= $max_attempts) {
            // Calculate lockout duration in SECONDS - DIUBAH
            $lockout_seconds = $initial_duration * pow(2, $current_multiplier - 1); // dalam detik
            
            // Don't exceed max multiplier
            $new_multiplier = min($current_multiplier + 1, $max_multiplier);
            
            // Set lockout time (in SECONDS) - DIUBAH
            $locked_until = date('Y-m-d H:i:s', strtotime("+{$lockout_seconds} seconds"));
            
            $updateQuery = "UPDATE users SET 
                           failed_attempts = ?,
                           locked_until = ?,
                           lockout_multiplier = ?,
                           last_failed_attempt = NOW()
                           WHERE id = ?";
            
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([$failed_attempts, $locked_until, $new_multiplier, $user_id]);
            
            return [
                'locked' => true,
                'duration' => $lockout_seconds, // dalam detik
                'locked_until' => $locked_until
            ];
        } else {
            // Update only failed attempts
            $updateQuery = "UPDATE users SET 
                           failed_attempts = ?,
                           last_failed_attempt = NOW()
                           WHERE id = ?";
            
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([$failed_attempts, $user_id]);
            
            return [
                'locked' => false,
                'attempts_left' => $max_attempts - $failed_attempts
            ];
        }
    } catch (Exception $e) {
        error_log("Error handling failed login: " . $e->getMessage());
        return false;
    }
}

/**
 * Reset login attempts and unlock account
 */
function resetLoginAttempts($db, $user_id) {
    try {
        $stmt = $db->prepare("UPDATE users SET 
                             failed_attempts = 0,
                             locked_until = NULL,
                             lockout_multiplier = 1,
                             last_failed_attempt = NULL
                             WHERE id = ?");
        return $stmt->execute([$user_id]);
    } catch (Exception $e) {
        error_log("Error resetting login attempts: " . $e->getMessage());
        return false;
    }
}

/**
 * Check real-time lockout status and auto-reset if expired
 * DIPERBAIKI: Reset failed_attempts menjadi 0 ketika lockout berakhir
 */
function checkRealTimeLockoutStatus($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT locked_until, failed_attempts FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['locked_until']) {
            return false; // Not locked
        }
        
        $locked_until = strtotime($user['locked_until']);
        $current_time = time();
        
        // If lockout time has passed, reset attempts and unlock automatically
        if ($locked_until <= $current_time) {
            // Reset failed attempts dan unlock account
            resetLoginAttempts($db, $user_id);
            return false;
        }
        
        return $locked_until - $current_time; // Return remaining seconds
    } catch (Exception $e) {
        error_log("Error checking real-time lockout: " . $e->getMessage());
        return false;
    }
}

/**
 * Format lockout time for display
 */
function formatLockoutTime($seconds) {
    if ($seconds < 60) {
        return "{$seconds} detik";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        return "{$minutes} menit " . ($seconds > 0 ? "{$seconds} detik" : "");
    } elseif ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "{$hours} jam " . ($minutes > 0 ? "{$minutes} menit" : "");
    } else {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        return "{$days} hari " . ($hours > 0 ? "{$hours} jam" : "");
    }
}

/**
 * Check if account is inactive
 */
function isAccountInactive($db, $username) {
    try {
        $stmt = $db->prepare("SELECT is_active FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user && $user['is_active'] == 0;
    } catch (Exception $e) {
        error_log("Error checking account status: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean up expired lockouts automatically
 * DIPERBAIKI: Reset failed_attempts menjadi 0
 */
function cleanupExpiredLockouts($db) {
    try {
        $query = "UPDATE users SET 
                  failed_attempts = 0,
                  locked_until = NULL,
                  lockout_multiplier = 1,
                  last_failed_attempt = NULL
                  WHERE locked_until IS NOT NULL 
                  AND locked_until <= NOW()";
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute();
        
        if ($result && $stmt->rowCount() > 0) {
            error_log("Cleaned up " . $stmt->rowCount() . " expired lockouts (reset attempts to 0)");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error cleaning up expired lockouts: " . $e->getMessage());
        return false;
    }
}

/**
 * Get lockout status message for a specific username
 */
function getLockoutStatusMessage($db, $username) {
    try {
        $stmt = $db->prepare("SELECT locked_until, failed_attempts FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['locked_until']) {
            return null;
        }
        
        $locked_until = strtotime($user['locked_until']);
        $current_time = time();
        
        if ($locked_until <= $current_time) {
            // Auto reset jika sudah expired
            cleanupExpiredLockouts($db);
            return null;
        }
        
        $remaining = $locked_until - $current_time;
        return formatLockoutTime($remaining);
    } catch (Exception $e) {
        error_log("Error getting lockout status: " . $e->getMessage());
        return null;
    }
}

// =======================================================
// SESSION SECURITY FUNCTIONS
// =======================================================

function validateSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Cek apakah ada session yang menunjukkan pengguna mencoba bypass login
    if (isset($_SESSION['login_bypass_attempt'])) {
        session_destroy();
        header("Location: login.php?error=access_denied");
        exit();
    }
}

function preventDirectAccess() {
    $allowed_pages = ['login.php', 'register_superadmin.php'];
    $current_page = basename($_SERVER['PHP_SELF']);
    
    if (!in_array($current_page, $allowed_pages) && !isset($_SESSION['user_id'])) {
        // Tandai sebagai bypass attempt
        session_start();
        $_SESSION['login_bypass_attempt'] = true;
        header("Location: login.php?error=direct_access");
        exit();
    }
}

// =======================================================
// GET LOCKOUT SETTINGS
// =======================================================

function getLockoutSettings($db) {
    try {
        $settings = [
            'max_login_attempts' => (int)getSetting($db, 'max_login_attempts', 5),
            'lockout_initial_duration' => (int)getSetting($db, 'lockout_initial_duration', 15), // SEKARANG DALAM DETIK
            'lockout_max_multiplier' => (int)getSetting($db, 'lockout_max_multiplier', 24),
            'lockout_reset_hours' => (int)getSetting($db, 'lockout_reset_hours', 24)
        ];
        
        return $settings;
    } catch (Exception $e) {
        error_log("Error getting lockout settings: " . $e->getMessage());
        return [
            'max_login_attempts' => 5,
            'lockout_initial_duration' => 15, // DALAM DETIK
            'lockout_max_multiplier' => 24,
            'lockout_reset_hours' => 24
        ];
    }
}

// =======================================================
// GET USER LOCKOUT INFO
// =======================================================

function getUserLockoutInfo($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT 
                              failed_attempts,
                              locked_until,
                              lockout_multiplier,
                              last_failed_attempt,
                              is_active
                              FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return null;
        
        $info = [
            'failed_attempts' => $user['failed_attempts'],
            'locked_until' => $user['locked_until'],
            'lockout_multiplier' => $user['lockout_multiplier'],
            'is_active' => $user['is_active'],
            'is_locked' => false,
            'remaining_time' => 0
        ];
        
        // Check if currently locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $info['is_locked'] = true;
            $info['remaining_time'] = strtotime($user['locked_until']) - time();
        }
        
        return $info;
    } catch (Exception $e) {
        error_log("Error getting user lockout info: " . $e->getMessage());
        return null;
    }
}
?>