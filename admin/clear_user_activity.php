<?php
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once 'check_auth.php';
requireSuperadmin(); // Hanya superadmin yang bisa akses

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

// Validasi CSRF token
if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid']);
    exit();
}

// Validasi input
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID user tidak valid']);
    exit();
}

// Ambil data user untuk logging
$user_query = "SELECT username FROM users WHERE id = ?";
$user_stmt = $db->prepare($user_query);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
    exit();
}

try {
    // Hitung jumlah log sebelum dihapus
    $count_query = "SELECT COUNT(*) FROM activity_logs WHERE user_id = ?";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute([$user_id]);
    $total_logs = $count_stmt->fetchColumn();
    
    // Log sebelum menghapus (untuk audit)
    logAdminAudit($db, $_SESSION['user_id'], $user_id, 'clear_activity_logs', 
                 "Cleared {$total_logs} activity logs for user: {$user['username']}");
    
    // Hapus semua log aktivitas user
    $stmt = $db->prepare("DELETE FROM activity_logs WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    $deleted_count = $stmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'message' => "Berhasil menghapus {$deleted_count} log aktivitas",
        'deleted_count' => $deleted_count
    ]);
    
} catch (Exception $e) {
    error_log("Clear user activity error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
}
?>