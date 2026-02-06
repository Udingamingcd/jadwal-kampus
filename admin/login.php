<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';

$error = '';
$lockout_time = 0;
$inactive_account = false;
$lockout_username = '';
$lockout_formatted_time = '';
$stored_username = '';
$attempts_info = null;
$show_progress = false;

$database = new Database();
$db = $database->getConnection();

// PERBAIKAN: Reset semua lockout yang sudah expired sebelum proses login
cleanupExpiredLockouts($db);

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Simpan username untuk ditampilkan kembali
    $stored_username = htmlspecialchars($username);
    
    if(empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi';
        $stored_username = htmlspecialchars($username);
    } else {
        // Check if account is inactive
        if (isAccountInactive($db, $username)) {
            $inactive_account = true;
            $_SESSION['inactive_account'] = $username;
            $error = 'Akun dinonaktifkan';
        } else {
            $query = "SELECT * FROM users WHERE username = :username";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // PERBAIKAN: Cek status lockout real-time dengan reset otomatis
                $lockout_result = checkRealTimeLockoutStatus($db, $user['id']);
                
                if ($lockout_result !== false) {
                    $lockout_time = $lockout_result;
                    $lockout_username = $username;
                    $lockout_formatted_time = formatLockoutTime($lockout_time);
                    $error = "Akun '$username' terkunci. Silakan coba lagi dalam {$lockout_formatted_time}";
                    $attempts_info = getRemainingAttemptsInfo($db, $user['id']);
                } else {
                    // PERBAIKAN: Pastikan failed_attempts sudah direset sebelum verifikasi password
                    if ($user['locked_until'] && strtotime($user['locked_until']) <= time()) {
                        // Reset jika masih ada lockout yang sudah expired
                        resetLoginAttempts($db, $user['id']);
                    }
                    
                    if(password_verify($password, $user['password'])) {
                        // Successful login, reset attempts
                        resetFailedAttempts($db, $user['id']);
                        resetLoginAttempts($db, $user['id']);
                        
                        // Update last login
                        $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
                        $updateStmt = $db->prepare($updateQuery);
                        $updateStmt->bindParam(':id', $user['id']);
                        $updateStmt->execute();
                        
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        
                        // Log activity
                        logActivity($db, $user['id'], 'Login', 'Login berhasil');
                        
                        header('Location: dashboard.php');
                        exit();
                    } else {
                        // Wrong password, handle failed login dengan sistem baru
                        $result = handleFailedLoginModified($db, $user['id']);
                        
                        if ($result && $result['locked']) {
                            $lockout_time = $result['duration'];
                            $lockout_username = $username;
                            $lockout_formatted_time = formatLockoutTime($lockout_time);
                            $error = "Terlalu banyak percobaan gagal. Akun '$username' terkunci selama {$lockout_formatted_time}";
                            $attempts_info = getRemainingAttemptsInfo($db, $user['id']);
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
                        }
                    }
                }
            } else {
                $error = 'Username tidak ditemukan';
                $stored_username = htmlspecialchars($username);
            }
        }
    }
}

// Check if the submitted username is locked
$is_locked_user = ($lockout_time > 0 && isset($_POST['username']) && $_POST['username'] === $lockout_username);

// Ambil username dari session jika ada (untuk akun dinonaktifkan)
if (isset($_SESSION['inactive_account'])) {
    $stored_username = htmlspecialchars($_SESSION['inactive_account']);
}

// Get lockout settings for display
$lockout_settings = getLockoutSettings($db);
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
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            position: relative;
        }
        .login-header {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .login-body {
            padding: 40px;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .register-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .register-link:hover {
            text-decoration: underline;
        }
        
        /* Popup Styles */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
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
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            position: relative;
            animation: popupIn 0.3s ease-out;
        }
        @keyframes popupIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        .popup-icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .popup-title {
            color: #dc3545;
            margin-bottom: 15px;
        }
        .popup-message {
            margin-bottom: 25px;
            color: #666;
        }
        .popup-timer {
            font-size: 0.9rem;
            color: #888;
            margin-top: 15px;
        }
        .popup-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #999;
            cursor: pointer;
            transition: color 0.3s;
        }
        .popup-close:hover {
            color: #333;
        }
        
        /* Specific lockout message */
        .lockout-specific {
            background: #ffe6e6;
            border: 1px solid #ff9999;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }
        .lockout-specific i {
            color: #dc3545;
            font-size: 1.5rem;
            margin-right: 10px;
        }
        
        /* Style for locked inputs */
        .input-locked {
            background-color: #fff3cd;
            border-color: #ffc107;
        }
        .btn-locked {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: #000;
        }
        
        /* Progress Bar Styles */
        .progress-container {
            margin: 15px 0;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            height: 12px;
        }
        
        .progress-bar {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-safe { background-color: #28a745; }
        .progress-caution { background-color: #ffc107; }
        .progress-warning { background-color: #fd7e14; }
        .progress-danger { background-color: #dc3545; }
        .progress-locked { background-color: #6c757d; }
        
        .attempts-info {
            font-size: 0.85rem;
            margin-top: 5px;
            display: flex;
            justify-content: space-between;
        }
        
        .attempts-text {
            color: #666;
        }
        
        .attempts-count {
            font-weight: bold;
        }
        
        .attempts-safe { color: #28a745; }
        .attempts-caution { color: #ffc107; }
        .attempts-warning { color: #fd7e14; }
        .attempts-danger { color: #dc3545; }
        .attempts-locked { color: #6c757d; }
        
        /* Lockout info box - DIUBAH */
        .lockout-info-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .lockout-info-box h6 {
            color: #495057;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .lockout-info-box ul {
            margin-bottom: 0;
            padding-left: 20px;
        }
        
        .lockout-info-box li {
            margin-bottom: 5px;
            color: #6c757d;
        }
        
        .warning-alert {
            background-color: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
    </style>
</head>
<body>
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

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="login-card">
                    <div class="login-header">
                        <h2><i class="fas fa-calendar-alt"></i> Admin Panel</h2>
                        <p class="mb-0">Sistem Jadwal Kuliah</p>
                    </div>
                    <div class="login-body">
                        <!-- Lockout Info Box - DIUBAH TEKS: menit menjadi detik -->
                        <div class="lockout-info-box">
                            <h6><i class="fas fa-info-circle"></i> Sistem Keamanan Login:</h6>
                            <ul>
                                <li>Maksimal <?php echo $lockout_settings['max_login_attempts']; ?> percobaan login</li>
                                <li>Akun terkunci <?php echo $lockout_settings['lockout_initial_duration']; ?> detik pada percobaan terakhir</li> <!-- DIUBAH: menit -> detik -->
                                <li>Setelah waktu habis, percobaan direset ke 0</li>
                                <li>Countdown hanya dimulai saat akun terkunci</li>
                            </ul>
                        </div>
                        
                        <?php if($is_locked_user): ?>
                        <div class="lockout-specific">
                            <i class="fas fa-lock"></i>
                            <strong>Akun Terkunci!</strong><br>
                            Akun <strong><?php echo htmlspecialchars($lockout_username); ?></strong> terkunci karena mencapai batas maksimal percobaan.<br>
                            Akan terbuka dalam: <strong id="countdownDisplay"><?php echo $lockout_formatted_time; ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <?php if($show_progress && $attempts_info): ?>
                        <div class="mb-3">
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
                                        echo 'Akun terkunci';
                                    } else {
                                        echo "Percobaan: {$attempts_info['failed_attempts']}/{$attempts_info['max_attempts']}";
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
                        
                        <?php if($error && !$inactive_account && !$is_locked_user): ?>
                            <div class="alert <?php echo strpos($error, 'akan terkunci') !== false ? 'warning-alert' : 'alert-danger'; ?> 
                                 alert-dismissible fade show" role="alert">
                                <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="loginForm">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control <?php echo $is_locked_user ? 'input-locked' : ''; ?>" 
                                           id="username" name="username" required autofocus 
                                           placeholder="Masukkan username"
                                           value="<?php echo $stored_username; ?>">
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
                            <button type="submit" class="btn w-100 mb-3 <?php echo $is_locked_user ? 'btn-locked' : 'btn-login'; ?>"
                                    id="loginButton" <?php echo $is_locked_user ? 'disabled' : ''; ?>>
                                <i class="fas fa-sign-in-alt"></i> 
                                <?php if($is_locked_user): ?>
                                    <span id="buttonText">Akun Terkunci</span>
                                <?php else: ?>
                                    Login
                                <?php endif; ?>
                            </button>
                            
                            <?php if($is_locked_user): ?>
                            <div class="text-center text-muted small mb-3">
                                <i class="fas fa-info-circle"></i> 
                                Anda dapat mencoba akun lain
                            </div>
                            <?php endif; ?>
                            
                            <div class="text-center mt-4">
                                <p class="mb-0">
                                    Hanya untuk admin? 
                                    <a href="register_superadmin.php" class="register-link">
                                        Daftar Super Admin
                                    </a>
                                </p>
                                <p class="text-muted small mt-2">
                                    <i class="fas fa-info-circle"></i> 
                                    Link pendaftaran hanya bisa diakses sekali
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
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
        
        // Countdown for lockout - ONLY when account is locked
        <?php if($is_locked_user && $lockout_time > 0): ?>
        let lockoutSeconds = <?php echo $lockout_time; ?>;
        const countdownDisplay = document.getElementById('countdownDisplay');
        const loginButton = document.getElementById('loginButton');
        const buttonText = document.getElementById('buttonText');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const loginForm = document.getElementById('loginForm');
        
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
                countdownDisplay.textContent = 'Akun terbuka! Percobaan direset ke 0';
                loginButton.classList.remove('btn-locked');
                loginButton.classList.add('btn-login');
                buttonText.textContent = 'Login';
                usernameInput.classList.remove('input-locked');
                passwordInput.classList.remove('input-locked');
                loginButton.disabled = false;
                
                // PERBAIKAN: Auto reload untuk reset form dan reset percobaan
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
        loginForm.addEventListener('submit', function(e) {
            const currentUsername = usernameInput.value;
            const lockedUsername = '<?php echo $lockout_username; ?>';
            
            if (currentUsername === lockedUsername && lockoutSeconds > 0) {
                e.preventDefault();
                alert(`Akun ${lockedUsername} masih terkunci. Silakan tunggu atau gunakan akun lain.`);
                return false;
            }
        });
        <?php endif; ?>
        
        // Reset form when user changes username
        document.getElementById('username').addEventListener('input', function() {
            const currentUsername = this.value;
            const lockedUsername = '<?php echo $lockout_username; ?>';
            
            if (currentUsername !== lockedUsername) {
                const loginButton = document.getElementById('loginButton');
                const usernameInput = document.getElementById('username');
                const passwordInput = document.getElementById('password');
                
                loginButton.classList.remove('btn-locked');
                loginButton.classList.add('btn-login');
                loginButton.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login';
                loginButton.disabled = false;
                
                usernameInput.classList.remove('input-locked');
                passwordInput.classList.remove('input-locked');
                
                // Hide lockout message if exists
                const lockoutMessage = document.querySelector('.lockout-specific');
                if (lockoutMessage) {
                    lockoutMessage.style.display = 'none';
                }
                
                // Hide progress bar if exists
                const progressBar = document.querySelector('.progress-container');
                if (progressBar && progressBar.parentElement) {
                    progressBar.parentElement.style.display = 'none';
                }
            }
        });
    </script>
</body>
</html>