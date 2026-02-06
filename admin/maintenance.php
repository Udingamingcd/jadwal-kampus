<?php
// maintenance.php - Pengaturan Mode Maintenance
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';

// Cek login dan role admin - SEMUA ADMIN BISA AKSES
if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'superadmin')) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Ambil status maintenance saat ini
$query = "SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'";
$stmt = $db->prepare($query);
$stmt->execute();
$currentStatus = $stmt->fetch(PDO::FETCH_ASSOC);

$isMaintenance = ($currentStatus && $currentStatus['setting_value'] == '1') ? true : false;

// Ambil pesan maintenance saat ini
$query = "SELECT setting_value FROM settings WHERE setting_key = 'maintenance_message'";
$stmt = $db->prepare($query);
$stmt->execute();
$messageResult = $stmt->fetch(PDO::FETCH_ASSOC);
$currentMessage = $messageResult ? $messageResult['setting_value'] : 'Sistem sedang dalam perbaikan untuk peningkatan layanan. Mohon maaf atas ketidaknyamanannya.';

// Tangani request toggle maintenance
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['toggle_maintenance'])) {
        $newStatus = $isMaintenance ? '0' : '1';
        
        // Update atau insert setting
        if($currentStatus) {
            $query = "UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = 'maintenance_mode'";
        } else {
            $query = "INSERT INTO settings (setting_key, setting_value) VALUES ('maintenance_mode', :value)";
        }
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':value', $newStatus);
        
        if($stmt->execute()) {
            // Log activity
            $action = $newStatus == '1' ? 'Aktifkan Maintenance Mode' : 'Nonaktifkan Maintenance Mode';
            $logQuery = "INSERT INTO activity_logs (user_id, action, ip_address, user_agent) 
                        VALUES (:user_id, :action, :ip, :agent)";
            $logStmt = $db->prepare($logQuery);
            $logStmt->bindParam(':user_id', $_SESSION['user_id']);
            $logStmt->bindParam(':action', $action);
            $logStmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
            $logStmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
            $logStmt->execute();
            
            $_SESSION['success_message'] = 'Status maintenance berhasil diubah!';
            header('Location: maintenance.php');
            exit();
        } else {
            $_SESSION['error_message'] = 'Gagal mengubah status maintenance.';
        }
    }
    
    // Tangani update pesan maintenance
    if(isset($_POST['update_message'])) {
        $message = $_POST['maintenance_message'] ?? '';
        
        if($messageResult) {
            $query = "UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = 'maintenance_message'";
        } else {
            $query = "INSERT INTO settings (setting_key, setting_value) VALUES ('maintenance_message', :value)";
        }
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':value', $message);
        
        if($stmt->execute()) {
            // Log activity
            $action = 'Update Maintenance Message';
            $logQuery = "INSERT INTO activity_logs (user_id, action, ip_address, user_agent) 
                        VALUES (:user_id, :action, :ip, :agent)";
            $logStmt = $db->prepare($logQuery);
            $logStmt->bindParam(':user_id', $_SESSION['user_id']);
            $logStmt->bindParam(':action', $action);
            $logStmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
            $logStmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
            $logStmt->execute();
            
            $_SESSION['success_message'] = 'Pesan maintenance berhasil diperbarui!';
            header('Location: maintenance.php');
            exit();
        } else {
            $_SESSION['error_message'] = 'Gagal memperbarui pesan maintenance.';
        }
    }
}

// Ambil log maintenance
$query = "SELECT al.*, u.username FROM activity_logs al 
          LEFT JOIN users u ON al.user_id = u.id 
          WHERE al.action LIKE '%Maintenance%' 
          ORDER BY al.created_at DESC LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute();
$maintenanceLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ambil statistik
$query = "SELECT COUNT(*) as total FROM activity_logs WHERE action LIKE '%Maintenance%'";
$stmt = $db->prepare($query);
$stmt->execute();
$maintenanceCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Maintenance - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-light: #eef2ff;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --warning-color: #f8961e;
            --info-color: #4895ef;
            --dark-color: #1a1a2e;
            --light-color: #f8f9fa;
            --gray-color: #6c757d;
            --border-radius: 16px;
            --box-shadow: 0 12px 35px rgba(0, 0, 0, 0.1);
            --transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.1);
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.3);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            padding: 20px;
        }
        
        .maintenance-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Header Styles */
        .page-header {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 35px 40px;
            margin-bottom: 40px;
            box-shadow: var(--box-shadow);
            border: 1px solid var(--glass-border);
            position: relative;
            overflow: hidden;
        }
        
        .page-header:before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 300px;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(67, 97, 238, 0.05) 100%);
            border-radius: 0 0 0 200px;
        }
        
        .page-title {
            color: var(--dark-color);
            font-weight: 800;
            margin-bottom: 12px;
            font-size: 2.2rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-subtitle {
            color: var(--gray-color);
            font-size: 1.1rem;
            max-width: 600px;
        }
        
        /* Card Styles */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            border: 1px solid var(--glass-border);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            overflow: hidden;
            height: 100%;
        }
        
        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            border-color: rgba(67, 97, 238, 0.2);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px 30px;
            border-bottom: none;
            position: relative;
            overflow: hidden;
        }
        
        .card-header:before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .card-title {
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.3rem;
            position: relative;
            z-index: 1;
        }
        
        .card-title i {
            font-size: 1.4rem;
        }
        
        /* Status Indicator */
        .status-display {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 12px 25px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1rem;
            background: var(--glass-bg);
            gap: 12px;
            transition: var(--transition);
            border: 2px solid transparent;
        }
        
        .status-badge.active {
            background: linear-gradient(135deg, rgba(247, 37, 133, 0.15), rgba(247, 37, 133, 0.05));
            color: var(--danger-color);
            box-shadow: 0 8px 25px rgba(247, 37, 133, 0.15);
            border-color: rgba(247, 37, 133, 0.2);
        }
        
        .status-badge.inactive {
            background: linear-gradient(135deg, rgba(76, 201, 240, 0.15), rgba(76, 201, 240, 0.05));
            color: var(--success-color);
            box-shadow: 0 8px 25px rgba(76, 201, 240, 0.15);
            border-color: rgba(76, 201, 240, 0.2);
        }
        
        .status-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            position: relative;
        }
        
        .status-dot.active {
            background-color: var(--danger-color);
            animation: pulse 2s infinite;
            box-shadow: 0 0 20px rgba(247, 37, 133, 0.5);
        }
        
        .status-dot.inactive {
            background-color: var(--success-color);
            box-shadow: 0 0 20px rgba(76, 201, 240, 0.5);
        }
        
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(247, 37, 133, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 15px rgba(247, 37, 133, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(247, 37, 133, 0); }
        }
        
        /* Stat Card */
        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius);
            padding: 35px 30px;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-8px) scale(1.03);
            box-shadow: 0 25px 60px rgba(67, 97, 238, 0.4);
        }
        
        .stat-card:before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 250px;
            height: 250px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }
        
        .stat-card:after {
            content: '';
            position: absolute;
            bottom: -40%;
            left: -40%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 8s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
        
        .stat-icon {
            font-size: 3.2rem;
            opacity: 0.9;
            margin-bottom: 25px;
            position: relative;
            z-index: 2;
        }
        
        .stat-number {
            font-size: 3.8rem;
            font-weight: 900;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }
        
        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 2;
        }
        
        /* Button Styles */
        .btn-toggle {
            padding: 18px 50px;
            font-size: 1.2rem;
            font-weight: 700;
            border-radius: var(--border-radius);
            border: none;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            z-index: 1;
            cursor: pointer;
            letter-spacing: 0.5px;
        }
        
        .btn-toggle:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
            z-index: -1;
        }
        
        .btn-toggle:hover:before {
            transform: translateX(0);
        }
        
        .btn-toggle:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }
        
        .btn-toggle:active {
            transform: translateY(-2px);
        }
        
        .btn-toggle-maintenance {
            background: linear-gradient(135deg, var(--danger-color), #ff4d8d);
            color: white;
            box-shadow: 0 10px 30px rgba(247, 37, 133, 0.3);
        }
        
        .btn-toggle-normal {
            background: linear-gradient(135deg, var(--success-color), #3a86ff);
            color: white;
            box-shadow: 0 10px 30px rgba(76, 201, 240, 0.3);
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 14px 35px;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: var(--transition);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.25);
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(67, 97, 238, 0.4);
            color: white;
        }
        
        /* Form Styles */
        .form-control-custom {
            border: 2px solid rgba(233, 236, 239, 0.8);
            border-radius: var(--border-radius);
            padding: 16px 20px;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--glass-bg);
            font-family: inherit;
        }
        
        .form-control-custom:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
            background: white;
        }
        
        .form-label {
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
        }
        
        /* Feature List */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }
        
        .feature-item {
            background: rgba(248, 249, 250, 0.9);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        .feature-item:hover {
            background: white;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }
        
        .feature-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        
        .feature-icon.success {
            background: linear-gradient(135deg, rgba(76, 201, 240, 0.15), rgba(76, 201, 240, 0.05));
            color: var(--success-color);
            box-shadow: 0 5px 15px rgba(76, 201, 240, 0.2);
        }
        
        .feature-icon.warning {
            background: linear-gradient(135deg, rgba(248, 150, 30, 0.15), rgba(248, 150, 30, 0.05));
            color: var(--warning-color);
            box-shadow: 0 5px 15px rgba(248, 150, 30, 0.2);
        }
        
        /* Log Item */
        .log-item {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(248, 249, 250, 0.9));
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 18px;
            border-left: 5px solid var(--primary-color);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        
        .log-item:hover {
            transform: translateX(8px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-left-color: var(--danger-color);
        }
        
        .log-item:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), transparent);
            z-index: 0;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 20px;
            position: relative;
            z-index: 1;
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .log-badge {
            padding: 8px 18px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        /* Message Preview */
        .message-preview {
            background: linear-gradient(135deg, rgba(255, 250, 240, 0.9), rgba(255, 245, 230, 0.9));
            border-radius: var(--border-radius);
            padding: 30px;
            border: 2px solid rgba(255, 216, 178, 0.8);
            margin-top: 30px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .message-preview:before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, rgba(255, 183, 77, 0.15), transparent);
            border-radius: 0 0 0 150px;
        }
        
        .preview-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .preview-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--warning-color), #ffaa33);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            box-shadow: 0 8px 25px rgba(248, 150, 30, 0.4);
        }
        
        /* Footer */
        .footer-custom {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 30px;
            margin-top: 50px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--box-shadow);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state-icon {
            font-size: 4.5rem;
            color: #e9ecef;
            margin-bottom: 25px;
        }
        
        /* Modal Custom Styles */
        .modal-custom {
            backdrop-filter: blur(20px);
        }
        
        .modal-content-custom {
            background: var(--glass-bg);
            border-radius: var(--border-radius);
            border: 1px solid var(--glass-border);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .modal-icon {
            width: 70px;
            height: 70px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin: 0 auto 20px;
        }
        
        .modal-body-custom {
            padding: 30px;
        }
        
        .modal-footer-custom {
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            padding: 25px 30px;
            background: rgba(248, 249, 250, 0.8);
        }
        
        .btn-modal-cancel {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: var(--transition);
        }
        
        .btn-modal-confirm {
            background: linear-gradient(135deg, var(--danger-color), #ff4d8d);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: var(--transition);
        }
        
        .btn-modal-normal {
            background: linear-gradient(135deg, var(--success-color), #3a86ff);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: var(--transition);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                padding: 25px;
            }
            
            .page-title {
                font-size: 1.8rem;
            }
            
            .stat-number {
                font-size: 3rem;
            }
            
            .btn-toggle {
                padding: 16px 35px;
                font-size: 1.1rem;
            }
            
            .feature-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-body-custom {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <!-- Header dengan navigasi -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
                <div class="mb-4 mb-md-0">
                    <h1 class="page-title">
                        <i class="fas fa-tools me-3"></i>Pengaturan Maintenance
                    </h1>
                    <p class="page-subtitle">
                        Kelola mode maintenance untuk sistem jadwal kuliah dengan mudah dan aman
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    <a href="dashboard.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                    <a href="../index.php" class="btn btn-outline-secondary" target="_blank">
                        <i class="fas fa-external-link-alt me-2"></i>Lihat Website
                    </a>
                </div>
            </div>
        </div>

        <?php if(isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4 glass-card" role="alert" style="border-left: 5px solid var(--success-color);">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle me-3" style="font-size: 1.8rem; color: var(--success-color);"></i>
                <div>
                    <h5 class="alert-heading mb-1 fw-bold">Berhasil!</h5>
                    <p class="mb-0"><?php echo $_SESSION['success_message']; ?></p>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4 glass-card" role="alert" style="border-left: 5px solid var(--danger-color);">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-3" style="font-size: 1.8rem; color: var(--danger-color);"></i>
                <div>
                    <h5 class="alert-heading mb-1 fw-bold">Terjadi Kesalahan!</h5>
                    <p class="mb-0"><?php echo $_SESSION['error_message']; ?></p>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Status dan Kontrol -->
        <div class="row mb-5">
            <!-- Status Sistem -->
            <div class="col-lg-8 mb-4 mb-lg-0">
                <div class="glass-card h-100">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-server"></i> Status Sistem Saat Ini
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="status-display">
                            <span class="status-badge <?php echo $isMaintenance ? 'active' : 'inactive'; ?>">
                                <span class="status-dot <?php echo $isMaintenance ? 'active' : 'inactive'; ?>"></span>
                                <?php echo $isMaintenance ? 'MAINTENANCE MODE' : 'NORMAL MODE'; ?>
                            </span>
                            <div class="flex-grow-1">
                                <h2 class="mb-2 <?php echo $isMaintenance ? 'text-danger' : 'text-success'; ?> fw-bold">
                                    <?php echo $isMaintenance ? 'Sistem Dalam Perbaikan' : 'Sistem Berjalan Normal'; ?>
                                </h2>
                                <p class="text-muted mb-0">
                                    <i class="fas fa-clock me-2"></i>
                                    Terakhir diperbarui: <?php echo date('d/m/Y H:i'); ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="text-center mt-5">
                            <button type="button" 
                                    class="btn-toggle <?php echo $isMaintenance ? 'btn-toggle-normal' : 'btn-toggle-maintenance'; ?>"
                                    data-bs-toggle="modal" 
                                    data-bs-target="#confirmationModal">
                                <i class="fas fa-power-off me-3"></i>
                                <?php echo $isMaintenance ? 'NONAKTIFKAN MAINTENANCE' : 'AKTIFKAN MAINTENANCE'; ?>
                            </button>
                            <p class="text-muted mt-4 mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Klik tombol untuk <?php echo $isMaintenance ? 'menonaktifkan' : 'mengaktifkan'; ?> mode maintenance
                            </p>
                        </div>
                        
                        <div class="feature-grid mt-5">
                            <div class="feature-item">
                                <div class="feature-icon success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="feature-text">
                                    <strong class="d-block mb-1">Fitur Normal</strong>
                                    <p class="mb-0 small text-muted">Jadwal kuliah, filter, dan info ruangan tetap berjalan</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon success">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="feature-text">
                                    <strong class="d-block mb-1">Data Terlindungi</strong>
                                    <p class="mb-0 small text-muted">Semua data tetap aman dan dapat diakses</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="feature-text">
                                    <strong class="d-block mb-1">Notifikasi</strong>
                                    <p class="mb-0 small text-muted">Modal peringatan muncul untuk semua pengunjung</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon warning">
                                    <i class="fas fa-history"></i>
                                </div>
                                <div class="feature-text">
                                    <strong class="d-block mb-1">Log Aktifitas</strong>
                                    <p class="mb-0 small text-muted">Semua perubahan dicatat secara detail</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistik dan Tips -->
            <div class="col-lg-4">
                <div class="stat-card mb-4">
                    <div class="d-flex flex-column align-items-center text-center">
                        <i class="fas fa-history stat-icon"></i>
                        <div class="stat-number"><?php echo $maintenanceCount; ?></div>
                        <div class="stat-label">Total Aktivitas Maintenance</div>
                    </div>
                </div>
                
                <div class="glass-card h-100">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-lightbulb"></i> Panduan Maintenance
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="fw-bold text-primary mb-3">Kapan menggunakan Maintenance Mode?</p>
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <span class="badge bg-light text-dark border p-3 mb-2 rounded-3">
                                <i class="fas fa-database me-2"></i> Update data besar
                            </span>
                            <span class="badge bg-light text-dark border p-3 mb-2 rounded-3">
                                <i class="fas fa-save me-2"></i> Backup database
                            </span>
                            <span class="badge bg-light text-dark border p-3 mb-2 rounded-3">
                                <i class="fas fa-bug me-2"></i> Perbaikan bug kritis
                            </span>
                            <span class="badge bg-light text-dark border p-3 mb-2 rounded-3">
                                <i class="fas fa-server me-2"></i> Migrasi server
                            </span>
                            <span class="badge bg-light text-dark border p-3 mb-2 rounded-3">
                                <i class="fas fa-flask me-2"></i> Testing fitur baru
                            </span>
                        </div>
                        <div class="mt-4 pt-4 border-top">
                            <p class="mb-3 fw-semibold text-dark">
                                <i class="fas fa-info-circle me-2 text-info"></i>
                                Tips Penting
                            </p>
                            <ul class="ps-3 mb-0 text-muted">
                                <li class="mb-2">Beritahu pengguna sebelum maintenance</li>
                                <li class="mb-2">Jadwalkan maintenance di luar jam sibuk</li>
                                <li class="mb-2">Simpan backup data terlebih dahulu</li>
                                <li>Test sistem setelah maintenance</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pesan Maintenance -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="glass-card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-comment-alt"></i> Pesan Maintenance
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="messageForm">
                            <div class="mb-4">
                                <label for="maintenance_message" class="form-label">
                                    <i class="fas fa-edit"></i> Pesan yang akan ditampilkan di modal
                                </label>
                                <textarea class="form-control form-control-custom" id="maintenance_message" 
                                          name="maintenance_message" 
                                          rows="6"
                                          placeholder="Contoh: Sistem sedang dalam perbaikan untuk peningkatan layanan. Mohon maaf atas ketidaknyamanannya. Perkiraan selesai: 2 jam..."><?php echo htmlspecialchars($currentMessage); ?></textarea>
                                <div class="form-text mt-3 d-flex align-items-center">
                                    <i class="fas fa-info-circle me-2 text-info"></i>
                                    Pesan ini akan muncul di modal maintenance ketika mode maintenance diaktifkan.
                                </div>
                            </div>
                            <button type="submit" name="update_message" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i> Simpan Perubahan Pesan
                            </button>
                        </form>
                        
                        <div class="message-preview mt-4">
                            <div class="preview-header">
                                <div class="preview-icon">
                                    <i class="fas fa-tools"></i>
                                </div>
                                <div>
                                    <h4 class="mb-2 fw-bold">Mode Maintenance</h4>
                                    <span class="badge bg-warning text-dark py-2 px-3 rounded-pill fw-semibold">Aktif</span>
                                </div>
                            </div>
                            <p class="mb-4 fs-5" id="previewMessage">
                                <?php echo htmlspecialchars($currentMessage); ?>
                            </p>
                            <div class="d-flex align-items-center text-muted">
                                <i class="fas fa-clock me-3 fs-5"></i>
                                <span class="fs-6"><?php echo date('d F Y, H:i'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Log Aktivitas Maintenance -->
        <div class="row">
            <div class="col-12">
                <div class="glass-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <h5 class="card-title">
                                <i class="fas fa-history"></i> Log Aktivitas Maintenance
                            </h5>
                            <span class="badge bg-light text-dark py-2 px-3">
                                <i class="fas fa-list me-2"></i> 10 log terbaru
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if(empty($maintenanceLogs)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h5 class="text-muted mb-2 fw-bold">Belum ada aktivitas maintenance</h5>
                            <p class="text-muted mb-0">Log akan muncul setelah Anda mengubah status maintenance</p>
                        </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach($maintenanceLogs as $log): ?>
                                <div class="col-12 mb-3">
                                    <div class="log-item">
                                        <div class="row align-items-center">
                                            <div class="col-md-8 mb-3 mb-md-0">
                                                <div class="d-flex align-items-center mb-3">
                                                    <div class="user-avatar me-3">
                                                        <?php echo strtoupper(substr($log['username'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($log['username']); ?></h6>
                                                        <small class="text-muted d-flex align-items-center">
                                                            <i class="fas fa-user-circle me-2"></i>
                                                            <?php echo strtoupper($log['role']); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <span class="log-badge <?php echo strpos($log['action'], 'Aktifkan') !== false ? 'bg-danger' : 'bg-success'; ?>">
                                                        <i class="fas fa-cog me-2"></i>
                                                        <?php echo htmlspecialchars($log['action']); ?>
                                                    </span>
                                                </div>
                                                <small class="text-muted d-flex align-items-center">
                                                    <i class="fas fa-network-wired me-2"></i>
                                                    IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                                                </small>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="text-muted text-md-end">
                                                    <div class="mb-2 d-flex align-items-center justify-content-md-end">
                                                        <i class="far fa-calendar me-2"></i>
                                                        <?php echo date('d/m/Y', strtotime($log['created_at'])); ?>
                                                    </div>
                                                    <div class="d-flex align-items-center justify-content-md-end">
                                                        <i class="far fa-clock me-2"></i>
                                                        <?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(!empty($maintenanceLogs)): ?>
                        <div class="mt-5 text-center">
                            <a href="activity_logs.php?filter=maintenance" class="btn btn-outline-primary px-4 py-3">
                                <i class="fas fa-list me-3"></i> Lihat Semua Log Maintenance
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="footer-custom mt-5">
            <div class="row align-items-center">
                <div class="col-md-6 mb-3 mb-md-0">
                    <p class="mb-0 d-flex align-items-center">
                        <i class="fas fa-info-circle text-primary me-3 fs-5"></i>
                        <span class="fs-6">
                            Mode maintenance memungkinkan perawatan sistem tanpa menghentikan akses pengunjung ke data jadwal.
                        </span>
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0 text-muted d-flex align-items-center justify-content-md-end">
                        <i class="fas fa-code me-3"></i>
                        <span class="fs-6">Sistem Jadwal Kuliah v2.0 &copy; <?php echo date('Y'); ?> - Muhammad Syaiful</span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Konfirmasi -->
    <div class="modal fade modal-custom" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <div class="w-100 text-center">
                        <div class="modal-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h3 class="modal-title text-center mb-0" id="confirmationModalLabel">
                            Konfirmasi <?php echo $isMaintenance ? 'Nonaktifkan' : 'Aktifkan'; ?> Maintenance
                        </h3>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body modal-body-custom text-center">
                    <div class="mb-4">
                        <h4 class="fw-bold text-dark mb-3">
                            Anda yakin ingin <?php echo $isMaintenance ? 'menonaktifkan' : 'mengaktifkan'; ?> maintenance mode?
                        </h4>
                        <p class="text-muted mb-4">
                            <?php if($isMaintenance): ?>
                            Sistem akan kembali ke mode normal. Pengunjung tidak akan melihat modal maintenance lagi.
                            <?php else: ?>
                            Sistem akan masuk ke mode maintenance. Pengunjung akan melihat modal peringatan di halaman utama.
                            <?php endif; ?>
                        </p>
                        
                        <div class="alert <?php echo $isMaintenance ? 'alert-success' : 'alert-warning'; ?> border-0 rounded-3 p-4">
                            <div class="d-flex align-items-center">
                                <i class="fas <?php echo $isMaintenance ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-3 fs-4"></i>
                                <div class="text-start">
                                    <strong class="d-block mb-1">Dampak yang akan terjadi:</strong>
                                    <p class="mb-0 small">
                                        <?php if($isMaintenance): ?>
                                        • Modal maintenance akan hilang<br>
                                        • Sistem kembali beroperasi normal<br>
                                        • Log aktivitas akan dicatat
                                        <?php else: ?>
                                        • Modal peringatan muncul untuk semua pengunjung<br>
                                        • Pesan maintenance akan ditampilkan<br>
                                        • Status header berubah menjadi maintenance<br>
                                        • Log aktivitas akan dicatat
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" action="" id="maintenanceForm">
                        <input type="hidden" name="toggle_maintenance" value="1">
                    </form>
                </div>
                <div class="modal-footer modal-footer-custom justify-content-center">
                    <button type="button" class="btn btn-modal-cancel me-3" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i> Batalkan
                    </button>
                    <button type="button" class="btn <?php echo $isMaintenance ? 'btn-modal-normal' : 'btn-modal-confirm'; ?>" id="confirmAction">
                        <i class="fas fa-check me-2"></i> Ya, <?php echo $isMaintenance ? 'Nonaktifkan' : 'Aktifkan'; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Update preview pesan saat diketik
            const messageTextarea = document.getElementById('maintenance_message');
            const previewMessage = document.getElementById('previewMessage');
            
            if (messageTextarea && previewMessage) {
                messageTextarea.addEventListener('input', function() {
                    previewMessage.textContent = this.value || 'Sistem sedang dalam perbaikan untuk peningkatan layanan. Mohon maaf atas ketidaknyamanannya.';
                });
            }
            
            // Konfirmasi maintenance melalui modal
            const confirmButton = document.getElementById('confirmAction');
            const maintenanceForm = document.getElementById('maintenanceForm');
            
            if (confirmButton && maintenanceForm) {
                confirmButton.addEventListener('click', function() {
                    // Tampilkan loading state
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Memproses...';
                    this.disabled = true;
                    
                    // Submit form setelah 1 detik (simulasi)
                    setTimeout(() => {
                        maintenanceForm.submit();
                    }, 1000);
                });
            }
            
            // Animasi untuk stat card
            const statCard = document.querySelector('.stat-card');
            if (statCard) {
                statCard.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.04)';
                });
                
                statCard.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(-8px) scale(1.03)';
                });
            }
            
            // Efek glass morphism untuk semua glass-card
            document.querySelectorAll('.glass-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.background = 'rgba(255, 255, 255, 0.95)';
                    this.style.backdropFilter = 'blur(25px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.background = 'var(--glass-bg)';
                    this.style.backdropFilter = 'blur(20px)';
                });
            });
            
            // Smooth scroll untuk link anchor
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
            
            // Validasi form pesan
            const messageForm = document.getElementById('messageForm');
            if (messageForm) {
                messageForm.addEventListener('submit', function(e) {
                    const message = document.getElementById('maintenance_message').value.trim();
                    if (message.length < 10) {
                        e.preventDefault();
                        alert('Pesan maintenance harus minimal 10 karakter!');
                        return false;
                    }
                    return true;
                });
            }
            
            // Animasi untuk modal saat ditampilkan
            const confirmationModal = document.getElementById('confirmationModal');
            if (confirmationModal) {
                confirmationModal.addEventListener('show.bs.modal', function () {
                    const modalContent = this.querySelector('.modal-content-custom');
                    modalContent.style.transform = 'scale(0.8)';
                    setTimeout(() => {
                        modalContent.style.transform = 'scale(1)';
                    }, 100);
                });
            }
            
            // Responsif untuk layar kecil
            function handleResponsive() {
                if (window.innerWidth < 768) {
                    document.querySelectorAll('.btn-toggle').forEach(btn => {
                        btn.style.padding = '14px 25px';
                        btn.style.fontSize = '1rem';
                    });
                }
            }
            
            window.addEventListener('resize', handleResponsive);
            handleResponsive();
        });
    </script>
</body>
</html>