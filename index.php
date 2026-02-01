<?php
require_once 'config/database.php';
require_once 'config/helpers.php';

$database = new Database();
$db = $database->getConnection();

// Cek maintenance mode
$maintenance_mode = getSetting($db, 'maintenance_mode');
$maintenance_message = getSetting($db, 'maintenance_message');

if ($maintenance_mode == '1') {
    $is_maintenance = true;
} else {
    $is_maintenance = false;
}

// AMBIL SEMESTER AKTIF DARI SYSTEM
$activeSemester = getActiveSemester($db);
$tahun_akademik = $activeSemester['tahun_akademik'];
$semester_aktif = $activeSemester['semester'];

// Ambil setting untuk header
$institusi_nama = getSetting($db, 'institusi_nama') ?? 'Politeknik Negeri Padang';
$institusi_lokasi = getSetting($db, 'institusi_lokasi') ?? 'PSDKU Tanah Datar';
$program_studi = getSetting($db, 'program_studi') ?? 'D3 Sistem Informasi';
$fakultas = getSetting($db, 'fakultas') ?? 'Fakultas Teknik';

// Ambil semua semester untuk dropdown
$all_semesters = getAllSemesters($db);

// Ambil daftar kelas unik dari database yang memiliki jadwal
$query_kelas = "SELECT DISTINCT kelas FROM schedules 
                WHERE tahun_akademik = ? 
                AND semester = ? 
                ORDER BY kelas";
$stmt_kelas = $db->prepare($query_kelas);
$stmt_kelas->execute([$tahun_akademik, $semester_aktif]);
$kelas_list = $stmt_kelas->fetchAll(PDO::FETCH_COLUMN);

// Perbaiki jika kelas_list kosong
if (empty($kelas_list)) {
    // Ambil semua kelas yang ada di database (tanpa filter tahun/semester)
    $query_all_kelas = "SELECT DISTINCT kelas FROM schedules ORDER BY kelas";
    $stmt_all_kelas = $db->prepare($query_all_kelas);
    $stmt_all_kelas->execute();
    $kelas_list = $stmt_all_kelas->fetchAll(PDO::FETCH_COLUMN);
}

// Tentukan hari dan kelas yang dipilih (dari GET atau default)
$hari_selected = $_GET['hari'] ?? date('N'); // 1=Senin, ..., 7=Minggu
$kelas_selected = $_GET['kelas'] ?? ($kelas_list[0] ?? 'A1');
$tampil_semua_hari = isset($_GET['semua_hari']) && $_GET['semua_hari'] == '1';
$tampil_semua_kelas = isset($_GET['semua_kelas']) && $_GET['semua_kelas'] == '1';

// Pastikan kelas_selected valid
if (!in_array($kelas_selected, $kelas_list) && !empty($kelas_list)) {
    $kelas_selected = $kelas_list[0];
}

// Konversi angka hari ke teks
$hari_map = [
    1 => 'SENIN',
    2 => 'SELASA',
    3 => 'RABU',
    4 => 'KAMIS',
    5 => 'JUMAT'
];

$hari_teks = isset($_GET['hari']) ? ($hari_map[$hari_selected] ?? 'SENIN') : 'SENIN';

// Ambil jadwal berdasarkan filter
$params = [$tahun_akademik, $semester_aktif];
$query = "";

if ($tampil_semua_hari && $tampil_semua_kelas) {
    // Tampilkan semua jadwal untuk semester aktif
    $query = "SELECT * FROM schedules 
              WHERE tahun_akademik = ? 
              AND semester = ? 
              ORDER BY FIELD(hari, 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'), kelas, jam_ke";
} elseif ($tampil_semua_hari) {
    // Semua hari, kelas tertentu
    $query = "SELECT * FROM schedules 
              WHERE kelas = ? 
              AND tahun_akademik = ? 
              AND semester = ? 
              ORDER BY FIELD(hari, 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'), jam_ke";
    $params = [$kelas_selected, $tahun_akademik, $semester_aktif];
} elseif ($tampil_semua_kelas) {
    // Hari tertentu, semua kelas
    $query = "SELECT * FROM schedules 
              WHERE hari = ? 
              AND tahun_akademik = ? 
              AND semester = ? 
              ORDER BY kelas, jam_ke";
    $params = [$hari_teks, $tahun_akademik, $semester_aktif];
} else {
    // Hari dan kelas tertentu
    $query = "SELECT * FROM schedules 
              WHERE hari = ? 
              AND kelas = ? 
              AND tahun_akademik = ? 
              AND semester = ? 
              ORDER BY jam_ke";
    $params = [$hari_teks, $kelas_selected, $tahun_akademik, $semester_aktif];
}

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error mengambil jadwal: " . $e->getMessage());
    $jadwal = [];
}

// Kelompokkan jadwal berdasarkan hari (untuk tampilan semua hari)
$jadwal_per_hari = [];
if ($tampil_semua_hari && !empty($jadwal)) {
    foreach ($jadwal as $item) {
        $jadwal_per_hari[$item['hari']][] = $item;
    }
}

// Cari jadwal yang sedang berlangsung sekarang
$jam_sekarang = date('H:i');
$hari_sekarang = date('N'); // 1=Senin, 5=Jumat
$hari_sekarang_teks = $hari_map[$hari_sekarang] ?? null;

$jadwal_berlangsung = null;
$jadwal_berikutnya = null;
$waktu_tunggu = null; // dalam menit

if ($hari_sekarang_teks && $hari_sekarang_teks != 'SABTU' && $hari_sekarang_teks != 'MINGGU') {
    try {
        // Cari semua jadwal hari ini
        $query_hari_ini = "SELECT * FROM schedules 
                           WHERE hari = ? 
                           AND tahun_akademik = ? 
                           AND semester = ? 
                           ORDER BY jam_ke";
        $stmt_hari_ini = $db->prepare($query_hari_ini);
        $stmt_hari_ini->execute([$hari_sekarang_teks, $tahun_akademik, $semester_aktif]);
        $jadwal_hari_ini = $stmt_hari_ini->fetchAll(PDO::FETCH_ASSOC);
        
        // Cari yang sedang berlangsung
        foreach ($jadwal_hari_ini as $item) {
            if (strpos($item['waktu'], ' - ') !== false) {
                list($waktu_mulai, $waktu_selesai) = explode(' - ', $item['waktu']);
                if ($jam_sekarang >= $waktu_mulai && $jam_sekarang <= $waktu_selesai) {
                    $jadwal_berlangsung = $item;
                    break;
                }
            }
        }
        
        // Jika tidak ada yang berlangsung, cari jadwal berikutnya
        if (!$jadwal_berlangsung) {
            $waktu_terdekat = null;
            foreach ($jadwal_hari_ini as $item) {
                if (strpos($item['waktu'], ' - ') !== false) {
                    list($waktu_mulai, $waktu_selesai) = explode(' - ', $item['waktu']);
                    if ($jam_sekarang < $waktu_mulai) {
                        $jadwal_berikutnya = $item;
                        
                        // Hitung waktu tunggu
                        list($jam_mulai, $menit_mulai) = explode(':', $waktu_mulai);
                        $total_menit_mulai = ($jam_mulai * 60) + $menit_mulai;
                        list($jam_sekarang_int, $menit_sekarang_int) = explode(':', $jam_sekarang);
                        $total_menit_sekarang = ($jam_sekarang_int * 60) + $menit_sekarang_int;
                        $waktu_tunggu = $total_menit_mulai - $total_menit_sekarang;
                        
                        break;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error mencari jadwal saat ini: " . $e->getMessage());
    }
}

// Ambil data ruangan untuk popup
$ruangan_map = [];
try {
    $query_ruangan = "SELECT nama_ruang, foto_path, deskripsi FROM rooms";
    $stmt_ruangan = $db->prepare($query_ruangan);
    $stmt_ruangan->execute();
    $ruangan_data = $stmt_ruangan->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($ruangan_data as $ruang) {
        $ruangan_map[$ruang['nama_ruang']] = $ruang;
    }
} catch (Exception $e) {
    error_log("Error mengambil data ruangan: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Kuliah - <?php echo htmlspecialchars($institusi_nama); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #eef2ff;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #f72585;
            --dark: #1a1a2e;
            --light: #f8f9fa;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .hero-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 40px 0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .hero-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .logo-container {
            background: white;
            border-radius: 20px;
            padding: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .logo-container:hover {
            transform: translateY(-5px);
        }
        
        .header-info {
            color: white;
            text-align: center;
        }
        
        .header-info h1 {
            font-weight: 800;
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .header-info h2 {
            font-weight: 600;
            font-size: 1.5rem;
            opacity: 0.9;
        }
        
        .info-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            padding: 10px 20px;
            display: inline-block;
            margin-top: 15px;
        }
        
        .filter-section {
            margin-top: -30px;
            position: relative;
            z-index: 10;
        }
        
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }
        
        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .filter-tab {
            padding: 12px 25px;
            border-radius: 50px;
            border: 2px solid var(--primary-light);
            background: white;
            color: var(--dark);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-tab:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .filter-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }
        
        .filter-tab input {
            display: none;
        }
        
        .jadwal-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            height: 100%;
        }
        
        .jadwal-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-light);
        }
        
        .jadwal-card.active {
            border-color: var(--success);
            box-shadow: 0 0 25px rgba(76, 201, 240, 0.3);
        }
        
        .jadwal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px;
        }
        
        .jadwal-time {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 50px;
            display: inline-block;
            font-weight: 600;
        }
        
        .jadwal-body {
            padding: 25px;
        }
        
        .jadwal-mata-kuliah {
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .jadwal-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            color: #666;
        }
        
        .jadwal-info i {
            color: var(--primary);
            width: 20px;
        }
        
        .current-jadwal {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(76, 201, 240, 0.3);
        }
        
        .current-jadwal-header {
            background: rgba(0, 0, 0, 0.1);
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .current-jadwal-body {
            padding: 30px;
        }
        
        .next-jadwal {
            background: linear-gradient(135deg, #3a0ca3, #7209b7);
            color: white;
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(58, 12, 163, 0.3);
        }
        
        .next-jadwal-header {
            background: rgba(0, 0, 0, 0.1);
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .next-jadwal-body {
            padding: 30px;
        }
        
        .hari-section {
            margin-bottom: 40px;
        }
        
        .hari-title {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .jadwal-count {
            background: white;
            color: var(--primary);
            padding: 5px 15px;
            border-radius: 50px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }
        
        /* Mobile Header */
        .mobile-header {
            display: none;
        }
        
        /* Sidebar Mobile */
        .sidebar-filter {
            position: fixed;
            top: 0;
            left: -300px;
            width: 280px;
            height: 100vh;
            background: white;
            z-index: 1050;
            transition: left 0.3s ease;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }
        
        .sidebar-filter.show {
            left: 0;
        }
        
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1049;
            display: none;
        }
        
        .overlay.show {
            display: block;
        }
        
        .sidebar-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .sidebar-body {
            max-height: calc(100vh - 120px);
            overflow-y: auto;
        }
        
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            background: white;
        }
        
        .filter-toggle-btn {
            background: rgba(255, 255, 255, 0.2) !important;
            color: white !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
        }
        
        .filter-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.3) !important;
        }
        
        /* Current Schedule Toggle */
        .current-next-section {
            transition: all 0.3s ease;
        }
        
        .collapsed-section {
            opacity: 0.7;
        }
        
        .collapsed-section #currentScheduleContent {
            display: none;
        }
        
        /* Responsive Filter Tabs di Sidebar */
        #filter-hari-mobile .filter-tab,
        #filter-kelas-mobile .filter-tab {
            width: 100%;
            justify-content: flex-start;
        }
        
        /* Countdown Animation */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        #countdownTimer {
            animation: pulse 2s infinite;
        }
        
        @media (max-width: 768px) {
            .desktop-header {
                display: none !important;
            }
            
            .mobile-header {
                display: block !important;
            }
            
            .hero-header {
                padding: 20px 0 !important;
            }
            
            .filter-section {
                display: none;
            }
            
            .current-jadwal-header,
            .next-jadwal-header {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 10px;
            }
            
            .current-jadwal-body,
            .next-jadwal-body {
                padding: 20px !important;
            }
            
            .current-jadwal-body .row,
            .next-jadwal-body .row {
                flex-direction: column;
                text-align: center;
            }
            
            .jadwal-section {
                padding: 20px 0;
            }
            
            .jadwal-card {
                margin-bottom: 15px;
            }
            
            .header-info h1 {
                font-size: 1.8rem;
            }
            
            .header-info h2 {
                font-size: 1.2rem;
            }
            
            .filter-tab {
                padding: 10px 20px;
                font-size: 14px;
            }
            
            .jadwal-card {
                margin-bottom: 20px;
            }
        }
        
        @media (max-width: 576px) {
            .hero-header {
                padding: 30px 0;
            }
            
            .filter-tabs {
                flex-direction: column;
            }
            
            .filter-tab {
                width: 100%;
                justify-content: center;
            }
            
            .current-next-section {
                margin-top: 10px;
            }
            
            .no-schedule {
                padding: 20px !important;
            }
            
            #countdownTimer {
                font-size: 0.8rem;
                margin-top: 5px;
            }
        }
        
        /* Print styles */
        @media print {
            .current-next-section,
            .filter-section,
            .filter-toggle-btn,
            .sidebar-filter,
            .overlay {
                display: none !important;
            }
        }
        
        /* Tambahan CSS untuk feedback loading */
        .filter-tab:active {
            transform: scale(0.98);
        }
        
        .filter-tab.loading {
            opacity: 0.7;
            cursor: wait;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Notification styles */
        .save-notification {
            animation: slideInUp 0.3s ease-out;
        }
        
        @keyframes slideInUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body class="<?php echo $is_maintenance ? 'maintenance-active' : ''; ?>" data-ruangan='<?php echo json_encode($ruangan_map); ?>'>
    <!-- Maintenance Modal -->
    <?php if ($is_maintenance): ?>
    <div class="maintenance-modal" id="maintenanceModal">
        <div class="maintenance-content">
            <div class="maintenance-icon">
                <i class="fas fa-tools"></i>
            </div>
            <h2>Sistem Sedang Dalam Perawatan</h2>
            <p class="maintenance-message"><?php echo htmlspecialchars($maintenance_message); ?></p>
            <div class="maintenance-info">
                <i class="fas fa-clock me-2"></i>
                <span><?php echo date('d F Y, H:i'); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="hero-header">
        <div class="container">
            <div class="row align-items-center">
                <!-- Desktop Logo Kiri -->
                <div class="col-md-3 text-center mb-4 mb-md-0 desktop-header">
                    <div class="logo-container">
                        <img src="assets/images/logo_kampus.png" alt="Logo Kampus" class="img-fluid" 
                             style="max-height: 100px;"
                             onerror="this.onerror=null; this.src='https://via.placeholder.com/100x100/4361ee/ffffff?text=LOGO'">
                    </div>
                </div>
                
                <!-- Mobile Header -->
                <div class="mobile-header">
                    <div class="d-flex justify-content-between align-items-center w-100">
                        <!-- Logo Kiri Mobile -->
                        <div class="logo-container" style="width: 60px; height: 60px; padding: 8px;">
                            <img src="assets/images/logo_kampus.png" alt="Logo Kampus" class="img-fluid"
                                 onerror="this.onerror=null; this.src='https://via.placeholder.com/60x60/4361ee/ffffff?text=LOGO'">
                        </div>
                        
                        <!-- Judul Mobile -->
                        <div class="header-text text-center mx-2">
                            <h1 style="font-size: 1.2rem; font-weight: 700; color: white; margin-bottom: 5px;">
                                <?php echo htmlspecialchars($institusi_nama); ?>
                            </h1>
                            <p style="font-size: 0.8rem; color: rgba(255,255,255,0.9); margin: 0;">
                                <?php echo htmlspecialchars($institusi_lokasi); ?>
                            </p>
                        </div>
                        
                        <!-- Logo Kanan Mobile -->
                        <div class="logo-container" style="width: 60px; height: 60px; padding: 8px;">
                            <img src="assets/images/logo_jurusan.png" alt="Logo Jurusan" class="img-fluid"
                                 onerror="this.onerror=null; this.src='https://via.placeholder.com/60x60/3a0ca3/ffffff?text=SI'">
                        </div>
                    </div>
                    
                    <!-- Tombol Filter Mobile -->
                    <div class="text-center mt-3">
                        <button class="btn btn-light btn-sm filter-toggle-btn" onclick="toggleSidebar()">
                            <i class="fas fa-filter me-2"></i> Filter Jadwal
                        </button>
                    </div>
                </div>
                
                <!-- Info Tengah (Desktop) -->
                <div class="col-md-6 desktop-header">
                    <div class="header-info">
                        <h1><?php echo htmlspecialchars($institusi_nama); ?></h1>
                        <h2><?php echo htmlspecialchars($institusi_lokasi); ?></h2>
                        <div class="info-badge">
                            <i class="fas fa-graduation-cap me-2"></i>
                            <?php echo htmlspecialchars($program_studi); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Desktop Logo Kanan -->
                <div class="col-md-3 text-center mt-4 mt-md-0 desktop-header">
                    <div class="logo-container">
                        <img src="assets/images/logo_jurusan.png" alt="Logo Jurusan" class="img-fluid"
                             style="max-height: 100px;"
                             onerror="this.onerror=null; this.src='https://via.placeholder.com/100x100/3a0ca3/ffffff?text=SI'">
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Sidebar Filter Mobile -->
    <div class="sidebar-filter d-md-none" id="mobileSidebar">
        <div class="sidebar-header d-flex justify-content-between align-items-center p-3">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i> Filter Jadwal
            </h5>
            <button class="btn btn-close" onclick="toggleSidebar()"></button>
        </div>
        <div class="sidebar-body p-3">
            <!-- Filter Hari -->
            <div class="mb-4">
                <h6 class="mb-3">
                    <i class="fas fa-calendar-day me-2"></i> Pilih Hari
                </h6>
                <div class="filter-tabs" id="filter-hari-mobile">
                    <?php foreach ($hari_map as $num => $hari): ?>
                    <label class="filter-tab <?php echo (!$tampil_semua_hari && $hari_selected == $num) ? 'active' : ''; ?>" 
                           data-type="hari" data-value="<?php echo $num; ?>"
                           onclick="handleFilterClick(this, 'hari-mobile')">
                        <i class="fas fa-calendar-day"></i> <?php echo $hari; ?>
                    </label>
                    <?php endforeach; ?>
                    
                    <label class="filter-tab <?php echo $tampil_semua_hari ? 'active' : ''; ?>" 
                           data-type="semua_hari" data-value="1"
                           onclick="handleFilterClick(this, 'semua-hari-mobile')">
                        <i class="fas fa-calendar-week"></i> Semua Hari
                    </label>
                </div>
            </div>
            
            <!-- Filter Kelas -->
            <div class="mb-4">
                <h6 class="mb-3">
                    <i class="fas fa-users me-2"></i> Pilih Kelas
                </h6>
                <?php if (!empty($kelas_list)): ?>
                <div class="filter-tabs" id="filter-kelas-mobile">
                    <?php foreach ($kelas_list as $kelas): ?>
                    <label class="filter-tab <?php echo (!$tampil_semua_kelas && $kelas_selected == $kelas) ? 'active' : ''; ?>"
                           data-type="kelas" data-value="<?php echo htmlspecialchars($kelas); ?>"
                           onclick="handleFilterClick(this, 'kelas-mobile')">
                        <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($kelas); ?>
                    </label>
                    <?php endforeach; ?>
                    
                    <label class="filter-tab <?php echo $tampil_semua_kelas ? 'active' : ''; ?>"
                           data-type="semua_kelas" data-value="1"
                           onclick="handleFilterClick(this, 'semua-kelas-mobile')">
                        <i class="fas fa-layer-group"></i> Semua Kelas
                    </label>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Tidak ada kelas tersedia
                </div>
                <?php endif; ?>
            </div>
            
            <div class="sidebar-footer p-3 border-top">
                <button class="btn btn-primary w-100 mb-2" onclick="showAllSchedule()">
                    <i class="fas fa-eye me-2"></i> Tampilkan Semua
                </button>
                <button class="btn btn-outline-secondary w-100 mb-2" onclick="resetFilter()">
                    <i class="fas fa-undo me-2"></i> Reset Filter
                </button>
                <button class="btn btn-outline-warning w-100" onclick="clearSavedFilter()" 
                        data-bs-toggle="tooltip" title="Hapus filter yang disimpan di browser">
                    <i class="fas fa-trash-alt me-2"></i> Hapus Saved Filter
                </button>
            </div>
        </div>
    </div>
    <div class="overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Filter Section (Desktop) -->
    <section class="filter-section d-none d-md-block">
        <div class="container">
            <div class="filter-card">
                <div class="row align-items-center mb-4">
                    <div class="col-md-8">
                        <h3 class="mb-2">
                            <i class="fas fa-filter me-2 text-primary"></i> Filter Jadwal
                        </h3>
                        <p class="text-muted mb-0">
                            Tahun Akademik: <strong><?php echo htmlspecialchars($tahun_akademik); ?></strong> | 
                            Semester: <strong><?php echo htmlspecialchars($semester_aktif); ?></strong>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <button class="btn btn-primary" onclick="showAllSchedule()">
                            <i class="fas fa-eye me-2"></i> Tampilkan Semua
                        </button>
                        <button class="btn btn-outline-secondary ms-2" onclick="resetFilter()">
                            <i class="fas fa-undo me-2"></i> Reset Filter
                        </button>
                        <button class="btn btn-outline-warning ms-2" onclick="clearSavedFilter()" 
                                data-bs-toggle="tooltip" title="Hapus filter yang disimpan di browser">
                            <i class="fas fa-trash-alt me-2"></i> Hapus Saved
                        </button>
                    </div>
                </div>
                
                <!-- Filter Hari -->
                <div class="mb-4">
                    <h5 class="mb-3">
                        <i class="fas fa-calendar-day me-2"></i> Pilih Hari
                    </h5>
                    <div class="filter-tabs" id="filter-hari-desktop">
                        <?php foreach ($hari_map as $num => $hari): ?>
                        <label class="filter-tab <?php echo (!$tampil_semua_hari && $hari_selected == $num) ? 'active' : ''; ?>" 
                               data-type="hari" data-value="<?php echo $num; ?>"
                               onclick="handleFilterClick(this, 'hari-desktop')">
                            <i class="fas fa-calendar-day"></i> <?php echo $hari; ?>
                        </label>
                        <?php endforeach; ?>
                        
                        <label class="filter-tab <?php echo $tampil_semua_hari ? 'active' : ''; ?>" 
                               data-type="semua_hari" data-value="1"
                               onclick="handleFilterClick(this, 'semua-hari-desktop')">
                            <i class="fas fa-calendar-week"></i> Semua Hari
                        </label>
                    </div>
                </div>
                
                <!-- Filter Kelas -->
                <div>
                    <h5 class="mb-3">
                        <i class="fas fa-users me-2"></i> Pilih Kelas
                    </h5>
                    <?php if (!empty($kelas_list)): ?>
                    <div class="filter-tabs" id="filter-kelas-desktop">
                        <?php foreach ($kelas_list as $kelas): ?>
                        <label class="filter-tab <?php echo (!$tampil_semua_kelas && $kelas_selected == $kelas) ? 'active' : ''; ?>"
                               data-type="kelas" data-value="<?php echo htmlspecialchars($kelas); ?>"
                               onclick="handleFilterClick(this, 'kelas-desktop')">
                            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($kelas); ?>
                        </label>
                        <?php endforeach; ?>
                        
                        <label class="filter-tab <?php echo $tampil_semua_kelas ? 'active' : ''; ?>"
                               data-type="semua_kelas" data-value="1"
                               onclick="handleFilterClick(this, 'semua-kelas-desktop')">
                            <i class="fas fa-layer-group"></i> Semua Kelas
                        </label>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Tidak ada kelas tersedia untuk semester <?php echo htmlspecialchars($semester_aktif); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Jadwal Berlangsung/Berikutnya -->
    <div class="current-next-section py-4" id="currentNextSection">
        <div class="container">
            <!-- Header dengan toggle -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">
                    <i class="fas fa-clock me-2"></i>
                    <span id="scheduleStatusTitle">
                        <?php echo $jadwal_berlangsung ? 'Sedang Berlangsung' : 'Jadwal Berikutnya'; ?>
                    </span>
                </h4>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleCurrentSchedule()">
                        <i class="fas fa-eye-slash me-1"></i> <span id="toggleText">Sembunyikan</span>
                    </button>
                    <button class="btn btn-sm btn-outline-primary" onclick="refreshCurrentSchedule()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            
            <!-- Konten Jadwal -->
            <div id="currentScheduleContent">
                <?php if ($jadwal_berlangsung): ?>
                <div class="current-jadwal">
                    <div class="current-jadwal-header">
                        <h5 class="mb-0">
                            <i class="fas fa-play-circle me-2"></i> Sedang Berlangsung
                        </h5>
                        <span class="badge bg-light text-dark">
                            <i class="fas fa-clock me-1"></i>
                            <span id="currentTime"><?php echo date('H:i'); ?></span>
                        </span>
                    </div>
                    <div class="current-jadwal-body">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center mb-3 mb-md-0">
                                <div class="display-4 fw-bold text-light"><?php echo htmlspecialchars($jadwal_berlangsung['jam_ke']); ?></div>
                                <small>Jam ke-<?php echo htmlspecialchars($jadwal_berlangsung['jam_ke']); ?></small>
                            </div>
                            <div class="col-md-6">
                                <h3 class="text-light mb-3"><?php echo htmlspecialchars($jadwal_berlangsung['mata_kuliah']); ?></h3>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-user-tie me-3 text-light"></i>
                                            <span class="text-light"><?php echo htmlspecialchars($jadwal_berlangsung['dosen']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-door-open me-3 text-light"></i>
                                            <span class="text-light">Ruang <?php echo htmlspecialchars($jadwal_berlangsung['ruang']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <div class="mb-3">
                                    <span class="badge bg-light text-dark fs-6 p-2">
                                        <?php echo htmlspecialchars($jadwal_berlangsung['waktu']); ?>
                                    </span>
                                </div>
                                <button class="btn btn-light btn-detail" 
                                        data-schedule='<?php echo htmlspecialchars(json_encode($jadwal_berlangsung), ENT_QUOTES, 'UTF-8'); ?>'>
                                    <i class="fas fa-info-circle me-2"></i> Detail
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php elseif ($jadwal_berikutnya): ?>
                <div class="next-jadwal">
                    <div class="next-jadwal-header">
                        <h5 class="mb-0">
                            <i class="fas fa-clock me-2"></i> Jadwal Berikutnya
                            <?php if ($waktu_tunggu !== null): ?>
                            <span class="badge bg-warning text-dark ms-2" id="countdownTimer">
                                Mulai dalam: <span id="countdownValue"><?php echo $waktu_tunggu; ?></span> menit
                            </span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="next-jadwal-body">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center mb-3 mb-md-0">
                                <div class="display-4 fw-bold text-light"><?php echo htmlspecialchars($jadwal_berikutnya['jam_ke']); ?></div>
                                <small>Jam ke-<?php echo htmlspecialchars($jadwal_berikutnya['jam_ke']); ?></small>
                            </div>
                            <div class="col-md-6">
                                <h3 class="text-light mb-3"><?php echo htmlspecialchars($jadwal_berikutnya['mata_kuliah']); ?></h3>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-user-tie me-3 text-light"></i>
                                            <span class="text-light"><?php echo htmlspecialchars($jadwal_berikutnya['dosen']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-door-open me-3 text-light"></i>
                                            <span class="text-light">Ruang <?php echo htmlspecialchars($jadwal_berikutnya['ruang']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <div class="mb-3">
                                    <span class="badge bg-light text-dark fs-6 p-2">
                                        <?php echo htmlspecialchars($jadwal_berikutnya['waktu']); ?>
                                    </span>
                                </div>
                                <button class="btn btn-light btn-detail" 
                                        data-schedule='<?php echo htmlspecialchars(json_encode($jadwal_berikutnya), ENT_QUOTES, 'UTF-8'); ?>'>
                                    <i class="fas fa-info-circle me-2"></i> Detail
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-schedule" style="background: #f8f9fa; border-radius: 20px; padding: 40px; text-align: center;">
                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                    <h4 class="text-muted mb-2">Tidak ada jadwal hari ini</h4>
                    <p class="text-muted mb-0">
                        <?php echo date('l, d F Y'); ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Daftar Jadwal -->
    <section class="jadwal-section py-5">
        <div class="container">
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="fw-bold">
                        <i class="fas fa-calendar-alt me-3 text-primary"></i>
                        <?php if ($tampil_semua_hari): ?>
                            Semua Hari
                        <?php else: ?>
                            Hari <?php echo $hari_teks; ?>
                        <?php endif; ?>
                        
                        <?php if ($tampil_semua_kelas): ?>
                            - Semua Kelas
                        <?php else: ?>
                            - Kelas <?php echo htmlspecialchars($kelas_selected); ?>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="badge bg-primary fs-6 p-3">
                        <i class="fas fa-calendar-check me-2"></i>
                        <?php echo count($jadwal); ?> Jadwal
                    </span>
                </div>
            </div>

            <?php if (empty($jadwal)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3 class="text-muted mb-3">Tidak ada jadwal</h3>
                <p class="text-muted mb-4">Tidak ada jadwal kuliah untuk kriteria yang dipilih</p>
                <button class="btn btn-primary" onclick="showAllSchedule()">
                    <i class="fas fa-eye me-2"></i> Tampilkan Semua Jadwal
                </button>
            </div>
            <?php elseif ($tampil_semua_hari): ?>
                <!-- Tampilan semua hari -->
                <?php foreach ($hari_map as $num => $hari): ?>
                    <?php if (isset($jadwal_per_hari[$hari]) && !empty($jadwal_per_hari[$hari])): ?>
                    <div class="hari-section">
                        <div class="hari-title">
                            <h4 class="mb-0">
                                <i class="fas fa-calendar-day me-2"></i> <?php echo $hari; ?>
                            </h4>
                            <span class="jadwal-count">
                                <?php echo count($jadwal_per_hari[$hari]); ?> Jadwal
                            </span>
                        </div>
                        <div class="row">
                            <?php foreach ($jadwal_per_hari[$hari] as $item): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="jadwal-card <?php echo ($item['hari'] == $hari_sekarang_teks && $item['jam_ke'] == ($jadwal_berlangsung['jam_ke'] ?? '')) ? 'active' : ''; ?>">
                                    <div class="jadwal-header">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span class="jadwal-time"><?php echo htmlspecialchars($item['waktu']); ?></span>
                                            <span class="badge bg-light text-dark">Jam ke-<?php echo htmlspecialchars($item['jam_ke']); ?></span>
                                        </div>
                                        <h5 class="text-light mb-0 text-truncate"><?php echo htmlspecialchars($item['mata_kuliah']); ?></h5>
                                    </div>
                                    <div class="jadwal-body">
                                        <div class="jadwal-mata-kuliah"><?php echo htmlspecialchars($item['mata_kuliah']); ?></div>
                                        <div class="jadwal-info">
                                            <i class="fas fa-user-tie"></i>
                                            <span><?php echo htmlspecialchars($item['dosen']); ?></span>
                                        </div>
                                        <div class="jadwal-info">
                                            <i class="fas fa-door-open"></i>
                                            <span>Ruang <?php echo htmlspecialchars($item['ruang']); ?></span>
                                        </div>
                                        <div class="jadwal-info">
                                            <i class="fas fa-users"></i>
                                            <span>Kelas <?php echo htmlspecialchars($item['kelas']); ?></span>
                                        </div>
                                        <div class="mt-4">
                                            <button class="btn btn-outline-primary w-100 btn-detail" 
                                                    data-schedule='<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>'>
                                                <i class="fas fa-info-circle me-2"></i> Detail Jadwal
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Tampilan per hari -->
                <div class="row">
                    <?php foreach ($jadwal as $item): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="jadwal-card <?php echo ($item['hari'] == $hari_sekarang_teks && $item['jam_ke'] == ($jadwal_berlangsung['jam_ke'] ?? '')) ? 'active' : ''; ?>">
                            <div class="jadwal-header">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="jadwal-time"><?php echo htmlspecialchars($item['waktu']); ?></span>
                                    <span class="badge bg-light text-dark">Jam ke-<?php echo htmlspecialchars($item['jam_ke']); ?></span>
                                </div>
                                <h5 class="text-light mb-0 text-truncate"><?php echo htmlspecialchars($item['mata_kuliah']); ?></h5>
                            </div>
                            <div class="jadwal-body">
                                <div class="jadwal-mata-kuliah"><?php echo htmlspecialchars($item['mata_kuliah']); ?></div>
                                <div class="jadwal-info">
                                    <i class="fas fa-user-tie"></i>
                                    <span><?php echo htmlspecialchars($item['dosen']); ?></span>
                                </div>
                                <div class="jadwal-info">
                                    <i class="fas fa-door-open"></i>
                                    <span>Ruang <?php echo htmlspecialchars($item['ruang']); ?></span>
                                </div>
                                <div class="jadwal-info">
                                    <i class="fas fa-users"></i>
                                    <span>Kelas <?php echo htmlspecialchars($item['kelas']); ?></span>
                                </div>
                                <div class="mt-4">
                                    <button class="btn btn-outline-primary w-100 btn-detail" 
                                            data-schedule='<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>'>
                                        <i class="fas fa-info-circle me-2"></i> Detail Jadwal
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer bg-dark text-light py-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5 class="mb-3">
                        <i class="fas fa-university me-2"></i>
                        <?php echo htmlspecialchars($institusi_nama); ?>
                    </h5>
                    <p class="mb-2">
                        <i class="fas fa-map-marker-alt me-2"></i>
                        <?php echo htmlspecialchars($institusi_lokasi); ?>
                    </p>
                    <p class="mb-2">
                        <i class="fas fa-graduation-cap me-2"></i>
                        <?php echo htmlspecialchars($program_studi); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-building me-2"></i>
                        <?php echo htmlspecialchars($fakultas); ?>
                    </p>
                </div>
                <div class="col-md-6">
                    <h5 class="mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        Informasi Sistem
                    </h5>
                    <p class="mb-2">
                        <i class="fas fa-calendar me-2"></i>
                        Tahun Akademik: <?php echo htmlspecialchars($tahun_akademik); ?>
                    </p>
                    <p class="mb-2">
                        <i class="fas fa-book me-2"></i>
                        Semester: <?php echo htmlspecialchars($semester_aktif); ?>
                    </p>
                    <p class="mb-2">
                        <i class="fas fa-clock me-2"></i>
                        Update Terakhir: <?php echo date('d/m/Y H:i'); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-database me-2"></i>
                        Total Jadwal: <?php echo count($jadwal); ?>
                    </p>
                </div>
            </div>
            <hr class="my-4 bg-light">
            <div class="text-center">
                <p class="mb-2">
                    © <?php echo date('Y'); ?> Sistem Informasi Jadwal Kuliah v4.0
                </p>
                <small class="text-light opacity-75">
                    Sistem menampilkan <?php echo count($jadwal); ?> jadwal untuk semester <?php echo htmlspecialchars($semester_aktif); ?> <?php echo htmlspecialchars($tahun_akademik); ?>
                </small>
            </div>
        </div>
    </footer>

    <!-- Modal Detail Jadwal -->
    <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i> Detail Jadwal
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="scheduleDetail">
                    <!-- Detail akan diisi oleh JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i> Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        // State management untuk filter
        let filterState = {
            hari: null,
            semua_hari: false,
            kelas: null,
            semua_kelas: false,
            last_update: null
        };

        // Load saved filter dari localStorage
        function loadSavedFilter() {
            try {
                const saved = localStorage.getItem('jadwal_filter_state');
                if (saved) {
                    const parsed = JSON.parse(saved);
                    // Cek jika data masih fresh (kurang dari 7 hari)
                    const lastUpdate = new Date(parsed.last_update);
                    const now = new Date();
                    const diffDays = (now - lastUpdate) / (1000 * 60 * 60 * 24);
                    
                    if (diffDays < 7) {
                        filterState = parsed;
                        console.log('Loaded saved filter:', filterState);
                        return true;
                    } else {
                        localStorage.removeItem('jadwal_filter_state');
                    }
                }
            } catch (error) {
                console.error('Error loading saved filter:', error);
            }
            return false;
        }

        // Save current filter ke localStorage
        function saveCurrentFilter() {
            try {
                const filterData = {
                    ...filterState,
                    last_update: new Date().toISOString()
                };
                localStorage.setItem('jadwal_filter_state', JSON.stringify(filterData));
                console.log('Saved filter:', filterData);
                
                // Tampilkan notifikasi
                showSaveNotification();
            } catch (error) {
                console.error('Error saving filter:', error);
            }
        }

        // Tampilkan notifikasi penyimpanan
        function showSaveNotification() {
            const notification = document.createElement('div');
            notification.className = 'position-fixed bottom-0 end-0 m-3 p-3 bg-success text-white rounded-3 shadow-lg save-notification';
            notification.style.zIndex = '9999';
            notification.style.maxWidth = '300px';
            notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-save me-2"></i>
                    <span>Filter tersimpan untuk sesi berikutnya</span>
                    <button class="btn-close btn-close-white ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            document.body.appendChild(notification);
            
            // Auto remove setelah 3 detik
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }

        // Apply saved filter jika tidak ada parameter URL
        function applySavedFilterIfNeeded() {
            const urlParams = new URLSearchParams(window.location.search);
            const hasFilterParams = urlParams.has('hari') || urlParams.has('semua_hari') || 
                                   urlParams.has('kelas') || urlParams.has('semua_kelas');
            
            // Jika tidak ada parameter di URL dan ada saved filter
            if (!hasFilterParams && loadSavedFilter()) {
                const params = new URLSearchParams();
                
                if (filterState.semua_hari) {
                    params.append('semua_hari', '1');
                } else if (filterState.hari) {
                    params.append('hari', filterState.hari);
                }
                
                if (filterState.semua_kelas) {
                    params.append('semua_kelas', '1');
                } else if (filterState.kelas) {
                    params.append('kelas', filterState.kelas);
                }
                
                // Redirect ke saved filter
                if (params.toString()) {
                    console.log('Redirecting to saved filter:', params.toString());
                    window.location.href = `index.php?${params.toString()}`;
                    return true;
                }
            }
            return false;
        }

        // Fungsi untuk menangani klik filter (DESKTOP & MOBILE)
        function handleFilterClick(element, filterType) {
            event.preventDefault();
            event.stopPropagation();
            
            // Tentukan grup berdasarkan filter type
            let groupId;
            if (filterType.includes('hari')) {
                groupId = filterType.includes('mobile') ? 'filter-hari-mobile' : 'filter-hari-desktop';
            } else if (filterType.includes('kelas')) {
                groupId = filterType.includes('mobile') ? 'filter-kelas-mobile' : 'filter-kelas-desktop';
            }
            
            // Remove active class from all tabs in the same group
            if (groupId) {
                document.querySelectorAll(`#${groupId} .filter-tab`).forEach(tab => {
                    tab.classList.remove('active');
                });
            }
            
            // Add active class to clicked tab
            element.classList.add('active');
            
            // Update filter state dari UI
            updateFilterStateFromUI();
            
            // Apply filter
            applyFilter();
        }

        // Update filter state dari UI
        function updateFilterStateFromUI() {
            // Cari tab aktif untuk desktop dulu, jika tidak ada cari mobile
            let activeHariTab = document.querySelector('#filter-hari-desktop .filter-tab.active');
            if (!activeHariTab) {
                activeHariTab = document.querySelector('#filter-hari-mobile .filter-tab.active');
            }
            
            let activeKelasTab = document.querySelector('#filter-kelas-desktop .filter-tab.active');
            if (!activeKelasTab) {
                activeKelasTab = document.querySelector('#filter-kelas-mobile .filter-tab.active');
            }
            
            // Reset state
            filterState.semua_hari = false;
            filterState.hari = null;
            filterState.semua_kelas = false;
            filterState.kelas = null;
            
            // Update hari state
            if (activeHariTab) {
                const type = activeHariTab.getAttribute('data-type');
                if (type === 'semua_hari') {
                    filterState.semua_hari = true;
                } else {
                    filterState.hari = activeHariTab.getAttribute('data-value');
                }
            }
            
            // Update kelas state
            if (activeKelasTab) {
                const type = activeKelasTab.getAttribute('data-type');
                if (type === 'semua_kelas') {
                    filterState.semua_kelas = true;
                } else {
                    filterState.kelas = activeKelasTab.getAttribute('data-value');
                }
            }
        }

        // Fungsi untuk apply filter
        function applyFilter() {
            const params = new URLSearchParams();
            
            // Tambahkan parameter hari
            if (filterState.semua_hari) {
                params.append('semua_hari', '1');
            } else if (filterState.hari) {
                params.append('hari', filterState.hari);
            }
            
            // Tambahkan parameter kelas
            if (filterState.semua_kelas) {
                params.append('semua_kelas', '1');
            } else if (filterState.kelas) {
                params.append('kelas', filterState.kelas);
            }
            
            // Save to localStorage
            saveCurrentFilter();
            
            // Redirect dengan parameter baru
            window.location.href = 'index.php?' + params.toString();
        }

        // Fungsi untuk reset filter ke default
        function resetFilter() {
            const hariSekarang = <?php echo date('N'); ?>;
            const kelasPertama = <?php echo json_encode($kelas_list[0] ?? 'SI-2A'); ?>;
            
            window.location.href = `index.php?hari=${hariSekarang}&kelas=${encodeURIComponent(kelasPertama)}`;
        }

        // Fungsi untuk tampilkan semua jadwal
        function showAllSchedule() {
            // Set state untuk semua filter
            filterState.semua_hari = true;
            filterState.semua_kelas = true;
            saveCurrentFilter();
            
            // Redirect
            window.location.href = 'index.php?semua_hari=1&semua_kelas=1';
        }

        // Clear saved filter saja tanpa redirect
        function clearSavedFilter() {
            if (confirm('Hapus filter yang disimpan? Filter saat ini akan tetap aktif.')) {
                localStorage.removeItem('jadwal_filter_state');
                
                // Tampilkan notifikasi
                const notification = document.createElement('div');
                notification.className = 'position-fixed bottom-0 end-0 m-3 p-3 bg-success text-white rounded-3 shadow-lg save-notification';
                notification.style.zIndex = '9999';
                notification.style.maxWidth = '300px';
                notification.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check me-2"></i>
                        <span>Filter yang disimpan telah dihapus</span>
                        <button class="btn-close btn-close-white ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                    </div>
                `;
                document.body.appendChild(notification);
                
                // Auto remove setelah 3 detik
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 3000);
            }
        }

        // Toggle Sidebar Mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
            
            // Prevent body scroll when sidebar is open
            document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
        }

        // Close sidebar when clicking outside on overlay
        document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);

        // Toggle current schedule section
        let isScheduleVisible = true;
        function toggleCurrentSchedule() {
            const section = document.getElementById('currentNextSection');
            const toggleText = document.getElementById('toggleText');
            
            section.classList.toggle('collapsed-section');
            
            if (section.classList.contains('collapsed-section')) {
                toggleText.textContent = 'Tampilkan';
                isScheduleVisible = false;
            } else {
                toggleText.textContent = 'Sembunyikan';
                isScheduleVisible = true;
            }
            
            // Save preference to localStorage
            localStorage.setItem('scheduleVisible', isScheduleVisible);
        }

        // Refresh current schedule
        function refreshCurrentSchedule() {
            const refreshBtn = event.target.closest('button');
            if (refreshBtn) {
                refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                refreshBtn.disabled = true;
                
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            }
        }

        // Update countdown timer
        function updateCountdownTimer() {
            const countdownElement = document.getElementById('countdownValue');
            if (countdownElement) {
                let minutes = parseInt(countdownElement.textContent);
                if (minutes > 0) {
                    minutes--;
                    countdownElement.textContent = minutes;
                    
                    // Update title every minute
                    if (minutes % 5 === 0 || minutes < 5) {
                        document.getElementById('countdownTimer').innerHTML = 
                            `Mulai dalam: <span id="countdownValue">${minutes}</span> menit`;
                    }
                } else {
                    // Auto-refresh when countdown reaches 0
                    window.location.reload();
                }
            }
        }

        // Load saved preference
        function loadPreferences() {
            const scheduleVisible = localStorage.getItem('scheduleVisible');
            if (scheduleVisible === 'false') {
                toggleCurrentSchedule(); // Collapse if saved as hidden
            }
        }

        // Utility function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Show schedule detail
        function showScheduleDetail(schedule) {
            const ruang = <?php echo json_encode($ruangan_map); ?>[schedule.ruang] || {};
            
            const modalBody = document.getElementById('scheduleDetail');
            
            // Determine status
            const now = new Date();
            const currentTime = now.getHours() * 60 + now.getMinutes();
            const [startHour, startMinute] = schedule.waktu.split(' - ')[0].split(':').map(Number);
            const startTime = startHour * 60 + startMinute;
            const [endHour, endMinute] = schedule.waktu.split(' - ')[1]?.split(':').map(Number) || [0, 0];
            const endTime = endHour * 60 + endMinute;
            
            let statusBadge = '';
            if (currentTime >= startTime && currentTime <= endTime) {
                statusBadge = '<span class="badge bg-success mb-3">Sedang Berlangsung</span>';
            } else if (currentTime < startTime) {
                statusBadge = '<span class="badge bg-primary mb-3">Akan Datang</span>';
            } else {
                statusBadge = '<span class="badge bg-secondary mb-3">Selesai</span>';
            }
            
            let html = `
                <div class="schedule-detail">
                    ${statusBadge}
                    <div class="mb-4">
                        <h3 class="text-primary fw-bold mb-3">${escapeHtml(schedule.mata_kuliah)}</h3>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-calendar-day me-3 text-primary"></i>
                                    <div>
                                        <small class="text-muted d-block">Hari</small>
                                        <strong>${schedule.hari}</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-clock me-3 text-primary"></i>
                                    <div>
                                        <small class="text-muted d-block">Waktu</small>
                                        <strong>${schedule.waktu}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card border-0 bg-light p-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary text-white rounded-circle p-2 me-3">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div>
                                        <small class="text-muted d-block">Kelas</small>
                                        <strong class="fs-5">${schedule.kelas}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 bg-light p-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-success text-white rounded-circle p-2 me-3">
                                        <i class="fas fa-door-open"></i>
                                    </div>
                                    <div>
                                        <small class="text-muted d-block">Ruang</small>
                                        <strong class="fs-5">${schedule.ruang}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card border-0 bg-primary-light p-3 mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-user-tie me-2"></i> Dosen Pengampu
                        </h6>
                        <p class="fs-5 fw-semibold mb-0">${escapeHtml(schedule.dosen)}</p>
                    </div>
                    
                    ${ruang.deskripsi ? `
                    <div class="card border-0 bg-info-light p-3 mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-info-circle me-2"></i> Informasi Ruangan
                        </h6>
                        <p class="mb-0">${escapeHtml(ruang.deskripsi)}</p>
                    </div>
                    ` : ''}
                    
                    ${ruang.foto_path ? `
                    <div class="mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-image me-2"></i> Foto Ruangan
                        </h6>
                        <img src="${escapeHtml(ruang.foto_path)}" 
                             alt="Ruang ${escapeHtml(schedule.ruang)}" 
                             class="img-fluid rounded-3 w-100"
                             style="max-height: 300px; object-fit: cover;"
                             onerror="this.onerror=null; this.src='https://via.placeholder.com/800x400/4361ee/ffffff?text=RUANG+${escapeHtml(schedule.ruang)}'">
                    </div>
                    ` : ''}
                </div>
            `;
            
            modalBody.innerHTML = html;
            
            const modal = new bootstrap.Modal(document.getElementById('scheduleModal'));
            modal.show();
        }

        // Setup detail buttons dengan event delegation
        document.addEventListener('click', function(e) {
            if (e.target.closest('.btn-detail')) {
                const btn = e.target.closest('.btn-detail');
                try {
                    const scheduleData = JSON.parse(btn.getAttribute('data-schedule'));
                    showScheduleDetail(scheduleData);
                } catch (error) {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan saat memuat detail');
                }
            }
        });

        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = `${hours}:${minutes}`;
            }
        }

        // Initialize on DOM loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize state berdasarkan URL
            const urlParams = new URLSearchParams(window.location.search);
            
            if (urlParams.has('semua_hari')) {
                filterState.semua_hari = true;
            } else if (urlParams.has('hari')) {
                filterState.hari = urlParams.get('hari');
                filterState.semua_hari = false;
            }
            
            if (urlParams.has('semua_kelas')) {
                filterState.semua_kelas = true;
            } else if (urlParams.has('kelas')) {
                filterState.kelas = urlParams.get('kelas');
                filterState.semua_kelas = false;
            }
            
            // Save current filter state
            saveCurrentFilter();
            
            // Setup event listeners lainnya...
            loadPreferences();
            updateCurrentTime();
            
            if (document.getElementById('countdownValue')) {
                setInterval(updateCountdownTimer, 60000);
            }
            
            // Update time every minute
            setInterval(updateCurrentTime, 60000);
        });
    </script>
</body>
</html>