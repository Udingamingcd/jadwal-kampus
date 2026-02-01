<?php
// clear_logs.php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireSuperAdmin();

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $_SESSION['error_message'] = "Koneksi database gagal.";
    header('Location: dashboard.php');
    exit();
}

if (isset($_POST['confirm_clear_logs'])) {
    try {
        // Hapus semua log aktivitas
        $query = "DELETE FROM activity_logs";
        $stmt = $db->prepare($query);
        $success = $stmt->execute();
        
        if ($success) {
            logActivity($db, $_SESSION['user_id'], 'Clear All Logs', 'Semua log aktivitas dihapus');
            $_SESSION['message'] = "Semua log aktivitas berhasil dihapus!";
        } else {
            $_SESSION['error_message'] = "Gagal menghapus log aktivitas.";
        }
        
        header('Location: manage_settings.php');
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header('Location: manage_settings.php');
        exit();
    }
}

// Hitung jumlah log saat ini
$query = "SELECT COUNT(*) as total_logs FROM activity_logs";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_logs = $result['total_logs'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hapus Log Aktivitas - Admin Panel</title>
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
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 0;
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
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <?php include 'templates/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- Navbar -->
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="navbar-toggler d-md-none" type="button" onclick="toggleMobileSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">Hapus Log Aktivitas</h4>
                        <span class="badge bg-danger ms-2">Superadmin Only</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
                                <span class="badge bg-danger ms-1">Superadmin</span>
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
            <div class="container mt-4">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i> Konfirmasi Hapus Log Aktivitas</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger">
                            <h5><i class="fas fa-exclamation-circle me-2"></i> PERINGATAN!</h5>
                            <p>Tindakan ini akan menghapus <strong>SELURUH LOG AKTIVITAS</strong> dari sistem.</p>
                            <ul>
                                <li>Semua riwayat aktivitas akan hilang permanen</li>
                                <li>Data tidak dapat dikembalikan</li>
                                <li>Aksi ini hanya dapat dilakukan oleh Superadmin</li>
                                <li>Log ini sendiri tidak akan tercatat karena semua log akan dihapus</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-history me-2"></i> Statistik Log Saat Ini</h6>
                            <p>Total log yang akan dihapus: <strong class="text-danger"><?php echo $total_logs; ?> log</strong></p>
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Termasuk log login, edit jadwal, tambah ruangan, dan semua aktivitas lainnya.
                            </small>
                        </div>
                        
                        <form method="POST">
                            <div class="mb-4">
                                <label for="confirm_text" class="form-label fw-bold">Ketik "HAPUS LOG" untuk konfirmasi:</label>
                                <input type="text" class="form-control form-control-lg" id="confirm_text" name="confirm_text" 
                                       placeholder="Ketik HAPUS LOG di sini" required
                                       style="font-weight: bold; text-align: center;">
                                <div class="form-text">
                                    <i class="fas fa-shield-alt me-1"></i>
                                    Tindakan keamanan untuk mencegah penghapusan tidak sengaja
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="manage_settings.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i> Kembali ke Pengaturan
                                    </a>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-outline-danger me-2" onclick="showWarning()">
                                        <i class="fas fa-eye me-2"></i> Lihat Peringatan
                                    </button>
                                    <button type="submit" name="confirm_clear_logs" class="btn btn-danger btn-lg">
                                        <i class="fas fa-trash-alt me-2"></i> Ya, Hapus Semua Log
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Additional Info -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> Informasi</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Mengapa perlu menghapus log?</strong></p>
                        <ul>
                            <li>Mengurangi ukuran database</li>
                            <li>Menghapus riwayat aktivitas lama yang tidak diperlukan</li>
                            <li>Membersihkan data untuk keperluan audit atau reset sistem</li>
                        </ul>
                        <p class="mb-0"><strong>Alternatif:</strong> Pertimbangkan untuk mengekspor log sebelum menghapus jika data perlu disimpan untuk arsip.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Warning Modal -->
    <div class="modal fade" id="warningModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> PERINGATAN AKHIR</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-skull-crossbones fa-4x text-danger"></i>
                    </div>
                    <h4 class="text-center text-danger mb-4">ANDA YAKIN?</h4>
                    <p class="text-center">Tindakan ini akan menghapus <strong><?php echo $total_logs; ?> log aktivitas</strong> secara permanen.</p>
                    <p class="text-center">Setelah dihapus, data TIDAK DAPAT DIKEMBALIKAN.</p>
                    <div class="alert alert-warning text-center">
                        <strong><i class="fas fa-bell me-2"></i>Pastikan Anda adalah Superadmin yang berwenang!</strong>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i> Batalkan
                    </button>
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                        <i class="fas fa-check me-2"></i> Saya Mengerti
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showWarning() {
            const modal = new bootstrap.Modal(document.getElementById('warningModal'));
            modal.show();
        }
        
        function toggleMobileSidebar() {
            const sidebar = document.querySelector('.mobile-sidebar');
            const overlay = document.querySelector('.mobile-overlay');
            
            if (sidebar.style.display === 'block') {
                sidebar.style.display = 'none';
                if (overlay) overlay.remove();
            } else {
                sidebar.style.display = 'block';
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
        
        // Validasi form
        document.querySelector('form').addEventListener('submit', function(e) {
            const confirmText = document.getElementById('confirm_text').value;
            if (confirmText !== 'HAPUS LOG') {
                e.preventDefault();
                alert('Silakan ketik "HAPUS LOG" dengan huruf besar untuk konfirmasi.');
                document.getElementById('confirm_text').focus();
                document.getElementById('confirm_text').select();
                return false;
            }
            
            if (!confirm('Apakah Anda YAKIN ingin menghapus SEMUA log aktivitas?\n\nTindakan ini tidak dapat dibatalkan!')) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Focus ke input konfirmasi saat halaman dimuat
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('confirm_text').focus();
        });
    </script>
</body>
</html>