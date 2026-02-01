<?php
// Cek status session sebelum memulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Jika tidak ada session login → paksa login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Koneksi database (diperlukan untuk verifikasi user)
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $_SESSION['error'] = "Koneksi database gagal.";
    header("Location: login.php");
    exit();
}

// Ambil user dari database
$query = "SELECT id, role, is_active FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Jika user tidak ditemukan atau dihapus oleh superadmin
if (!$user) {
    $_SESSION['error'] = "Akun telah dihapus. Silakan login kembali.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// Jika user di-nonaktifkan
if (!$user['is_active']) {
    $_SESSION['error'] = "Akun Anda dinonaktifkan.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// =======================================================
// CEK APAKAH ADA AKUN AKTIF MINIMAL SATU
// =======================================================

/**
 * Fungsi untuk cek apakah ini adalah akun aktif terakhir
 */
function isLastActiveAccount($db, $user_id) {
    try {
        // Hitung jumlah akun aktif
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = TRUE");
        $stmt->execute();
        $active_count = $stmt->fetchColumn();
        
        // Jika hanya ada 1 akun aktif, cek apakah ini akun tersebut
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

// Simpan status ke session untuk digunakan di halaman lain
$_SESSION['is_last_active'] = isLastActiveAccount($db, $_SESSION['user_id']);

// =======================================================
// CEK AKSES HALAMAN BERDASARKAN ROLE
// =======================================================

$current_page = basename($_SERVER['PHP_SELF']);

// Daftar halaman yang hanya boleh diakses superadmin
$superadmin_only_pages = ['clear_logs.php']; // Hanya Clear Logs yang khusus superadmin

// Cek jika halaman saat ini adalah halaman khusus superadmin
if (in_array($current_page, $superadmin_only_pages) && $_SESSION['role'] !== 'superadmin') {
    $_SESSION['error_message'] = "Akses ditolak. Hanya superadmin yang dapat mengakses halaman ini.";
    header("Location: dashboard.php");
    exit();
}
?>