<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin();

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Ambil data user
$query = "SELECT * FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Update profile
if(isset($_POST['update_profile'])) {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validasi
    if(empty($current_password)) {
        $error = "Password saat ini harus diisi!";
    } else if(!password_verify($current_password, $user['password'])) {
        $error = "Password saat ini salah!";
    } else {
        // Update data
        $query = "UPDATE users SET username = ?, email = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$username, $email, $user_id]);
        
        // Update password jika diisi
        if(!empty($new_password)) {
            if(strlen($new_password) < 6) {
                $error = "Password baru minimal 6 karakter!";
            } else if($new_password !== $confirm_password) {
                $error = "Password baru dan konfirmasi tidak cocok!";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $query = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$hashed_password, $user_id]);
                $success = "Profile dan password berhasil diperbarui!";
            }
        } else {
            $success = "Profile berhasil diperbarui!";
        }
        
        if(empty($error)) {
            $_SESSION['username'] = $username;
            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            logActivity($db, $user_id, 'Update Profile', 'Memperbarui profil pengguna');
        }
    }
}

// Tentukan halaman aktif untuk mobile sidebar
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Admin Panel</title>
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
            .main-content {
                margin-left: 0;
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
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto 20px;
        }
        .profile-info-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .profile-info-item:last-child {
            border-bottom: none;
        }
        .profile-info-item i {
            width: 20px;
            margin-right: 10px;
            color: #6c757d;
        }
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 3px;
            width: 0%;
            transition: width 0.3s;
        }
        .strength-weak { background-color: #dc3545; }
        .strength-medium { background-color: #ffc107; }
        .strength-strong { background-color: #28a745; }
        .card-stat {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar Desktop -->
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
                <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_schedule.php' ? 'active' : ''; ?>" href="manage_schedule.php">
                    <i class="fas fa-calendar"></i> Kelola Jadwal
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_rooms.php' ? 'active' : ''; ?>" href="manage_rooms.php">
                    <i class="fas fa-door-open"></i> Kelola Ruangan
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_semester.php' ? 'active' : ''; ?>" href="manage_semester.php">
                    <i class="fas fa-calendar-alt"></i> Kelola Semester
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_settings.php' ? 'active' : ''; ?>" href="manage_settings.php">
                    <i class="fas fa-cog"></i> Pengaturan
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>" href="manage_users.php">
                    <i class="fas fa-users"></i> Kelola Admin
                </a>
                <a class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                    <i class="fas fa-chart-bar"></i> Laporan
                </a>
                <div class="mt-4"></div>
                <a class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>" href="profile.php">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- Navbar -->
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" 
                            data-bs-target="#mobileSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">Profil Saya</h4>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
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

            <!-- Mobile Sidebar -->
            <div class="collapse d-md-none mb-4" id="mobileSidebar">
                <div class="card">
                    <div class="card-body">
                        <nav class="nav flex-column">
                            <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                            <a class="nav-link <?php echo $current_page == 'manage_schedule.php' ? 'active' : ''; ?>" href="manage_schedule.php">
                                <i class="fas fa-calendar"></i> Kelola Jadwal
                            </a>
                            <a class="nav-link <?php echo $current_page == 'manage_rooms.php' ? 'active' : ''; ?>" href="manage_rooms.php">
                                <i class="fas fa-door-open"></i> Kelola Ruangan
                            </a>
                            <a class="nav-link <?php echo $current_page == 'manage_semester.php' ? 'active' : ''; ?>" href="manage_semester.php">
                                <i class="fas fa-calendar-alt"></i> Kelola Semester
                            </a>
                            <a class="nav-link <?php echo $current_page == 'manage_settings.php' ? 'active' : ''; ?>" href="manage_settings.php">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <a class="nav-link <?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>" href="manage_users.php">
                                <i class="fas fa-users"></i> Kelola Admin
                            </a>
                            <a class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                                <i class="fas fa-chart-bar"></i> Laporan
                            </a>
                            <hr>
                            <a class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>" href="profile.php">
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
                            <h5 class="mb-1">Kelola Profil</h5>
                            <p class="text-muted mb-0">Ubah informasi profil dan password akun Anda</p>
                        </div>
                    </div>
                </div>

                <!-- Notifications -->
                <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Profile Card -->
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <div class="profile-avatar">
                                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                </div>
                                <h4 class="mb-2"><?php echo htmlspecialchars($user['username']); ?></h4>
                                <p class="text-muted mb-3">
                                    <span class="badge bg-<?php echo $user['role'] == 'superadmin' ? 'danger' : 'primary'; ?> p-2">
                                        <i class="fas fa-user-shield me-1"></i>
                                        <?php echo strtoupper($user['role']); ?>
                                    </span>
                                </p>
                                
                                <div class="profile-info text-start">
                                    <div class="profile-info-item">
                                        <i class="fas fa-envelope"></i>
                                        <div>
                                            <small class="text-muted">Email</small>
                                            <div><?php echo htmlspecialchars($user['email'] ?? 'Tidak ada email'); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="profile-info-item">
                                        <i class="fas fa-calendar-plus"></i>
                                        <div>
                                            <small class="text-muted">Bergabung</small>
                                            <div><?php echo date('d F Y', strtotime($user['created_at'])); ?></div>
                                        </div>
                                    </div>
                                    
                                    <?php if($user['last_login']): ?>
                                    <div class="profile-info-item">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <div>
                                            <small class="text-muted">Login Terakhir</small>
                                            <div><?php echo date('d F Y H:i', strtotime($user['last_login'])); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="profile-info-item">
                                        <i class="fas fa-user-check"></i>
                                        <div>
                                            <small class="text-muted">Status</small>
                                            <div>
                                                <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                                    <?php echo $user['is_active'] ? 'AKTIF' : 'NONAKTIF'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <small>Untuk keamanan, pastikan password Anda kuat dan tidak mudah ditebak.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Edit Profile Form -->
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-edit me-2"></i>Edit Profil
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="profileForm">
                                    <div class="row mb-4">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Username</label>
                                            <input type="text" name="username" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['username']); ?>" 
                                                   required 
                                                   minlength="3"
                                                   maxlength="50">
                                            <small class="text-muted">Username untuk login, minimal 3 karakter</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                                            <small class="text-muted">Email untuk notifikasi dan reset password</small>
                                        </div>
                                    </div>
                                    
                                    <hr class="my-4">
                                    
                                    <h5 class="mb-4">
                                        <i class="fas fa-key me-2"></i>Ubah Password
                                    </h5>
                                    <p class="text-muted mb-4">Isi password saat ini dan password baru jika ingin mengubah password.</p>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Password Saat Ini <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="password" name="current_password" id="currentPassword" 
                                                       class="form-control" required>
                                                <button class="btn btn-outline-secondary" type="button" 
                                                        onclick="togglePassword('currentPassword', this)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <small class="text-muted">Harus diisi untuk verifikasi</small>
                                        </div>
                                    </div>
                                    
                                    <div class="row mt-2">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Password Baru</label>
                                            <div class="input-group">
                                                <input type="password" name="new_password" id="newPassword" 
                                                       class="form-control" minlength="6">
                                                <button class="btn btn-outline-secondary" type="button" 
                                                        onclick="togglePassword('newPassword', this)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div id="passwordStrength" class="password-strength mt-1"></div>
                                            <small class="text-muted">Minimal 6 karakter, kosongkan jika tidak ingin mengubah</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Konfirmasi Password Baru</label>
                                            <div class="input-group">
                                                <input type="password" name="confirm_password" id="confirmPassword" 
                                                       class="form-control">
                                                <button class="btn btn-outline-secondary" type="button" 
                                                        onclick="togglePassword('confirmPassword', this)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div id="passwordMatch" class="mt-1"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 pt-3 border-top">
                                        <button type="submit" name="update_profile" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Simpan Perubahan
                                        </button>
                                        <a href="dashboard.php" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Batal
                                        </a>
                                        <button type="button" class="btn btn-outline-danger float-end" 
                                                onclick="confirmResetForm()">
                                            <i class="fas fa-redo me-2"></i>Reset Form
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Security Info -->
                        <div class="card mt-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="fas fa-shield-alt me-2"></i>Tips Keamanan
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Gunakan password yang kuat dengan kombinasi huruf, angka, dan simbol
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Jangan gunakan password yang sama untuk akun lain
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Perbarui password secara berkala setiap 3-6 bulan
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Jangan membagikan informasi login Anda kepada siapapun
                                    </li>
                                    <li>
                                        <i class="fas fa-check text-success me-2"></i>
                                        Selalu logout setelah menggunakan sistem
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(passwordId, button) {
            const passwordInput = document.getElementById(passwordId);
            const icon = button.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
        
        function checkPasswordStrength(password) {
            let strength = 0;
            const strengthBar = document.getElementById('passwordStrength');
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            // Reset classes
            strengthBar.className = 'password-strength';
            
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                return;
            }
            
            let width = (strength / 5) * 100;
            strengthBar.style.width = width + '%';
            
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        }
        
        function checkPasswordMatch() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (newPassword === '' && confirmPassword === '') {
                matchDiv.innerHTML = '';
                return;
            }
            
            if (newPassword === confirmPassword) {
                matchDiv.innerHTML = '<small class="text-success"><i class="fas fa-check-circle me-1"></i>Password cocok</small>';
            } else {
                matchDiv.innerHTML = '<small class="text-danger"><i class="fas fa-times-circle me-1"></i>Password tidak cocok</small>';
            }
        }
        
        function confirmResetForm() {
            if (confirm('Apakah Anda yakin ingin mereset form? Semua perubahan yang belum disimpan akan hilang.')) {
                document.getElementById('profileForm').reset();
                document.getElementById('passwordStrength').style.width = '0%';
                document.getElementById('passwordMatch').innerHTML = '';
            }
        }
        
        // Event listeners
        document.getElementById('newPassword').addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
        });
        
        document.getElementById('confirmPassword').addEventListener('input', checkPasswordMatch);
        
        // Form validation
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            if (newPassword !== '' && newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Password baru dan konfirmasi tidak cocok!');
                return false;
            }
            
            if (newPassword.length > 0 && newPassword.length < 6) {
                e.preventDefault();
                alert('Password baru minimal 6 karakter!');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>