<?php
date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Redirect dengan pesan
 */
function redirect($url, $message = null, $type = 'info') {
    if ($message) {
        if ($type == 'error') {
            $_SESSION['error_message'] = $message;
        } else {
            $_SESSION['message'] = $message;
        }
    }
    header("Location: $url");
    exit();
}

/**
 * Tampilkan pesan alert
 */
function displayMessage() {
    $html = '';
    
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        unset($_SESSION['message']);
        $html = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                    <i class='fas fa-check-circle me-2'></i>
                    {$message}
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    
    if (isset($_SESSION['error_message'])) {
        $message = $_SESSION['error_message'];
        unset($_SESSION['error_message']);
        $html .= "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                    <i class='fas fa-exclamation-circle me-2'></i>
                    {$message}
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    
    return $html;
}

/**
 * Log aktivitas
 */
function logActivity($db, $user_id, $action, $description = null) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $query = "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?, ?)";

        $stmt = $db->prepare($query);
        return $stmt->execute([$user_id, $action, $description, $ip_address, $user_agent]);
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Ambil pengaturan
 */
function getSetting($db, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        error_log("Error getting setting {$key}: " . $e->getMessage());
        return $default;
    }
}

/**
 * Wajib login admin
 */
function requireAdmin() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        redirect('../admin/login.php', 'Silakan login terlebih dahulu.', 'error');
    }
}

/**
 * Wajib superadmin
 */
function requireSuperAdmin() {
    requireAdmin();
    if ($_SESSION['role'] != 'superadmin') {
        redirect('dashboard.php', 'Akses ditolak. Hanya superadmin yang dapat mengakses halaman ini.', 'error');
    }
}

/**
 * Update / Insert Setting
 */
function updateSetting($db, $key, $value) {
    try {
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $checkStmt->execute([$key]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            $stmt = $db->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() 
                                  WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }

        return true;
    } catch (Exception $e) {
        error_log("Error updating setting {$key}: " . $e->getMessage());
        return false;
    }
}

/**
 * Ambil semua pengaturan sebagai array
 */
function getAllSettings($db) {
    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($results as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        return $settings;
    } catch (Exception $e) {
        error_log("Error getting all settings: " . $e->getMessage());
        return [];
    }
}

/**
 * Cek apakah ada data jadwal
 */
function hasScheduleData($db, $tahun_akademik = null, $semester = null) {
    try {
        if ($tahun_akademik && $semester) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM schedules WHERE tahun_akademik = ? AND semester = ?");
            $stmt->execute([$tahun_akademik, $semester]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM schedules");
            $stmt->execute();
        }
        
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log("Error checking schedule data: " . $e->getMessage());
        return false;
    }
}

/**
 * Validasi waktu untuk jadwal
 */
function validateScheduleTime($waktu_mulai, $waktu_selesai) {
    if (empty($waktu_mulai) || empty($waktu_selesai)) {
        return "Waktu mulai dan selesai harus diisi";
    }
    
    if ($waktu_selesai <= $waktu_mulai) {
        return "Waktu selesai harus setelah waktu mulai";
    }
    
    // Format validation
    if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $waktu_mulai) ||
        !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $waktu_selesai)) {
        return "Format waktu tidak valid. Gunakan format HH:MM";
    }
    
    return null;
}

/**
 * Konversi jam ke → jam real
 */
function jamKeToTime($jam_ke) {
    $slots = [
        1 => '07:30 - 09:00',
        2 => '09:00 - 10:30',
        3 => '10:30 - 12:00',
        4 => '13:00 - 14:30',
        5 => '14:30 - 16:00',
        6 => '16:00 - 17:30',
        7 => '18:00 - 19:30',
        8 => '19:30 - 21:00',
        9 => '21:00 - 22:30',
        10 => '22:30 - 00:00'
    ];

    return $slots[$jam_ke] ?? '';
}

/**
 * Ambil jam ke dari waktu
 */
function getJamKeFromTime($time) {
    $jam = (int)substr($time, 0, 2);
    $menit = (int)substr($time, 3, 2);
    
    $total_menit = $jam * 60 + $menit;
    
    if ($total_menit >= 450 && $total_menit < 540) return 1;      // 07:30 - 09:00
    elseif ($total_menit >= 540 && $total_menit < 630) return 2;   // 09:00 - 10:30
    elseif ($total_menit >= 630 && $total_menit < 720) return 3;   // 10:30 - 12:00
    elseif ($total_menit >= 780 && $total_menit < 870) return 4;   // 13:00 - 14:30
    elseif ($total_menit >= 870 && $total_menit < 960) return 5;   // 14:30 - 16:00
    elseif ($total_menit >= 960 && $total_menit < 1050) return 6;  // 16:00 - 17:30
    elseif ($total_menit >= 1080 && $total_menit < 1170) return 7; // 18:00 - 19:30
    elseif ($total_menit >= 1170 && $total_menit < 1260) return 8; // 19:30 - 21:00
    elseif ($total_menit >= 1260 && $total_menit < 1350) return 9; // 21:00 - 22:30
    elseif ($total_menit >= 1350 || $total_menit < 450) return 10; // 22:30 - 00:00
    else return 0;
}

/**
 * Cek bentrok jadwal
 */
function checkScheduleConflict($db, $kelas, $hari, $waktu_mulai, $waktu_selesai, $semester, $tahun_akademik, $dosen = null, $ruang = null, $exclude_id = null) {
    $conflicts = [];
    
    try {
        // Cek bentrok kelas
        $query = "SELECT * FROM schedules 
                  WHERE kelas = ? 
                  AND hari = ? 
                  AND semester = ? 
                  AND tahun_akademik = ?";
        
        $params = [$kelas, $hari, $semester, $tahun_akademik];
        
        if ($exclude_id) {
            $query .= " AND id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $existing_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($existing_schedules as $existing) {
            list($existing_start, $existing_end) = explode(' - ', $existing['waktu']);
            
            if (($waktu_mulai < $existing_end) && ($waktu_selesai > $existing_start)) {
                $conflicts[] = "Kelas {$kelas} sudah memiliki jadwal {$existing['mata_kuliah']} pada hari {$hari} pukul {$existing['waktu']}";
                break;
            }
        }
        
        // Cek bentrok dosen
        if ($dosen) {
            $query_dosen = "SELECT * FROM schedules 
                           WHERE dosen = ? 
                           AND hari = ? 
                           AND semester = ? 
                           AND tahun_akademik = ?";
            
            $params_dosen = [$dosen, $hari, $semester, $tahun_akademik];
            
            if ($exclude_id) {
                $query_dosen .= " AND id != ?";
                $params_dosen[] = $exclude_id;
            }
            
            $stmt_dosen = $db->prepare($query_dosen);
            $stmt_dosen->execute($params_dosen);
            $existing_dosen = $stmt_dosen->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($existing_dosen as $existing) {
                list($existing_start, $existing_end) = explode(' - ', $existing['waktu']);
                
                if (($waktu_mulai < $existing_end) && ($waktu_selesai > $existing_start)) {
                    $conflicts[] = "Dosen {$dosen} sudah mengajar {$existing['mata_kuliah']} di kelas {$existing['kelas']} pada hari {$hari} pukul {$existing['waktu']}";
                    break;
                }
            }
        }
        
        // Cek bentrok ruangan
        if ($ruang) {
            $query_ruang = "SELECT * FROM schedules 
                           WHERE ruang = ? 
                           AND hari = ? 
                           AND semester = ? 
                           AND tahun_akademik = ?";
            
            $params_ruang = [$ruang, $hari, $semester, $tahun_akademik];
            
            if ($exclude_id) {
                $query_ruang .= " AND id != ?";
                $params_ruang[] = $exclude_id;
            }
            
            $stmt_ruang = $db->prepare($query_ruang);
            $stmt_ruang->execute($params_ruang);
            $existing_ruang = $stmt_ruang->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($existing_ruang as $existing) {
                list($existing_start, $existing_end) = explode(' - ', $existing['waktu']);
                
                if (($waktu_mulai < $existing_end) && ($waktu_selesai > $existing_start)) {
                    $conflicts[] = "Ruangan {$ruang} sudah digunakan oleh kelas {$existing['kelas']} untuk mata kuliah {$existing['mata_kuliah']} pada hari {$hari} pukul {$existing['waktu']}";
                    break;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error checking schedule conflict: " . $e->getMessage());
        $conflicts[] = "Error checking schedule conflict";
    }
    
    return $conflicts;
}

/**
 * Format waktu untuk display
 */
function formatWaktuDisplay($waktu) {
    if (strpos($waktu, '-') !== false) {
        list($start, $end) = explode('-', $waktu);
        return trim($start) . ' - ' . trim($end);
    }
    return $waktu;
}

/**
 * Generate tahun akademik otomatis
 */
function generateTahunAkademik() {
    $current_year = date('Y');
    $next_year = $current_year + 1;
    return "{$current_year}/{$next_year}";
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Ambil semua tahun akademik dari semester_settings
 */
function getAllTahunAkademik($db) {
    try {
        $stmt = $db->prepare("SELECT DISTINCT tahun_akademik FROM semester_settings ORDER BY tahun_akademik DESC");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $result ?: [];
    } catch (Exception $e) {
        error_log("Error getting all tahun akademik: " . $e->getMessage());
        return [];
    }
}

// =======================================================
// FUNGSI SEMESTER MANAGEMENT (BARU)
// =======================================================

/**
 * Ambil semua semester yang tersedia
 */
function getAllSemesters($db) {
    try {
        $query = "SELECT * FROM semester_settings ORDER BY tahun_akademik DESC, semester DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting all semesters: " . $e->getMessage());
        return [];
    }
}

/**
 * Ambil semester aktif
 */
function getActiveSemester($db) {
    try {
        $query = "SELECT * FROM semester_settings WHERE is_active = TRUE LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result;
        } else {
            // Fallback ke setting default atau semester pertama
            $query = "SELECT * FROM semester_settings ORDER BY tahun_akademik DESC, semester DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($fallback) {
                // Set this as active
                setActiveSemester($db, $fallback['tahun_akademik'], $fallback['semester']);
                return $fallback;
            }
            
            // Create default semester if none exists
            $default_tahun = generateTahunAkademik();
            $query = "INSERT INTO semester_settings (tahun_akademik, semester, is_active) VALUES (?, ?, TRUE)";
            $stmt = $db->prepare($query);
            $stmt->execute([$default_tahun, 'GANJIL']);
            
            return [
                'tahun_akademik' => $default_tahun,
                'semester' => 'GANJIL',
                'is_active' => true
            ];
        }
    } catch (Exception $e) {
        error_log("Error getting active semester: " . $e->getMessage());
        return [
            'tahun_akademik' => generateTahunAkademik(),
            'semester' => 'GANJIL'
        ];
    }
}

/**
 * Set semester aktif
 */
function setActiveSemester($db, $tahun_akademik, $semester) {
    try {
        // Nonaktifkan semua semester
        $stmt = $db->prepare("UPDATE semester_settings SET is_active = FALSE");
        $stmt->execute();
        
        // Aktifkan semester yang dipilih
        $stmt = $db->prepare("UPDATE semester_settings SET is_active = TRUE 
                             WHERE tahun_akademik = ? AND semester = ?");
        $stmt->execute([$tahun_akademik, $semester]);
        
        // Update setting utama juga
        updateSetting($db, 'tahun_akademik', $tahun_akademik);
        updateSetting($db, 'semester_aktif', $semester);
        
        return true;
    } catch (Exception $e) {
        error_log("Error setting active semester: " . $e->getMessage());
        return false;
    }
}

/**
 * Tambah semester baru
 */
function addSemester($db, $tahun_akademik, $semester) {
    try {
        $query = "INSERT INTO semester_settings (tahun_akademik, semester) 
                  VALUES (?, ?) 
                  ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP";
        $stmt = $db->prepare($query);
        return $stmt->execute([$tahun_akademik, $semester]);
    } catch (Exception $e) {
        error_log("Error adding semester: " . $e->getMessage());
        return false;
    }
}

/**
 * Hapus semester
 */
function deleteSemester($db, $id) {
    try {
        // Cek apakah semester aktif
        $stmt = $db->prepare("SELECT is_active FROM semester_settings WHERE id = ?");
        $stmt->execute([$id]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($semester && $semester['is_active']) {
            return false; // Tidak bisa hapus semester aktif
        }
        
        // Hapus semester
        $stmt = $db->prepare("DELETE FROM semester_settings WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (Exception $e) {
        error_log("Error deleting semester: " . $e->getMessage());
        return false;
    }
}

/**
 * Cek apakah semester ada
 */
function semesterExists($db, $tahun_akademik, $semester) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM semester_settings WHERE tahun_akademik = ? AND semester = ?");
        $stmt->execute([$tahun_akademik, $semester]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log("Error checking semester existence: " . $e->getMessage());
        return false;
    }
}

/**
 * Ambil data semester berdasarkan ID
 */
function getSemesterById($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM semester_settings WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting semester by ID: " . $e->getMessage());
        return false;
    }
}
?>