<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

$database = new Database();
$db = $database->getConnection();

// Ambil parameter filter dari request
$filter_hari = $_GET['hari'] ?? null;
$filter_kelas = $_GET['kelas'] ?? null;
$semua_hari = isset($_GET['semua_hari']) && $_GET['semua_hari'] == '1';
$semua_kelas = isset($_GET['semua_kelas']) && $_GET['semua_kelas'] == '1';

// Ambil semester aktif
$activeSemester = getActiveSemester($db);
$tahun_akademik = $activeSemester['tahun_akademik'];
$semester_aktif = $activeSemester['semester'];

// Cari jadwal yang sedang berlangsung
$jam_sekarang = date('H:i');
$hari_sekarang = date('N');
$hari_map = [
    1 => 'SENIN',
    2 => 'SELASA',
    3 => 'RABU',
    4 => 'KAMIS',
    5 => 'JUMAT'
];

// Tentukan hari yang akan dicari
if ($semua_hari || !$filter_hari) {
    $hari_cari = $hari_map[$hari_sekarang] ?? null;
} else {
    $hari_cari = $hari_map[$filter_hari] ?? $hari_map[1];
}

header('Content-Type: application/json');

$response = [
    'status' => 'success',
    'ongoing' => null,
    'next' => null,
    'filter_info' => [
        'hari' => $hari_cari,
        'kelas' => $semua_kelas ? 'Semua Kelas' : $filter_kelas,
        'semua_hari' => $semua_hari,
        'semua_kelas' => $semua_kelas
    ],
    'message' => ''
];

if ($hari_cari) {
    try {
        // Query dasar
        $query_base = "SELECT * FROM schedules 
                      WHERE hari = ? 
                      AND semester = ? 
                      AND tahun_akademik = ? ";
        
        $params = [$hari_cari, $semester_aktif, $tahun_akademik];
        
        // Tambahkan filter kelas jika tidak semua kelas
        if (!$semua_kelas && $filter_kelas) {
            $query_base .= " AND kelas = ? ";
            $params[] = $filter_kelas;
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
        
        if ($jadwal_berlangsung) {
            $response['ongoing'] = $jadwal_berlangsung;
            $response['message'] = 'Jadwal sedang berlangsung ditemukan';
        }
        
        // 2. Cari jadwal berikutnya (hari ini dulu)
        $query_selanjutnya = $query_base . " AND SUBSTRING_INDEX(waktu, ' - ', 1) > ?
                      ORDER BY SUBSTRING_INDEX(waktu, ' - ', 1)
                      LIMIT 1";
        
        $stmt_selanjutnya = $db->prepare($query_selanjutnya);
        $stmt_selanjutnya->execute(array_merge($params, [$jam_sekarang]));
        $jadwal_selanjutnya = $stmt_selanjutnya->fetch(PDO::FETCH_ASSOC);
        
        if ($jadwal_selanjutnya) {
            $response['next'] = $jadwal_selanjutnya;
            $response['message'] = 'Jadwal berikutnya ditemukan';
        } else {
            // 3. Jika tidak ada jadwal hari ini, cari jadwal di hari berikutnya
            // Hanya jika tidak memilih semua hari
            if (!$semua_hari) {
                $hari_order = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'];
                $current_index = array_search($hari_cari, $hari_order);
                
                for ($i = 1; $i <= 4; $i++) {
                    $next_index = ($current_index + $i) % 5;
                    $next_day = $hari_order[$next_index];
                    
                    $query_next_day = "SELECT * FROM schedules 
                              WHERE hari = ? 
                              AND semester = ? 
                              AND tahun_akademik = ? ";
                    
                    $params_next_day = [$next_day, $semester_aktif, $tahun_akademik];
                    
                    if (!$semua_kelas && $filter_kelas) {
                        $query_next_day .= " AND kelas = ? ";
                        $params_next_day[] = $filter_kelas;
                    }
                    
                    $query_next_day .= " ORDER BY SUBSTRING_INDEX(waktu, ' - ', 1)
                              LIMIT 1";
                    
                    $stmt_next_day = $db->prepare($query_next_day);
                    $stmt_next_day->execute($params_next_day);
                    $jadwal_next_day = $stmt_next_day->fetch(PDO::FETCH_ASSOC);
                    
                    if ($jadwal_next_day) {
                        $response['next'] = $jadwal_next_day;
                        $response['message'] = 'Jadwal berikutnya di hari ' . $next_day;
                        break;
                    }
                }
            }
        }
        
        if (!$response['ongoing'] && !$response['next']) {
            $response['message'] = 'Tidak ada jadwal yang ditemukan untuk filter ini';
        }
        
    } catch (Exception $e) {
        $response['status'] = 'error';
        $response['message'] = 'Terjadi kesalahan: ' . $e->getMessage();
    }
} else {
    $response['status'] = 'success';
    $response['message'] = 'Hari ini adalah hari libur';
}

echo json_encode($response);
?>