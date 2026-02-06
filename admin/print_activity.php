<?php
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once 'check_auth.php';
requireSuperadmin(); // Hanya superadmin yang bisa akses

$database = new Database();
$db = $database->getConnection();

$admin_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : null;
$date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : null;

// Ambil data admin
$admin_query = "SELECT username, role, email FROM users WHERE id = ?";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->execute([$admin_id]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("Admin tidak ditemukan!");
}

// Build query
$query = "SELECT * FROM activity_logs WHERE user_id = ?";
$params = [$admin_id];

if ($date_from) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log print activity
logAdminAudit($db, $_SESSION['user_id'], $admin_id, 'print_activity', 
             "Printed activity logs for user: {$admin['username']}");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Log Aktivitas - <?php echo htmlspecialchars($admin['username']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .container {
                max-width: 100% !important;
            }
            body {
                font-size: 12px;
            }
            .table {
                font-size: 10px;
            }
            .page-break {
                page-break-before: always;
            }
        }
        @page {
            size: A4;
            margin: 20mm;
        }
        .header-print {
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .footer-print {
            border-top: 1px solid #ccc;
            padding-top: 10px;
            margin-top: 20px;
            font-size: 10px;
        }
        table {
            font-size: 11px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
        }
        .print-title {
            text-align: center;
            margin-bottom: 20px;
        }
        .print-info {
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .badge-print {
            padding: 3px 8px;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <!-- Header -->
        <div class="header-print">
            <div class="print-title">
                <h4>LAPORAN AKTIVITAS ADMIN</h4>
                <p>Sistem Manajemen Jadwal Kuliah</p>
            </div>
            
            <div class="row">
                <div class="col-6">
                    <p class="mb-1"><strong>Admin:</strong> <?php echo htmlspecialchars($admin['username']); ?></p>
                    <p class="mb-1"><strong>Role:</strong> <?php echo strtoupper($admin['role']); ?></p>
                    <?php if($admin['email']): ?>
                        <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($admin['email']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-1"><strong>Tanggal Cetak:</strong> <?php echo date('d F Y H:i:s'); ?></p>
                    <p class="mb-1"><strong>Dicetak oleh:</strong> <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                    <p class="mb-0"><strong>Role:</strong> <?php echo strtoupper($_SESSION['role']); ?></p>
                </div>
            </div>
            
            <div class="print-info mt-3">
                <p class="mb-1"><strong>Periode:</strong> 
                    <?php 
                    if ($date_from && $date_to) {
                        echo date('d F Y', strtotime($date_from)) . ' - ' . date('d F Y', strtotime($date_to));
                    } elseif ($date_from) {
                        echo 'Sejak ' . date('d F Y', strtotime($date_from));
                    } elseif ($date_to) {
                        echo 'Sampai ' . date('d F Y', strtotime($date_to));
                    } else {
                        echo 'Semua Waktu';
                    }
                    ?>
                </p>
                <p class="mb-0"><strong>Total Data:</strong> <?php echo count($logs); ?> log aktivitas</p>
            </div>
        </div>

        <!-- Table -->
        <?php if(empty($logs)): ?>
            <div class="text-center py-5">
                <p>Tidak ada data log aktivitas</p>
            </div>
        <?php else: ?>
            <table class="table table-bordered table-sm">
                <thead>
                    <tr class="table-dark">
                        <th width="5%">No</th>
                        <th width="15%">Tanggal & Waktu</th>
                        <th width="10%">Aksi</th>
                        <th width="35%">Deskripsi</th>
                        <th width="15%">IP Address</th>
                        <th width="20%">Device Info</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; ?>
                    <?php foreach($logs as $log): ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></td>
                        <td>
                            <span class="badge 
                                <?php 
                                if (stripos($log['action'], 'tambah') !== false) echo 'bg-success';
                                elseif (stripos($log['action'], 'edit') !== false) echo 'bg-warning text-dark';
                                elseif (stripos($log['action'], 'hapus') !== false) echo 'bg-danger';
                                elseif (stripos($log['action'], 'login') !== false) echo 'bg-primary';
                                else echo 'bg-secondary';
                                ?>
                                badge-print">
                                <?php echo htmlspecialchars(ucfirst($log['action'])); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($log['description']); ?></td>
                        <td><small><?php echo htmlspecialchars($log['ip_address']); ?></small></td>
                        <td>
                            <small>
                                <?php 
                                $user_agent = $log['user_agent'];
                                $device_info = 'Unknown';
                                
                                if (stripos($user_agent, 'Windows') !== false) $device_info = 'Windows';
                                elseif (stripos($user_agent, 'Mac') !== false) $device_info = 'Mac';
                                elseif (stripos($user_agent, 'Linux') !== false) $device_info = 'Linux';
                                elseif (stripos($user_agent, 'Android') !== false) $device_info = 'Android';
                                elseif (stripos($user_agent, 'iPhone') !== false) $device_info = 'iPhone';
                                
                                echo $device_info;
                                ?>
                            </small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer-print">
            <div class="row">
                <div class="col-6">
                    <p class="mb-0"><strong>Catatan:</strong></p>
                    <p class="mb-0">1. Dokumen ini dicetak dari sistem manajemen jadwal kuliah</p>
                    <p class="mb-0">2. Hanya untuk keperluan internal dan audit</p>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0">Politeknik Negeri Padang</p>
                    <p class="mb-0">Fakultas Teknik - D3 Sistem Informasi</p>
                    <p class="mb-0">PSDKU Tanah Datar</p>
                </div>
            </div>
        </div>
    </div>

    <div class="container no-print mt-3">
        <div class="text-center">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print me-2"></i>Cetak Dokumen
            </button>
            <a href="view_admin_activity.php?id=<?php echo $admin_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Kembali
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto print jika diinginkan
        window.onload = function() {
            // Uncomment untuk auto print saat halaman dibuka
            // setTimeout(function() {
            //     window.print();
            // }, 1000);
        };
        
        // Handle page breaks for printing
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr');
            let currentPage = 1;
            const maxRowsPerPage = 30;
            
            rows.forEach((row, index) => {
                if (index > 0 && index % maxRowsPerPage === 0) {
                    const pageBreak = document.createElement('tr');
                    pageBreak.className = 'page-break';
                    pageBreak.innerHTML = '<td colspan="6"></td>';
                    row.parentNode.insertBefore(pageBreak, row);
                }
            });
        });
    </script>
</body>
</html>