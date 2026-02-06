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
    if (!isset($_GET['hari'])) {
        $hari_selected = 1; // Jika tidak ada pilihan, set ke Senin
    }
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

// ========================================================
// PERUBAHAN PENTING: LOGIKA JADWAL BERIKUTNYA
// ========================================================
$jam_sekarang = date('H:i');
$hari_sekarang = date('N'); // 1=Senin, 5=Jumat
$hari_sekarang_teks = $hari_map[$hari_sekarang] ?? null;

$jadwal_berlangsung = null;
$jadwal_berikutnya = null;
$waktu_tunggu_detik = 0;
$selisih_hari = 0;
$target_hari = '';

// PERUBAHAN 1: UNTUK JADWAL BERIKUTNYA, SELALU GUNAKAN HARI SAAT INI
// Tidak peduli filter hari yang dipilih, jadwal berikutnya selalu dicari dari hari ini
$hari_filter_berikutnya = $hari_sekarang_teks;

// Jika menampilkan semua kelas, set kelas_filter ke null
$kelas_filter = $tampil_semua_kelas ? null : $kelas_selected;

// LOGIKA JADWAL BERLANGSUNG (tetap seperti sebelumnya)
if ($hari_filter_berikutnya) {
    try {
        // Query dasar untuk mencari jadwal
        $query_base = "SELECT * FROM schedules 
                      WHERE hari = ? 
                      AND tahun_akademik = ? 
                      AND semester = ? ";
        
        // Tambahkan filter kelas jika tidak null
        $params = [$hari_filter_berikutnya, $tahun_akademik, $semester_aktif];
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
        
        // PERUBAHAN 2: JADWAL BERIKUTNYA DICARI HANYA BERDASARKAN KELAS
        // Mulai pencarian dari hari ini, lalu hari-hari berikutnya
        $hari_order = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'];
        $current_index = array_search($hari_filter_berikutnya, $hari_order);
        
        // Loop maksimal 5 hari (Senin-Jumat)
        for ($i = 0; $i < 5; $i++) {
            $next_index = ($current_index + $i) % 5;
            $next_day = $hari_order[$next_index];
            
            $query_next_day = "SELECT * FROM schedules 
                      WHERE hari = ? 
                      AND tahun_akademik = ? 
                      AND semester = ? ";
            
            $params_next_day = [$next_day, $tahun_akademik, $semester_aktif];
            
            // Filter kelas hanya jika kelas tertentu dipilih
            if ($kelas_filter) {
                $query_next_day .= " AND kelas = ? ";
                $params_next_day[] = $kelas_filter;
            }
            
            // Jika ini hari yang sama dengan hari ini, cari yang waktu mulai > jam sekarang
            if ($i == 0) {
                $query_next_day .= " AND SUBSTRING_INDEX(waktu, ' - ', 1) > ?
                          ORDER BY SUBSTRING_INDEX(waktu, ' - ', 1)
                          LIMIT 1";
                $params_next_day[] = $jam_sekarang;
            } else {
                // Jika hari berbeda, ambil jadwal pertama di hari tersebut
                $query_next_day .= " ORDER BY SUBSTRING_INDEX(waktu, ' - ', 1)
                          LIMIT 1";
            }
            
            $stmt_next_day = $db->prepare($query_next_day);
            $stmt_next_day->execute($params_next_day);
            $jadwal_next_day = $stmt_next_day->fetch(PDO::FETCH_ASSOC);
            
            if ($jadwal_next_day) {
                $jadwal_berikutnya = $jadwal_next_day;
                $target_hari = $next_day;
                $selisih_hari = $i;
                
                // Hitung waktu tunggu
                if (strpos($jadwal_next_day['waktu'], ' - ') !== false) {
                    $waktu_parts = explode(' - ', $jadwal_next_day['waktu']);
                    if (count($waktu_parts) >= 2) {
                        $waktu_mulai = $waktu_parts[0];
                        
                        // Parsing waktu_mulai dengan validasi
                        $waktu_mulai_parts = explode(':', $waktu_mulai);
                        if (count($waktu_mulai_parts) >= 2) {
                            $jam_mulai = (int) $waktu_mulai_parts[0];
                            $menit_mulai = (int) $waktu_mulai_parts[1];
                        } else {
                            $jam_mulai = 0;
                            $menit_mulai = 0;
                        }
                        
                        // Waktu sekarang
                        $jam_sekarang_clean = str_replace('.', ':', $jam_sekarang);
                        $jam_sekarang_parts = explode(':', $jam_sekarang_clean);
                        if (count($jam_sekarang_parts) >= 2) {
                            $jam_sekarang_int = (int) $jam_sekarang_parts[0];
                            $menit_sekarang_int = (int) $jam_sekarang_parts[1];
                        } else {
                            $jam_sekarang_int = (int) date('H');
                            $menit_sekarang_int = (int) date('i');
                        }
                        
                        // Hitung waktu target (hari ini + selisih hari)
                        $waktu_target = mktime($jam_mulai, $menit_mulai, 0, 
                                              date('m'), date('d') + $selisih_hari, date('Y'));
                        $waktu_sekarang = mktime($jam_sekarang_int, $menit_sekarang_int, 0, 
                                                date('m'), date('d'), date('Y'));
                        $waktu_tunggu_detik = $waktu_target - $waktu_sekarang;
                        
                        // Pastikan waktu tunggu tidak negatif
                        if ($waktu_tunggu_detik < 0) {
                            $waktu_tunggu_detik = 0;
                        }
                    }
                }
                break; // Keluar dari loop setelah menemukan jadwal berikutnya
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

// JavaScript variables untuk default values
$currentDay = date('N');
$firstClass = !empty($kelas_list) ? $kelas_list[0] : 'A1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Kuliah - <?php echo htmlspecialchars($institusi_nama); ?></title>
    <link rel="icon" type="image/png" href="assets/images/si.png">
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
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-color: white;
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
        
        /* Countdown Timer Styles - DIPERBAIKI */
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
        
        /* Filter info display */
        .filter-info-display {
            margin-top: 15px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
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
        
        /* Animation for filter tabs */
        @keyframes filterPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .filter-tab.active {
            animation: filterPulse 0.5s ease;
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
                        <button class="btn btn-light btn-sm filter-toggle-btn">
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
            <button class="btn btn-close btn-close-white"></button>
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
                           data-type="hari" data-value="<?php echo $num; ?>">
                        <i class="fas fa-calendar-day"></i> <?php echo $hari; ?>
                    </label>
                    <?php endforeach; ?>
                    
                    <label class="filter-tab <?php echo $tampil_semua_hari ? 'active' : ''; ?>" 
                           data-type="semua_hari" data-value="1">
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
                           data-type="kelas" data-value="<?php echo htmlspecialchars($kelas); ?>">
                        <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($kelas); ?>
                    </label>
                    <?php endforeach; ?>
                    
                    <label class="filter-tab <?php echo $tampil_semua_kelas ? 'active' : ''; ?>"
                           data-type="semua_kelas" data-value="1">
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
    <div class="overlay" id="sidebarOverlay"></div>

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
                               data-type="hari" data-value="<?php echo $num; ?>">
                            <i class="fas fa-calendar-day"></i> <?php echo $hari; ?>
                        </label>
                        <?php endforeach; ?>
                        
                        <label class="filter-tab <?php echo $tampil_semua_hari ? 'active' : ''; ?>" 
                               data-type="semua_hari" data-value="1">
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
                               data-type="kelas" data-value="<?php echo htmlspecialchars($kelas); ?>">
                            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($kelas); ?>
                        </label>
                        <?php endforeach; ?>
                        
                        <label class="filter-tab <?php echo $tampil_semua_kelas ? 'active' : ''; ?>"
                               data-type="semua_kelas" data-value="1">
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
                    <small class="text-muted fs-6 ms-2">
                        (Jadwal berikutnya berdasarkan kelas dan hari ini)
                    </small>
                </h4>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleCurrentSchedule()">
                        <i class="fas fa-eye-slash me-1"></i> <span id="toggleText">Sembunyikan</span>
                    </button>
                    <button class="btn btn-sm btn-outline-primary btn-refresh">
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
                                                <!-- PERUBAHAN: Tambahkan info bahwa ini berdasarkan hari saat ini -->
                                                <span class="badge bg-info ms-2" data-bs-toggle="tooltip" 
                                                      title="Jadwal berikutnya berdasarkan kelas dan hari saat ini">
                                                    <i class="fas fa-calendar-day me-1"></i>
                                                    Hari Ini: <?php echo $hari_sekarang_teks ?? 'Hari ini'; ?>
                                                </span>
                                                <?php if ($selisih_hari > 0): ?>
                                                    <span class="badge bg-warning text-dark ms-2">
                                                        <i class="fas fa-calendar-alt me-1"></i>
                                                        <?php echo $target_hari; ?> (<?php echo $selisih_hari; ?> hari lagi)
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
                                            
                                            <!-- Countdown Timer - DIPERBAIKI -->
                                            <?php if ($waktu_tunggu_detik > 0): ?>
                                            <div class="countdown-container mt-3">
                                                <div class="text-center mb-2">
                                                    <small class="text-light opacity-75">
                                                        <i class="fas fa-hourglass-half me-1"></i>
                                                        Mulai dalam:
                                                    </small>
                                                </div>
                                                <div class="countdown-timer text-center text-light" id="countdownTimer">
                                                    <span class="countdown-unit">
                                                        <div id="countdownDays">0</div>
                                                        <div class="countdown-label">Hari</div>
                                                    </span>
                                                    <span class="countdown-unit">
                                                        <div id="countdownHours">00</div>
                                                        <div class="countdown-label">Jam</div>
                                                    </span>
                                                    <span class="countdown-unit">
                                                        <div id="countdownMinutes">00</div>
                                                        <div class="countdown-label">Menit</div>
                                                    </span>
                                                    <span class="countdown-unit">
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
                                        <!-- PERUBAHAN: Tambahkan info tentang jadwal berikutnya -->
                                        <span class="text-info">
                                            <i class="fas fa-clock me-1"></i> 
                                            Jadwal berikutnya: Berdasarkan <strong>Kelas</strong> dan <strong>Hari Ini</strong>
                                        </span>
                                        <span><i class="fas fa-clock me-1 text-primary"></i> Waktu: <?php echo date('H:i'); ?></span>
                                        <span><i class="fas fa-graduation-cap me-1 text-primary"></i> Semester: <?php echo htmlspecialchars($semester_aktif); ?></span>
                                    </div>
                                    <!-- Filter Info Display -->
                                    <div class="filter-info-display mt-3">
                                        <div class="alert alert-info p-2 mb-0">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <small>
                                                <strong>Info:</strong> Jadwal "Berikutnya" selalu ditampilkan berdasarkan:
                                                <br>1. <strong>Kelas yang dipilih</strong> (atau semua kelas)
                                                <br>2. <strong>Hari saat ini</strong> (<?php echo $hari_sekarang_teks ?? 'Hari ini'; ?>)
                                                <br>3. Jika tidak ada jadwal di hari ini, sistem akan mencari di hari berikutnya.
                                            </small>
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
        // JavaScript variables untuk default values
        const currentDay = <?php echo date('N'); ?>;
        const firstClass = <?php echo json_encode(!empty($kelas_list) ? $kelas_list[0] : 'A1'); ?>;
        
        // Fungsi untuk menyimpan filter ke localStorage
        function saveFilterToLocalStorage() {
            try {
                const params = new URLSearchParams(window.location.search);
                const filterData = {
                    hari: params.get('hari'),
                    semua_hari: params.get('semua_hari') === '1',
                    kelas: params.get('kelas'),
                    semua_kelas: params.get('semua_kelas') === '1',
                    timestamp: new Date().getTime()
                };
                
                localStorage.setItem('jadwalFilter', JSON.stringify(filterData));
            } catch (e) {
                // Silent fail - tidak tampilkan error ke user
                console.error('Gagal menyimpan filter:', e);
            }
        }
        
        // Fungsi untuk memuat filter dari localStorage
        function loadFilterFromLocalStorage() {
            try {
                const savedFilter = localStorage.getItem('jadwalFilter');
                if (savedFilter) {
                    const filterData = JSON.parse(savedFilter);
                    
                    // Validasi data tidak lebih dari 30 hari
                    const thirtyDaysAgo = new Date().getTime() - (30 * 24 * 60 * 60 * 1000);
                    if (filterData.timestamp && filterData.timestamp < thirtyDaysAgo) {
                        localStorage.removeItem('jadwalFilter');
                        return null;
                    }
                    
                    return filterData;
                }
            } catch (e) {
                localStorage.removeItem('jadwalFilter');
            }
            return null;
        }
        
        // Fungsi untuk menerapkan filter yang disimpan jika tidak ada parameter GET
        function applySavedFilterIfNeeded() {
            try {
                const urlParams = new URLSearchParams(window.location.search);
                const hasUrlParams = urlParams.toString() !== '';
                
                // Jika tidak ada parameter di URL, coba gunakan filter yang disimpan
                if (!hasUrlParams) {
                    const savedFilter = loadFilterFromLocalStorage();
                    if (savedFilter) {
                        // Bangun URL dengan filter yang disimpan
                        const params = new URLSearchParams();
                        
                        if (savedFilter.semua_hari) {
                            params.append('semua_hari', '1');
                        } else if (savedFilter.hari) {
                            params.append('hari', savedFilter.hari);
                        }
                        
                        if (savedFilter.semua_kelas) {
                            params.append('semua_kelas', '1');
                        } else if (savedFilter.kelas) {
                            params.append('kelas', savedFilter.kelas);
                        }
                        
                        const queryString = params.toString();
                        if (queryString) {
                            // Gunakan replaceState untuk mengubah URL tanpa reload
                            window.history.replaceState({}, '', `index.php?${queryString}`);
                            // Reload halaman untuk menerapkan filter
                            setTimeout(() => {
                                window.location.reload();
                            }, 100);
                            return true;
                        }
                    }
                }
                // Jika ada parameter, simpan ke localStorage
                else {
                    saveFilterToLocalStorage();
                }
            } catch (e) {
                console.error('Error applying saved filter:', e);
            }
            return false;
        }
        
        // Simple initialization - let main.js handle the events
        document.addEventListener('DOMContentLoaded', function() {
            // Terapkan filter yang disimpan jika diperlukan
            applySavedFilterIfNeeded();
            
            // Initialize Bootstrap tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Load saved schedule visibility preference
            const scheduleVisible = localStorage.getItem('scheduleVisible');
            if (scheduleVisible === 'false') {
                const section = document.getElementById('currentNextSection');
                const toggleText = document.getElementById('toggleText');
                if (section && toggleText) {
                    section.classList.add('collapsed-section');
                    toggleText.textContent = 'Tampilkan';
                }
            }
            
            // Initialize countdown timer if needed
            <?php if ($jadwal_berikutnya && $waktu_tunggu_detik > 0): ?>
            // Simpan waktu tunggu di variabel global
            window.waktuTungguDetik = <?php echo $waktu_tunggu_detik; ?>;
            
            // Mulai countdown timer
            startCountdownTimer();
            <?php endif; ?>
            
            // Auto-update current time every minute
            setInterval(() => {
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
            }, 60000);
            
            // Fungsi untuk refresh jadwal berikutnya ketika filter kelas berubah
            function updateNextScheduleOnClassChange() {
                // Event listener untuk perubahan filter kelas
                document.querySelectorAll('.filter-tab[data-type="kelas"], .filter-tab[data-type="semua_kelas"]').forEach(tab => {
                    tab.addEventListener('click', function() {
                        // Karena jadwal berikutnya berdasarkan kelas, kita perlu refresh
                        setTimeout(() => {
                            location.reload();
                        }, 100);
                    });
                });
            }
            
            // Panggil fungsi saat halaman dimuat
            updateNextScheduleOnClassChange();
        });

        // Global functions accessible from HTML onclick
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (sidebar && overlay) {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
            }
        };

        window.handleShowAllSchedule = function() {
            // Simpan filter "semua" sebelum redirect
            const allFilter = {
                hari: null,
                semua_hari: true,
                kelas: null,
                semua_kelas: true,
                timestamp: new Date().getTime()
            };
            
            try {
                localStorage.setItem('jadwalFilter', JSON.stringify(allFilter));
            } catch (e) {
                // Silent fail
            }
            
            window.location.href = 'index.php?semua_hari=1&semua_kelas=1';
        };

        window.handleResetFilter = function() {
            // Reset to current day and first class
            const hariSekarang = currentDay > 5 ? 1 : currentDay;
            const kelasPertama = firstClass;
            
            // Simpan filter reset
            const resetFilter = {
                hari: hariSekarang,
                semua_hari: false,
                kelas: kelasPertama,
                semua_kelas: false,
                timestamp: new Date().getTime()
            };
            
            try {
                localStorage.setItem('jadwalFilter', JSON.stringify(resetFilter));
            } catch (e) {
                // Silent fail
            }
            
            window.location.href = `index.php?hari=${hariSekarang}&kelas=${encodeURIComponent(kelasPertama)}`;
        };

        window.toggleCurrentSchedule = function() {
            const section = document.getElementById('currentNextSection');
            const toggleText = document.getElementById('toggleText');
            
            if (section && toggleText) {
                section.classList.toggle('collapsed-section');
                
                if (section.classList.contains('collapsed-section')) {
                    toggleText.textContent = 'Tampilkan';
                    try {
                        localStorage.setItem('scheduleVisible', 'false');
                    } catch (e) {
                        // Silent fail
                    }
                } else {
                    toggleText.textContent = 'Sembunyikan';
                    try {
                        localStorage.setItem('scheduleVisible', 'true');
                    } catch (e) {
                        // Silent fail
                    }
                }
            }
        };

        window.refreshCurrentSchedule = function(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            window.location.reload();
        };

        // FUNGSI COUNTDOWN TIMER YANG DIPERBAIKI
        window.startCountdownTimer = function() {
            if (!window.waktuTungguDetik || window.waktuTungguDetik <= 0) {
                console.log('Tidak ada waktu tunggu untuk countdown');
                return;
            }

            let remainingSeconds = window.waktuTungguDetik;
            
            function updateCountdown() {
                if (remainingSeconds <= 0) {
                    // Reload halaman ketika countdown selesai
                    window.location.reload();
                    return;
                }
                
                // Hitung hari, jam, menit, detik
                const days = Math.floor(remainingSeconds / (24 * 3600));
                const hours = Math.floor((remainingSeconds % (24 * 3600)) / 3600);
                const minutes = Math.floor((remainingSeconds % 3600) / 60);
                const seconds = remainingSeconds % 60;
                
                // Update elemen HTML
                const daysElement = document.getElementById('countdownDays');
                const hoursElement = document.getElementById('countdownHours');
                const minutesElement = document.getElementById('countdownMinutes');
                const secondsElement = document.getElementById('countdownSeconds');
                
                if (daysElement) daysElement.textContent = days.toString().padStart(2, '0');
                if (hoursElement) hoursElement.textContent = hours.toString().padStart(2, '0');
                if (minutesElement) minutesElement.textContent = minutes.toString().padStart(2, '0');
                if (secondsElement) secondsElement.textContent = seconds.toString().padStart(2, '0');
                
                // Kurangi waktu
                remainingSeconds--;
            }
            
            // Jalankan segera
            updateCountdown();
            
            // Update setiap detik
            window.countdownInterval = setInterval(updateCountdown, 1000);
        };
        
        // Hentikan interval saat halaman ditutup
        window.addEventListener('beforeunload', function() {
            if (window.countdownInterval) {
                clearInterval(window.countdownInterval);
            }
        });
    </script>
    
    <!-- Inisialisasi Countdown Timer jika ada -->
    <?php if ($jadwal_berikutnya && $waktu_tunggu_detik > 0): ?>
    <script>
        // Inisialisasi countdown saat halaman selesai dimuat
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof startCountdownTimer === 'function') {
                startCountdownTimer();
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>