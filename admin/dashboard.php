<?php
// WAJIB duluan → database
require_once '../config/database.php';

// Baru panggil check_auth
require_once 'check_auth.php';

$database = new Database();
$db = $database->getConnection();

// Get stats
$stats = [];
$query = "SELECT COUNT(*) as total FROM schedules";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_jadwal'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$query = "SELECT COUNT(*) as total FROM rooms";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_ruangan'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$query = "SELECT COUNT(DISTINCT kelas) as total FROM schedules";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_kelas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get maintenance status
$query = "SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'";
$stmt = $db->prepare($query);
$stmt->execute();
$maintenanceStatus = $stmt->fetch(PDO::FETCH_ASSOC);
$isMaintenance = ($maintenanceStatus && $maintenanceStatus['setting_value'] == '1') ? true : false;

// Get recent activities
$query = "SELECT a.*, u.username FROM activity_logs a 
          LEFT JOIN users u ON a.user_id = u.id 
          ORDER BY a.created_at DESC LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute();
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Jadwal Kuliah</title>
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
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
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
        .maintenance-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: <?php echo $isMaintenance ? '#ff6b6b' : '#28a745'; ?>;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
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
            }
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
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
                <a class="nav-link active" href="dashboard.php">
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
                        <h4 class="mb-0">Dashboard</h4>
                        <?php if ($isMaintenance): ?>
                        <span class="badge bg-danger ms-3">
                            <i class="fas fa-tools"></i> Maintenance Mode Aktif
                        </span>
                        <?php endif; ?>
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

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="card card-stat border-primary">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Total Jadwal</h6>
                                    <h2><?php echo $stats['total_jadwal']; ?></h2>
                                </div>
                                <div class="stat-icon text-primary">
                                    <i class="fas fa-calendar"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card card-stat border-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Total Ruangan</h6>
                                    <h2><?php echo $stats['total_ruangan']; ?></h2>
                                </div>
                                <div class="stat-icon text-success">
                                    <i class="fas fa-door-open"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card card-stat border-warning">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Total Kelas</h6>
                                    <h2><?php echo $stats['total_kelas']; ?></h2>
                                </div>
                                <div class="stat-icon text-warning">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Maintenance Status Card -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-tools me-2"></i>Status Sistem
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <div class="d-flex align-items-center">
                                        <div class="status-indicator me-3" 
                                             style="width: 20px; height: 20px; border-radius: 50%; background-color: <?php echo $isMaintenance ? '#ff6b6b' : '#28a745'; ?>;"></div>
                                        <div>
                                            <h4 class="mb-1"><?php echo $isMaintenance ? 'MAINTENANCE MODE' : 'NORMAL MODE'; ?></h4>
                                            <p class="text-muted mb-0">
                                                <?php if($isMaintenance): ?>
                                                Sistem sedang dalam mode maintenance. Pengunjung akan melihat notifikasi maintenance.
                                                <?php else: ?>
                                                Sistem berjalan normal. Semua fitur tersedia untuk pengunjung.
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-end">
                                    <a href="maintenance.php" class="btn btn-lg <?php echo $isMaintenance ? 'btn-success' : 'btn-warning'; ?>">
                                        <i class="fas fa-cog me-2"></i>
                                        <?php echo $isMaintenance ? 'Nonaktifkan Maintenance' : 'Kelola Maintenance'; ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-history me-2"></i>Aktivitas Terbaru
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Waktu</th>
                                            <th>User</th>
                                            <th>Aksi</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($activities as $activity): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($activity['created_at'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($activity['username'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($activity['action'] ?? ''); ?></td>
                                            <td><code><?php echo htmlspecialchars($activity['ip_address'] ?? ''); ?></code></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-bolt me-2"></i>Aksi Cepat
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-6 mb-3">
                                    <a href="manage_schedule.php?action=add" class="btn btn-primary w-100">
                                        <i class="fas fa-plus me-2"></i>Tambah Jadwal
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-3">
                                    <a href="manage_rooms.php?action=add" class="btn btn-success w-100">
                                        <i class="fas fa-plus me-2"></i>Tambah Ruangan
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-3">
                                    <a href="maintenance.php" class="btn btn-warning w-100">
                                        <i class="fas fa-cog me-2"></i>Maintenance
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-3">
                                    <a href="export.php" class="btn btn-info w-100">
                                        <i class="fas fa-download me-2"></i>Export Jadwal
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('table').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                },
                "order": [[0, 'asc']]
            });
        });
    </script>
</body>
</html>