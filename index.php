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

// Jika hari ini weekend, default ke Senin
$hari_sekarang = date('N'); // 1=Senin, 7=Minggu
if ($hari_sekarang >= 6) { // 6=Sabtu, 7=Minggu
    $hari_sekarang = 1; // Default ke Senin
    $hari_selected = $hari_selected ?? 1; // Jika tidak ada pilihan, set ke Senin
}

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

// LOGIKA UNTUK MENDAPATKAN JADWAL BERLANGSUNG DAN BERIKUTNYA DENGAN FILTER
$jam_sekarang = date('H:i');
$hari_sekarang = date('N'); // 1=Senin, 5=Jumat
$hari_sekarang_teks = $hari_map[$hari_sekarang] ?? null;

$jadwal_berlangsung = null;
$jadwal_berikutnya = null;
$waktu_tunggu_detik = 0;
$selisih_hari = 0;
$target_hari = '';

// Jika menampilkan semua hari, cari di hari ini saja
$hari_filter = $tampil_semua_hari ? $hari_sekarang_teks : $hari_teks;

// Jika menampilkan semua kelas, set kelas_filter ke null
$kelas_filter = $tampil_semua_kelas ? null : $kelas_selected;

if ($hari_filter) {
    try {
        // Query dasar untuk mencari jadwal
        $query_base = "SELECT * FROM schedules 
                      WHERE hari = ? 
                      AND tahun_akademik = ? 
                      AND semester = ? ";
        
        // Tambahkan filter kelas jika tidak null
        $params = [$hari_filter, $tahun_akademik, $semester_aktif];
        if ($kelas_filter) {
            $query_base .= " AND kelas = ? ";
            $params[] = $kelas_filter;
        }
        
        // 1. Cari jadwal yang sedang berlangsung
        $query_berlangsung = $query_base . " AND ? BETWEEN 
                          SUBSTRING_INDEX(waktu, ' - ', 1) AND 
                          SUBSTRING_INDEX(waktu, ' - ', -1)
                      ORDER BY jam_ke
                      LIMIT 1";
        
        $params_berlangsung = array_merge($params, [$jam_sekarang]);
        $stmt_berlangsung = $db->prepare($query_berlangsung);
        $stmt_berlangsung->execute($params_berlangsung);
        $jadwal_berlangsung = $stmt_berlangsung->fetch(PDO::FETCH_ASSOC);
        
        // 2. Cari jadwal berikutnya (hari ini/filter hari dulu)
        $query_selanjutnya = $query_base . " AND SUBSTRING_INDEX(waktu, ' - ', 1) > ?
                      ORDER BY SUBSTRING_INDEX(waktu, ' - ', 1)
                      LIMIT 1";
        
        $params_selanjutnya = array_merge($params, [$jam_sekarang]);
        $stmt_selanjutnya = $db->prepare($query_selanjutnya);
        $stmt_selanjutnya->execute($params_selanjutnya);
        $jadwal_selanjutnya = $stmt_selanjutnya->fetch(PDO::FETCH_ASSOC);
        
        if ($jadwal_selanjutnya) {
            $jadwal_berikutnya = $jadwal_selanjutnya;
            $target_hari = $hari_filter;
            $selisih_hari = 0;
            
            // Hitung waktu tunggu
            if (strpos($jadwal_selanjutnya['waktu'], ' - ') !== false) {
                list($waktu_mulai, $waktu_selesai) = explode(' - ', $jadwal_selanjutnya['waktu']);
                list($jam_mulai, $menit_mulai) = explode(':', $waktu_mulai);
                list($jam_sekarang_int, $menit_sekarang_int) = explode(':', $jam_sekarang);
                
                $waktu_target = mktime($jam_mulai, $menit_mulai, 0, date('m'), date('d'), date('Y'));
                $waktu_sekarang = mktime($jam_sekarang_int, $menit_sekarang_int, 0, date('m'), date('d'), date('Y'));
                $waktu_tunggu_detik = $waktu_target - $waktu_sekarang;
            }
        } else {
            // 3. Jika tidak ada jadwal di hari filter, cari di hari berikutnya
            // Hanya jika filter bukan "semua hari"
            if (!$tampil_semua_hari) {
                $hari_order = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'];
                $current_index = array_search($hari_filter, $hari_order);
                
                for ($i = 1; $i <= 4; $i++) {
                    $next_index = ($current_index + $i) % 5;
                    $next_day = $hari_order[$next_index];
                    
                    $query_next_day = "SELECT * FROM schedules 
                              WHERE hari = ? 
                              AND tahun_akademik = ? 
                              AND semester = ? ";
                    
                    $params_next_day = [$next_day, $tahun_akademik, $semester_aktif];
                    if ($kelas_filter) {
                        $query_next_day .= " AND kelas = ? ";
                        $params_next_day[] = $kelas_filter;
                    }
                    
                    $query_next_day .= " ORDER BY SUBSTRING_INDEX(waktu, ' - ', 1)
                              LIMIT 1";
                    
                    $stmt_next_day = $db->prepare($query_next_day);
                    $stmt_next_day->execute($params_next_day);
                    $jadwal_next_day = $stmt_next_day->fetch(PDO::FETCH_ASSOC);
                    
                    if ($jadwal_next_day) {
                        $jadwal_berikutnya = $jadwal_next_day;
                        $target_hari = $next_day;
                        $selisih_hari = $i;
                        
                        // Hitung waktu tunggu
                        if (strpos($jadwal_next_day['waktu'], ' - ') !== false) {
                            list($waktu_mulai, $waktu_selesai) = explode(' - ', $jadwal_next_day['waktu']);
                            list($jam_mulai, $menit_mulai) = explode(':', $waktu_mulai);
                            list($jam_sekarang_int, $menit_sekarang_int) = explode(':', $jam_sekarang);
                            
                            $waktu_target = mktime($jam_mulai, $menit_mulai, 0, date('m'), date('d') + $selisih_hari, date('Y'));
                            $waktu_sekarang = mktime($jam_sekarang_int, $menit_sekarang_int, 0, date('m'), date('d'), date('Y'));
                            $waktu_tunggu_detik = $waktu_target - $waktu_sekarang;
                        }
                        break;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error mencari jadwal saat ini: " . $e->getMessage());
    }
}

// Logika khusus untuk "Semua Hari" - cari jadwal berlangsung di semua hari
if ($tampil_semua_hari && !$jadwal_berlangsung) {
    try {
        // Cari di semua hari untuk jadwal yang sedang berlangsung
        $query_all_days = "SELECT * FROM schedules 
                          WHERE tahun_akademik = ? 
                          AND semester = ? 
                          AND ? BETWEEN 
                              SUBSTRING_INDEX(waktu, ' - ', 1) AND 
                              SUBSTRING_INDEX(waktu, ' - ', -1) ";
        
        $params_all_days = [$tahun_akademik, $semester_aktif, $jam_sekarang];
        
        if ($kelas_filter) {
            $query_all_days .= " AND kelas = ? ";
            $params_all_days[] = $kelas_filter;
        }
        
        $query_all_days .= " ORDER BY FIELD(hari, 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'), jam_ke
                          LIMIT 1";
        
        $stmt_all_days = $db->prepare($query_all_days);
        $stmt_all_days->execute($params_all_days);
        $jadwal_berlangsung = $stmt_all_days->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error mencari jadwal semua hari: " . $e->getMessage());
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
            user-select: none;
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
            height: 100%;
            box-shadow: 0 10px 30px rgba(76, 201, 240, 0.3);
            animation: pulse-glow 2s infinite;
            display: flex;
            flex-direction: column;
        }
        
        @keyframes pulse-glow {
            0% { box-shadow: 0 0 20px rgba(76, 201, 240, 0.5); }
            50% { box-shadow: 0 0 30px rgba(76, 201, 240, 0.8); }
            100% { box-shadow: 0 0 20px rgba(76, 201, 240, 0.5); }
        }
        
        .current-jadwal-header {
            background: rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .current-jadwal-body {
            padding: 25px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .next-jadwal {
            background: linear-gradient(135deg, #3a0ca3, #7209b7);
            color: white;
            border-radius: 20px;
            overflow: hidden;
            height: 100%;
            box-shadow: 0 10px 30px rgba(58, 12, 163, 0.3);
            display: flex;
            flex-direction: column;
        }
        
        .next-jadwal-header {
            background: rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .next-jadwal-body {
            padding: 25px;
            flex: 1;
            display: flex;
            flex-direction: column;
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
        
        /* Countdown Timer Styles */
        .countdown-container {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 15px;
            padding: 15px;
            margin-top: 15px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .countdown-timer {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .countdown-unit {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 12px;
            border-radius: 10px;
            margin: 0 3px;
            min-width: 60px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        
        .countdown-unit:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .countdown-unit > div:first-child {
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .countdown-label {
            font-size: 0.8rem;
            opacity: 0.8;
            color: rgba(255, 255, 255, 0.9);
            margin-top: 3px;
        }
        
        .next-day-info {
            background: rgba(255, 193, 7, 0.2);
            border-radius: 10px;
            padding: 10px 15px;
            margin-top: 15px;
            border-left: 4px solid #ffc107;
            color: white;
        }
        
        .next-day-info strong {
            color: #ffc107;
        }
        
        /* Current Schedule Layout */
        .current-next-section .row {
            display: flex;
            flex-wrap: wrap;
            margin-left: -10px;
            margin-right: -10px;
        }
        
        .current-next-section .col-md-6 {
            padding-left: 10px;
            padding-right: 10px;
        }
        
        /* Card Height Adjustment */
        .no-ongoing-schedule,
        .no-next-schedule {
            min-height: 280px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        /* Responsive Layout */
        @media (max-width: 768px) {
            .current-next-section .row {
                flex-direction: column;
            }
            
            .current-next-section .col-md-6 {
                width: 100%;
                margin-bottom: 15px;
            }
            
            .current-jadwal,
            .next-jadwal {
                min-height: 250px;
            }
        }
        
        /* Info Box */
        .info-box {
            border-left: 4px solid var(--primary);
            background: rgba(67, 97, 238, 0.05) !important;
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
        
        /* Filter indicator */
        .filter-indicator {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 8px 15px;
            border-radius: 50px;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            margin-right: 10px;
        }
        
        .filter-indicator i {
            margin-right: 5px;
        }
        
        .filter-indicator.active {
            background: var(--success);
        }
        
        /* Current schedule filter info */
        .current-filter-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 15px;
            border-left: 3px solid var(--primary);
        }
        
        /* Highlight jadwal sesuai filter */
        .jadwal-card.filter-match {
            border-color: var(--primary);
            box-shadow: 0 0 15px rgba(67, 97, 238, 0.2);
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
            
            .current-jadwal-body,
            .next-jadwal-body {
                padding: 20px !important;
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
            
            .countdown-container {
                padding: 10px;
            }
            
            .countdown-unit {
                padding: 3px 8px;
                min-width: 40px;
                font-size: 0.9rem;
            }
            
            .filter-indicator {
                font-size: 0.8rem;
                padding: 6px 12px;
            }
            
            .current-filter-info {
                font-size: 0.9rem;
                padding: 8px;
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
            
            .countdown-unit {
                min-width: 40px !important;
                font-size: 0.8rem;
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
        
        /* Jadwal berlangsung highlight */
        .jadwal-berlangsung-highlight {
            position: relative;
            overflow: hidden;
        }
        
        .jadwal-berlangsung-highlight::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 8px;
            height: 100%;
            background: var(--success);
            border-radius: 4px 0 0 4px;
        }
        
        /* Info card dalam modal */
        .info-card {
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.1);
        }
        
        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .info-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .info-card:hover .info-icon {
            transform: scale(1.1);
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
                <button class="btn btn-primary w-100 mb-2" onclick="handleShowAllSchedule()">
                    <i class="fas fa-eye me-2"></i> Tampilkan Semua
                </button>
                <button class="btn btn-outline-secondary w-100" onclick="handleResetFilter()">
                    <i class="fas fa-undo me-2"></i> Reset Filter
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
                        <button class="btn btn-primary" onclick="handleShowAllSchedule()">
                            <i class="fas fa-eye me-2"></i> Tampilkan Semua
                        </button>
                        <button class="btn btn-outline-secondary ms-2" onclick="handleResetFilter()">
                            <i class="fas fa-undo me-2"></i> Reset Filter
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
                    <?php if ($tampil_semua_hari): ?>
                        Jadwal Saat Ini (<?php echo $hari_sekarang_teks ?? 'Libur'; ?>)
                    <?php else: ?>
                        Jadwal <?php echo $hari_teks; ?>
                    <?php endif; ?>
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
            
            <!-- Konten Jadwal - DUA KOLOM -->
            <div id="currentScheduleContent">
                <div class="row">
                    <!-- KOLOM KIRI: Jadwal Berlangsung -->
                    <div class="col-md-6 mb-3 mb-md-0">
                        <?php if ($jadwal_berlangsung): ?>
                            <div class="current-jadwal h-100">
                                <div class="current-jadwal-header">
                                    <div class="d-flex justify-content-between align-items-center w-100">
                                        <div>
                                            <h5 class="mb-0">
                                                <i class="fas fa-play-circle me-2"></i> Sedang Berlangsung
                                            </h5>
                                        </div>
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-clock me-1"></i>
                                            <span id="currentTime"><?php echo date('H:i'); ?></span>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="current-jadwal-body">
                                    <div class="row align-items-center">
                                        <div class="col-3 text-center mb-3 mb-md-0">
                                            <div class="display-4 fw-bold text-light"><?php echo htmlspecialchars($jadwal_berlangsung['jam_ke']); ?></div>
                                            <small class="text-light">Jam ke-<?php echo htmlspecialchars($jadwal_berlangsung['jam_ke']); ?></small>
                                        </div>
                                        <div class="col-9">
                                            <h5 class="text-light mb-2"><?php echo htmlspecialchars($jadwal_berlangsung['mata_kuliah']); ?></h5>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-user-tie me-2 text-light"></i>
                                                    <span class="text-light"><?php echo htmlspecialchars($jadwal_berlangsung['dosen']); ?></span>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-door-open me-2 text-light"></i>
                                                    <span class="text-light">Ruang <?php echo htmlspecialchars($jadwal_berlangsung['ruang']); ?></span>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-users me-2 text-light"></i>
                                                    <span class="text-light">Kelas <?php echo htmlspecialchars($jadwal_berlangsung['kelas']); ?></span>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-clock me-2 text-light"></i>
                                                    <span class="text-light"><?php echo htmlspecialchars($jadwal_berlangsung['waktu']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 text-end">
                                        <button class="btn btn-light btn-sm btn-detail" 
                                                data-schedule='<?php echo htmlspecialchars(json_encode($jadwal_berlangsung), ENT_QUOTES, 'UTF-8'); ?>'>
                                            <i class="fas fa-info-circle me-2"></i> Detail
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- TIDAK ADA JADWAL BERLANGSUNG -->
                            <div class="no-ongoing-schedule h-100" style="background: #f8f9fa; border-radius: 15px; padding: 30px; text-align: center; height: 100%;">
                                <i class="fas fa-clock fa-2x text-muted mb-3"></i>
                                <h5 class="text-muted mb-2">Tidak Ada Jadwal Berlangsung</h5>
                                <p class="text-muted mb-0 small">
                                    <?php if ($tampil_semua_hari): ?>
                                        Tidak ada jadwal kuliah yang sedang berlangsung untuk filter ini
                                    <?php else: ?>
                                        Tidak ada jadwal kuliah yang sedang berlangsung saat ini
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- KOLOM KANAN: Jadwal Berikutnya -->
                    <div class="col-md-6">
                        <?php if ($jadwal_berikutnya): ?>
                            <div class="next-jadwal h-100">
                                <div class="next-jadwal-header">
                                    <div class="d-flex justify-content-between align-items-center w-100">
                                        <div>
                                            <h5 class="mb-0">
                                                <i class="fas fa-clock me-2"></i> Jadwal Berikutnya
                                                <?php if ($selisih_hari > 0): ?>
                                                    <span class="badge bg-warning text-dark ms-2">
                                                        <i class="fas fa-calendar-alt me-1"></i>
                                                        <?php echo $target_hari; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="next-jadwal-body">
                                    <div class="row align-items-center">
                                        <div class="col-3 text-center mb-3 mb-md-0">
                                            <div class="display-4 fw-bold text-light"><?php echo htmlspecialchars($jadwal_berikutnya['jam_ke']); ?></div>
                                            <small class="text-light">Jam ke-<?php echo htmlspecialchars($jadwal_berikutnya['jam_ke']); ?></small>
                                        </div>
                                        <div class="col-9">
                                            <h5 class="text-light mb-2"><?php echo htmlspecialchars($jadwal_berikutnya['mata_kuliah']); ?></h5>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-user-tie me-2 text-light"></i>
                                                    <span class="text-light"><?php echo htmlspecialchars($jadwal_berikutnya['dosen']); ?></span>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-door-open me-2 text-light"></i>
                                                    <span class="text-light">Ruang <?php echo htmlspecialchars($jadwal_berikutnya['ruang']); ?></span>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-users me-2 text-light"></i>
                                                    <span class="text-light">Kelas <?php echo htmlspecialchars($jadwal_berikutnya['kelas']); ?></span>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-clock me-2 text-light"></i>
                                                    <span class="text-light"><?php echo htmlspecialchars($jadwal_berikutnya['waktu']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <!-- Jika filter semua hari dan jadwal di hari berbeda -->
                                            <?php if ($tampil_semua_hari && $selisih_hari > 0): ?>
                                            <div class="next-day-info">
                                                <i class="fas fa-calendar-alt me-2"></i>
                                                <strong>Jadwal di hari <?php echo $target_hari; ?></strong>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Countdown Timer -->
                                            <?php if ($waktu_tunggu_detik > 0): ?>
                                            <div class="countdown-container mt-3">
                                                <div class="text-center mb-2">
                                                    <small class="text-light opacity-75">
                                                        <i class="fas fa-hourglass-half me-1"></i>
                                                        Mulai dalam:
                                                    </small>
                                                </div>
                                                <div class="countdown-timer text-center text-light">
                                                    <span class="countdown-unit countdown-pulse">
                                                        <div id="countdownDays">0</div>
                                                        <div class="countdown-label">Hari</div>
                                                    </span>
                                                    <span class="countdown-unit countdown-pulse">
                                                        <div id="countdownHours">00</div>
                                                        <div class="countdown-label">Jam</div>
                                                    </span>
                                                    <span class="countdown-unit countdown-pulse">
                                                        <div id="countdownMinutes">00</div>
                                                        <div class="countdown-label">Menit</div>
                                                    </span>
                                                    <span class="countdown-unit countdown-pulse">
                                                        <div id="countdownSeconds">00</div>
                                                        <div class="countdown-label">Detik</div>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 text-end">
                                        <button class="btn btn-light btn-sm btn-detail" 
                                                data-schedule='<?php echo htmlspecialchars(json_encode($jadwal_berikutnya), ENT_QUOTES, 'UTF-8'); ?>'>
                                            <i class="fas fa-info-circle me-2"></i> Detail
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- TIDAK ADA JADWAL BERIKUTNYA -->
                            <div class="no-next-schedule h-100" style="background: #f8f9fa; border-radius: 15px; padding: 30px; text-align: center; height: 100%;">
                                <i class="fas fa-calendar-times fa-2x text-muted mb-3"></i>
                                <h5 class="text-muted mb-2">Tidak Ada Jadwal Berikutnya</h5>
                                <p class="text-muted mb-0 small">
                                    <?php if ($tampil_semua_hari): ?>
                                        Tidak ada jadwal kuliah berikutnya untuk filter ini
                                    <?php else: ?>
                                        Tidak ada jadwal kuliah berikutnya
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Info Tambahan -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="info-box bg-light rounded-3 p-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-info-circle text-primary me-3 fs-4"></i>
                                <div>
                                    <small class="text-muted d-block mb-1">Info Sistem & Filter</small>
                                    <div class="d-flex flex-wrap gap-3">
                                        <span><i class="fas fa-calendar me-1 text-primary"></i> Hari: 
                                            <?php echo $tampil_semua_hari ? 'Semua Hari' : $hari_teks; ?> 
                                            <?php echo ($hari_sekarang_teks && !$tampil_semua_hari && $hari_teks != $hari_sekarang_teks) ? ' (Hari ini: '.$hari_sekarang_teks.')' : ''; ?>
                                        </span>
                                        <span><i class="fas fa-users me-1 text-primary"></i> Kelas: 
                                            <?php echo $tampil_semua_kelas ? 'Semua Kelas' : htmlspecialchars($kelas_selected); ?>
                                        </span>
                                        <span><i class="fas fa-clock me-1 text-primary"></i> Waktu: <?php echo date('H:i'); ?></span>
                                        <span><i class="fas fa-graduation-cap me-1 text-primary"></i> Semester: <?php echo htmlspecialchars($semester_aktif); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
                <button class="btn btn-primary" onclick="handleShowAllSchedule()">
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
                            <?php 
                            // Cek apakah ini jadwal yang sedang berlangsung
                            $is_current = false;
                            if ($item['hari'] == $hari_sekarang_teks && $jadwal_berlangsung) {
                                if ($jadwal_berlangsung['id'] == $item['id']) {
                                    $is_current = true;
                                }
                            }
                            ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="jadwal-card <?php echo $is_current ? 'active jadwal-berlangsung-highlight' : ''; ?>">
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
                    <?php 
                    // Cek apakah ini jadwal yang sedang berlangsung
                    $is_current = false;
                    if ($item['hari'] == $hari_sekarang_teks && $jadwal_berlangsung) {
                        if ($jadwal_berlangsung['id'] == $item['id']) {
                            $is_current = true;
                        }
                    }
                    ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="jadwal-card <?php echo $is_current ? 'active jadwal-berlangsung-highlight' : ''; ?>">
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
                    <?php if ($tampil_semua_kelas): ?>
                        - Mode: Semua Kelas
                    <?php else: ?>
                        - Mode: Kelas <?php echo htmlspecialchars($kelas_selected); ?>
                    <?php endif; ?>
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
        // ============================================
        // FILTER MANAGEMENT SYSTEM
        // ============================================
        
        // State management untuk filter
        let filterState = {
            hari: <?php echo json_encode($hari_selected); ?>,
            semua_hari: <?php echo $tampil_semua_hari ? 'true' : 'false'; ?>,
            kelas: <?php echo json_encode($kelas_selected); ?>,
            semua_kelas: <?php echo $tampil_semua_kelas ? 'true' : 'false'; ?>,
            last_update: null
        };

        // Initialize filter state dari PHP
        function initializeFilterState() {
            // Update filter state berdasarkan URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            
            if (urlParams.has('semua_hari') && urlParams.get('semua_hari') === '1') {
                filterState.semua_hari = true;
                filterState.hari = null;
            } else if (urlParams.has('hari')) {
                filterState.hari = urlParams.get('hari');
                filterState.semua_hari = false;
            }
            
            if (urlParams.has('semua_kelas') && urlParams.get('semua_kelas') === '1') {
                filterState.semua_kelas = true;
                filterState.kelas = null;
            } else if (urlParams.has('kelas')) {
                filterState.kelas = urlParams.get('kelas');
                filterState.semua_kelas = false;
            }
            
            // Update UI berdasarkan state
            updateFilterUI();
        }

        // Update UI filter berdasarkan state
        function updateFilterUI() {
            // Reset semua tab aktif
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Update filter hari
            if (filterState.semua_hari) {
                // Aktifkan "Semua Hari" di desktop dan mobile
                document.querySelectorAll('.filter-tab[data-type="semua_hari"]').forEach(tab => {
                    tab.classList.add('active');
                });
            } else if (filterState.hari) {
                // Aktifkan tab hari yang sesuai
                document.querySelectorAll(`.filter-tab[data-type="hari"][data-value="${filterState.hari}"]`).forEach(tab => {
                    tab.classList.add('active');
                });
            }
            
            // Update filter kelas
            if (filterState.semua_kelas) {
                // Aktifkan "Semua Kelas" di desktop dan mobile
                document.querySelectorAll('.filter-tab[data-type="semua_kelas"]').forEach(tab => {
                    tab.classList.add('active');
                });
            } else if (filterState.kelas) {
                // Aktifkan tab kelas yang sesuai
                document.querySelectorAll(`.filter-tab[data-type="kelas"][data-value="${filterState.kelas}"]`).forEach(tab => {
                    tab.classList.add('active');
                });
            }
        }

        // Fungsi utama untuk menangani klik filter
        function handleFilterClick(element, filterType) {
            event.preventDefault();
            event.stopPropagation();
            
            // Ambil data dari element
            const type = element.getAttribute('data-type');
            const value = element.getAttribute('data-value');
            
            // Update filter state
            if (type === 'hari') {
                filterState.hari = value;
                filterState.semua_hari = false;
            } else if (type === 'semua_hari') {
                filterState.semua_hari = true;
                filterState.hari = null;
            } else if (type === 'kelas') {
                filterState.kelas = value;
                filterState.semua_kelas = false;
            } else if (type === 'semua_kelas') {
                filterState.semua_kelas = true;
                filterState.kelas = null;
            }
            
            // Update UI
            updateFilterUI();
            
            // Save state
            saveCurrentFilter();
            
            // Jika di mobile, tutup sidebar terlebih dahulu
            if (filterType.includes('mobile')) {
                // Tutup sidebar
                toggleSidebar();
                
                // Tunggu sidebar tertutup, lalu apply filter
                setTimeout(() => {
                    applyFilter();
                }, 400);
            } else {
                // Apply filter langsung untuk desktop
                applyFilter();
            }
        }

        // Apply filter dan reload halaman
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
            
            // Update filter info sebelum redirect
            updateFilterInfo();
            
            // Save to localStorage
            saveCurrentFilter();
            
            // Redirect dengan parameter baru
            window.location.href = 'index.php?' + params.toString();
        }

        // Save current filter ke localStorage
        function saveCurrentFilter() {
            try {
                const filterData = {
                    ...filterState,
                    last_update: new Date().toISOString()
                };
                localStorage.setItem('jadwal_filter_state', JSON.stringify(filterData));
            } catch (error) {
                console.error('Error saving filter:', error);
            }
        }

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
                    window.location.href = `index.php?${params.toString()}`;
                    return true;
                }
            }
            return false;
        }

        // Fungsi untuk update informasi filter di current section
        function updateFilterInfo() {
            const hariFilter = filterState.semua_hari ? 'Semua Hari' : 
                              (filterState.hari === '1' ? 'SENIN' :
                               filterState.hari === '2' ? 'SELASA' :
                               filterState.hari === '3' ? 'RABU' :
                               filterState.hari === '4' ? 'KAMIS' :
                               filterState.hari === '5' ? 'JUMAT' : 'Hari Ini');
            
            const kelasFilter = filterState.semua_kelas ? 'Semua Kelas' : filterState.kelas;
            
            // Update judul section
            const titleElement = document.querySelector('#currentNextSection h4');
            if (titleElement) {
                if (filterState.semua_hari) {
                    titleElement.innerHTML = `<i class="fas fa-clock me-2"></i> Jadwal Saat Ini (Semua Hari)`;
                } else {
                    titleElement.innerHTML = `<i class="fas fa-clock me-2"></i> Jadwal ${hariFilter}`;
                }
            }
            
            // Update filter display
            const filterDisplay = document.getElementById('filterDisplay');
            if (filterDisplay) {
                let html = `<div class="current-filter-info">`;
                html += `<strong>Filter Aktif:</strong> `;
                html += `<span class="badge bg-primary me-2">${filterState.semua_hari ? 'Semua Hari' : hariFilter}</span>`;
                html += `<span class="badge bg-success">${filterState.semua_kelas ? 'Semua Kelas' : kelasFilter}</span>`;
                html += `</div>`;
                filterDisplay.innerHTML = html;
            }
        }

        // ============================================
        // UI FUNCTIONS
        // ============================================

        // Tampilkan semua jadwal
        function handleShowAllSchedule() {
            filterState.semua_hari = true;
            filterState.semua_kelas = true;
            
            // Tutup sidebar jika di mobile
            if (window.innerWidth < 768) {
                toggleSidebar();
                setTimeout(() => {
                    applyFilter();
                }, 400);
            } else {
                applyFilter();
            }
        }

        // Reset filter ke default
        function handleResetFilter() {
            const hariSekarang = <?php echo date('N'); ?>;
            const kelasPertama = <?php echo json_encode($kelas_list[0] ?? 'A1'); ?>;
            
            filterState.hari = hariSekarang;
            filterState.semua_hari = false;
            filterState.kelas = kelasPertama;
            filterState.semua_kelas = false;
            
            // Tutup sidebar jika di mobile
            if (window.innerWidth < 768) {
                toggleSidebar();
                setTimeout(() => {
                    applyFilter();
                }, 400);
            } else {
                applyFilter();
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

        // ============================================
        // COUNTDOWN TIMER
        // ============================================

        <?php if ($jadwal_berikutnya && $waktu_tunggu_detik > 0): ?>
        function updateCountdownTimer() {
            // Hitung waktu target dari waktu tunggu dalam detik
            const targetTime = new Date();
            targetTime.setSeconds(targetTime.getSeconds() + <?php echo $waktu_tunggu_detik; ?>);
            
            function updateDisplay() {
                const now = new Date();
                const timeDiff = targetTime - now;
                
                if (timeDiff <= 0) {
                    // Jika sudah waktunya, refresh halaman
                    window.location.reload();
                    return;
                }
                
                // Hitung hari, jam, menit, detik
                const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((timeDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeDiff % (1000 * 60)) / 1000);
                
                // Update tampilan
                const daysElement = document.getElementById('countdownDays');
                const hoursElement = document.getElementById('countdownHours');
                const minutesElement = document.getElementById('countdownMinutes');
                const secondsElement = document.getElementById('countdownSeconds');
                
                if (daysElement) daysElement.textContent = days;
                if (hoursElement) hoursElement.textContent = hours.toString().padStart(2, '0');
                if (minutesElement) minutesElement.textContent = minutes.toString().padStart(2, '0');
                if (secondsElement) secondsElement.textContent = seconds.toString().padStart(2, '0');
                
                // Pulsing animation untuk detik terakhir
                if (days === 0 && hours === 0 && minutes === 0 && seconds < 30) {
                    secondsElement.classList.add('countdown-pulse');
                } else {
                    secondsElement.classList.remove('countdown-pulse');
                }
            }
            
            // Jalankan sekali di awal
            updateDisplay();
            
            // Update setiap detik
            setInterval(updateDisplay, 1000);
        }
        
        // Jalankan countdown timer
        updateCountdownTimer();
        <?php endif; ?>

        // ============================================
        // SCHEDULE DETAIL MODAL
        // ============================================

        // Show schedule detail
        function showScheduleDetail(schedule) {
            // Parse schedule data jika masih string JSON
            if (typeof schedule === 'string') {
                try {
                    schedule = JSON.parse(schedule);
                } catch (e) {
                    console.error('Error parsing schedule:', e);
                    return;
                }
            }
            
            // Get room data from body attribute
            let ruanganData = {};
            try {
                const ruanganAttr = document.body.getAttribute('data-ruangan');
                if (ruanganAttr) {
                    ruanganData = JSON.parse(ruanganAttr);
                }
            } catch (e) {
                console.error('Error loading room data:', e);
            }
            
            const ruang = ruanganData[schedule.ruang] || {};
            
            const modalBody = document.getElementById('scheduleDetail');
            
            // Format waktu yang lebih user-friendly
            const waktuParts = schedule.waktu.split(' - ');
            const waktuFormatted = waktuParts.length === 2 ? 
                `${waktuParts[0]} - ${waktuParts[1]}` : schedule.waktu;
            
            // Determine current status
            const now = new Date();
            const currentTime = now.getHours() * 60 + now.getMinutes();
            const [startHour, startMinute] = waktuParts[0].split(':').map(Number);
            const startTime = startHour * 60 + startMinute;
            const [endHour, endMinute] = waktuParts[1]?.split(':').map(Number) || [0, 0];
            const endTime = endHour * 60 + endMinute;
            
            let statusBadge = '';
            if (currentTime >= startTime && currentTime <= endTime) {
                statusBadge = `<span class="badge bg-success mb-3"><i class="fas fa-play-circle me-1"></i> Sedang Berlangsung</span>`;
            } else if (currentTime < startTime) {
                statusBadge = `<span class="badge bg-primary mb-3"><i class="fas fa-clock me-1"></i> Akan Datang</span>`;
            } else {
                statusBadge = `<span class="badge bg-secondary mb-3"><i class="fas fa-check-circle me-1"></i> Selesai</span>`;
            }
            
            let html = `
                <div class="schedule-detail">
                    ${statusBadge}
                    <div class="detail-header mb-4">
                        <h4 class="text-primary fw-bold mb-3">${escapeHtml(schedule.mata_kuliah)}</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="text-muted mb-2">
                                    <i class="fas fa-calendar-day me-2"></i>
                                    ${schedule.hari}, ${waktuFormatted}
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-muted mb-2">
                                    <i class="fas fa-clock me-2"></i>
                                    Jam ke-${schedule.jam_ke}
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="info-card bg-light p-3 rounded-3">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="info-icon bg-primary text-white rounded-circle p-2 me-3">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <div>
                                        <small class="text-muted d-block">Kelas</small>
                                        <strong class="text-dark fs-5">${schedule.kelas}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-card bg-light p-3 rounded-3">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="info-icon bg-success text-white rounded-circle p-2 me-3">
                                        <i class="fas fa-door-open"></i>
                                    </div>
                                    <div>
                                        <small class="text-muted d-block">Ruang</small>
                                        <strong class="text-dark fs-5">${schedule.ruang}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dosen-info mb-4 p-3 bg-primary-light rounded-3">
                        <h6 class="mb-3 d-flex align-items-center">
                            <i class="fas fa-user-tie me-2 text-primary"></i>
                            Dosen Pengampu
                        </h6>
                        <p class="mb-0 fw-semibold fs-5">${escapeHtml(schedule.dosen)}</p>
                    </div>
                    
                    ${ruang.deskripsi ? `
                    <div class="ruang-info mb-4 p-3 bg-info-light rounded-3">
                        <h6 class="mb-3 d-flex align-items-center">
                            <i class="fas fa-info-circle me-2 text-info"></i>
                            Informasi Ruangan
                        </h6>
                        <p class="mb-0">${escapeHtml(ruang.deskripsi)}</p>
                    </div>
                    ` : ''}
                    
                    ${ruang.foto_path ? `
                    <div class="ruang-photo mb-4">
                        <h6 class="mb-3 d-flex align-items-center">
                            <i class="fas fa-image me-2 text-warning"></i>
                            Foto Ruangan
                        </h6>
                        <div class="photo-container position-relative">
                            <img src="${escapeHtml(ruang.foto_path)}" 
                                 alt="Ruang ${escapeHtml(schedule.ruang)}" 
                                 class="img-fluid rounded-3 w-100" 
                                 style="max-height: 300px; object-fit: cover;"
                                 onerror="this.onerror=null; this.src='https://via.placeholder.com/800x400/4361ee/ffffff?text=RUANG+${escapeHtml(schedule.ruang)}'">
                            <div class="photo-overlay position-absolute top-0 start-0 w-100 h-100 bg-dark opacity-10 rounded-3"></div>
                        </div>
                    </div>
                    ` : ''}
                    
                    <div class="schedule-meta mt-4 pt-3 border-top">
                        <h6 class="mb-3 d-flex align-items-center">
                            <i class="fas fa-info-circle me-2"></i>
                            Informasi Akademik
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                                    <small class="text-muted">Semester</small>
                                    <strong class="${schedule.semester === 'GANJIL' ? 'text-warning' : 'text-success'}">
                                        ${schedule.semester}
                                    </strong>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                                    <small class="text-muted">Tahun Akademik</small>
                                    <strong>${schedule.tahun_akademik}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            modalBody.innerHTML = html;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('scheduleModal'));
            modal.show();
            
            // Handle modal close
            modal._element.addEventListener('hidden.bs.modal', function () {
                modalBody.innerHTML = '';
            });
        }

        // ============================================
        // UTILITY FUNCTIONS
        // ============================================

        // Utility function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Setup auto-refresh current time
        function updateCurrentTime() {
            const now = new Date();
            const currentTime = now.toLocaleTimeString('id-ID', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: false
            });
            
            const timeBadge = document.getElementById('currentTime');
            if (timeBadge) {
                timeBadge.textContent = currentTime;
            }
        }

        // Setup filter display element
        function setupFilterDisplay() {
            // Tambahkan elemen untuk menampilkan filter info
            const currentSection = document.getElementById('currentScheduleContent');
            if (currentSection && !document.getElementById('filterDisplay')) {
                const filterDiv = document.createElement('div');
                filterDiv.id = 'filterDisplay';
                currentSection.insertBefore(filterDiv, currentSection.firstChild);
                
                // Initial update
                updateFilterDisplayFromURL();
            }
        }

        function updateFilterDisplayFromURL() {
            const params = new URLSearchParams(window.location.search);
            const filterInfo = {
                hari: params.has('hari') ? 
                    (params.get('hari') === '1' ? 'SENIN' :
                     params.get('hari') === '2' ? 'SELASA' :
                     params.get('hari') === '3' ? 'RABU' :
                     params.get('hari') === '4' ? 'KAMIS' :
                     params.get('hari') === '5' ? 'JUMAT' : 'Hari Ini') : 'Hari Ini',
                kelas: params.has('kelas') ? params.get('kelas') : 
                       params.has('semua_kelas') ? 'Semua Kelas' : 'Default',
                semua_hari: params.has('semua_hari'),
                semua_kelas: params.has('semua_kelas')
            };
            
            // Update filter display jika elemen ada
            const filterDisplay = document.getElementById('filterDisplay');
            if (filterDisplay) {
                let html = `<div class="current-filter-info">`;
                html += `<strong>Filter Aktif:</strong> `;
                html += `<span class="badge bg-primary me-2">${filterInfo.semua_hari ? 'Semua Hari' : filterInfo.hari}</span>`;
                html += `<span class="badge bg-success">${filterInfo.semua_kelas ? 'Semua Kelas' : filterInfo.kelas}</span>`;
                html += `</div>`;
                filterDisplay.innerHTML = html;
            }
        }

        // Load saved preferences
        function loadPreferences() {
            const scheduleVisible = localStorage.getItem('scheduleVisible');
            if (scheduleVisible === 'false') {
                toggleCurrentSchedule(); // Collapse if saved as hidden
            }
        }

        // ============================================
        // INITIALIZATION
        // ============================================

        // Initialize on DOM loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize filter state dan UI
            initializeFilterState();
            
            // Setup filter display
            setupFilterDisplay();
            
            // Load preferences
            loadPreferences();
            
            // Update current time
            updateCurrentTime();
            
            // Update time every minute
            setInterval(updateCurrentTime, 60000);
            
            // Apply saved filter jika perlu
            applySavedFilterIfNeeded();
            
            // Setup detail buttons dengan event delegation
            document.addEventListener('click', function(e) {
                if (e.target.closest('.btn-detail')) {
                    const btn = e.target.closest('.btn-detail');
                    try {
                        const scheduleData = btn.getAttribute('data-schedule');
                        if (scheduleData) {
                            showScheduleDetail(scheduleData);
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('Terjadi kesalahan saat memuat detail');
                    }
                }
            });
            
            // Close sidebar when clicking outside on overlay
            document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);
            
            // Add keyboard support for sidebar
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const sidebar = document.getElementById('mobileSidebar');
                    if (sidebar && sidebar.classList.contains('show')) {
                        toggleSidebar();
                    }
                }
            });
        });
    </script>
</body>
</html>