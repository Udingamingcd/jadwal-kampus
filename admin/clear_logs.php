<?php
// clear_logs.php - Hanya untuk superadmin
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireSuperAdmin(); // Hanya superadmin yang bisa akses

$database = new Database();
$db = $database->getConnection();

if(isset($_POST['clear_logs'])) {
    // Verifikasi password superadmin
    if(empty($_POST['confirm_password'])) {
        $_SESSION['error_message'] = "Password konfirmasi harus diisi!";
        header('Location: clear_logs.php');
        exit();
    }
    
    // Ambil password hash user
    $query = "SELECT password FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$user || !password_verify($_POST['confirm_password'], $user['password'])) {
        $_SESSION['error_message'] = "Password salah!";
        header('Location: clear_logs.php');
        exit();
    }
    
    // Hapus semua log aktivitas
    $query = "DELETE FROM activity_logs";
    $stmt = $db->prepare($query);
    $success = $stmt->execute();
    
    if($success) {
        // Log activity sebelum dihapus (log terakhir)
        $logQuery = "INSERT INTO activity_logs (user_id, action, ip_address, user_agent) 
                    VALUES (:user_id, :action, :ip, :agent)";
        $logStmt = $db->prepare($logQuery);
        $logStmt->bindParam(':user_id', $_SESSION['user_id']);
        $action = 'Clear All Logs';
        $logStmt->bindParam(':action', $action);
        $logStmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $logStmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $logStmt->execute();
        
        $_SESSION['message'] = "Semua log aktivitas berhasil dihapus!";
    } else {
        $_SESSION['error_message'] = "Gagal menghapus log aktivitas.";
    }
    
    header('Location: clear_logs.php');
    exit();
}

// Hitung total log
$query = "SELECT COUNT(*) as total FROM activity_logs";
$stmt = $db->prepare($query);
$stmt->execute();
$total_logs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Ambil 5 log terbaru untuk preview
$query = "SELECT al.*, u.username, u.role FROM activity_logs al 
          LEFT JOIN users u ON al.user_id = u.id 
          ORDER BY al.created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hapus Log Aktivitas - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .sidebar {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 250px;
            z-index: 1000;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 0;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 5px 10px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                min-height: auto;
                display: none;
            }
            .sidebar.mobile-show {
                display: block;
            }
            .main-content {
                margin-left: 0;
            }
            .mobile-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
        }
        .content-wrapper {
            padding-top: 20px;
        }
        .page-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .log-preview {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .log-item {
            border-left: 4px solid #dc3545;
            padding: 10px 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        .badge-superadmin {
            background-color: #dc3545;
        }
        .badge-admin {
            background-color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="d-flex">

        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- Navbar -->
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="navbar-toggler d-md-none" type="button" onclick="toggleMobileSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">Hapus Log Aktivitas</h4>
                        <span class="badge bg-danger ms-2">Superadmin Only</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
                                <span class="badge bg-danger ms-1">Superadmin</span>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="profile.php">
                                    <i class="fas fa-user me-2"></i>Profile
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Content -->
            <div class="content-wrapper">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">Hapus Log Aktivitas</h5>
                            <p class="text-muted mb-0">Hapus semua catatan aktivitas sistem</p>
                            <small class="text-danger">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Halaman ini hanya dapat diakses oleh Superadmin
                            </small>
                        </div>
                        <a href="manage_settings.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Kembali ke Pengaturan
                        </a>
                    </div>
                </div>

                <?php if(isset($_SESSION['message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                        <?php echo $_SESSION['message']; ?>
                        <?php unset($_SESSION['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                        <?php echo $_SESSION['error_message']; ?>
                        <?php unset($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Log Preview -->
                <div class="log-preview mb-4">
                    <h5><i class="fas fa-history me-2"></i> Preview Log Aktivitas</h5>
                    <p class="text-muted mb-3">
                        Total log yang akan dihapus: <strong><?php echo $total_logs; ?> entri</strong>
                    </p>
                    
                    <?php if($total_logs > 0): ?>
                        <div class="mb-3">
                            <h6>5 Log Terbaru:</h6>
                            <?php foreach($recent_logs as $log): ?>
                            <div class="log-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong><?php echo htmlspecialchars($log['action']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($log['username']); ?>
                                            <span class="badge <?php echo $log['role'] == 'superadmin' ? 'badge-superadmin' : 'badge-admin'; ?> ms-1">
                                                <?php echo strtoupper($log['role']); ?>
                                            </span>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-network-wired me-1"></i>
                                            <?php echo htmlspecialchars($log['ip_address']); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Tidak ada log aktivitas yang tersimpan.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Danger Zone -->
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Peringatan Tinggi!</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-circle me-2"></i> PERINGATAN TINGGI!</h6>
                            <p class="mb-3">Tindakan ini akan menghapus <strong>SELURUH LOG AKTIVITAS</strong> dari sistem.</p>
                            <ul class="mb-3">
                                <li>Semua catatan aktivitas admin akan hilang permanen</li>
                                <li>Tidak dapat dikembalikan (irreversible)</li>
                                <li>Total data yang akan dihapus: <strong><?php echo $total_logs; ?> entri log</strong></li>
                                <li>Hanya superadmin yang dapat melakukan aksi ini</li>
                            </ul>
                            <p class="mb-0 fw-bold">Log aktivitas penting untuk audit dan keamanan sistem.</p>
                        </div>
                        
                        <form method="POST">
                            <div class="mb-4">
                                <label for="confirm_password" class="form-label fw-bold">
                                    <i class="fas fa-key me-2"></i>Konfirmasi Password Superadmin
                                </label>
                                <input type="password" class="form-control form-control-lg" id="confirm_password" 
                                       name="confirm_password" required 
                                       placeholder="Masukkan password superadmin Anda untuk konfirmasi">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Masukkan password akun superadmin Anda untuk mengonfirmasi penghapusan semua log
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="manage_settings.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Kembali
                                </a>
                                <button type="submit" name="clear_logs" class="btn btn-danger btn-lg" 
                                        onclick="return confirmDelete()">
                                    <i class="fas fa-trash-alt me-2"></i> Ya, Hapus Semua Log
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete() {
            const password = document.getElementById('confirm_password').value;
            if (!password) {
                alert('Silakan masukkan password untuk konfirmasi!');
                document.getElementById('confirm_password').focus();
                return false;
            }
            
            return confirm('APAKAH ANDA YAKIN?\n\nTindakan ini akan menghapus SEMUA LOG AKTIVITAS (' + <?php echo $total_logs; ?> + ' entri).\nTindakan ini TIDAK DAPAT DIBATALKAN!');
        }
        
        function toggleMobileSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.mobile-overlay');
            
            if (sidebar.classList.contains('mobile-show')) {
                sidebar.classList.remove('mobile-show');
                if (overlay) overlay.remove();
            } else {
                sidebar.classList.add('mobile-show');
                // Tambah overlay
                if (!overlay) {
                    const overlayDiv = document.createElement('div');
                    overlayDiv.className = 'mobile-overlay';
                    overlayDiv.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0,0,0,0.5);
                        z-index: 999;
                    `;
                    overlayDiv.onclick = toggleMobileSidebar;
                    document.body.appendChild(overlayDiv);
                }
            }
        }
    </script>
</body>
</html>