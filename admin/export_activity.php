<?php
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once 'check_auth.php';
requireSuperadmin(); // Hanya superadmin yang bisa akses

$database = new Database();
$db = $database->getConnection();

// Validasi parameter
$admin_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$format = isset($_GET['format']) ? sanitizeInput($_GET['format']) : 'csv';
$date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : null;
$date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : null;

// Ambil data admin
$admin_query = "SELECT username, email, role FROM users WHERE id = ?";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->execute([$admin_id]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    $_SESSION['error_message'] = "Admin tidak ditemukan!";
    header('Location: manage_users.php');
    exit();
}

// Build query untuk mengambil log
$query = "SELECT * FROM activity_logs WHERE user_id = ?";
$params = [$admin_id];

if ($date_from) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log export activity
logAdminAudit($db, $_SESSION['user_id'], $admin_id, 'export_activity', 
             "Exported activity logs in {$format} format for user: {$admin['username']}");

// Export berdasarkan format
switch ($format) {
    case 'csv':
        exportToCSV($logs, $admin);
        break;
        
    case 'json':
        exportToJSON($logs, $admin);
        break;
        
    case 'pdf':
        exportToPDF($logs, $admin);
        break;
        
    default:
        exportToCSV($logs, $admin);
}

function exportToCSV($data, $admin_info) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=activity_logs_' . $admin_info['username'] . '_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Header CSV
    fputcsv($output, [
        'ID Log', 
        'Tanggal', 
        'Waktu', 
        'Aksi', 
        'Deskripsi', 
        'IP Address', 
        'User Agent',
        'ID Admin',
        'Username Admin',
        'Role Admin'
    ]);
    
    // Data
    foreach ($data as $row) {
        fputcsv($output, [
            $row['id'],
            date('Y-m-d', strtotime($row['created_at'])),
            date('H:i:s', strtotime($row['created_at'])),
            $row['action'],
            $row['description'],
            $row['ip_address'],
            $row['user_agent'],
            $GLOBALS['admin_id'],
            $admin_info['username'],
            $admin_info['role']
        ]);
    }
    
    fclose($output);
    exit();
}

function exportToJSON($data, $admin_info) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=activity_logs_' . $admin_info['username'] . '_' . date('Y-m-d') . '.json');
    
    $export_data = prepareActivityDataForExport($data, array_merge($admin_info, ['id' => $GLOBALS['admin_id']]));
    
    echo json_encode($export_data, JSON_PRETTY_PRINT);
    exit();
}

function exportToPDF($data, $admin_info) {
    // Redirect ke halaman print
    $query_string = 'id=' . $GLOBALS['admin_id'];
    if ($GLOBALS['date_from']) $query_string .= '&date_from=' . $GLOBALS['date_from'];
    if ($GLOBALS['date_to']) $query_string .= '&date_to=' . $GLOBALS['date_to'];
    
    header('Location: print_activity.php?' . $query_string);
    exit();
}
?>