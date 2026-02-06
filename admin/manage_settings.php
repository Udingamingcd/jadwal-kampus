<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin(); // Semua admin bisa akses (dulu: requireSuperAdmin())

$database = new Database();
$db = $database->getConnection();

// Update settings
if(isset($_POST['update_settings'])) {
    foreach($_POST as $key => $value) {
        if($key != 'update_settings') {
            updateSetting($db, $key, $value);
        }
    }
    logActivity($db, $_SESSION['user_id'], 'Update Settings', 'Memperbarui pengaturan sistem');
    $_SESSION['message'] = "Pengaturan berhasil diperbarui!";
    header('Location: manage_settings.php');
    exit();
}

// Ambil semua settings
$query = "SELECT * FROM settings";
$stmt = $db->prepare($query);
$stmt->execute();
$settings = [];
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Sistem - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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
        .card-stat {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .card-stat:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
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
        .settings-form {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                min-height: auto;
            }
            .main-content {
                margin-left: 0;
            }
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
                <a class="nav-link active" href="manage_settings.php">
                    <i class="fas fa-cog"></i> Pengaturan
                </a>
                <a class="nav-link" href="manage_users.php">
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
            <!-- Navbar -->
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" 
                            data-bs-target="#mobileSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">Pengaturan Sistem</h4>
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
                            <a class="nav-link active" href="manage_settings.php">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <a class="nav-link" href="manage_users.php">
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
                            <h5 class="mb-1">Pengaturan Sistem</h5>
                            <p class="text-muted mb-0">Kelola pengaturan aplikasi jadwal kuliah</p>
                        </div>
                    </div>
                </div>

                <?php if(isset($_SESSION['message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['message']; ?>
                        <?php unset($_SESSION['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Settings Form -->
                <div class="settings-form mb-4">
                    <form method="POST">
                        <h5 class="mb-4"><i class="fas fa-cog me-2"></i>Informasi Sistem</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Tahun Akademik Default</label>
                                    <input type="text" name="tahun_akademik" class="form-control" 
                                           value="<?php echo htmlspecialchars($settings['tahun_akademik'] ?? ''); ?>" required>
                                    <small class="text-muted">Contoh: 2023/2024 (Digunakan sebagai default untuk semester baru)</small>
                                </div>
                                <div class="mb-3">
                                    <label>Nama Institusi</label>
                                    <input type="text" name="institusi_nama" class="form-control" 
                                           value="<?php echo htmlspecialchars($settings['institusi_nama'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Lokasi Institusi</label>
                                    <input type="text" name="institusi_lokasi" class="form-control" 
                                           value="<?php echo htmlspecialchars($settings['institusi_lokasi'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Program Studi</label>
                                    <input type="text" name="program_studi" class="form-control" 
                                           value="<?php echo htmlspecialchars($settings['program_studi'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Jurusan</label>
                                    <input type="text" name="fakultas" class="form-control" 
                                           value="<?php echo htmlspecialchars($settings['fakultas'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label>Email Admin</label>
                                    <input type="email" name="admin_email" class="form-control" 
                                           value="<?php echo htmlspecialchars($settings['admin_email'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <h5 class="mb-4"><i class="fas fa-shield-alt me-2"></i>Pengaturan Keamanan</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Superadmin Registered</label>
                                        <input type="text" name="superadmin_registered" class="form-control" 
                                               value="<?php echo htmlspecialchars($settings['superadmin_registered'] ?? '0'); ?>" readonly>
                                        <small class="text-muted">Status registrasi superadmin (0 = belum, 1 = sudah)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Max Login Attempts</label>
                                        <input type="number" name="max_login_attempts" class="form-control" 
                                               value="<?php echo htmlspecialchars($settings['max_login_attempts'] ?? '5'); ?>" min="1" max="10">
                                        <small class="text-muted">Jumlah maksimal percobaan login yang gagal</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 d-flex justify-content-end">
                            <button type="submit" name="update_settings" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Simpan Pengaturan
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Danger Zone - Hanya untuk Superadmin -->
                <?php if(isSuperAdmin()): ?>
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Zona Berbahaya (Hanya Superadmin)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-danger"><i class="fas fa-info-circle me-2"></i>Hati-hati! Aksi di bawah ini tidak dapat dibatalkan.</p>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="d-grid gap-2">
                                    <a href="reset_data.php" class="btn btn-warning" onclick="return confirm('Yakin reset semua data jadwal? Semua jadwal akan terhapus!')">
                                        <i class="fas fa-redo me-2"></i> Reset Data Jadwal
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-grid gap-2">
                                    <a href="backup_database.php" class="btn btn-info">
                                        <i class="fas fa-database me-2"></i> Backup Database
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-grid gap-2">
                                    <a href="clear_logs.php" class="btn btn-secondary" onclick="return confirm('Yakin hapus semua log aktivitas?')">
                                        <i class="fas fa-trash-alt me-2"></i> Hapus Log Aktivitas
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card border-secondary">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Akses Terbatas</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            <i class="fas fa-info-circle me-2"></i>
                            Fitur zona berbahaya hanya dapat diakses oleh Superadmin.
                            Hubungi Superadmin untuk aksi-aksi seperti reset data, backup database, atau hapus log.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>