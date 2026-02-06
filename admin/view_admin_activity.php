<?php
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once 'check_auth.php';
requireSuperadmin(); // Hanya superadmin yang bisa akses

$database = new Database();
$db = $database->getConnection();

// Dapatkan parameter ID admin
$admin_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Ambil data admin
$admin_query = "SELECT id, username, email, role, created_at, last_login, is_active 
                FROM users WHERE id = ?";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->execute([$admin_id]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    $_SESSION['error_message'] = "Admin tidak ditemukan!";
    header('Location: manage_users.php');
    exit();
}

// Validasi: Superadmin tidak bisa melihat aktivitas sendiri di halaman ini
if ($admin_id == $_SESSION['user_id']) {
    $_SESSION['error_message'] = "Anda tidak dapat melihat aktivitas sendiri di halaman ini. Gunakan dashboard untuk melihat aktivitas Anda.";
    header('Location: manage_users.php');
    exit();
}

// Filter
$filters = [];
if (isset($_GET['action']) && !empty($_GET['action'])) {
    $filters['action'] = sanitizeInput($_GET['action']);
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $filters['date_from'] = sanitizeInput($_GET['date_from']);
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $filters['date_to'] = sanitizeInput($_GET['date_to']);
}

// Ambil log aktivitas
$activity_logs = getUserActivityLogs($db, $admin_id, $page, $limit, $filters);
$total_logs = countUserActivityLogs($db, $admin_id, $filters);
$total_pages = ceil($total_logs / $limit);

// Ambil statistik
$activity_stats = getUserActivityStats($db, $admin_id);
$distinct_actions = getUserDistinctActions($db, $admin_id);

// Ambil aktivitas terkini (7 hari terakhir)
$recent_activity_query = "SELECT DATE(created_at) as date, COUNT(*) as count 
                          FROM activity_logs 
                          WHERE user_id = ? 
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          GROUP BY DATE(created_at)
                          ORDER BY date DESC";
$recent_stmt = $db->prepare($recent_activity_query);
$recent_stmt->execute([$admin_id]);
$recent_activity = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung aktivitas per hari
$activity_by_day = [];
foreach ($recent_activity as $activity) {
    $activity_by_day[$activity['date']] = $activity['count'];
}

// Ambil summary 30 hari
$activity_summary = getAdminActivitySummary($db, $admin_id, 30);

// Ambil top actions
$top_actions = getTopActions($db, $admin_id, 5);

// Ambil time range
$activity_range = getActivityTimeRange($db, $admin_id);

// Log audit
logAdminAudit($db, $_SESSION['user_id'], $admin_id, 'view_activity', "Viewed activity logs for admin: {$admin['username']}");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Admin - Admin Panel</title>
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
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stats-card .number {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .stats-card .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .badge-activity {
            padding: 5px 10px;
            font-size: 0.8rem;
            border-radius: 20px;
        }
        .activity-day-chart {
            height: 200px;
            display: flex;
            align-items: flex-end;
            gap: 10px;
            padding: 20px 0;
        }
        .chart-bar {
            flex: 1;
            background: linear-gradient(to top, #667eea, #764ba2);
            border-radius: 5px 5px 0 0;
            position: relative;
            min-height: 10px;
        }
        .chart-bar-label {
            position: absolute;
            bottom: -25px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.8rem;
            color: #666;
        }
        .chart-bar-value {
            position: absolute;
            top: -25px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.8rem;
            font-weight: bold;
            color: #333;
        }
        .filter-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .pagination-custom .page-link {
            border-radius: 5px;
            margin: 0 3px;
            border: none;
        }
        .pagination-custom .page-item.active .page-link {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-color: transparent;
        }
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        .breadcrumb-item a {
            color: #667eea;
            text-decoration: none;
        }
        .action-badge-tambah {
            background-color: #28a745 !important;
        }
        .action-badge-edit {
            background-color: #ffc107 !important;
            color: #000;
        }
        .action-badge-hapus {
            background-color: #dc3545 !important;
        }
        .action-badge-login {
            background-color: #007bff !important;
        }
        .action-badge-lainnya {
            background-color: #6c757d !important;
        }
        .summary-table {
            font-size: 0.9rem;
        }
        .summary-table th {
            font-weight: 600;
            background-color: #f8f9fa;
        }
        .top-actions-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .top-actions-list li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .top-actions-list li:last-child {
            border-bottom: none;
        }
        .action-count {
            font-weight: bold;
            color: #667eea;
        }
        .info-badge {
            font-size: 0.7rem;
            padding: 3px 8px;
            margin-left: 5px;
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
                        <h4 class="mb-0">Aktivitas Admin</h4>
                        <span class="badge bg-warning ms-2">Superadmin Only</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>
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
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="manage_users.php">Kelola Admin</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Aktivitas Admin</li>
                    </ol>
                </nav>

                <!-- Page Header -->
                <div class="page-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-1">Aktivitas Admin: <?php echo htmlspecialchars($admin['username'] ?? ''); ?></h5>
                            <p class="text-muted mb-0">
                                <span class="badge bg-<?php echo ($admin['role'] ?? '') == 'superadmin' ? 'danger' : 'primary'; ?>">
                                    <?php echo strtoupper($admin['role'] ?? ''); ?>
                                </span>
                                <span class="badge bg-<?php echo ($admin['is_active'] ?? false) ? 'success' : 'secondary'; ?> ms-2">
                                    <?php echo ($admin['is_active'] ?? false) ? 'AKTIF' : 'NONAKTIF'; ?>
                                </span>
                                <?php if(!empty($admin['email'])): ?>
                                    <span class="ms-2"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($admin['email'] ?? ''); ?></span>
                                <?php endif; ?>
                            </p>
                            <small class="text-muted">
                                Bergabung sejak: <?php echo !empty($admin['created_at']) ? date('d F Y', strtotime($admin['created_at'])) : '-'; ?>
                                <?php if(!empty($admin['last_login'])): ?>
                                    | Terakhir login: <?php echo date('d/m/Y H:i', strtotime($admin['last_login'])); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="col-md-4 text-end">
                            <a href="manage_users.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Kembali
                            </a>
                            <button class="btn btn-danger" onclick="clearUserActivity()">
                                <i class="fas fa-trash-alt me-2"></i>Bersihkan Log
                            </button>
                        </div>
                    </div>
                </div>

                <?php echo displayMessage(); ?>

                <!-- Warning untuk Superadmin -->
                <div class="alert alert-warning mb-4">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Informasi Keamanan</h6>
                    <p class="mb-0">Halaman ini hanya dapat diakses oleh <strong>Superadmin</strong>. Semua aktivitas Anda dalam mengakses data ini akan dicatat dalam log audit.</p>
                </div>

                <!-- Statistik Card -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="number"><?php echo $activity_stats['total_activities'] ?? 0; ?></div>
                            <div class="label">Total Aktivitas</div>
                            <i class="fas fa-history fa-2x mt-3"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card" style="background: linear-gradient(135deg, #ff6b6b, #ee5a24);">
                            <div class="number"><?php echo $activity_stats['active_days'] ?? 0; ?></div>
                            <div class="label">Hari Aktif</div>
                            <i class="fas fa-calendar-alt fa-2x mt-3"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card" style="background: linear-gradient(135deg, #1dd1a1, #10ac84);">
                            <div class="number">
                                <?php echo !empty($activity_stats['first_activity']) 
                                    ? date('d/m/Y', strtotime($activity_stats['first_activity'])) 
                                    : '-'; ?>
                            </div>
                            <div class="label">Aktivitas Pertama</div>
                            <i class="fas fa-play-circle fa-2x mt-3"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card" style="background: linear-gradient(135deg, #54a0ff, #2e86de);">
                            <div class="number">
                                <?php echo !empty($activity_stats['last_activity']) 
                                    ? date('d/m/Y', strtotime($activity_stats['last_activity'])) 
                                    : '-'; ?>
                            </div>
                            <div class="label">Aktivitas Terakhir</div>
                            <i class="fas fa-flag-checkered fa-2x mt-3"></i>
                        </div>
                    </div>
                </div>

                <!-- Info Summary -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Ringkasan Aktivitas</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm summary-table">
                                    <tbody>
                                        <tr>
                                            <td><strong>Rentang Waktu</strong></td>
                                            <td>
                                                <?php if(!empty($activity_range['first_date']) && !empty($activity_range['last_date'])): ?>
                                                    <?php echo date('d/m/Y', strtotime($activity_range['first_date'])); ?> 
                                                    - 
                                                    <?php echo date('d/m/Y', strtotime($activity_range['last_date'])); ?>
                                                    <span class="badge bg-info info-badge"><?php echo $activity_range['days_range'] ?? 0; ?> hari</span>
                                                <?php else: ?>
                                                    <span class="text-muted">Tidak ada data</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Rata-rata/Hari</strong></td>
                                            <td>
                                                <?php if(($activity_stats['active_days'] ?? 0) > 0): ?>
                                                    <?php echo round(($activity_stats['total_activities'] ?? 0) / ($activity_stats['active_days'] ?? 1), 2); ?> aktivitas/hari
                                                <?php else: ?>
                                                    <span class="text-muted">0 aktivitas/hari</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Jenis Aksi Unik</strong></td>
                                            <td>
                                                <?php echo count($distinct_actions ?? []); ?> jenis
                                                <?php if(!empty($distinct_actions)): ?>
                                                    <span class="badge bg-success info-badge"><?php echo implode(', ', array_slice($distinct_actions, 0, 3)); ?><?php echo count($distinct_actions) > 3 ? '...' : ''; ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-trophy me-2"></i>Top 5 Aksi Terbanyak</h6>
                            </div>
                            <div class="card-body">
                                <?php if(!empty($top_actions)): ?>
                                    <ul class="top-actions-list">
                                        <?php foreach($top_actions as $action): ?>
                                        <li>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span>
                                                    <i class="fas <?php echo getActivityIcon($action['action'] ?? ''); ?> me-2 text-primary"></i>
                                                    <?php echo htmlspecialchars(ucfirst($action['action'] ?? '')); ?>
                                                </span>
                                                <div>
                                                    <span class="action-count"><?php echo $action['count'] ?? 0; ?>x</span>
                                                    <small class="text-muted ms-2">
                                                        terakhir: <?php echo !empty($action['last_performed']) ? date('d/m', strtotime($action['last_performed'])) : '-'; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-center text-muted mb-0">Belum ada data aktivitas</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grafik Aktivitas 7 Hari Terakhir -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Aktivitas 7 Hari Terakhir</h6>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($recent_activity)): ?>
                            <div class="activity-day-chart">
                                <?php 
                                // Generate 7 hari terakhir
                                $max_count = !empty($recent_activity) ? max(array_column($recent_activity, 'count')) : 1;
                                for($i = 6; $i >= 0; $i--): 
                                    $date = date('Y-m-d', strtotime("-$i days"));
                                    $day_name = date('D', strtotime($date));
                                    $count = $activity_by_day[$date] ?? 0;
                                    $height = ($count / $max_count) * 100;
                                ?>
                                <div class="chart-bar" style="height: <?php echo max($height, 10); ?>%;">
                                    <div class="chart-bar-value"><?php echo $count; ?></div>
                                    <div class="chart-bar-label">
                                        <?php 
                                        $day_translation = [
                                            'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab',
                                            'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab',
                                            'Sun' => 'Min'
                                        ];
                                        echo $day_translation[$day_name] ?? $day_name; 
                                        ?><br>
                                        <?php echo date('d/m', strtotime($date)); ?>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted">Belum ada aktivitas dalam 7 hari terakhir</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="id" value="<?php echo $admin_id; ?>">
                        
                        <div class="col-md-3">
                            <label class="form-label">Jenis Aksi</label>
                            <select name="action" class="form-select">
                                <option value="">Semua Aksi</option>
                                <?php foreach(($distinct_actions ?? []) as $action): ?>
                                    <option value="<?php echo htmlspecialchars($action ?? ''); ?>" 
                                        <?php echo isset($_GET['action']) && $_GET['action'] == $action ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($action ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Dari Tanggal</label>
                            <input type="date" name="date_from" class="form-control" 
                                   value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Sampai Tanggal</label>
                            <input type="date" name="date_to" class="form-control" 
                                   value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>">
                        </div>
                        
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100 me-2">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                            <a href="?id=<?php echo $admin_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-redo"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Data Table -->
                <div class="table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Daftar Log Aktivitas</h6>
                        <span class="text-muted">
                            Menampilkan <?php echo count($activity_logs ?? []); ?> dari <?php echo $total_logs ?? 0; ?> log
                        </span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover" id="activityTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Waktu</th>
                                    <th>Aksi</th>
                                    <th>Deskripsi</th>
                                    <th>IP Address</th>
                                    <th>Device</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($activity_logs)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="fas fa-inbox fa-3x mb-3"></i><br>
                                            Tidak ada log aktivitas
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $counter = ($page - 1) * $limit + 1; ?>
                                    <?php foreach($activity_logs as $log): 
                                        $action_class = '';
                                        if (stripos($log['action'] ?? '', 'tambah') !== false) $action_class = 'action-badge-tambah';
                                        elseif (stripos($log['action'] ?? '', 'edit') !== false) $action_class = 'action-badge-edit';
                                        elseif (stripos($log['action'] ?? '', 'hapus') !== false) $action_class = 'action-badge-hapus';
                                        elseif (stripos($log['action'] ?? '', 'login') !== false) $action_class = 'action-badge-login';
                                        else $action_class = 'action-badge-lainnya';
                                    ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td>
                                            <?php echo date('d/m/Y H:i:s', strtotime($log['created_at'] ?? '')); ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo !empty($log['created_at']) ? date('l', strtotime($log['created_at'])) : ''; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $action_class; ?> badge-activity">
                                                <i class="fas <?php echo getActivityIcon($log['action'] ?? ''); ?> me-1"></i>
                                                <?php echo htmlspecialchars(ucfirst($log['action'] ?? '')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($log['description'] ?? ''); ?>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></code>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php 
                                                $user_agent = $log['user_agent'] ?? '';
                                                if (stripos($user_agent, 'Windows') !== false) {
                                                    echo '<i class="fab fa-windows me-1"></i> Windows';
                                                } elseif (stripos($user_agent, 'Mac') !== false) {
                                                    echo '<i class="fab fa-apple me-1"></i> Mac';
                                                } elseif (stripos($user_agent, 'Linux') !== false) {
                                                    echo '<i class="fab fa-linux me-1"></i> Linux';
                                                } elseif (stripos($user_agent, 'Android') !== false) {
                                                    echo '<i class="fab fa-android me-1"></i> Android';
                                                } elseif (stripos($user_agent, 'iPhone') !== false) {
                                                    echo '<i class="fas fa-mobile-alt me-1"></i> iPhone';
                                                } else {
                                                    echo '<i class="fas fa-desktop me-1"></i> Unknown';
                                                }
                                                ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination pagination-custom justify-content-center">
                            <!-- Previous Page -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?id=<?php echo $admin_id; ?>&page=<?php echo $page-1; ?><?php echo isset($_GET['action']) ? '&action='.$_GET['action'] : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from='.$_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to='.$_GET['date_to'] : ''; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page Numbers -->
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if($start_page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?id=<?php echo $admin_id; ?>&page=1">1</a></li>
                                <?php if($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" 
                                       href="?id=<?php echo $admin_id; ?>&page=<?php echo $i; ?><?php echo isset($_GET['action']) ? '&action='.$_GET['action'] : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from='.$_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to='.$_GET['date_to'] : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if($end_page < $total_pages): ?>
                                <?php if($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="?id=<?php echo $admin_id; ?>&page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>
                            
                            <!-- Next Page -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?id=<?php echo $admin_id; ?>&page=<?php echo $page+1; ?><?php echo isset($_GET['action']) ? '&action='.$_GET['action'] : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from='.$_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to='.$_GET['date_to'] : ''; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>

                <!-- Export Options -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-download me-2"></i>Ekspor Data</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="input-group mb-3">
                                    <span class="input-group-text">Format</span>
                                    <select class="form-select" id="exportFormat">
                                        <option value="csv">CSV</option>
                                        <option value="json">JSON</option>
                                        <option value="pdf">PDF</option>
                                    </select>
                                    <button class="btn btn-success" onclick="exportActivity()">
                                        <i class="fas fa-download me-2"></i>Ekspor Log
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="input-group mb-3">
                                    <span class="input-group-text">Rentang</span>
                                    <input type="date" class="form-control" id="exportDateFrom">
                                    <input type="date" class="form-control" id="exportDateTo">
                                    <button class="btn btn-outline-primary" onclick="exportCustomRange()">
                                        <i class="fas fa-calendar-alt me-2"></i>Custom Range
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="clearModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Konfirmasi Hapus Log</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus semua log aktivitas untuk admin ini?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Perhatian:</strong> Tindakan ini tidak dapat dibatalkan. Semua data log akan dihapus permanen.
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Catatan:</strong> Tindakan ini akan dicatat dalam log audit.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-danger" id="confirmClear">Ya, Hapus Semua</button>
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
            // Hanya inisialisasi DataTables jika ada data
            var table = $('#activityTable');
            var hasData = table.find('tbody tr').not(':has(td[colspan])').length > 0;
            
            if (hasData) {
                table.DataTable({
                    "paging": false,
                    "searching": true,
                    "ordering": true,
                    "info": false,
                    "language": {
                        "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                    },
                    "order": [[1, 'desc']],
                    "columnDefs": [
                        { "orderable": false, "targets": [0, 2, 3, 4, 5] }
                    ]
                });
            }
        });
        
        function clearUserActivity() {
            $('#clearModal').modal('show');
        }
        
        $('#confirmClear').click(function() {
            $.ajax({
                url: 'clear_user_activity.php',
                type: 'POST',
                data: {
                    user_id: <?php echo $admin_id; ?>,
                    csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                },
                success: function(response) {
                    try {
                        const result = JSON.parse(response);
                        if (result.success) {
                            $('#clearModal').modal('hide');
                            alert('Log aktivitas berhasil dihapus');
                            location.reload();
                        } else {
                            alert('Error: ' + result.message);
                        }
                    } catch (e) {
                        alert('Terjadi kesalahan saat memproses respons');
                    }
                },
                error: function() {
                    alert('Terjadi kesalahan jaringan');
                }
            });
        });
        
        function exportActivity() {
            const format = $('#exportFormat').val();
            const dateFrom = $('#exportDateFrom').val();
            const dateTo = $('#exportDateTo').val();
            
            let url = `export_activity.php?id=<?php echo $admin_id; ?>&format=${format}`;
            
            if (dateFrom) url += `&date_from=${dateFrom}`;
            if (dateTo) url += `&date_to=${dateTo}`;
            
            window.open(url, '_blank');
        }
        
        function exportCustomRange() {
            const dateFrom = $('#exportDateFrom').val();
            const dateTo = $('#exportDateTo').val();
            
            if (dateFrom && dateTo) {
                exportActivity();
            } else {
                alert('Harap pilih rentang tanggal yang valid');
            }
        }
        
        function toggleMobileSidebar() {
            const sidebar = document.querySelector('.mobile-sidebar');
            const overlay = document.querySelector('.mobile-overlay');
            
            if (sidebar.style.display === 'block') {
                sidebar.style.display = 'none';
                if (overlay) overlay.remove();
            } else {
                sidebar.style.display = 'block';
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
                        z-index: 1000;
                    `;
                    overlayDiv.onclick = toggleMobileSidebar;
                    document.body.appendChild(overlayDiv);
                }
            }
        }
    </script>
</body>
</html>