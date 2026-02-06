<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin(); // Semua admin bisa akses, tapi dengan batasan

$database = new Database();
$db = $database->getConnection();

// Tambah admin - hanya superadmin yang bisa tambah admin baru
if(isset($_POST['add_admin'])) {
    if (!isSuperAdmin()) {
        $_SESSION['error_message'] = "Hanya superadmin yang dapat menambah admin baru.";
        header('Location: manage_users.php');
        exit();
    }
    
    $username = sanitizeInput($_POST['username']);
    $email = sanitizeInput($_POST['email']);
    $role = sanitizeInput($_POST['role']);
    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Cek apakah username sudah ada
    $check = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $check->execute([$username]);
    if($check->fetchColumn() > 0) {
        $_SESSION['error_message'] = "Username '$username' sudah ada!";
        header('Location: manage_users.php');
        exit();
    }

    // Cek email jika diisi
    if (!empty($email)) {
        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $check->execute([$email]);
        if($check->fetchColumn() > 0) {
            $_SESSION['error_message'] = "Email '$email' sudah digunakan!";
            header('Location: manage_users.php');
            exit();
        }
    }

    // Insert user baru
    $query = "INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$username, $password_hash, $email, $role]);

    logActivity($db, $_SESSION['user_id'], 'Tambah Admin', $username);
    logAdminAudit($db, $_SESSION['user_id'], $db->lastInsertId(), 'create_admin', "Created new admin: {$username}");
    
    $_SESSION['message'] = "Admin berhasil ditambahkan!";
    header('Location: manage_users.php');
    exit();
}

// Edit admin - semua admin bisa edit tapi dengan batasan
if(isset($_POST['edit_admin'])) {
    $user_id = (int)$_POST['id'];
    $current_user_role = $_SESSION['role'];
    $current_user_id = $_SESSION['user_id'];
    
    // Validasi: Admin biasa tidak boleh mengubah password admin lain
    // (Sekarang dihapus karena field password sudah dipindahkan ke halaman terpisah)
    
    // Ambil data user yang akan diedit
    $query = "SELECT role, is_active FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$user_id]);
    $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$target_user) {
        $_SESSION['error_message'] = "User tidak ditemukan";
        header('Location: manage_users.php');
        exit();
    }
    
    $error = false;
    $error_message = "";
    
    // 1. Cek apakah user mencoba mengedit diri sendiri
    if ($user_id == $_SESSION['user_id']) {
        // User bisa mengedit username dan email diri sendiri
        // Tapi tidak bisa mengubah role atau status aktif diri sendiri
        if (isset($_POST['role']) && $_POST['role'] != $target_user['role']) {
            $error = true;
            $error_message = "Tidak dapat mengubah role akun sendiri.";
        }
        
        if (isset($_POST['is_active']) != $target_user['is_active']) {
            $error = true;
            $error_message = "Tidak dapat mengubah status aktif akun sendiri.";
        }
    }
    // 2. Admin biasa mencoba mengedit superadmin
    else if ($current_user_role !== 'superadmin' && $target_user['role'] === 'superadmin') {
        // Admin biasa TIDAK BISA mengedit superadmin sama sekali
        $error = true;
        $error_message = "Admin biasa tidak dapat mengedit akun superadmin.";
    }
    // 3. Admin biasa mencoba mengubah role menjadi superadmin
    else if ($current_user_role !== 'superadmin' && isset($_POST['role']) && $_POST['role'] === 'superadmin') {
        $error = true;
        $error_message = "Admin biasa tidak dapat membuat atau mengubah akun menjadi superadmin.";
    }
    // 4. Admin biasa mencoba menonaktifkan superadmin
    else if ($current_user_role !== 'superadmin' && $target_user['role'] === 'superadmin') {
        if (!isset($_POST['is_active']) && $target_user['is_active'] == 1) {
            $error = true;
            $error_message = "Admin biasa tidak dapat menonaktifkan akun superadmin.";
        }
    }
    
    // 5. Cek apakah ini adalah akun aktif terakhir
    $query_check = "SELECT COUNT(*) as active_count FROM users WHERE is_active = TRUE AND id != ?";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->execute([$user_id]);
    $result = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($result['active_count'] == 0 && $target_user['is_active'] == 1 && !isset($_POST['is_active'])) {
        $error = true;
        $error_message = "Tidak dapat menonaktifkan akun aktif terakhir.";
    }
    
    if ($error) {
        $_SESSION['error_message'] = $error_message;
        header('Location: manage_users.php');
        exit();
    }
    
    // Lanjutkan dengan update jika tidak ada error
    $query = "UPDATE users SET username = ?, email = ?";
    $params = [sanitizeInput($_POST['username']), sanitizeInput($_POST['email'])];
    
    // Hanya superadmin atau jika bukan mengedit superadmin yang bisa ubah role
    if (isset($_POST['role']) && 
        ($current_user_role === 'superadmin' || 
         ($current_user_role !== 'superadmin' && $target_user['role'] !== 'superadmin'))) {
        $query .= ", role = ?";
        $params[] = sanitizeInput($_POST['role']);
    }
    
    // Hanya superadmin atau jika bukan mengedit superadmin yang bisa ubah status aktif
    if (isset($_POST['is_active']) && 
        ($current_user_role === 'superadmin' || 
         ($current_user_role !== 'superadmin' && $target_user['role'] !== 'superadmin'))) {
        $query .= ", is_active = ?";
        $params[] = 1;
    } else if (!isset($_POST['is_active']) && 
               ($current_user_role === 'superadmin' || 
                ($current_user_role !== 'superadmin' && $target_user['role'] !== 'superadmin'))) {
        $query .= ", is_active = ?";
        $params[] = 0;
    }
    
    $query .= " WHERE id = ?";
    $params[] = $user_id;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    // Log aktivitas
    logActivity($db, $_SESSION['user_id'], 'Edit Admin', sanitizeInput($_POST['username']));
    logAdminAudit($db, $_SESSION['user_id'], $user_id, 'edit_admin', "Updated admin info for user ID: {$user_id}");
    
    $_SESSION['message'] = "Admin berhasil diperbarui!";
    header('Location: manage_users.php');
    exit();
}

// Hapus admin - hanya superadmin yang bisa menghapus
if(isset($_GET['delete'])) {
    if (!isSuperAdmin()) {
        $_SESSION['error_message'] = "Hanya superadmin yang dapat menghapus admin.";
        header('Location: manage_users.php');
        exit();
    }
    
    $target_id = (int)$_GET['delete'];
    
    // Validasi menggunakan fungsi helper
    $validation_error = validateUserAction($db, $_SESSION['user_id'], $_SESSION['role'], $target_id, 'delete');
    
    if ($validation_error) {
        $_SESSION['error_message'] = $validation_error;
    } else {
        // Ambil username sebelum dihapus untuk logging
        $query = "SELECT username FROM users WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$target_id]);
        $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $query = "DELETE FROM users WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$target_id]);
        
        logActivity($db, $_SESSION['user_id'], 'Hapus Admin', "ID: {$target_id}");
        logAdminAudit($db, $_SESSION['user_id'], $target_id, 'delete_admin', "Deleted admin: {$target_user['username']}");
        
        $_SESSION['message'] = "Admin berhasil dihapus!";
    }
    
    header('Location: manage_users.php');
    exit();
}

// RESET LOCKOUT - hanya superadmin yang bisa
if(isset($_GET['reset_lockout'])) {
    if (!isSuperAdmin()) {
        $_SESSION['error_message'] = "Hanya superadmin yang dapat mereset lockout.";
        header('Location: manage_users.php');
        exit();
    }
    
    $target_id = (int)$_GET['reset_lockout'];
    
    // Reset lockout
    $query = "UPDATE users SET 
              failed_attempts = 0,
              locked_until = NULL,
              lockout_multiplier = 1,
              last_failed_attempt = NULL
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$target_id]);
    
    // Log aktivitas
    logActivity($db, $_SESSION['user_id'], 'Reset Lockout', "Reset lockout untuk user ID: {$target_id}");
    logAdminAudit($db, $_SESSION['user_id'], $target_id, 'reset_lockout', "Reset lockout untuk user ID: {$target_id}");
    
    $_SESSION['message'] = "Lockout berhasil direset!";
    header('Location: manage_users.php');
    exit();
}

// CANCEL LOCKOUT - menghentikan lockout yang sedang berjalan
if(isset($_GET['cancel_lockout'])) {
    if (!isSuperAdmin()) {
        $_SESSION['error_message'] = "Hanya superadmin yang dapat membatalkan lockout.";
        header('Location: manage_users.php');
        exit();
    }
    
    $target_id = (int)$_GET['cancel_lockout'];
    
    // Cancel lockout (hanya hapus locked_until, biarkan failed_attempts untuk logging)
    $query = "UPDATE users SET 
              locked_until = NULL
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$target_id]);
    
    // Log aktivitas
    logActivity($db, $_SESSION['user_id'], 'Cancel Lockout', "Membatalkan lockout untuk user ID: {$target_id}");
    logAdminAudit($db, $_SESSION['user_id'], $target_id, 'cancel_lockout', "Membatalkan lockout untuk user ID: {$target_id}");
    
    $_SESSION['message'] = "Lockout berhasil dibatalkan!";
    header('Location: manage_users.php');
    exit();
}

// Ambil semua admin dengan info proteksi
$query = "SELECT u.*, 
          (SELECT COUNT(*) FROM users WHERE is_active = TRUE AND id != u.id) as other_active_count
          FROM users u 
          ORDER BY u.role DESC, u.username ASC";
$stmt = $db->prepare($query);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Admin - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
    <style>
        /* Sidebar Styles (from dashboard.php) */
        .sidebar {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 250px;
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
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
        }
        
        /* Manage Users Specific Styles */
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
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .badge-admin {
            background-color: #6c757d;
        }
        .badge-superadmin {
            background-color: #dc3545;
        }
        .badge-locked {
            background-color: #dc3545;
        }
        .badge-failed {
            background-color: #ffc107;
            color: #000;
        }
        .protection-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            margin-left: 5px;
        }
        .protection-tooltip {
            position: relative;
            cursor: help;
        }
        .protection-tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 1000;
        }
        .checkbox-disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .select-disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: #e9ecef;
        }
        .row-protected {
            background-color: #fff8e1 !important;
        }
        .row-locked {
            background-color: #ffe6e6 !important;
        }
        .btn-add-disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .password-input-group {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            z-index: 10;
        }
        .input-disabled {
            background-color: #e9ecef;
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-eye {
            background-color: #20c997;
            border-color: #20c997;
            color: white;
        }
        .btn-eye:hover {
            background-color: #1ba87e;
            border-color: #1ba87e;
        }
        .btn-group .btn {
            margin-right: 2px;
        }
        .btn-group .btn:last-child {
            margin-right: 0;
        }
        
        /* Responsive Table */
        @media (max-width: 767.98px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            
            .table-container {
                padding: 10px;
            }
            
            .page-header {
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .page-header .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
            }
            
            .page-header .btn {
                margin-top: 10px;
                width: 100%;
            }
            
            .alert ul {
                padding-left: 20px;
                margin-bottom: 0;
            }
            
            .btn-group .btn {
                padding: 0.25rem 0.4rem;
                font-size: 0.75rem;
            }
            
            /* Mobile card styles */
            .mobile-card {
                margin-bottom: 10px;
                border-radius: 8px;
                border: 1px solid #dee2e6;
            }
            
            .mobile-card-header {
                padding: 12px 15px;
                background-color: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
            }
            
            .mobile-card-body {
                padding: 15px;
            }
            
            .mobile-card-footer {
                padding: 10px 15px;
                background-color: #f8f9fa;
                border-top: 1px solid #dee2e6;
            }
            
            /* Modal adjustments */
            .modal-dialog {
                margin: 10px;
            }
            
            .modal-content {
                border-radius: 8px;
            }
            
            .modal-body {
                padding: 15px;
            }
            
            .modal-footer {
                padding: 10px 15px;
            }
            
            /* Mobile action buttons - horizontal layout */
            .mobile-action-buttons {
                display: flex;
                justify-content: center;
                flex-wrap: nowrap;
                gap: 5px;
                overflow-x: auto;
                padding-bottom: 5px;
                -webkit-overflow-scrolling: touch;
            }
            
            .mobile-action-buttons .btn {
                flex-shrink: 0;
                min-width: 40px;
                height: 40px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0.375rem 0.5rem;
                font-size: 0.875rem;
            }
            
            /* Hide scrollbar but keep functionality */
            .mobile-action-buttons::-webkit-scrollbar {
                height: 3px;
            }
            
            .mobile-action-buttons::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 3px;
            }
            
            .mobile-action-buttons::-webkit-scrollbar-thumb {
                background: #888;
                border-radius: 3px;
            }
            
            .mobile-action-buttons::-webkit-scrollbar-thumb:hover {
                background: #555;
            }
        }

        /* Desktop table improvements */
        @media (min-width: 768px) {
            #mobileUserList {
                display: none;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
            
            #usersTableDesktop {
                width: 100%;
                min-width: 800px;
            }
            
            #usersTableDesktop th:nth-child(1) { width: 5%; } /* No */
            #usersTableDesktop th:nth-child(2) { width: 15%; } /* Username */
            #usersTableDesktop th:nth-child(3) { width: 20%; } /* Email */
            #usersTableDesktop th:nth-child(4) { width: 10%; } /* Role */
            #usersTableDesktop th:nth-child(5) { width: 15%; } /* Status & Lockout */
            #usersTableDesktop th:nth-child(6) { width: 10%; } /* Last Login */
            #usersTableDesktop th:nth-child(7) { width: 25%; } /* Actions */
        }

        /* Ensure table cells wrap text properly */
        #usersTableDesktop td {
            vertical-align: middle;
            word-break: break-word;
            max-width: 200px;
        }

        /* Mobile responsive utilities */
        .text-truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Improve mobile sidebar */
        @media (max-width: 767.98px) {
            .navbar-custom {
                padding: 10px 0;
            }
            
            .navbar-custom h4 {
                font-size: 1.1rem;
            }
            
            #mobileSidebar .nav-link {
                padding: 8px 15px;
                margin: 2px 0;
            }
            
            .user-info {
                margin-bottom: 15px !important;
            }
        }

        /* Fix for small mobile devices */
        @media (max-width: 575.98px) {
            .mobile-card-body .col-6 {
                width: 100%;
                margin-bottom: 8px;
            }
            
            .mobile-action-buttons {
                gap: 4px;
            }
            
            .mobile-action-buttons .btn {
                min-width: 36px;
                height: 36px;
                padding: 0.25rem 0.4rem;
                font-size: 0.75rem;
            }
            
            /* Compact info display */
            .mobile-card-header .d-flex {
                flex-wrap: wrap;
                row-gap: 5px;
            }
            
            .mobile-card-header .badge {
                font-size: 0.7rem;
                padding: 0.2em 0.5em;
            }
        }
        
        /* Better table row alignment */
        .table td, .table th {
            vertical-align: middle;
        }
        
        /* Improve card borders for mobile */
        .border-info {
            border-left: 4px solid #0dcaf0 !important;
        }
        
        .border-warning {
            border-left: 4px solid #ffc107 !important;
        }
        
        .border-danger {
            border-left: 4px solid #dc3545 !important;
        }
        
        /* Desktop action buttons */
        .desktop-action-buttons .btn-group {
            display: flex;
            flex-wrap: nowrap;
        }
        
        /* Lockout info styles */
        .lockout-info {
            margin-top: 5px;
            font-size: 0.8rem;
        }
        .lockout-info .badge {
            margin-right: 3px;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar (from dashboard.php) -->
        <div class="sidebar d-none d-md-block">
            <div class="p-4">
                <h3 class="mb-4"><i class="fas fa-calendar-alt"></i> Admin Panel</h3>
                <div class="user-info mb-4">
                    <div class="d-flex align-items-center">
                        <div class="user-avatar me-3">
                            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <h6 class="mb-0"><?php echo htmlspecialchars($_SESSION['username']); ?></h6>
                            <small class="text-muted"><?php echo ucfirst($_SESSION['role']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <nav class="nav flex-column">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a class="nav-link" href="manage_schedule.php">
                    <i class="fas fa-calendar"></i> Kelola Jadwal
                </a>
                <a class="nav-link" href="manage_rooms.php">
                    <i class="fas fa-door-open"></i> Kelola Ruangan
                </a>
                <a class="nav-link" href="manage_semester.php">
                    <i class="fas fa-calendar-alt"></i> Kelola Semester
                </a>
                <a class="nav-link" href="manage_settings.php">
                    <i class="fas fa-cog"></i> Pengaturan
                </a>
                <a class="nav-link active" href="manage_users.php">
                    <i class="fas fa-users"></i> Kelola Admin
                </a>
                <a class="nav-link" href="reports.php">
                    <i class="fas fa-chart-bar"></i> Laporan
                </a>
                <div class="mt-4"></div>
                <a class="nav-link" href="profile.php">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- Navbar (from dashboard.php) -->
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" 
                            data-bs-target="#mobileSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">Kelola Admin</h4>
                        <?php if(!isSuperAdmin()): ?>
                        <span class="badge bg-info ms-2">Mode Terbatas</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
                                <?php if($_SESSION['is_last_active'] ?? false): ?>
                                    <span class="badge bg-warning ms-1" title="Akun aktif terakhir">!</span>
                                <?php endif; ?>
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

            <!-- Mobile Sidebar (from dashboard.php) -->
            <div class="collapse d-md-none mb-4" id="mobileSidebar">
                <div class="card">
                    <div class="card-body">  
                        <nav class="nav flex-column">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                            <a class="nav-link" href="manage_schedule.php">
                                <i class="fas fa-calendar"></i> Kelola Jadwal
                            </a>
                            <a class="nav-link" href="manage_rooms.php">
                                <i class="fas fa-door-open"></i> Kelola Ruangan
                            </a>
                            <a class="nav-link" href="manage_semester.php">
                                <i class="fas fa-calendar-alt"></i> Kelola Semester
                            </a>
                            <a class="nav-link" href="manage_settings.php">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <a class="nav-link active" href="manage_users.php">
                                <i class="fas fa-users"></i> Kelola Admin
                            </a>
                            <a class="nav-link" href="reports.php">
                                <i class="fas fa-chart-bar"></i> Laporan
                            </a>
                            <hr>
                            <a class="nav-link" href="profile.php">
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a class="nav-link" href="logout.php">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="content-wrapper">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">Daftar Admin</h5>
                            <p class="text-muted mb-0">Kelola pengguna dengan akses admin</p>
                            <?php 
                            $active_count = countActiveUsers($db);
                            $total_count = count($users);
                            ?>
                            <small class="text-info">
                                <i class="fas fa-info-circle"></i> 
                                <?php echo $active_count; ?> akun aktif dari <?php echo $total_count; ?> total akun
                                <?php if(!isSuperAdmin()): ?>
                                    <span class="badge bg-warning ms-2">Hanya dapat melihat dan mengaktifkan akun non-aktif</span>
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php if(isSuperAdmin()): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="fas fa-plus me-2"></i>Tambah Admin
                        </button>
                        <?php else: ?>
                        <button class="btn btn-primary btn-add-disabled" disabled title="Hanya superadmin yang dapat menambah admin">
                            <i class="fas fa-plus me-2"></i>Tambah Admin
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php echo displayMessage(); ?>

                <!-- Info untuk admin biasa -->
                <?php if(!isSuperAdmin()): ?>
                <div class="alert alert-info mb-3">
                    <h6><i class="fas fa-info-circle me-2"></i>Informasi Hak Akses</h6>
                    <p class="mb-0">Sebagai <strong>Admin Biasa</strong>, Anda dapat:</p>
                    <ul class="mb-0">
                        <li>Melihat daftar semua admin</li>
                        <li>Mengaktifkan akun admin biasa yang non-aktif</li>
                        <li>Mengedit username dan email akun sendiri</li>
                        <li>Mengganti password akun sendiri</li>
                        <li><strong>Tidak dapat:</strong> mengedit superadmin, menonaktifkan superadmin, menghapus admin, menambah admin baru, atau mengganti password admin lain</li>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Data Table -->
                <div class="table-container">
                    <!-- Desktop View - Table -->
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-hover" id="usersTableDesktop">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status & Lockout</th>
                                    <th>Terakhir Login</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach($users as $user): 
                                    $is_protected = false;
                                    $protection_reason = '';
                                    $can_edit = true;
                                    $can_delete = true;
                                    $can_change_password = false;
                                    $can_view_activity = false;
                                    $is_locked = false;
                                    $lockout_info = '';
                                    
                                    // Cek apakah akun terkunci
                                    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                                        $is_locked = true;
                                        $remaining = strtotime($user['locked_until']) - time();
                                        $lockout_info = formatLockoutTime($remaining);
                                    }
                                    
                                    // Cek proteksi untuk admin biasa
                                    if (!isSuperAdmin()) {
                                        // Admin biasa tidak bisa mengedit/menghapus superadmin
                                        if ($user['role'] == 'superadmin') {
                                            $is_protected = true;
                                            $protection_reason = 'Superadmin - hanya dapat dilihat';
                                            $can_edit = false;
                                            $can_delete = false;
                                            $can_change_password = false;
                                            $can_view_activity = false;
                                        }
                                        
                                        // Admin biasa tidak bisa menghapus admin lain
                                        if ($user['id'] != $_SESSION['user_id']) {
                                            $can_delete = false;
                                        }
                                        
                                        // Admin biasa hanya bisa mengganti password sendiri
                                        if ($user['id'] == $_SESSION['user_id']) {
                                            $can_change_password = true;
                                        }
                                        
                                        // Admin biasa tidak bisa melihat aktivitas admin lain
                                        $can_view_activity = false;
                                    } else {
                                        // Superadmin dapat melakukan semua aksi
                                        $can_change_password = true;
                                        
                                        // Superadmin bisa melihat aktivitas admin lain, tapi tidak dirinya sendiri
                                        if ($user['id'] != $_SESSION['user_id']) {
                                            $can_view_activity = true;
                                        }
                                    }
                                    
                                    // Cek proteksi akun aktif terakhir
                                    if ($user['other_active_count'] == 0 && $user['is_active']) {
                                        $is_protected = true;
                                        $protection_reason = 'Akun aktif terakhir';
                                        $can_delete = false;
                                    }
                                ?>
                                <tr class="<?php echo $user['id'] == $_SESSION['user_id'] ? 'table-info' : ''; ?> <?php echo $is_protected ? 'row-protected' : ''; ?> <?php echo $is_locked ? 'row-locked' : ''; ?>">
                                    <td><?php echo $no++; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar me-2" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                            </div>
                                            <?php echo htmlspecialchars($user['username']); ?>
                                            <?php if($user['id'] == $_SESSION['user_id']): ?>
                                                <span class="badge bg-info protection-badge">Anda</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $user['role'] == 'superadmin' ? 'danger' : 'primary'; ?>">
                                            <?php echo strtoupper($user['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $user['is_active'] ? 'AKTIF' : 'NONAKTIF'; ?>
                                            </span>
                                            <?php 
                                            // Tampilkan badge proteksi jika diperlukan
                                            if ($is_protected && $user['is_active']): ?>
                                                <span class="badge bg-warning protection-badge protection-tooltip" 
                                                      data-tooltip="<?php echo $protection_reason; ?>"
                                                      data-bs-toggle="tooltip" 
                                                      title="<?php echo $protection_reason; ?>">
                                                    <i class="fas fa-shield-alt"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="lockout-info">
                                            <?php if($is_locked): ?>
                                                <span class="badge bg-danger" title="Terkunci sampai <?php echo date('d/m/Y H:i', strtotime($user['locked_until'])); ?>">
                                                    <i class="fas fa-lock"></i> Terkunci (<?php echo $lockout_info; ?>)
                                                </span>
                                            <?php elseif($user['failed_attempts'] > 0): ?>
                                                <span class="badge bg-warning" title="Percobaan gagal: <?php echo $user['failed_attempts']; ?>">
                                                    <i class="fas fa-exclamation-triangle"></i> Gagal: <?php echo $user['failed_attempts']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : '-'; ?></td>
                                    <td>
                                        <div class="desktop-action-buttons">
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                                        <?php echo !$can_edit ? 'disabled' : ''; ?>
                                                        <?php if(!$can_edit): ?>title="<?php echo $protection_reason; ?>"<?php endif; ?>>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <!-- Tombol Ganti Password -->
                                                <?php if($can_change_password): ?>
                                                    <a href="change_password.php?id=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-info" 
                                                       title="Ganti Password">
                                                        <i class="fas fa-key"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-info" disabled title="Hanya dapat mengganti password sendiri">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <!-- Tombol Lihat Aktivitas (hanya superadmin untuk admin lain) -->
                                                <?php if($can_view_activity): ?>
                                                    <a href="view_admin_activity.php?id=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-eye" 
                                                       title="Lihat Aktivitas">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-eye" disabled 
                                                            title="<?php echo $user['id'] == $_SESSION['user_id'] ? 'Lihat aktivitas Anda di dashboard' : 'Hanya superadmin yang dapat melihat aktivitas admin lain'; ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <!-- Tombol Lockout Management (hanya untuk superadmin) -->
                                                <?php if(isSuperAdmin()): ?>
                                                    <?php if($is_locked): ?>
                                                        <a href="?cancel_lockout=<?php echo $user['id']; ?>" 
                                                           class="btn btn-sm btn-warning" 
                                                           onclick="return confirm('Batalkan lockout untuk akun ini?')"
                                                           title="Batalkan Lockout">
                                                            <i class="fas fa-unlock-alt"></i>
                                                        </a>
                                                    <?php elseif($user['failed_attempts'] > 0): ?>
                                                        <a href="?reset_lockout=<?php echo $user['id']; ?>" 
                                                           class="btn btn-sm btn-info" 
                                                           onclick="return confirm('Reset lockout untuk akun ini?')"
                                                           title="Reset Lockout">
                                                            <i class="fas fa-redo"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if($can_delete && isSuperAdmin()): ?>
                                                    <a href="?delete=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Yakin hapus admin ini?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-danger" disabled title="Hanya superadmin yang dapat menghapus">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile View - Card Layout -->
                    <div class="d-block d-md-none" id="mobileUserList">
                        <?php $no = 1; ?>
                        <?php foreach($users as $user): 
                            $is_protected = false;
                            $protection_reason = '';
                            $can_edit = true;
                            $can_delete = true;
                            $can_change_password = false;
                            $can_view_activity = false;
                            $is_locked = false;
                            $lockout_info = '';
                            
                            // Cek apakah akun terkunci
                            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                                $is_locked = true;
                                $remaining = strtotime($user['locked_until']) - time();
                                $lockout_info = formatLockoutTime($remaining);
                            }
                            
                            // Cek proteksi untuk admin biasa
                            if (!isSuperAdmin()) {
                                // Admin biasa tidak bisa mengedit/menghapus superadmin
                                if ($user['role'] == 'superadmin') {
                                    $is_protected = true;
                                    $protection_reason = 'Superadmin - hanya dapat dilihat';
                                    $can_edit = false;
                                    $can_delete = false;
                                    $can_change_password = false;
                                    $can_view_activity = false;
                                }
                                
                                // Admin biasa tidak bisa menghapus admin lain
                                if ($user['id'] != $_SESSION['user_id']) {
                                    $can_delete = false;
                                }
                                
                                // Admin biasa hanya bisa mengganti password sendiri
                                if ($user['id'] == $_SESSION['user_id']) {
                                    $can_change_password = true;
                                }
                                
                                // Admin biasa tidak bisa melihat aktivitas admin lain
                                $can_view_activity = false;
                            } else {
                                // Superadmin dapat melakukan semua aksi
                                $can_change_password = true;
                                
                                // Superadmin bisa melihat aktivitas admin lain, tapi tidak dirinya sendiri
                                if ($user['id'] != $_SESSION['user_id']) {
                                    $can_view_activity = true;
                                }
                            }
                            
                            // Cek proteksi akun aktif terakhir
                            if ($user['other_active_count'] == 0 && $user['is_active']) {
                                $is_protected = true;
                                $protection_reason = 'Akun aktif terakhir';
                                $can_delete = false;
                            }
                        ?>
                        <div class="mobile-card <?php echo $user['id'] == $_SESSION['user_id'] ? 'border-info' : ''; ?> <?php echo $is_protected ? 'border-warning' : ''; ?> <?php echo $is_locked ? 'border-danger' : ''; ?>">
                            <div class="mobile-card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar me-2" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong class="d-block"><?php echo htmlspecialchars($user['username']); ?></strong>
                                            <div class="d-flex align-items-center mt-1">
                                                <?php if($user['id'] == $_SESSION['user_id']): ?>
                                                    <span class="badge bg-info me-1">Anda</span>
                                                <?php endif; ?>
                                                <?php if($is_protected && $user['is_active']): ?>
                                                    <span class="badge bg-warning me-1" title="<?php echo $protection_reason; ?>">
                                                        <i class="fas fa-shield-alt me-1"></i>Lindungi
                                                    </span>
                                                <?php endif; ?>
                                                <?php if($is_locked): ?>
                                                    <span class="badge bg-danger me-1">
                                                        <i class="fas fa-lock me-1"></i>Terkunci
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php echo $user['role'] == 'superadmin' ? 'danger' : 'primary'; ?>">
                                            <?php echo strtoupper($user['role']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="mobile-card-body">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <small class="text-muted d-block">Email</small>
                                        <div class="fw-medium text-truncate"><?php echo htmlspecialchars($user['email'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Status</small>
                                        <div>
                                            <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $user['is_active'] ? 'AKTIF' : 'NONAKTIF'; ?>
                                            </span>
                                            <?php if($user['failed_attempts'] > 0): ?>
                                                <span class="badge bg-warning" title="Percobaan gagal: <?php echo $user['failed_attempts']; ?>">
                                                    Gagal: <?php echo $user['failed_attempts']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Terakhir Login</small>
                                        <div class="small"><?php echo $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : '-'; ?></div>
                                    </div>
                                    <?php if($is_locked): ?>
                                    <div class="col-12">
                                        <small class="text-muted d-block">Status Lockout</small>
                                        <div class="small text-danger">
                                            <i class="fas fa-lock"></i> Terkunci: <?php echo $lockout_info; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mobile-card-footer">
                                <div class="mobile-action-buttons">
                                    <!-- Tombol Edit -->
                                    <button class="btn btn-sm btn-warning" 
                                            onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                            <?php echo !$can_edit ? 'disabled' : ''; ?>
                                            <?php if(!$can_edit): ?>title="<?php echo $protection_reason; ?>"<?php endif; ?>>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <!-- Tombol Ganti Password -->
                                    <?php if($can_change_password): ?>
                                        <a href="change_password.php?id=<?php echo $user['id']; ?>" 
                                           class="btn btn-sm btn-info" 
                                           title="Ganti Password">
                                            <i class="fas fa-key"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-info" disabled title="Hanya dapat mengganti password sendiri">
                                            <i class="fas fa-key"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <!-- Tombol Lihat Aktivitas -->
                                    <?php if($can_view_activity): ?>
                                        <a href="view_admin_activity.php?id=<?php echo $user['id']; ?>" 
                                           class="btn btn-sm btn-eye" 
                                           title="Lihat Aktivitas">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-eye" disabled 
                                                title="<?php echo $user['id'] == $_SESSION['user_id'] ? 'Lihat aktivitas Anda di dashboard' : 'Hanya superadmin yang dapat melihat aktivitas admin lain'; ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <!-- Tombol Lockout Management -->
                                    <?php if(isSuperAdmin()): ?>
                                        <?php if($is_locked): ?>
                                            <a href="?cancel_lockout=<?php echo $user['id']; ?>" 
                                               class="btn btn-sm btn-warning" 
                                               onclick="return confirm('Batalkan lockout untuk akun ini?')"
                                               title="Batalkan Lockout">
                                                <i class="fas fa-unlock-alt"></i>
                                            </a>
                                        <?php elseif($user['failed_attempts'] > 0): ?>
                                            <a href="?reset_lockout=<?php echo $user['id']; ?>" 
                                               class="btn btn-sm btn-info" 
                                               onclick="return confirm('Reset lockout untuk akun ini?')"
                                               title="Reset Lockout">
                                                <i class="fas fa-redo"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Tombol Hapus -->
                                    <?php if($can_delete && isSuperAdmin()): ?>
                                        <a href="?delete=<?php echo $user['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Yakin hapus admin ini?')"
                                           title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-danger" disabled title="Hanya superadmin yang dapat menghapus">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Tambah (hanya untuk superadmin) -->
    <?php if(isSuperAdmin()): ?>
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Admin Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Password</label>
                            <div class="password-input-group">
                                <input type="password" name="password" id="add_password" class="form-control" required minlength="6">
                                <button type="button" class="password-toggle" id="toggle_add_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted">Minimal 6 karakter</small>
                        </div>
                        <div class="mb-3">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label>Role</label>
                            <select name="role" class="form-control" required>
                                <option value="admin">Admin</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_admin" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Edit -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Admin</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        
                        <!-- Role selection (hanya untuk superadmin atau jika bukan superadmin) -->
                        <div class="mb-3">
                            <label>Role</label>
                            <select name="role" id="edit_role" class="form-control" required>
                                <option value="admin">Admin</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                        </div>
                        
                        <!-- Status aktif -->
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input">
                            <label class="form-check-label" for="edit_is_active">Aktif</label>
                        </div>
                        
                        <!-- Info proteksi -->
                        <div id="protection_info" class="alert alert-info d-none">
                            <small>
                                <i class="fas fa-info-circle"></i> 
                                <span id="protection_message"></span>
                            </small>
                        </div>
                        
                        <!-- Warning untuk akun aktif terakhir -->
                        <div id="last_active_warning" class="alert alert-warning d-none">
                            <small>
                                <i class="fas fa-exclamation-triangle"></i> 
                                <span id="last_active_message"></span>
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit_admin" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Only initialize DataTables on desktop
            if ($(window).width() >= 768) {
                $('#usersTableDesktop').DataTable({
                    "language": {
                        "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                    },
                    "pageLength": 10,
                    "responsive": false, // We handle responsive ourselves
                    "autoWidth": false,
                    "columnDefs": [
                        { "orderable": false, "targets": [6] } // Disable sorting on actions column
                    ]
                });
            }
            
            // Inisialisasi tooltip
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
            
            // Toggle password visibility untuk modal tambah
            $('#toggle_add_password').click(function() {
                const passwordInput = $('#add_password');
                const icon = $(this).find('i');
                
                if (passwordInput.attr('type') === 'password') {
                    passwordInput.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordInput.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Handle window resize
            $(window).resize(function() {
                // Hide any open modals on mobile to prevent overflow
                if ($(window).width() < 768) {
                    $('.modal').modal('hide');
                }
            });
            
            // Adjust modal for mobile
            if ($(window).width() < 768) {
                $('.modal').on('show.bs.modal', function () {
                    $('.modal-dialog').css({
                        'margin': '10px',
                        'max-width': 'calc(100% - 20px)'
                    });
                });
            }
            
            // Add tooltip to mobile action buttons
            $('.mobile-action-buttons .btn').each(function() {
                if (!$(this).prop('disabled')) {
                    var title = $(this).attr('title');
                    if (title) {
                        $(this).attr('data-bs-toggle', 'tooltip');
                        new bootstrap.Tooltip(this);
                    }
                }
            });
        });
        
        function editUser(user) {
            $('#edit_id').val(user.id);
            $('#edit_username').val(user.username);
            $('#edit_email').val(user.email || '');
            $('#edit_role').val(user.role);
            $('#edit_is_active').prop('checked', user.is_active == 1);
            
            // Proteksi logika
            const protectionInfo = $('#protection_info');
            const protectionMessage = $('#protection_message');
            const lastActiveWarning = $('#last_active_warning');
            const lastActiveMessage = $('#last_active_message');
            const isActiveCheckbox = $('#edit_is_active');
            const roleSelect = $('#edit_role');
            const currentUserRole = '<?php echo $_SESSION['role']; ?>';
            const currentUserId = <?php echo $_SESSION['user_id']; ?>;
            const isSuperAdmin = currentUserRole === 'superadmin';
            
            // Reset semua proteksi
            protectionInfo.addClass('d-none');
            lastActiveWarning.addClass('d-none');
            isActiveCheckbox.prop('disabled', false).removeClass('checkbox-disabled');
            roleSelect.prop('disabled', false).removeClass('select-disabled');
            
            // Reset semua input
            $('#edit_username').prop('disabled', false);
            $('#edit_email').prop('disabled', false);
            
            // 1. Jika ini adalah akun SUPERADMIN dan user yang login bukan superadmin
            if (user.role === 'superadmin' && !isSuperAdmin) {
                // Admin biasa tidak bisa mengedit superadmin sama sekali
                protectionInfo.removeClass('d-none');
                protectionMessage.text('Admin biasa tidak dapat mengedit akun superadmin.');
                
                // Nonaktifkan semua input
                $('#edit_username').prop('disabled', true);
                $('#edit_email').prop('disabled', true);
                roleSelect.prop('disabled', true).addClass('select-disabled');
                isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                
                // Jika superadmin non-aktif, admin biasa bisa mengaktifkan
                if (user.is_active == 0) {
                    protectionMessage.text('Admin biasa dapat mengaktifkan akun superadmin yang non-aktif.');
                    isActiveCheckbox.prop('disabled', false).removeClass('checkbox-disabled');
                    isActiveCheckbox.prop('checked', true);
                }
            }
            
            // 2. Jika ini adalah akun aktif terakhir
            if (user.other_active_count == 0 && user.is_active == 1) {
                isActiveCheckbox.prop('checked', true);
                isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                lastActiveWarning.removeClass('d-none');
                lastActiveMessage.text('PERINGATAN: Ini adalah akun aktif terakhir. Tidak dapat dinonaktifkan.');
            }
            
            // 3. Jika user mencoba mengedit akun sendiri
            if (user.id == currentUserId) {
                // User tidak bisa mengubah role sendiri
                roleSelect.prop('disabled', true).addClass('select-disabled');
                
                // User tidak bisa menonaktifkan diri sendiri
                isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                
                if (<?php echo $_SESSION['is_last_active'] ? 'true' : 'false'; ?>) {
                    isActiveCheckbox.prop('checked', true);
                    lastActiveWarning.removeClass('d-none');
                    lastActiveMessage.text('PERINGATAN: Anda adalah akun aktif terakhir. Tidak dapat dinonaktifkan.');
                } else {
                    protectionInfo.removeClass('d-none');
                    protectionMessage.text('Anda hanya dapat mengubah username dan email akun sendiri.');
                }
            }
            
            // 4. Validasi tambahan untuk admin biasa
            if (!isSuperAdmin) {
                // Admin biasa tidak bisa mengubah role menjadi superadmin
                roleSelect.find('option[value="superadmin"]').prop('disabled', true);
                
                // Admin biasa hanya bisa mengaktifkan akun yang non-aktif (kecuali superadmin)
                if (user.role !== 'superadmin' && user.is_active == 0) {
                    // Bisa mengaktifkan
                    isActiveCheckbox.prop('disabled', false).removeClass('checkbox-disabled');
                    isActiveCheckbox.prop('checked', true);
                    protectionInfo.removeClass('d-none');
                    protectionMessage.text('Anda dapat mengaktifkan akun admin ini.');
                } else if (user.role !== 'superadmin' && user.is_active == 1) {
                    // Tidak bisa menonaktifkan
                    isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                    protectionInfo.removeClass('d-none');
                    protectionMessage.text('Admin biasa tidak dapat menonaktifkan admin lain.');
                }
            }
            
            $('#editModal').modal('show');
        }
        
        // Validasi form sebelum submit
        $('#editModal form').submit(function(e) {
            const userId = $('#edit_id').val();
            const userRole = $('#edit_role').val();
            const isActive = $('#edit_is_active').prop('checked');
            const currentUserRole = '<?php echo $_SESSION['role']; ?>';
            const currentUserId = <?php echo $_SESSION['user_id']; ?>;
            const isSuperAdmin = currentUserRole === 'superadmin';
            
            // Validasi: Admin biasa tidak bisa mengubah role menjadi superadmin
            if (!isSuperAdmin && userRole === 'superadmin') {
                e.preventDefault();
                alert('Error: Admin biasa tidak dapat membuat atau mengubah akun menjadi superadmin.');
                return false;
            }
            
            // Validasi: User tidak bisa menonaktifkan diri sendiri
            if (userId == currentUserId && !isActive) {
                e.preventDefault();
                alert('Error: Tidak dapat menonaktifkan akun sendiri.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>