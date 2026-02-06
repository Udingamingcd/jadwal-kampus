<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Ambil statistik
$stats = [];

// Total jadwal per kelas
$query = "SELECT kelas, COUNT(*) as total FROM schedules GROUP BY kelas ORDER BY kelas";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['per_kelas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total jadwal per hari
$query = "SELECT hari, COUNT(*) as total FROM schedules GROUP BY hari ORDER BY FIELD(hari, 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT')";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['per_hari'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total jadwal per ruangan
$query = "SELECT ruang, COUNT(*) as total FROM schedules GROUP BY ruang ORDER BY ruang";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['per_ruangan'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aktivitas per user
$query = "SELECT u.username, COUNT(a.id) as total 
          FROM activity_logs a 
          LEFT JOIN users u ON a.user_id = u.id 
          GROUP BY a.user_id 
          ORDER BY total DESC 
          LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['aktivitas_user'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistik umum
$query = "SELECT COUNT(*) as total FROM schedules";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_jadwal'] = $stmt->fetchColumn();

$query = "SELECT COUNT(DISTINCT kelas) as total FROM schedules";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_kelas'] = $stmt->fetchColumn();

$query = "SELECT COUNT(DISTINCT ruang) as total FROM schedules";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_ruang_digunakan'] = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan dan Statistik - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            color: white;
            margin-bottom: 20px;
        }
        .stat-card-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        .stat-card-success {
            background: linear-gradient(135deg, #28a745, #20c997);
        }
        .stat-card-info {
            background: linear-gradient(135deg, #17a2b8, #20c997);
        }
        .stat-card-warning {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .nav-tabs .nav-link {
            color: #495057;
            border: none;
            border-bottom: 2px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #007bff;
            border-bottom: 2px solid #007bff;
            font-weight: 600;
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
                <a class="nav-link" href="manage_settings.php">
                    <i class="fas fa-cog"></i> Pengaturan
                </a>
                <a class="nav-link" href="manage_users.php">
                    <i class="fas fa-users"></i> Kelola Admin
                </a>
                <a class="nav-link active" href="reports.php">
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
                        <h4 class="mb-0">Laporan dan Statistik</h4>
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
                            <a class="nav-link" href="manage_settings.php">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <a class="nav-link" href="manage_users.php">
                                <i class="fas fa-users"></i> Kelola Admin
                            </a>
                            <a class="nav-link active" href="reports.php">
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
                            <h5 class="mb-1">Laporan dan Statistik Sistem</h5>
                            <p class="text-muted mb-0">Analisis dan visualisasi data jadwal kuliah</p>
                        </div>
                    </div>
                </div>

                <!-- Statistik Ringkas -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stat-card stat-card-primary">
                            <h3><i class="fas fa-calendar-alt"></i></h3>
                            <h4><?php echo $stats['total_jadwal']; ?></h4>
                            <p class="mb-0">Total Jadwal</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card stat-card-success">
                            <h3><i class="fas fa-users"></i></h3>
                            <h4><?php echo $stats['total_kelas']; ?></h4>
                            <p class="mb-0">Total Kelas</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card stat-card-info">
                            <h3><i class="fas fa-door-open"></i></h3>
                            <h4><?php echo $stats['total_ruang_digunakan']; ?></h4>
                            <p class="mb-0">Ruangan Digunakan</p>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row mb-4">
                    <div class="col-lg-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>Jadwal per Kelas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="kelasChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Jadwal per Hari
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="hariChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Detail -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-table me-2"></i>Data Detail Statistik
                                </h5>
                            </div>
                            <div class="card-body">
                                <ul class="nav nav-tabs" id="statTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="kelas-tab" data-bs-toggle="tab" data-bs-target="#kelas-pane" type="button" role="tab">
                                            <i class="fas fa-users me-2"></i>Per Kelas
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="hari-tab" data-bs-toggle="tab" data-bs-target="#hari-pane" type="button" role="tab">
                                            <i class="fas fa-calendar-day me-2"></i>Per Hari
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="ruangan-tab" data-bs-toggle="tab" data-bs-target="#ruangan-pane" type="button" role="tab">
                                            <i class="fas fa-door-open me-2"></i>Per Ruangan
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="aktivitas-tab" data-bs-toggle="tab" data-bs-target="#aktivitas-pane" type="button" role="tab">
                                            <i class="fas fa-history me-2"></i>Aktivitas User
                                        </button>
                                    </li>
                                </ul>
                                <div class="tab-content mt-3" id="statTabContent">
                                    <div class="tab-pane fade show active" id="kelas-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-hover" id="tableKelas">
                                                <thead>
                                                    <tr>
                                                        <th>Kelas</th>
                                                        <th>Jumlah Jadwal</th>
                                                        <th>Persentase</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $totalKelas = array_sum(array_column($stats['per_kelas'], 'total'));
                                                    foreach($stats['per_kelas'] as $item): 
                                                        $percentage = $totalKelas > 0 ? ($item['total'] / $totalKelas) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['kelas']); ?></td>
                                                        <td><?php echo $item['total']; ?></td>
                                                        <td>
                                                            <div class="progress" style="height: 20px;">
                                                                <div class="progress-bar" role="progressbar" 
                                                                     style="width: <?php echo $percentage; ?>%;" 
                                                                     aria-valuenow="<?php echo $percentage; ?>" 
                                                                     aria-valuemin="0" 
                                                                     aria-valuemax="100">
                                                                    <?php echo number_format($percentage, 1); ?>%
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="hari-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-hover" id="tableHari">
                                                <thead>
                                                    <tr>
                                                        <th>Hari</th>
                                                        <th>Jumlah Jadwal</th>
                                                        <th>Persentase</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $totalHari = array_sum(array_column($stats['per_hari'], 'total'));
                                                    foreach($stats['per_hari'] as $item): 
                                                        $percentage = $totalHari > 0 ? ($item['total'] / $totalHari) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['hari']); ?></td>
                                                        <td><?php echo $item['total']; ?></td>
                                                        <td>
                                                            <div class="progress" style="height: 20px;">
                                                                <div class="progress-bar" role="progressbar" 
                                                                     style="width: <?php echo $percentage; ?>%; background-color: <?php 
                                                                     $colors = ['SENIN' => '#ff6384', 'SELASA' => '#36a2eb', 'RABU' => '#ffce56', 'KAMIS' => '#4bc0c0', 'JUMAT' => '#9966ff'];
                                                                     echo $colors[$item['hari']] ?? '#36a2eb'; ?>" 
                                                                     aria-valuenow="<?php echo $percentage; ?>" 
                                                                     aria-valuemin="0" 
                                                                     aria-valuemax="100">
                                                                    <?php echo number_format($percentage, 1); ?>%
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="ruangan-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-hover" id="tableRuangan">
                                                <thead>
                                                    <tr>
                                                        <th>Ruangan</th>
                                                        <th>Jumlah Jadwal</th>
                                                        <th>Persentase</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $totalRuangan = array_sum(array_column($stats['per_ruangan'], 'total'));
                                                    foreach($stats['per_ruangan'] as $item): 
                                                        $percentage = $totalRuangan > 0 ? ($item['total'] / $totalRuangan) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['ruang']); ?></td>
                                                        <td><?php echo $item['total']; ?></td>
                                                        <td>
                                                            <div class="progress" style="height: 20px;">
                                                                <div class="progress-bar bg-info" role="progressbar" 
                                                                     style="width: <?php echo $percentage; ?>%;" 
                                                                     aria-valuenow="<?php echo $percentage; ?>" 
                                                                     aria-valuemin="0" 
                                                                     aria-valuemax="100">
                                                                    <?php echo number_format($percentage, 1); ?>%
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="aktivitas-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-hover" id="tableAktivitas">
                                                <thead>
                                                    <tr>
                                                        <th>Username</th>
                                                        <th>Jumlah Aktivitas</th>
                                                        <th>Persentase</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $totalAktivitas = array_sum(array_column($stats['aktivitas_user'], 'total'));
                                                    foreach($stats['aktivitas_user'] as $item): 
                                                        $percentage = $totalAktivitas > 0 ? ($item['total'] / $totalAktivitas) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="user-avatar me-2" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                                                    <?php echo strtoupper(substr($item['username'], 0, 1)); ?>
                                                                </div>
                                                                <?php echo htmlspecialchars($item['username']); ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo $item['total']; ?></td>
                                                        <td>
                                                            <div class="progress" style="height: 20px;">
                                                                <div class="progress-bar bg-warning" role="progressbar" 
                                                                     style="width: <?php echo $percentage; ?>%;" 
                                                                     aria-valuenow="<?php echo $percentage; ?>" 
                                                                     aria-valuemin="0" 
                                                                     aria-valuemax="100">
                                                                    <?php echo number_format($percentage, 1); ?>%
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ekspor Laporan -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-file-export me-2"></i>Ekspor Laporan
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-xl-3 col-md-6 mb-3">
                                        <div class="d-grid gap-2">
                                            <a href="export.php?type=jadwal" class="btn btn-success">
                                                <i class="fas fa-file-excel me-2"></i>Ekspor Data Jadwal
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6 mb-3">
                                        <div class="d-grid gap-2">
                                            <a href="export.php?type=ruangan" class="btn btn-info">
                                                <i class="fas fa-file-excel me-2"></i>Ekspor Data Ruangan
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6 mb-3">
                                        <div class="d-grid gap-2">
                                            <a href="export.php?type=aktivitas" class="btn btn-warning">
                                                <i class="fas fa-file-excel me-2"></i>Ekspor Data Aktivitas
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6 mb-3">
                                        <div class="d-grid gap-2">
                                            <a href="print_report.php" target="_blank" class="btn btn-secondary">
                                                <i class="fas fa-print me-2"></i>Cetak Laporan Lengkap
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Informasi:</strong> Data akan diekspor dalam format Excel (XLSX) dan dapat diunduh langsung.
                                        </div>
                                    </div>
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
            // Initialize DataTables
            $('#tableKelas, #tableHari, #tableRuangan, #tableAktivitas').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                },
                "pageLength": 10,
                "lengthChange": false,
                "searching": false,
                "ordering": false,
                "info": false
            });
        });

        // Chart Jadwal per Kelas
        const kelasCtx = document.getElementById('kelasChart').getContext('2d');
        const kelasLabels = <?php echo json_encode(array_column($stats['per_kelas'], 'kelas')); ?>;
        const kelasData = <?php echo json_encode(array_column($stats['per_kelas'], 'total')); ?>;
        
        new Chart(kelasCtx, {
            type: 'bar',
            data: {
                labels: kelasLabels,
                datasets: [{
                    label: 'Jumlah Jadwal',
                    data: kelasData,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Jadwal: ${context.parsed.y}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        // Chart Jadwal per Hari
        const hariCtx = document.getElementById('hariChart').getContext('2d');
        const hariLabels = <?php echo json_encode(array_column($stats['per_hari'], 'hari')); ?>;
        const hariData = <?php echo json_encode(array_column($stats['per_hari'], 'total')); ?>;
        
        new Chart(hariCtx, {
            type: 'doughnut',
            data: {
                labels: hariLabels,
                datasets: [{
                    label: 'Jumlah Jadwal',
                    data: hariData,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(54, 162, 235, 0.7)',
                        'rgba(255, 206, 86, 0.7)',
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(153, 102, 255, 0.7)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>