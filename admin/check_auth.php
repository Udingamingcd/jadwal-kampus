<?php
// =======================================================
// CEK SESSION & LOGIN DENGAN PENCEGAHAN BYPASS
// =======================================================

require_once __DIR__ . '/../config/helpers.php';

// Panggil fungsi validasi session
validateSession();

// Cegah akses langsung tanpa login
preventDirectAccess();

// Jika tidak ada session login → paksa login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// =======================================================
// KONEKSI DATABASE
// =======================================================
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $_SESSION['error'] = "Koneksi database gagal.";
    header("Location: login.php");
    exit();
}

// =======================================================
// AMBIL DATA USER LOGIN DAN CEK STATUS
// =======================================================
$query = "SELECT id, username, role, is_active, locked_until FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Jika user tidak ditemukan atau dihapus
if (!$user) {
    $_SESSION['error'] = "Akun telah dihapus. Silakan login kembali.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// Jika user dinonaktifkan
if (!$user['is_active']) {
    $_SESSION['error'] = "Akun Anda dinonaktifkan.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// Jika akun terkunci
$lockout_result = checkAccountLockout($db, $user);
if ($lockout_result !== false) {
    $_SESSION['error'] = "Akun Anda terkunci. Silakan hubungi superadmin.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// =======================================================
// FUNGSI CEK AKUN AKTIF TERAKHIR
// =======================================================

function isLastActiveAccount($db, $user_id) {
    try {
        // Hitung jumlah akun aktif
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = TRUE");
        $stmt->execute();
        $active_count = $stmt->fetchColumn();
        
        // Jika hanya ada 1 akun aktif, cek apakah user ini
        if ($active_count == 1) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = TRUE AND id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() == 1;
        }

        return false;

    } catch (Exception $e) {
        error_log("Error checking last active account: " . $e->getMessage());
        return false;
    }
}

// Simpan status ke session
$_SESSION['is_last_active'] = isLastActiveAccount($db, $_SESSION['user_id']);

// =======================================================
// CEK AKSES BERDASARKAN ROLE & HALAMAN
// =======================================================

$current_page = basename($_SERVER['PHP_SELF']);

// Halaman khusus superadmin
$superadmin_only_pages = [
    'clear_logs.php',
    'view_admin_activity.php',
    'clear_user_activity.php',
    'export_activity.php',
    'print_activity.php'
];

// Jika halaman khusus superadmin, tapi role bukan superadmin → tolak
if (in_array($current_page, $superadmin_only_pages) && $_SESSION['role'] !== 'superadmin') {
    $_SESSION['error_message'] = "Akses ditolak. Hanya superadmin yang dapat mengakses halaman ini.";
    header("Location: dashboard.php");
    exit();
}
?>