<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';

// Cek apakah session expired
$session_expired = false;
$expired_username = '';
if (isset($_SESSION['session_expired'])) {
    $session_expired = true;
    $expired_username = $_SESSION['expired_username'] ?? '';
    unset($_SESSION['session_expired'], $_SESSION['expired_username']);
}

// Cek parameter expired dari URL
if (isset($_GET['expired']) && $_GET['expired'] == 1) {
    $session_expired = true;
}

// Cek apakah sudah ada superadmin
$superadmin_exists = false;
try {
    $database = new Database();
    $db_temp = $database->getConnection();
    $stmt = $db_temp->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'superadmin'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $superadmin_exists = ($result['count'] > 0);
} catch (Exception $e) {
    $superadmin_exists = false; // Default to false if error
}

$error = '';
$lockout_time = 0;
$inactive_account = false;
$lockout_username = '';
$lockout_formatted_time = '';
$stored_username = '';
$attempts_info = null;
$show_progress = false;
$multiplier = 1;

// Token untuk mencegah form resubmission
$form_token = bin2hex(random_bytes(16));
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = $form_token;
}

// Cek jika ini adalah refresh setelah POST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['post_token'])) {
    if (isset($_POST['form_token']) && $_POST['form_token'] === $_SESSION['post_token']) {
        // Token valid, proses login
    } else {
        $error = 'Refresh terdeteksi. Silakan isi form login kembali.';
        unset($_SESSION['post_token']);
        $_POST = array();
    }
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST)) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if(empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi';
        $stored_username = htmlspecialchars($username);
    } else {
        $database = new Database();
        $db = $database->getConnection();
        
        $lockout_message = getLockoutStatusMessage($db, $username);
        if ($lockout_message) {
            $lockout_username = $username;
            $lockout_formatted_time = $lockout_message;
            $error = "Akun '$username' terkunci. Silakan coba lagi dalam {$lockout_formatted_time}";
            $stored_username = htmlspecialchars($username);
            
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $attempts_info = getRemainingAttemptsInfo($db, $user['id']);
                $multiplier_info = getLockoutMultiplierInfo($db, $user['id']);
                if ($multiplier_info) {
                    $multiplier = $multiplier_info['multiplier'];
                }
            }
        } 
        else if (isAccountInactive($db, $username)) {
            $inactive_account = true;
            $_SESSION['inactive_account'] = $username;
            $error = 'Akun dinonaktifkan';
            $stored_username = htmlspecialchars($username);
        } else {
            $query = "SELECT * FROM users WHERE username = :username";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $lockout_result = checkRealTimeLockoutStatus($db, $user['id']);
                
                if ($lockout_result !== false) {
                    $lockout_time = $lockout_result;
                    $lockout_username = $username;
                    $lockout_formatted_time = formatLockoutTime($lockout_time);
                    
                    $multiplier_info = getLockoutMultiplierInfo($db, $user['id']);
                    if ($multiplier_info && $multiplier_info['multiplier'] > 1) {
                        $lockout_formatted_time .= " (Level {$multiplier_info['multiplier']})";
                        $multiplier = $multiplier_info['multiplier'];
                    }
                    
                    $error = "Akun '$username' terkunci. Silakan coba lagi dalam {$lockout_formatted_time}";
                    $attempts_info = getRemainingAttemptsInfo($db, $user['id']);
                    $stored_username = htmlspecialchars($username);
                } else {
                    if (isset($_SESSION['last_login_check']) && 
                        $_SESSION['last_login_check'] === md5($username . $password) &&
                        time() - ($_SESSION['last_login_time'] ?? 0) < 3) {
                        $error = "Refresh terdeteksi. Percobaan login tidak dihitung.";
                        $stored_username = htmlspecialchars($username);
                    } else {
                        $_SESSION['last_login_check'] = md5($username . $password);
                        $_SESSION['last_login_time'] = time();
                        
                        if(password_verify($password, $user['password'])) {
                            resetFailedAttempts($db, $user['id']);
                            resetLoginAttempts($db, $user['id']);
                            
                            $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
                            $updateStmt = $db->prepare($updateQuery);
                            $updateStmt->bindParam(':id', $user['id']);
                            $updateStmt->execute();
                            
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['CREATED'] = time();
                            $_SESSION['LAST_ACTIVITY'] = time();
                            
                            unset($_SESSION['last_login_check'], $_SESSION['last_login_time']);
                            
                            logActivity($db, $user['id'], 'Login', 'Login berhasil');
                            
                            header('Location: dashboard.php');
                            exit();
                        } else {
                            $result = handleFailedLoginModified($db, $user['id']);
                            
                            if ($result && isset($result['locked']) && $result['locked']) {
                                $lockout_time = $result['duration'];
                                $lockout_username = $username;
                                $lockout_formatted_time = formatLockoutTime($lockout_time);
                                $multiplier = $result['multiplier'] ?? 1;
                                
                                if ($multiplier == 1) {
                                    $error = "Terlalu banyak percobaan gagal. Akun '$username' terkunci selama {$lockout_formatted_time} (Lockout Pertama)";
                                } else {
                                    $error = "Terlalu banyak percobaan gagal. Akun '$username' terkunci selama {$lockout_formatted_time} (Lockout Level {$multiplier})";
                                }
                                
                                $attempts_info = getRemainingAttemptsInfo($db, $user['id']);
                                $show_progress = true;
                            } else if ($result && isset($result['is_refresh'])) {
                                $error = "Refresh terdeteksi. Percobaan login tidak dihitung.";
                                $stored_username = htmlspecialchars($username);
                            } else {
                                $attempts_left = $result['attempts_left'] ?? 0;
                                $is_warning = $result['is_warning'] ?? false;
                                $attempts_info = getRemainingAttemptsInfo($db, $user['id']);
                                $show_progress = true;
                                
                                if ($is_warning) {
                                    $error = "Password salah! <strong>Percobaan tersisa: {$attempts_left}</strong>. Akun akan terkunci setelah percobaan terakhir.";
                                } else {
                                    $error = "Password salah! Percobaan tersisa: {$attempts_left}";
                                }
                                $stored_username = htmlspecialchars($username);
                            }
                        }
                    }
                }
            } else {
                $error = 'Username tidak ditemukan';
                $stored_username = htmlspecialchars($username);
            }
        }
    }
    
    $_SESSION['post_token'] = bin2hex(random_bytes(16));
}

$is_locked_user = ($lockout_time > 0 && isset($_POST['username']) && $_POST['username'] === $lockout_username);

if (isset($_SESSION['inactive_account'])) {
    $stored_username = htmlspecialchars($_SESSION['inactive_account']);
}

$database = new Database();
$db = $database->getConnection();
$lockout_settings = getLockoutSettings($db);

// Cek gambar logo kampus dan jurusan dari folder assets/images
$assets_path = '../assets/images/';

// Logo kampus - cek berbagai format
$campus_logos = ['logo_kampus.png', 'logo_kampus.jpg', 'logo-kampus.png', 'logo-kampus.jpg', 'campus-logo.png', 'campus-logo.jpg', 'logo-universitas.png', 'logo-universitas.jpg'];
$campus_logo_path = 'logo_kampus.png';
foreach ($campus_logos as $image) {
    $path = $assets_path . $image;
    if (file_exists($path)) {
        $campus_logo_path = $path;
        break;
    }
}

// Logo jurusan - cek berbagai format
$department_logos = ['logo_jurusan.png', 'logo_jurusan.jpg', 'logo-jurusan.png', 'logo-jurusan.jpg', 'department-logo.png', 'department-logo.jpg', 'jurusan-logo.png', 'jurusan-logo.jpg'];
$department_logo_path = 'logo_jurusan.';
foreach ($department_logos as $image) {
    $path = $assets_path . $image;
    if (file_exists($path)) {
        $department_logo_path = $path;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin - Jadwal Kuliah</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated background elements */
        .bg-bubble-1, .bg-bubble-2, .bg-bubble-3 {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            z-index: 0;
        }
        
        .bg-bubble-1 {
            width: 300px;
            height: 300px;
            top: -150px;
            right: -100px;
        }
        
        .bg-bubble-2 {
            width: 200px;
            height: 200px;
            bottom: -100px;
            left: -50px;
        }
        
        .bg-bubble-3 {
            width: 150px;
            height: 150px;
            top: 50%;
            right: 10%;
        }
        
        .login-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            box-shadow: var(--glass-shadow);
            overflow: hidden;
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }
        
        .login-header {
            padding: 20px 30px 15px;
            text-align: center;
            position: relative;
            background: linear-gradient(135deg, rgba(44, 62, 80, 0.9), rgba(74, 100, 145, 0.9));
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .logo-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            gap: 15px;
        }
        
        .campus-logo, .department-logo {
            height: 50px;
            max-width: 120px;
            object-fit: contain;
            flex-shrink: 0;
            background-color: rgba(255, 255, 255, 0.9);
            padding: 5px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .header-title {
            flex-grow: 1;
            padding: 0 10px;
            min-width: 0;
        }
        
        .header-title h2 {
            color: white;
            font-weight: 700;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            font-size: 1.3rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .header-title p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.85rem;
            margin-bottom: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .admin-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255, 255, 255, 0.3);
            margin: 10px auto;
            display: block;
            background: linear-gradient(135deg, #667eea, #764ba2);
            padding: 3px;
        }
        
        .login-body {
            padding: 30px;
            background: rgba(255, 255, 255, 0.95);
        }
        
        .form-control {
            border-radius: 12px;
            padding: 14px 20px;
            border: 2px solid #e8f0fe;
            background: #f8f9fa;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        .form-control:focus {
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            transform: translateY(-1px);
        }
        
        .input-group-text {
            background: #667eea;
            border: none;
            color: white;
            border-radius: 12px 0 0 12px;
            padding: 14px 20px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 14px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(-1px);
        }
        
        .btn-login::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.5s;
        }
        
        .btn-login:hover::after {
            left: 100%;
        }
        
        .register-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            position: relative;
            padding: 2px 0;
        }
        
        .register-link:hover {
            color: #764ba2;
            text-decoration: none;
        }
        
        .register-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            transition: width 0.3s;
        }
        
        .register-link:hover::after {
            width: 100%;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .warning-alert {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .lockout-specific {
            background: linear-gradient(135deg, #ffe6e6, #ffcccc);
            border: 2px solid #ff9999;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .lockout-specific::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #dc3545, #ff6b6b);
        }
        
        .lockout-specific i {
            color: #dc3545;
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }
        
        .progress-container {
            margin: 20px 0;
            background: rgba(0,0,0,0.05);
            border-radius: 10px;
            overflow: hidden;
            height: 12px;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .progress-bar {
            height: 100%;
            transition: width 0.5s ease;
            position: relative;
            overflow: hidden;
        }
        
        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background-image: linear-gradient(
                -45deg, 
                rgba(255, 255, 255, 0.2) 25%, 
                transparent 25%, 
                transparent 50%, 
                rgba(255, 255, 255, 0.2) 50%, 
                rgba(255, 255, 255, 0.2) 75%, 
                transparent 75%, 
                transparent
            );
            background-size: 50px 50px;
            animation: move 2s linear infinite;
        }
        
        @keyframes move {
            0% { background-position: 0 0; }
            100% { background-position: 50px 50px; }
        }
        
        .progress-safe { background: linear-gradient(135deg, #28a745, #20c997); }
        .progress-caution { background: linear-gradient(135deg, #ffc107, #fd7e14); }
        .progress-warning { background: linear-gradient(135deg, #fd7e14, #e8590c); }
        .progress-danger { background: linear-gradient(135deg, #dc3545, #c82333); }
        .progress-locked { background: linear-gradient(135deg, #6c757d, #495057); }
        
        .attempts-info {
            font-size: 0.9rem;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .session-expired-alert {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #ffc107;
            color: #856404;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: center;
            position: relative;
        }
        
        .refresh-notice {
            background: linear-gradient(135deg, #e7f3ff, #d9ecff);
            border: 2px solid #b3d7ff;
            color: #0066cc;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 0.95rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        .input-locked {
            background: #fff3cd;
            border-color: #ffc107;
        }
        
        .btn-locked {
            background: linear-gradient(135deg, #6c757d, #495057);
            cursor: not-allowed;
        }
        
        .btn-locked:hover {
            transform: none;
            box-shadow: none;
        }
        
        .lockout-level {
            font-weight: bold;
            color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-block;
            margin: 5px 0;
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .text-muted {
            color: #6c757d !important;
        }
        
        /* Popup styles for inactive account */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .popup-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .popup-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .popup-icon {
            font-size: 50px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        
        .popup-title {
            color: #333;
            margin-bottom: 15px;
            font-weight: bold;
        }
        
        .popup-message {
            color: #666;
            margin-bottom: 25px;
        }
        
        .popup-timer {
            margin-top: 15px;
            font-size: 14px;
            color: #888;
        }
        
        .logo-placeholder {
            width: 120px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Responsive adjustments */
        @media (max-width: 576px) {
            .login-card {
                margin: 10px;
            }
            
            .login-header {
                padding: 15px 20px 10px;
            }
            
            .logo-container {
                gap: 10px;
            }
            
            .campus-logo, .department-logo {
                height: 40px;
                max-width: 80px;
                padding: 4px;
            }
            
            .header-title h2 {
                font-size: 1.1rem;
            }
            
            .header-title p {
                font-size: 0.75rem;
            }
            
            .admin-avatar {
                width: 60px;
                height: 60px;
            }
            
            .login-body {
                padding: 25px;
            }
            
            .logo-placeholder {
                width: 80px;
                height: 40px;
            }
            
            .logo-placeholder i {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 400px) {
            .logo-container {
                flex-direction: column;
                gap: 10px;
            }
            
            .campus-logo, .department-logo, .logo-placeholder {
                max-width: 100px;
                height: 40px;
            }
            
            .header-title {
                order: 3;
                width: 100%;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Background elements -->
    <div class="bg-bubble-1"></div>
    <div class="bg-bubble-2"></div>
    <div class="bg-bubble-3"></div>
    
    <!-- Popup for inactive account -->
    <?php if($inactive_account): ?>
    <div class="popup-overlay" id="inactivePopup">
        <div class="popup-content">
            <button class="popup-close" onclick="closeInactivePopup()">&times;</button>
            <div class="popup-icon">
                <i class="fas fa-ban"></i>
            </div>
            <h3 class="popup-title">Akun Dinonaktifkan</h3>
            <p class="popup-message">
                Akun <strong><?php echo htmlspecialchars($username); ?></strong> telah dinonaktifkan.<br>
                Silakan hubungi superadmin untuk mengaktifkan kembali akun Anda.
            </p>
            <button class="btn btn-primary" onclick="closeInactivePopup()">Tutup</button>
            <div class="popup-timer">
                Popup akan hilang dalam <span id="countdown">10</span> detik
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="login-card">
        <div class="login-header">
            <div class="logo-container">
                <?php if($campus_logo_path): ?>
                    <img src="<?php echo $campus_logo_path; ?>" alt="Logo Kampus" class="campus-logo">
                <?php else: ?>
                    <div class="logo-placeholder">
                        <i class="fas fa-university text-white" style="font-size: 2rem;"></i>
                    </div>
                <?php endif; ?>
                
                <div class="header-title">
                    <h2><i class="fas fa-calendar-alt me-2"></i> Admin Panel</h2>
                    <p>Sistem Jadwal Kuliah</p>
                </div>
                
                <?php if($department_logo_path): ?>
                    <img src="<?php echo $department_logo_path; ?>" alt="Logo Jurusan" class="department-logo">
                <?php else: ?>
                    <div class="logo-placeholder">
                        <i class="fas fa-graduation-cap text-white" style="font-size: 2rem;"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="admin-avatar d-flex align-items-center justify-content-center">
                <i class="fas fa-user-cog text-white" style="font-size: 2rem;"></i>
            </div>
            
            <p class="mt-2 mb-0"><small>Login untuk mengakses dashboard admin</small></p>
        </div>
        <div class="login-body">
            <!-- Refresh notice -->
            <?php if(isset($_SESSION['refresh_detected'])): ?>
            <div class="refresh-notice">
                <i class="fas fa-sync-alt me-2"></i> 
                Refresh halaman terdeteksi. Percobaan login tidak dihitung untuk mencegah ketidakadilan.
            </div>
            <?php unset($_SESSION['refresh_detected']); ?>
            <?php endif; ?>
            
            <!-- Session expired message -->
            <?php if($session_expired): ?>
            <div class="session-expired-alert">
                <i class="fas fa-clock me-2"></i> 
                <?php if(!empty($expired_username)): ?>
                    Sesi untuk <strong><?php echo htmlspecialchars($expired_username); ?></strong> telah habis karena tidak aktif selama 1 jam.
                <?php else: ?>
                    Sesi Anda telah habis karena tidak aktif selama 1 jam.
                <?php endif; ?>
                <br>Silakan login kembali.
            </div>
            <?php endif; ?>
            
            <?php if($is_locked_user): ?>
            <div class="lockout-specific">
                <i class="fas fa-lock"></i>
                <strong>Akun Terkunci!</strong><br>
                Akun <strong><?php echo htmlspecialchars($lockout_username); ?></strong> terkunci karena mencapai batas maksimal percobaan.<br>
                <?php if($multiplier > 1): ?>
                    <span class="lockout-level">Level Lockout: <?php echo $multiplier; ?></span><br>
                <?php endif; ?>
                Akan terbuka dalam: <strong id="countdownDisplay"><?php echo $lockout_formatted_time; ?></strong>
            </div>
            <?php endif; ?>
            
            <?php if($show_progress && $attempts_info): ?>
            <div class="mb-4">
                <div class="progress-container">
                    <?php 
                    $status = getProgressiveLockoutStatus($attempts_info);
                    $width = $attempts_info['percentage'];
                    ?>
                    <div class="progress-bar progress-<?php echo $status; ?>" 
                         style="width: <?php echo min($width, 100); ?>%"></div>
                </div>
                <div class="attempts-info">
                    <span class="attempts-text attempts-<?php echo $status; ?>">
                        <?php 
                        if ($status === 'locked') {
                            echo '<i class="fas fa-lock me-1"></i> Akun terkunci';
                        } else {
                            echo '<i class="fas fa-exclamation-triangle me-1"></i> Percobaan: '.$attempts_info['failed_attempts'].'/'.$attempts_info['max_attempts'];
                        }
                        ?>
                    </span>
                    <span class="attempts-count attempts-<?php echo $status; ?>">
                        <?php 
                        if ($status === 'locked') {
                            echo '🔒';
                        } elseif ($status === 'danger') {
                            echo '⚠️ ' . $attempts_info['attempts_left'] . ' tersisa';
                        } elseif ($status === 'warning') {
                            echo '⚠️ ' . $attempts_info['attempts_left'] . ' tersisa';
                        } else {
                            echo $attempts_info['attempts_left'] . ' percobaan tersisa';
                        }
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if($error && !$inactive_account && !$is_locked_user && !$session_expired): ?>
                <div class="alert <?php echo strpos($error, 'akan terkunci') !== false || strpos($error, 'Refresh') !== false ? 'warning-alert' : 'alert-danger'; ?> 
                     alert-dismissible fade show d-flex align-items-center" role="alert">
                    <i class="fas fa-exclamation-circle me-3" style="font-size: 1.2rem;"></i>
                    <div><?php echo $error; ?></div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="form_token" value="<?php echo $_SESSION['form_token']; ?>">
                
                <div class="mb-4">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control <?php echo $is_locked_user ? 'input-locked' : ''; ?>" 
                               id="username" name="username" required autofocus 
                               placeholder="Masukkan username"
                               value="<?php echo $stored_username; ?>"
                               onkeydown="clearLockoutStatus()">
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control <?php echo $is_locked_user ? 'input-locked' : ''; ?>" 
                               id="password" name="password" required 
                               placeholder="Masukkan password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn w-100 mb-4 <?php echo $is_locked_user ? 'btn-locked' : 'btn-login'; ?>"
                        id="loginButton" <?php echo $is_locked_user ? 'disabled' : ''; ?>>
                    <i class="fas fa-sign-in-alt me-2"></i> 
                    <?php if($is_locked_user): ?>
                        <span id="buttonText">Akun Terkunci</span>
                    <?php else: ?>
                        Login ke Dashboard
                    <?php endif; ?>
                </button>
                
                <?php if($is_locked_user): ?>
                <div class="text-center text-muted small mb-3">
                    <i class="fas fa-info-circle me-1"></i> 
                    Anda dapat mencoba akun lain
                </div>
                <?php endif; ?>
                
                <div class="text-center mt-4 pt-3 border-top">
                    <?php if(!$superadmin_exists): ?>
                    <p class="mb-2">
                        Belum ada superadmin? 
                        <a href="register_superadmin.php" class="register-link">
                            Daftar Super Admin
                        </a>
                    </p>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-shield-alt me-1"></i> 
                        Link pendaftaran hanya bisa diakses sekali
                    </p>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if(passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Popup for inactive account
        <?php if($inactive_account): ?>
        let countdown = 10;
        const countdownElement = document.getElementById('countdown');
        const popup = document.getElementById('inactivePopup');
        
        const timer = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                closeInactivePopup();
            }
        }, 1000);
        
        function closeInactivePopup() {
            if (popup) {
                popup.style.display = 'none';
            }
            document.getElementById('password').value = '';
            document.getElementById('password').focus();
        }
        
        if (popup) {
            popup.addEventListener('click', function(e) {
                if (e.target === popup) {
                    closeInactivePopup();
                }
            });
        }
        <?php endif; ?>
        
        // Clear lockout status when user changes username
        function clearLockoutStatus() {
            const usernameInput = document.getElementById('username');
            const currentUsername = usernameInput.value;
            const lockedUsername = '<?php echo $lockout_username; ?>';
            
            if (currentUsername !== lockedUsername) {
                const loginButton = document.getElementById('loginButton');
                const passwordInput = document.getElementById('password');
                
                if (loginButton.classList.contains('btn-locked')) {
                    loginButton.classList.remove('btn-locked');
                    loginButton.classList.add('btn-login');
                    loginButton.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i> Login ke Dashboard';
                    loginButton.disabled = false;
                    
                    usernameInput.classList.remove('input-locked');
                    passwordInput.classList.remove('input-locked');
                    
                    // Hide lockout message if exists
                    const lockoutMessage = document.querySelector('.lockout-specific');
                    if (lockoutMessage) {
                        lockoutMessage.style.display = 'none';
                    }
                    
                    // Hide multiplier warning if exists
                    const multiplierWarning = document.querySelector('.multiplier-warning');
                    if (multiplierWarning) {
                        multiplierWarning.style.display = 'none';
                    }
                }
            }
        }
        
        // Countdown for lockout - ONLY when account is locked
        <?php if($is_locked_user && $lockout_time > 0): ?>
        let lockoutSeconds = <?php echo $lockout_time; ?>;
        const countdownDisplay = document.getElementById('countdownDisplay');
        const loginButton = document.getElementById('loginButton');
        const buttonText = document.getElementById('buttonText');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        
        function formatTime(seconds) {
            if (seconds < 60) {
                return `${seconds} detik`;
            } else if (seconds < 3600) {
                const minutes = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return `${minutes} menit ${secs > 0 ? secs + ' detik' : ''}`;
            } else {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                return `${hours} jam ${minutes > 0 ? minutes + ' menit' : ''}`;
            }
        }
        
        function updateCountdown() {
            if (lockoutSeconds <= 0) {
                countdownDisplay.textContent = 'Akun terbuka!';
                loginButton.classList.remove('btn-locked');
                loginButton.classList.add('btn-login');
                loginButton.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i> Login ke Dashboard';
                usernameInput.classList.remove('input-locked');
                passwordInput.classList.remove('input-locked');
                loginButton.disabled = false;
                
                // Auto reload to reset form
                setTimeout(() => {
                    location.reload();
                }, 2000);
                return;
            }
            
            countdownDisplay.textContent = formatTime(lockoutSeconds);
            lockoutSeconds--;
            
            setTimeout(updateCountdown, 1000);
        }
        
        // Update countdown every second
        updateCountdown();
        
        // Prevent submit for locked account
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const currentUsername = document.getElementById('username').value;
            const lockedUsername = '<?php echo $lockout_username; ?>';
            
            if (currentUsername === lockedUsername && lockoutSeconds > 0) {
                e.preventDefault();
                alert(`Akun ${lockedUsername} masih terkunci. Silakan tunggu atau gunakan akun lain.`);
                return false;
            }
        });
        <?php endif; ?>
        
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        // Auto focus on username field if session expired
        <?php if($session_expired && empty($stored_username)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });
        <?php endif; ?>
        
        // Clear session data when leaving page
        window.addEventListener('beforeunload', function() {
            // Kirim request untuk clear session data login
            if (typeof navigator.sendBeacon === 'function') {
                navigator.sendBeacon('clear_login_session.php');
            }
        });
        
        // Add floating animation to background bubbles
        document.addEventListener('DOMContentLoaded', function() {
            const bubbles = document.querySelectorAll('.bg-bubble-1, .bg-bubble-2, .bg-bubble-3');
            
            bubbles.forEach((bubble, index) => {
                // Random initial position and animation
                const duration = 15 + Math.random() * 10;
                const delay = Math.random() * 5;
                
                bubble.style.animation = `float ${duration}s ease-in-out ${delay}s infinite alternate`;
            });
        });
        
        // Add floating animation keyframes
        const style = document.createElement('style');
        style.textContent = `
            @keyframes float {
                0% { transform: translateY(0px) translateX(0px); }
                33% { transform: translateY(-20px) translateX(10px); }
                66% { transform: translateY(10px) translateX(-10px); }
                100% { transform: translateY(-10px) translateX(5px); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>