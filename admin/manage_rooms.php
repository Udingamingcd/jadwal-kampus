<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Fungsi untuk upload foto
function uploadFoto($file, $roomId) {
    $targetDir = "../uploads/rooms/";
    
    // Create directory if not exists
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Validasi file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Error upload file. Kode error: ' . $file['error']];
    }
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'message' => 'Format file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP.'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'Ukuran file maksimal 2MB'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'room_' . $roomId . '_' . time() . '.' . $extension;
    $targetFile = $targetDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'filename' => $filename];
    }
    
    return ['success' => false, 'message' => 'Gagal mengupload file'];
}

// Tambah ruangan
if(isset($_POST['add_room'])) {
    $nama_ruang = trim($_POST['nama_ruang']);
    $deskripsi = trim($_POST['deskripsi']);
    $kapasitas = isset($_POST['kapasitas']) ? (int)$_POST['kapasitas'] : 0;
    $fasilitas = trim($_POST['fasilitas'] ?? '');
    $foto_path = null;
    
    // Validasi
    if (empty($nama_ruang)) {
        $_SESSION['error_message'] = "Nama ruangan harus diisi";
        header('Location: manage_rooms.php');
        exit();
    }
    
    // Cek apakah nama ruangan sudah ada
    $check_query = "SELECT COUNT(*) FROM rooms WHERE nama_ruang = ?";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([$nama_ruang]);
    
    if ($check_stmt->fetchColumn() > 0) {
        $_SESSION['error_message'] = "Nama ruangan sudah digunakan";
        header('Location: manage_rooms.php');
        exit();
    }
    
    // Insert data
    $query = "INSERT INTO rooms (nama_ruang, deskripsi, kapasitas, fasilitas) VALUES (?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$nama_ruang, $deskripsi, $kapasitas, $fasilitas]);
    
    $roomId = $db->lastInsertId();
    
    // Upload foto jika ada
    if(isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $uploadResult = uploadFoto($_FILES['foto'], $roomId);
        
        if($uploadResult['success']) {
            $foto_path = $uploadResult['filename'];
            
            // Update database dengan foto path
            $updateQuery = "UPDATE rooms SET foto_path = ? WHERE id = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$foto_path, $roomId]);
        } else {
            $_SESSION['error_message'] = $uploadResult['message'];
        }
    }
    
    logActivity($db, $_SESSION['user_id'], 'Tambah Ruangan', $nama_ruang);
    $_SESSION['message'] = "Ruangan berhasil ditambahkan!";
    header('Location: manage_rooms.php');
    exit();
}

// Edit ruangan
if(isset($_POST['edit_room'])) {
    $id = $_POST['id'];
    $nama_ruang = trim($_POST['nama_ruang']);
    $deskripsi = trim($_POST['deskripsi']);
    $kapasitas = isset($_POST['kapasitas']) ? (int)$_POST['kapasitas'] : 0;
    $fasilitas = trim($_POST['fasilitas'] ?? '');
    
    // Validasi
    if (empty($nama_ruang)) {
        $_SESSION['error_message'] = "Nama ruangan harus diisi";
        header('Location: manage_rooms.php');
        exit();
    }
    
    // Cek apakah nama ruangan sudah ada (kecuali untuk dirinya sendiri)
    $check_query = "SELECT COUNT(*) FROM rooms WHERE nama_ruang = ? AND id != ?";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([$nama_ruang, $id]);
    
    if ($check_stmt->fetchColumn() > 0) {
        $_SESSION['error_message'] = "Nama ruangan sudah digunakan";
        header('Location: manage_rooms.php');
        exit();
    }
    
    $query = "UPDATE rooms SET nama_ruang = ?, deskripsi = ?, kapasitas = ?, fasilitas = ? WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$nama_ruang, $deskripsi, $kapasitas, $fasilitas, $id]);
    
    // Upload foto baru jika ada
    if(isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $uploadResult = uploadFoto($_FILES['foto'], $id);
        
        if($uploadResult['success']) {
            $foto_path = $uploadResult['filename'];
            
            // Hapus foto lama jika ada
            $get_old_foto = "SELECT foto_path FROM rooms WHERE id = ?";
            $stmt_old = $db->prepare($get_old_foto);
            $stmt_old->execute([$id]);
            $old_foto = $stmt_old->fetch(PDO::FETCH_ASSOC);
            
            if ($old_foto && $old_foto['foto_path']) {
                $old_path = "../uploads/rooms/" . $old_foto['foto_path'];
                if (file_exists($old_path)) {
                    unlink($old_path);
                }
            }
            
            // Update foto path
            $updateQuery = "UPDATE rooms SET foto_path = ? WHERE id = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$foto_path, $id]);
        } else {
            $_SESSION['error_message'] = $uploadResult['message'];
        }
    }
    
    logActivity($db, $_SESSION['user_id'], 'Edit Ruangan', $nama_ruang);
    $_SESSION['message'] = "Ruangan berhasil diperbarui!";
    header('Location: manage_rooms.php');
    exit();
}

// Hapus ruangan
if(isset($_GET['delete'])) {
    $room_id = $_GET['delete'];
    
    // Cek apakah ruangan digunakan di jadwal
    $check = "SELECT COUNT(*) FROM schedules WHERE ruang = (SELECT nama_ruang FROM rooms WHERE id = ?)";
    $stmt = $db->prepare($check);
    $stmt->execute([$room_id]);
    $used = $stmt->fetchColumn();
    
    if($used > 0) {
        $_SESSION['error_message'] = "Ruangan tidak dapat dihapus karena masih digunakan dalam jadwal!";
    } else {
        // Hapus foto jika ada
        $query = "SELECT foto_path FROM rooms WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($room && $room['foto_path']) {
            $fotoPath = "../uploads/rooms/" . $room['foto_path'];
            if(file_exists($fotoPath)) {
                unlink($fotoPath);
            }
        }
        
        $query = "DELETE FROM rooms WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$room_id]);
        
        logActivity($db, $_SESSION['user_id'], 'Hapus Ruangan', "ID: {$room_id}");
        $_SESSION['message'] = "Ruangan berhasil dihapus!";
    }
    header('Location: manage_rooms.php');
    exit();
}

// Hapus foto
if(isset($_GET['delete_foto'])) {
    $roomId = $_GET['delete_foto'];
    
    // Get foto path
    $query = "SELECT foto_path, nama_ruang FROM rooms WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($room && $room['foto_path']) {
        $fotoPath = "../uploads/rooms/" . $room['foto_path'];
        if(file_exists($fotoPath)) {
            unlink($fotoPath);
        }
        
        // Update database
        $updateQuery = "UPDATE rooms SET foto_path = NULL WHERE id = ?";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([$roomId]);
        
        logActivity($db, $_SESSION['user_id'], 'Hapus Foto Ruangan', "Room: {$room['nama_ruang']}");
        $_SESSION['message'] = "Foto ruangan berhasil dihapus!";
    }
    
    header('Location: manage_rooms.php');
    exit();
}

// Ambil semua ruangan
$query = "SELECT * FROM rooms ORDER BY nama_ruang";
$stmt = $db->prepare($query);
$stmt->execute();
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kelola Ruangan - Admin Panel</title>
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
        .foto-preview {
            max-width: 150px;
            max-height: 150px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 10px;
        }
        .upload-container {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-container:hover {
            border-color: #4a6491;
            background-color: #f8f9fa;
        }
        .upload-icon {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        .room-stat {
            background: linear-gradient(135deg, #4a6491, #2c3e50);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 15px;
        }
        .room-stat i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .room-stat .number {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .room-stat .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .fasilitas-badge {
            font-size: 0.8em;
            margin: 2px;
        }
        
        /* ========== PERBAIKAN UTAMA UNTUK MOBILE ========== */
        
        /* Tablet dan Mobile */
        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                position: fixed;
                min-height: 100vh;
                display: none;
                z-index: 1050;
                top: 0;
                left: 0;
            }
            .sidebar.mobile-show {
                display: block;
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
                width: 100%;
                overflow-x: hidden;
            }
            .mobile-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 1040;
                display: none;
            }
            .mobile-overlay.show {
                display: block;
            }
            
            /* Perbaikan navbar untuk mobile */
            .navbar-custom {
                position: sticky;
                top: 0;
                z-index: 1030;
                padding: 10px 0;
            }
            
            /* Perbaikan page header untuk mobile */
            .page-header {
                padding: 15px;
                margin: 0 -10px 15px -10px;
                width: calc(100% + 20px);
                border-radius: 0;
                overflow: hidden;
            }
            
            .page-header .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
            }
            
            .page-header .btn {
                margin-top: 10px;
                width: 100%;
            }
            
            /* Perbaikan container tabel untuk mobile */
            .table-container {
                padding: 10px;
                margin: 0 -10px;
                width: calc(100% + 20px);
                border-radius: 0;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Perbaikan grid statistics untuk mobile */
            .room-stat {
                margin-bottom: 10px;
                padding: 12px;
            }
            
            .room-stat i {
                font-size: 1.5rem;
                margin-bottom: 5px;
            }
            
            .room-stat .number {
                font-size: 1.2rem;
            }
            
            /* Perbaikan tabel untuk mobile - TEKS TIDAK TERPOTONG */
            .table-responsive {
                width: 100% !important;
                min-width: 100% !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }
            
            #roomsTable {
                width: 100% !important;
                min-width: 600px !important; /* Minimum width untuk memastikan konten tidak hancur */
                table-layout: auto;
            }
            
            /* Atur kolom dengan lebar yang sesuai */
            #roomsTable th,
            #roomsTable td {
                padding: 8px 6px !important;
                font-size: 0.85rem;
                min-width: 60px !important;
                max-width: 200px !important;
                word-break: break-word;
                overflow-wrap: break-word;
            }
            
            /* Kolom Nama Ruangan - lebih lebar untuk teks panjang */
            #roomsTable th:nth-child(2),
            #roomsTable td:nth-child(2) {
                min-width: 140px !important;
                max-width: 200px !important;
                white-space: normal;
            }
            
            /* Kolom Foto - ukuran tetap */
            #roomsTable th:nth-child(3),
            #roomsTable td:nth-child(3) {
                min-width: 100px !important;
                max-width: 120px !important;
                text-align: center;
            }
            
            /* Kolom Kapasitas - ukuran kecil */
            #roomsTable th:nth-child(5),
            #roomsTable td:nth-child(5) {
                min-width: 80px !important;
                max-width: 100px !important;
                text-align: center;
            }
            
            /* Kolom Aksi - ukuran tetap */
            #roomsTable th:last-child,
            #roomsTable td:last-child {
                min-width: 100px !important;
                max-width: 100px !important;
                text-align: center;
            }
            
            /* Kolom yang disembunyikan di mobile */
            #roomsTable th:nth-child(1),
            #roomsTable td:nth-child(1),
            #roomsTable th:nth-child(4),
            #roomsTable td:nth-child(4),
            #roomsTable th:nth-child(6),
            #roomsTable td:nth-child(6),
            #roomsTable th:nth-child(7),
            #roomsTable td:nth-child(7) {
                display: none;
            }
            
            /* Foto lebih kecil di mobile */
            .foto-preview {
                max-width: 70px;
                max-height: 70px;
            }
            
            /* Tombol lebih kecil di mobile */
            .btn {
                padding: 5px 8px;
                font-size: 0.8rem;
            }
            
            /* Pastikan tidak ada horizontal scroll di body */
            body {
                overflow-x: hidden;
                max-width: 100vw;
                width: 100%;
            }
            
            /* Modal di mobile */
            .modal-dialog {
                margin: 10px !important;
                max-width: calc(100% - 20px) !important;
            }
            
            .modal-content {
                border-radius: 10px;
            }
        }
        
        /* Perangkat sangat kecil (smartphone kecil) */
        @media (max-width: 576px) {
            .room-stat {
                margin-bottom: 8px;
            }
            
            #roomsTable {
                min-width: 500px !important;
            }
            
            #roomsTable th:nth-child(2),
            #roomsTable td:nth-child(2) {
                min-width: 120px !important;
            }
            
            .upload-container {
                padding: 15px;
            }
            
            /* Modal form layout untuk mobile */
            .modal-body .row {
                margin: 0;
            }
            
            .modal-body .col-md-6 {
                padding: 0;
                width: 100%;
            }
        }
        
        /* Perbaikan khusus untuk layar sangat kecil */
        @media (max-width: 375px) {
            .page-header h5 {
                font-size: 1rem;
            }
            
            #roomsTable {
                min-width: 450px !important;
            }
            
            .foto-preview {
                max-width: 60px;
                max-height: 60px;
            }
            
            .btn-sm {
                padding: 3px 5px;
                font-size: 0.75rem;
            }
        }
        
        /* ========== PERBAIKAN TAMBAHAN ========== */
        
        /* Pastikan konten tidak keluar dari layar */
        .container-fluid {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        /* Fix untuk modals */
        .modal {
            padding-right: 0 !important;
        }
        
        .modal.show .modal-dialog {
            transform: none;
        }
        
        /* Improved mobile menu */
        .navbar-toggler {
            border: none;
            padding: 5px;
        }
        
        .navbar-toggler:focus {
            box-shadow: none;
        }
        
        /* Mobile sidebar fixes */
        #mobileSidebar {
            z-index: 1050;
            position: relative;
        }
        
        /* Modal fixes for mobile */
        .modal-open {
            overflow: hidden;
            position: fixed;
            width: 100%;
        }
        
        .modal-backdrop {
            z-index: 1040;
        }
        
        .modal {
            z-index: 1050;
            padding-right: 0 !important;
        }
        
        /* Ensure text doesn't overflow in table cells */
        .table td {
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        
        /* Styling untuk info mobile */
        .mobile-info {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        
        /* Wrapper untuk tabel responsive */
        .table-wrapper {
            position: relative;
            overflow: hidden;
        }
        
        /* Scroll indicator untuk tabel di mobile */
        .scroll-indicator {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 20px;
            background: linear-gradient(to right, transparent, rgba(0,0,0,0.1));
            pointer-events: none;
            display: none;
        }
        
        @media (max-width: 992px) {
            .scroll-indicator {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar (Desktop) -->
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
                <a class="nav-link active" href="manage_rooms.php">
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
                        <h4 class="mb-0">Kelola Ruangan</h4>
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
                            <h5 class="mb-1">Daftar Ruangan</h5>
                            <p class="text-muted mb-0">Kelola ruangan untuk jadwal kuliah</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="fas fa-plus me-2"></i>Tambah Ruangan
                        </button>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4 g-2">
                    <div class="col-6 col-lg-3">
                        <div class="room-stat">
                            <i class="fas fa-door-open"></i>
                            <div class="number"><?php echo count($rooms); ?></div>
                            <div class="label">Total Ruangan</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="room-stat" style="background: linear-gradient(135deg, #4CAF50, #2E7D32);">
                            <i class="fas fa-camera"></i>
                            <div class="number">
                                <?php 
                                $with_photo = 0;
                                foreach ($rooms as $room) {
                                    if ($room['foto_path']) $with_photo++;
                                }
                                echo $with_photo;
                                ?>
                            </div>
                            <div class="label">Dengan Foto</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="room-stat" style="background: linear-gradient(135deg, #FF9800, #EF6C00);">
                            <i class="fas fa-users"></i>
                            <div class="number">
                                <?php 
                                $total_capacity = 0;
                                foreach ($rooms as $room) {
                                    $total_capacity += $room['kapasitas'] ?? 0;
                                }
                                echo $total_capacity;
                                ?>
                            </div>
                            <div class="label">Total Kapasitas</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="room-stat" style="background: linear-gradient(135deg, #9C27B0, #6A1B9A);">
                            <i class="fas fa-calendar-check"></i>
                            <div class="number">
                                <?php 
                                $used_rooms = [];
                                $query_used = "SELECT DISTINCT ruang FROM schedules";
                                $stmt_used = $db->prepare($query_used);
                                $stmt_used->execute();
                                $used_rooms = $stmt_used->fetchAll(PDO::FETCH_COLUMN);
                                echo count($used_rooms);
                                ?>
                            </div>
                            <div class="label">Digunakan</div>
                        </div>
                    </div>
                </div>

                <?php echo displayMessage(); ?>

                <!-- Data Table -->
                <div class="table-container">
                    <div class="table-wrapper">
                        <div class="table-responsive">
                            <table class="table table-hover" id="roomsTable">
                                <thead>
                                    <tr>
                                        <th class="d-none d-lg-table-cell">No</th>
                                        <th>Nama Ruangan</th>
                                        <th>Foto</th>
                                        <th class="d-none d-lg-table-cell">Deskripsi</th>
                                        <th>Kapasitas</th>
                                        <th class="d-none d-lg-table-cell">Fasilitas</th>
                                        <th class="d-none d-lg-table-cell">Tanggal Dibuat</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; ?>
                                    <?php foreach($rooms as $room): ?>
                                    <tr>
                                        <td class="d-none d-lg-table-cell"><?php echo $no++; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($room['nama_ruang']); ?></strong>
                                            <div class="d-lg-none mobile-info">
                                                <small>
                                                    <i class="fas fa-calendar me-1"></i><?php echo date('d/m/Y', strtotime($room['created_at'])); ?>
                                                    <?php if(!empty($room['deskripsi'])): ?>
                                                        <br><i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars(substr($room['deskripsi'], 0, 40)) . (strlen($room['deskripsi']) > 40 ? '...' : ''); ?>
                                                    <?php endif; ?>
                                                    <?php if(!empty($room['fasilitas'])): ?>
                                                        <?php 
                                                        $fasilitas = explode(',', $room['fasilitas']);
                                                        if(count($fasilitas) > 0): ?>
                                                            <br><i class="fas fa-tools me-1"></i><?php echo htmlspecialchars(trim($fasilitas[0])); ?>
                                                            <?php if(count($fasilitas) > 1): ?>
                                                                <span class="badge bg-secondary">+<?php echo count($fasilitas)-1; ?></span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if($room['foto_path']): ?>
                                                <img src="../uploads/rooms/<?php echo htmlspecialchars($room['foto_path']); ?>" 
                                                     class="foto-preview" 
                                                     alt="Foto <?php echo htmlspecialchars($room['nama_ruang']); ?>"
                                                     onclick="viewPhoto(this.src, '<?php echo htmlspecialchars($room['nama_ruang']); ?>')"
                                                     style="cursor: pointer;">
                                                <br>
                                                <a href="?delete_foto=<?php echo $room['id']; ?>" 
                                                   class="btn btn-sm btn-danger mt-1"
                                                   onclick="return confirm('Yakin hapus foto ini?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="d-none d-lg-table-cell"><?php echo htmlspecialchars($room['deskripsi']); ?></td>
                                        <td style="text-align: center;">
                                            <?php if($room['kapasitas'] > 0): ?>
                                                <span class="badge bg-info"><?php echo $room['kapasitas']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="d-none d-lg-table-cell">
                                            <?php 
                                            if (!empty($room['fasilitas'])) {
                                                $fasilitas = explode(',', $room['fasilitas']);
                                                $display_fasilitas = array_slice($fasilitas, 0, 3);
                                                foreach ($display_fasilitas as $fas) {
                                                    $fas = trim($fas);
                                                    if (!empty($fas)) {
                                                        echo '<span class="badge bg-secondary fasilitas-badge">' . htmlspecialchars($fas) . '</span> ';
                                                    }
                                                }
                                                if (count($fasilitas) > 3) {
                                                    echo '<span class="badge bg-light text-dark fasilitas-badge">+' . (count($fasilitas) - 3) . '</span>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="d-none d-lg-table-cell"><?php echo date('d/m/Y', strtotime($room['created_at'])); ?></td>
                                        <td style="text-align: center;">
                                            <button class="btn btn-sm btn-warning mb-1" onclick="editRoom(<?php echo htmlspecialchars(json_encode($room), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?delete=<?php echo $room['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin hapus ruangan ini?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-indicator"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Tambah -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Ruangan Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nama Ruangan <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_ruang" class="form-control" required 
                                           placeholder="Contoh: R.101, Lab. Komputer 1">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Kapasitas</label>
                                    <input type="number" name="kapasitas" class="form-control" 
                                           placeholder="Jumlah maksimal orang" min="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Fasilitas</label>
                                    <textarea name="fasilitas" class="form-control" rows="2" 
                                              placeholder="Pisahkan dengan koma (AC, Proyektor, Papan Tulis)"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Deskripsi</label>
                                    <textarea name="deskripsi" class="form-control" rows="4" 
                                              placeholder="Deskripsi ruangan..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Foto Ruangan</label>
                                    <div class="upload-container" onclick="document.getElementById('fotoInput').click()">
                                        <div class="upload-icon">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                        </div>
                                        <p class="text-muted mb-1">Klik untuk upload foto</p>
                                        <small class="text-muted">Format: JPG, PNG, GIF, WebP - Maksimal 2MB</small>
                                        <input type="file" name="foto" id="fotoInput" class="d-none" 
                                               accept="image/*" onchange="previewFoto(this, 'addFotoPreview')">
                                    </div>
                                    <div id="addFotoPreview" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_room" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Ruangan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nama Ruangan <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_ruang" id="edit_nama_ruang" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Kapasitas</label>
                                    <input type="number" name="kapasitas" id="edit_kapasitas" class="form-control" min="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Fasilitas</label>
                                    <textarea name="fasilitas" id="edit_fasilitas" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Deskripsi</label>
                                    <textarea name="deskripsi" id="edit_deskripsi" class="form-control" rows="4"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Foto Ruangan</label>
                                    <div id="currentFoto" class="mb-2"></div>
                                    <div class="upload-container" onclick="document.getElementById('editFotoInput').click()">
                                        <div class="upload-icon">
                                            <i class="fas fa-sync-alt"></i>
                                        </div>
                                        <p class="text-muted mb-1">Klik untuk ganti foto</p>
                                        <small class="text-muted">Biarkan kosong jika tidak ingin mengubah foto</small>
                                        <input type="file" name="foto" id="editFotoInput" class="d-none" 
                                               accept="image/*" onchange="previewFoto(this, 'editFotoPreview')">
                                    </div>
                                    <div id="editFotoPreview" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit_room" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal View Photo -->
    <div class="modal fade" id="viewPhotoModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="photoTitle">Foto Ruangan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="viewPhotoImg" src="" alt="" class="img-fluid" style="max-height: 500px;">
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
            $('#roomsTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                },
                "pageLength": 10,
                "responsive": false, // Nonaktifkan responsive DataTables karena kita custom
                "autoWidth": false,
                "scrollX": false
            });
            
            // Fungsi untuk menyesuaikan tampilan tabel
            function adjustTableForMobile() {
                const isMobile = window.innerWidth <= 992;
                const table = document.getElementById('roomsTable');
                const tableContainer = document.querySelector('.table-responsive');
                
                if (isMobile) {
                    // Pastikan tabel memiliki width yang cukup
                    if (table) {
                        table.style.minWidth = '600px';
                    }
                    
                    // Aktifkan scroll horizontal
                    if (tableContainer) {
                        tableContainer.style.overflowX = 'auto';
                        tableContainer.style.webkitOverflowScrolling = 'touch';
                    }
                } else {
                    // Reset untuk desktop
                    if (table) {
                        table.style.minWidth = '';
                    }
                    if (tableContainer) {
                        tableContainer.style.overflowX = '';
                    }
                }
            }
            
            // Panggil saat load dan resize
            adjustTableForMobile();
            window.addEventListener('resize', adjustTableForMobile);
            
            // Handle modal untuk mobile
            $(document).on('show.bs.modal', '.modal', function () {
                if ($(window).width() <= 992) {
                    $('body').addClass('modal-open');
                }
            });
            
            $(document).on('hidden.bs.modal', '.modal', function () {
                if ($(window).width() <= 992) {
                    $('body').removeClass('modal-open');
                }
            });
            
            // Inisialisasi tooltips
            $('[data-bs-toggle="tooltip"]').tooltip();
        });
        
        function editRoom(room) {
            $('#edit_id').val(room.id);
            $('#edit_nama_ruang').val(room.nama_ruang);
            $('#edit_deskripsi').val(room.deskripsi);
            $('#edit_kapasitas').val(room.kapasitas || '');
            $('#edit_fasilitas').val(room.fasilitas || '');
            
            const currentFotoDiv = document.getElementById('currentFoto');
            if (room.foto_path) {
                currentFotoDiv.innerHTML = `
                    <p class="mb-1">Foto saat ini:</p>
                    <img src="../uploads/rooms/${room.foto_path}" 
                         class="foto-preview" 
                         alt="Foto ${room.nama_ruang}"
                         onclick="viewPhoto(this.src, '${room.nama_ruang}')"
                         style="cursor: pointer;">
                `;
            } else {
                currentFotoDiv.innerHTML = '<p class="text-muted mb-1">Tidak ada foto</p>';
            }
            
            document.getElementById('editFotoPreview').innerHTML = '';
            
            $('#editModal').modal('show');
        }
        
        function previewFoto(input, previewId) {
            const preview = document.getElementById(previewId);
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                if (file.size > 2 * 1024 * 1024) {
                    alert('Ukuran file maksimal 2MB');
                    input.value = '';
                    return;
                }
                
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Format file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP.');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="border rounded p-2">
                            <img src="${e.target.result}" class="img-thumbnail" style="max-height: 150px;">
                            <small class="d-block mt-1">${file.name} (${(file.size / 1024).toFixed(1)} KB)</small>
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            }
        }
        
        function viewPhoto(src, title) {
            document.getElementById('viewPhotoImg').src = src;
            document.getElementById('photoTitle').textContent = 'Foto: ' + title;
            const modal = new bootstrap.Modal(document.getElementById('viewPhotoModal'));
            modal.show();
        }
        
        // Form validation
        document.querySelector('#addModal form').addEventListener('submit', function(e) {
            const nama_ruang = document.querySelector('#addModal input[name="nama_ruang"]').value.trim();
            if (!nama_ruang) {
                e.preventDefault();
                alert('Nama ruangan harus diisi');
                return false;
            }
            return true;
        });
        
        document.querySelector('#editModal form').addEventListener('submit', function(e) {
            const nama_ruang = document.querySelector('#editModal input[name="nama_ruang"]').value.trim();
            if (!nama_ruang) {
                e.preventDefault();
                alert('Nama ruangan harus diisi');
                return false;
            }
            return true;
        });
        
        // Fungsi untuk menunjukkan bahwa tabel bisa di-scroll horizontal di mobile
        function showScrollIndicator() {
            if (window.innerWidth <= 992) {
                const tableContainer = document.querySelector('.table-responsive');
                if (tableContainer) {
                    const hasHorizontalScroll = tableContainer.scrollWidth > tableContainer.clientWidth;
                    const scrollIndicator = document.querySelector('.scroll-indicator');
                    if (scrollIndicator) {
                        scrollIndicator.style.display = hasHorizontalScroll ? 'block' : 'none';
                    }
                }
            }
        }
        
        // Panggil fungsi showScrollIndicator
        setTimeout(showScrollIndicator, 100);
        window.addEventListener('resize', showScrollIndicator);
        
        // Tambahkan event listener untuk scroll pada tabel
        document.querySelector('.table-responsive')?.addEventListener('scroll', function() {
            const scrollIndicator = document.querySelector('.scroll-indicator');
            if (scrollIndicator) {
                const scrollLeft = this.scrollLeft;
                const maxScroll = this.scrollWidth - this.clientWidth;
                const opacity = 1 - (scrollLeft / maxScroll);
                scrollIndicator.style.opacity = opacity;
            }
        });
    </script>
</body>
</html>