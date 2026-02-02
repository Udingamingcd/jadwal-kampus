<?php
// Fungsi untuk mengambil setting dari database
function getSetting($db, $key) {
    try {
        $query = "SELECT setting_value FROM settings WHERE setting_key = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : null;
    } catch (Exception $e) {
        error_log("Error getting setting: " . $e->getMessage());
        return null;
    }
}

// Fungsi untuk mengambil semester aktif
function getActiveSemester($db) {
    try {
        $query = "SELECT tahun_akademik, semester FROM semester_settings WHERE is_active = 1 LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            // Default fallback
            return [
                'tahun_akademik' => '2025/2026',
                'semester' => 'GANJIL'
            ];
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error getting active semester: " . $e->getMessage());
        return [
            'tahun_akademik' => '2025/2026',
            'semester' => 'GANJIL'
        ];
    }
}

// Fungsi untuk mengambil semua semester
function getAllSemesters($db) {
    try {
        $query = "SELECT tahun_akademik, semester FROM semester_settings ORDER BY tahun_akademik DESC, semester DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting all semesters: " . $e->getMessage());
        return [];
    }
}

// Fungsi untuk format waktu
function formatWaktu($waktu) {
    $parts = explode(' - ', $waktu);
    if (count($parts) === 2) {
        return $parts[0] . ' - ' . $parts[1];
    }
    return $waktu;
}

// Fungsi untuk cek apakah waktu sedang berlangsung
function isWaktuBerlangsung($waktu) {
    $jam_sekarang = date('H:i');
    $parts = explode(' - ', $waktu);
    
    if (count($parts) === 2) {
        $mulai = $parts[0];
        $selesai = $parts[1];
        
        return ($jam_sekarang >= $mulai && $jam_sekarang <= $selesai);
    }
    
    return false;
}

// Fungsi untuk menghitung selisih waktu
function hitungSelisihWaktu($waktu_target) {
    $sekarang = time();
    $target = strtotime($waktu_target);
    
    if ($target > $sekarang) {
        $selisih = $target - $sekarang;
        
        $hari = floor($selisih / (60 * 60 * 24));
        $jam = floor(($selisih % (60 * 60 * 24)) / (60 * 60));
        $menit = floor(($selisih % (60 * 60)) / 60);
        $detik = $selisih % 60;
        
        return [
            'hari' => $hari,
            'jam' => $jam,
            'menit' => $menit,
            'detik' => $detik,
            'total_detik' => $selisih
        ];
    }
    
    return [
        'hari' => 0,
        'jam' => 0,
        'menit' => 0,
        'detik' => 0,
        'total_detik' => 0
    ];
}
?>