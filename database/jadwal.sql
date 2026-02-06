-- DATABASE
CREATE DATABASE IF NOT EXISTS jadwal_kuliah
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE jadwal_kuliah;

-- TABLE users
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('superadmin', 'admin') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE
);

-- TABLE settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- TABLE schedules
CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kelas VARCHAR(10) NOT NULL,
    hari ENUM('SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT') NOT NULL,
    jam_ke INT NOT NULL,
    waktu VARCHAR(50) NOT NULL,
    mata_kuliah VARCHAR(100) NOT NULL,
    dosen TEXT NOT NULL,
    ruang VARCHAR(50) NOT NULL,
    semester ENUM('GANJIL', 'GENAP') DEFAULT 'GANJIL',
    tahun_akademik VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kelas (kelas),
    INDEX idx_hari (hari),
    INDEX idx_kelas_hari (kelas, hari)
);

-- TABLE rooms
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_ruang VARCHAR(50) UNIQUE NOT NULL,
    foto_path VARCHAR(255),
    deskripsi TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
ALTER TABLE rooms ADD COLUMN kapasitas INT NOT NULL DEFAULT 0 AFTER nama_ruang;

-- TABLE activity_logs
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- TABLE broadcast_logs
CREATE TABLE IF NOT EXISTS broadcast_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    status VARCHAR(50) NOT NULL,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
);

-- TABLE admin_audit_logs
CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    target_admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_id (admin_id),
    INDEX idx_target_admin_id (target_admin_id),
    INDEX idx_created_at (created_at)
);

-- DEFAULT settings
INSERT INTO settings (setting_key, setting_value) VALUES
    ('tahun_akademik', '2025/2026'),
    ('institusi_nama', 'Politeknik Negeri Padang'),
    ('institusi_lokasi', 'PSDKU Tanah Datar'),
    ('program_studi', 'D3 Sistem Informasi'),
    ('superadmin_registered', '0'),
    ('maintenance_mode', '0'),
    ('maintenance_message', 'Sistem sedang dalam perbaikan.')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO settings (setting_key, setting_value) VALUES
    ('fakultas', 'Fakultas Teknik')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- TABLE semester_settings
CREATE TABLE IF NOT EXISTS semester_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tahun_akademik VARCHAR(20) NOT NULL,
    semester ENUM('GANJIL', 'GENAP') NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tahun_semester (tahun_akademik, semester)
);

-- DEFAULT semester active
INSERT INTO semester_settings (tahun_akademik, semester, is_active) VALUES
    ('2025/2026', 'GANJIL', 1)
ON DUPLICATE KEY UPDATE is_active = VALUES(is_active);
