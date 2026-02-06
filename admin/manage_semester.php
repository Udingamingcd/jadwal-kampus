<?php
// manage_semester.php
require_once '../config/database.php';
require_once '../config/helpers.php';

// Mulai session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'check_auth.php';
requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Tambah semester baru
if (isset($_POST['add_semester'])) {
    $tahun_akademik = $_POST['tahun_akademik'] ?? '';
    $semester = $_POST['semester'] ?? '';
    
    if ($tahun_akademik && $semester) {
        if (addSemester($db, $tahun_akademik, $semester)) {
            $_SESSION['message'] = "Semester berhasil ditambahkan!";
        } else {
            $_SESSION['error_message'] = "Gagal menambahkan semester!";
        }
    }
    header('Location: manage_semester.php');
    exit();
}

// Set semester aktif
if (isset($_POST['set_active'])) {
    $id = $_POST['semester_id'] ?? '';
    
    if ($id) {
        $stmt = $db->prepare("SELECT tahun_akademik, semester FROM semester_settings WHERE id = ?");
        $stmt->execute([$id]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($semester && setActiveSemester($db, $semester['tahun_akademik'], $semester['semester'])) {
            logActivity($db, $_SESSION['user_id'], 'Change Active Semester', 
                       'Mengubah semester aktif menjadi ' . $semester['semester'] . ' ' . $semester['tahun_akademik']);
            $_SESSION['message'] = "Semester aktif berhasil diubah!";
        } else {
            $_SESSION['error_message'] = "Gagal mengubah semester aktif!";
        }
    }
    header('Location: manage_semester.php');
    exit();
}

// Hapus semester
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    if (deleteSemester($db, $id)) {
        $_SESSION['message'] = "Semester berhasil dihapus!";
    } else {
        $_SESSION['error_message'] = "Tidak bisa menghapus semester aktif!";
    }
    header('Location: manage_semester.php');
    exit();
}

// Ambil semua semester
$semesters = getAllSemesters($db);
$activeSemester = getActiveSemester($db);

// Get maintenance status
$query = "SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'";
$stmt = $db->prepare($query);
$stmt->execute();
$maintenanceStatus = $stmt->fetch(PDO::FETCH_ASSOC);
$isMaintenance = ($maintenanceStatus && $maintenanceStatus['setting_value'] == '1') ? true : false;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Semester - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
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
        .active-semester-card {
            background: linear-gradient(135deg, #4a6491, #2c3e50);
            color: white;
            border: none;
        }
        .semester-card {
            transition: all 0.3s ease;
            border: 1px solid #dee2e6;
            border-radius: 10px;
        }
        .semester-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .badge-semester {
            font-size: 0.8rem;
            padding: 5px 10px;
        }
        .alert-success {
            background-color: #d1e7dd;
            border-color: #badbcc;
            color: #0f5132;
        }
        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
        }
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffecb5;
            color: #664d03;
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
        .navbar-toggler:focus {
            box-shadow: none;
        }
        .mobile-sidebar {
            max-height: 70vh;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar untuk Desktop -->
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
                <a class="nav-link active" href="manage_semester.php">
                    <i class="fas fa-calendar-alt"></i> Kelola Semester
                </a>
                <a class="nav-link" href="manage_settings.php">
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
                    <!-- Toggle Button untuk Mobile -->
                    <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" 
                            data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" 
                            aria-expanded="false" aria-label="Toggle navigation">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-calendar-alt me-2"></i> Kelola Semester
                        </h4>
                        <?php if ($isMaintenance): ?>
                        <span class="badge bg-danger ms-3">
                            <i class="fas fa-tools"></i> Maintenance Mode Aktif
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3 d-none d-md-block"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="profile.php">
                                    <i class="fas fa-user me-2"></i>Profile
                                </a></li>
                                <li><a class="dropdown-item" href="dashboard.php">
                                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
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

            <!-- Mobile Sidebar (Collapse) -->
            <div class="collapse d-md-none mb-4" id="mobileSidebar">
                <div class="card mobile-sidebar">
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

            <!-- Konten Utama -->
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-calendar-alt me-2"></i> Kelola Semester</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSemesterModal">
                        <i class="fas fa-plus me-2"></i> Tambah Semester
                    </button>
                </div>
                
                <?php 
                // Tampilkan pesan
                if (function_exists('displayMessage')) {
                    echo displayMessage();
                } elseif (isset($_SESSION['message'])) {
                    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>' . $_SESSION['message'] . '
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                          </div>';
                    unset($_SESSION['message']);
                } elseif (isset($_SESSION['error_message'])) {
                    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>' . $_SESSION['error_message'] . '
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                          </div>';
                    unset($_SESSION['error_message']);
                }
                ?>
                
                <!-- Semester Aktif -->
                <div class="card active-semester-card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5><i class="fas fa-star me-2"></i> Semester Aktif</h5>
                                <h3 class="mb-1">
                                    <?php echo isset($activeSemester['semester']) ? htmlspecialchars($activeSemester['semester']) : 'GANJIL'; ?> - 
                                    <?php echo isset($activeSemester['tahun_akademik']) ? htmlspecialchars($activeSemester['tahun_akademik']) : '2024/2025'; ?>
                                </h3>
                                <small>Semester ini yang akan ditampilkan di halaman utama</small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-light text-dark px-3 py-2">
                                    <i class="fas fa-calendar-check me-1"></i>
                                    <?php 
                                        // Hitung jumlah jadwal di semester aktif
                                        if (isset($activeSemester['tahun_akademik']) && isset($activeSemester['semester'])) {
                                            try {
                                                $stmt = $db->prepare("SELECT COUNT(*) FROM schedules WHERE tahun_akademik = ? AND semester = ?");
                                                $stmt->execute([$activeSemester['tahun_akademik'], $activeSemester['semester']]);
                                                $jumlah_jadwal = $stmt->fetchColumn();
                                                echo $jumlah_jadwal . " Jadwal";
                                            } catch (Exception $e) {
                                                echo "0 Jadwal";
                                            }
                                        } else {
                                            echo "0 Jadwal";
                                        }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Daftar Semua Semester -->
                <div class="row">
                    <?php if (!empty($semesters)): ?>
                        <?php foreach ($semesters as $semester): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card semester-card h-100 <?php echo isset($semester['is_active']) && $semester['is_active'] ? 'border-primary border-2' : ''; ?>">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="flex-grow-1">
                                            <h5 class="card-title mb-1">
                                                <?php echo isset($semester['semester']) ? htmlspecialchars($semester['semester']) : ''; ?>
                                            </h5>
                                            <h6 class="text-muted mb-0">
                                                <?php echo isset($semester['tahun_akademik']) ? htmlspecialchars($semester['tahun_akademik']) : ''; ?>
                                            </h6>
                                        </div>
                                        <?php if (isset($semester['is_active']) && $semester['is_active']): ?>
                                        <span class="badge bg-success">Aktif</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-3 flex-grow-1">
                                        <?php 
                                            // Hitung jumlah jadwal di semester ini
                                            if (isset($semester['tahun_akademik']) && isset($semester['semester'])) {
                                                try {
                                                    $stmt = $db->prepare("SELECT COUNT(*) FROM schedules WHERE tahun_akademik = ? AND semester = ?");
                                                    $stmt->execute([$semester['tahun_akademik'], $semester['semester']]);
                                                    $jumlah_jadwal = $stmt->fetchColumn();
                                                } catch (Exception $e) {
                                                    $jumlah_jadwal = 0;
                                                }
                                            } else {
                                                $jumlah_jadwal = 0;
                                            }
                                        ?>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-calendar-check me-2 text-muted"></i>
                                            <div>
                                                <div class="fw-bold"><?php echo $jumlah_jadwal; ?> jadwal</div>
                                                <small class="text-muted">kuliah terdaftar</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mt-auto">
                                        <?php if (!isset($semester['is_active']) || !$semester['is_active']): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="semester_id" value="<?php echo isset($semester['id']) ? $semester['id'] : ''; ?>">
                                            <button type="submit" name="set_active" class="btn btn-sm btn-primary">
                                                <i class="fas fa-check me-1"></i> Set Aktif
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="btn btn-sm btn-success disabled">
                                            <i class="fas fa-check-circle me-1"></i> Aktif
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!isset($semester['is_active']) || !$semester['is_active']): ?>
                                        <a href="manage_semester.php?delete=<?php echo isset($semester['id']) ? $semester['id'] : ''; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Yakin hapus semester <?php echo isset($semester['semester']) ? $semester['semester'] : ''; ?> <?php echo isset($semester['tahun_akademik']) ? $semester['tahun_akademik'] : ''; ?>?')">
                                            <i class="fas fa-trash me-1"></i> Hapus
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Belum ada data semester. Silakan tambah semester baru.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Info Box -->
                <div class="alert alert-info mt-4">
                    <h5><i class="fas fa-info-circle me-2"></i> Informasi</h5>
                    <ul class="mb-0">
                        <li>Hanya satu semester yang dapat aktif pada satu waktu</li>
                        <li>Semester yang aktif akan ditampilkan di halaman utama</li>
                        <li>Jadwal kuliah akan difilter berdasarkan semester aktif</li>
                        <li>Pastikan jadwal sudah dimasukkan untuk semester yang akan diaktifkan</li>
                        <li>Semester aktif tidak dapat dihapus</li>
                        <li>Semester yang sudah dihapus tidak dapat dikembalikan</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Tambah Semester -->
    <div class="modal fade" id="addSemesterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Semester Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tahun_akademik" class="form-label">Tahun Akademik</label>
                            <input type="text" class="form-control" id="tahun_akademik" 
                                   name="tahun_akademik" required 
                                   placeholder="Contoh: 2024/2025"
                                   pattern="\d{4}/\d{4}">
                            <small class="text-muted">Format: YYYY/YYYY</small>
                        </div>
                        <div class="mb-3">
                            <label for="semester" class="form-label">Semester</label>
                            <select class="form-select" id="semester" name="semester" required>
                                <option value="GANJIL">GANJIL</option>
                                <option value="GENAP">GENAP</option>
                            </select>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <small>Pastikan jadwal sudah dimasukkan untuk semester ini sebelum mengaktifkannya</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_semester" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i> Simpan Semester
                        </button>
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
        // Auto-generate tahun akademik berdasarkan tahun saat ini
        document.addEventListener('DOMContentLoaded', function() {
            const tahunInput = document.getElementById('tahun_akademik');
            if (tahunInput && !tahunInput.value) {
                const currentYear = new Date().getFullYear();
                const nextYear = currentYear + 1;
                tahunInput.value = `${currentYear}/${nextYear}`;
            }
            
            // Validasi input tahun akademik
            if (tahunInput) {
                tahunInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length > 4) {
                        value = value.substring(0, 4) + '/' + value.substring(4, 8);
                    }
                    e.target.value = value;
                });
            }
            
            // Initialize DataTable jika ada tabel
            if ($('table').length) {
                $('table').DataTable({
                    "language": {
                        "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                    },
                    "order": [[0, 'desc']],
                    "responsive": true
                });
            }
            
            // Auto-close alerts after 5 seconds
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);
            
            // Collapse mobile sidebar when clicking outside
            document.addEventListener('click', function(event) {
                const mobileSidebar = document.getElementById('mobileSidebar');
                const navbarToggler = document.querySelector('.navbar-toggler');
                
                // Jika sidebar mobile terbuka dan user klik di luar sidebar dan toggle button
                if (mobileSidebar && mobileSidebar.classList.contains('show') && 
                    !mobileSidebar.contains(event.target) && 
                    !navbarToggler.contains(event.target)) {
                    
                    // Tutup sidebar menggunakan Bootstrap collapse
                    const bsCollapse = new bootstrap.Collapse(mobileSidebar);
                    bsCollapse.hide();
                }
            });
        });
    </script>
</body>
</html>