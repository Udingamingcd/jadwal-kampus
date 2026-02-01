<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin(); // Semua admin bisa akses, tapi dengan batasan

$database = new Database();
$db = $database->getConnection();

// Tambah admin - hanya superadmin yang bisa tambah admin baru
if(isset($_POST['add_admin'])) {
    if (!isSuperAdmin()) {
        $_SESSION['error_message'] = "Hanya superadmin yang dapat menambah admin baru.";
        header('Location: manage_users.php');
        exit();
    }
    
    $username = $_POST['username'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Cek apakah username sudah ada
    $check = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $check->execute([$username]);
    if($check->fetchColumn() > 0) {
        $_SESSION['error_message'] = "Username '$username' sudah ada!";
        header('Location: manage_users.php');
        exit();
    }

    // Insert user baru
    $query = "INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$username, $password_hash, $email, $role]);

    logActivity($db, $_SESSION['user_id'], 'Tambah Admin', $username);
    $_SESSION['message'] = "Admin berhasil ditambahkan!";
    header('Location: manage_users.php');
    exit();
}

// Edit admin - semua admin bisa edit tapi dengan batasan
if(isset($_POST['edit_admin'])) {
    $user_id = $_POST['id'];
    $current_user_role = $_SESSION['role'];
    
    // Ambil data user yang akan diedit
    $query = "SELECT role, is_active FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$user_id]);
    $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$target_user) {
        $_SESSION['error_message'] = "User tidak ditemukan";
        header('Location: manage_users.php');
        exit();
    }
    
    $error = false;
    $error_message = "";
    
    // 1. Cek apakah user mencoba mengedit diri sendiri
    if ($user_id == $_SESSION['user_id']) {
        // User bisa mengedit username dan email diri sendiri
        // Tapi tidak bisa mengubah role atau status aktif diri sendiri
        if (isset($_POST['role']) && $_POST['role'] != $target_user['role']) {
            $error = true;
            $error_message = "Tidak dapat mengubah role akun sendiri.";
        }
        
        if (isset($_POST['is_active']) != $target_user['is_active']) {
            $error = true;
            $error_message = "Tidak dapat mengubah status aktif akun sendiri.";
        }
    }
    // 2. Admin biasa mencoba mengedit superadmin
    else if ($current_user_role !== 'superadmin' && $target_user['role'] === 'superadmin') {
        // Admin biasa TIDAK BISA mengedit superadmin sama sekali
        $error = true;
        $error_message = "Admin biasa tidak dapat mengedit akun superadmin.";
    }
    // 3. Admin biasa mencoba mengubah role menjadi superadmin
    else if ($current_user_role !== 'superadmin' && isset($_POST['role']) && $_POST['role'] === 'superadmin') {
        $error = true;
        $error_message = "Admin biasa tidak dapat membuat atau mengubah akun menjadi superadmin.";
    }
    // 4. Admin biasa mencoba menonaktifkan superadmin
    else if ($current_user_role !== 'superadmin' && $target_user['role'] === 'superadmin') {
        if (!isset($_POST['is_active']) && $target_user['is_active'] == 1) {
            $error = true;
            $error_message = "Admin biasa tidak dapat menonaktifkan akun superadmin.";
        }
    }
    
    // 5. Cek apakah ini adalah akun aktif terakhir
    $query_check = "SELECT COUNT(*) as active_count FROM users WHERE is_active = TRUE AND id != ?";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->execute([$user_id]);
    $result = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($result['active_count'] == 0 && $target_user['is_active'] == 1 && !isset($_POST['is_active'])) {
        $error = true;
        $error_message = "Tidak dapat menonaktifkan akun aktif terakhir.";
    }
    
    if ($error) {
        $_SESSION['error_message'] = $error_message;
        header('Location: manage_users.php');
        exit();
    }
    
    // Lanjutkan dengan update jika tidak ada error
    $query = "UPDATE users SET username = ?, email = ?";
    $params = [$_POST['username'], $_POST['email']];
    
    // Hanya superadmin atau jika bukan mengedit superadmin yang bisa ubah role
    if (isset($_POST['role']) && 
        ($current_user_role === 'superadmin' || 
         ($current_user_role !== 'superadmin' && $target_user['role'] !== 'superadmin'))) {
        $query .= ", role = ?";
        $params[] = $_POST['role'];
    }
    
    // Hanya superadmin atau jika bukan mengedit superadmin yang bisa ubah status aktif
    if (isset($_POST['is_active']) && 
        ($current_user_role === 'superadmin' || 
         ($current_user_role !== 'superadmin' && $target_user['role'] !== 'superadmin'))) {
        $query .= ", is_active = ?";
        $params[] = 1;
    } else if (!isset($_POST['is_active']) && 
               ($current_user_role === 'superadmin' || 
                ($current_user_role !== 'superadmin' && $target_user['role'] !== 'superadmin'))) {
        $query .= ", is_active = ?";
        $params[] = 0;
    }
    
    $query .= " WHERE id = ?";
    $params[] = $user_id;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    // Update password jika diisi
    if(!empty($_POST['password'])) {
        $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $query = "UPDATE users SET password = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$password_hash, $_POST['id']]);
    }
    
    logActivity($db, $_SESSION['user_id'], 'Edit Admin', $_POST['username']);
    $_SESSION['message'] = "Admin berhasil diperbarui!";
    header('Location: manage_users.php');
    exit();
}

// Hapus admin - hanya superadmin yang bisa menghapus
if(isset($_GET['delete'])) {
    if (!isSuperAdmin()) {
        $_SESSION['error_message'] = "Hanya superadmin yang dapat menghapus admin.";
        header('Location: manage_users.php');
        exit();
    }
    
    $target_id = $_GET['delete'];
    
    // Validasi menggunakan fungsi helper
    $validation_error = validateUserAction($db, $_SESSION['user_id'], $_SESSION['role'], $target_id, 'delete');
    
    if ($validation_error) {
        $_SESSION['error_message'] = $validation_error;
    } else {
        $query = "DELETE FROM users WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$target_id]);
        
        logActivity($db, $_SESSION['user_id'], 'Hapus Admin', "ID: {$target_id}");
        $_SESSION['message'] = "Admin berhasil dihapus!";
    }
    
    header('Location: manage_users.php');
    exit();
}

// Ambil semua admin dengan info proteksi
$query = "SELECT u.*, 
          (SELECT COUNT(*) FROM users WHERE is_active = TRUE AND id != u.id) as other_active_count
          FROM users u 
          ORDER BY u.role DESC, u.username ASC";
$stmt = $db->prepare($query);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Admin - Admin Panel</title>
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
        .badge-admin {
            background-color: #6c757d;
        }
        .badge-superadmin {
            background-color: #dc3545;
        }
        .protection-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            margin-left: 5px;
        }
        .protection-tooltip {
            position: relative;
            cursor: help;
        }
        .protection-tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 1000;
        }
        .checkbox-disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .select-disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: #e9ecef;
        }
        .row-protected {
            background-color: #fff8e1 !important;
        }
        .btn-add-disabled {
            opacity: 0.6;
            cursor: not-allowed;
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
                        <h4 class="mb-0">Kelola Admin</h4>
                        <?php if(!isSuperAdmin()): ?>
                        <span class="badge bg-info ms-2">Mode Terbatas</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
                                <?php if($_SESSION['is_last_active'] ?? false): ?>
                                    <span class="badge bg-warning ms-1" title="Akun aktif terakhir">!</span>
                                <?php endif; ?>
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
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">Daftar Admin</h5>
                            <p class="text-muted mb-0">Kelola pengguna dengan akses admin</p>
                            <?php 
                            $active_count = countActiveUsers($db);
                            $total_count = count($users);
                            ?>
                            <small class="text-info">
                                <i class="fas fa-info-circle"></i> 
                                <?php echo $active_count; ?> akun aktif dari <?php echo $total_count; ?> total akun
                                <?php if(!isSuperAdmin()): ?>
                                    <span class="badge bg-warning ms-2">Hanya dapat melihat dan mengaktifkan akun non-aktif</span>
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php if(isSuperAdmin()): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="fas fa-plus me-2"></i>Tambah Admin
                        </button>
                        <?php else: ?>
                        <button class="btn btn-primary btn-add-disabled" disabled title="Hanya superadmin yang dapat menambah admin">
                            <i class="fas fa-plus me-2"></i>Tambah Admin
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php echo displayMessage(); ?>

                <!-- Info untuk admin biasa -->
                <?php if(!isSuperAdmin()): ?>
                <div class="alert alert-info mb-3">
                    <h6><i class="fas fa-info-circle me-2"></i>Informasi Hak Akses</h6>
                    <p class="mb-0">Sebagai <strong>Admin Biasa</strong>, Anda dapat:</p>
                    <ul class="mb-0">
                        <li>Melihat daftar semua admin</li>
                        <li>Mengaktifkan akun admin biasa yang non-aktif</li>
                        <li>Mengedit username dan email akun sendiri</li>
                        <li><strong>Tidak dapat:</strong> mengedit superadmin, menonaktifkan superadmin, menghapus admin, atau menambah admin baru</li>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Data Table -->
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover" id="usersTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Terakhir Login</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach($users as $user): 
                                    $is_protected = false;
                                    $protection_reason = '';
                                    $can_edit = true;
                                    $can_delete = true;
                                    
                                    // Cek proteksi untuk admin biasa
                                    if (!isSuperAdmin()) {
                                        // Admin biasa tidak bisa mengedit/menghapus superadmin
                                        if ($user['role'] == 'superadmin') {
                                            $is_protected = true;
                                            $protection_reason = 'Superadmin - hanya dapat dilihat';
                                            $can_edit = false;
                                            $can_delete = false;
                                        }
                                        
                                        // Admin biasa tidak bisa menghapus admin lain
                                        if ($user['id'] != $_SESSION['user_id']) {
                                            $can_delete = false;
                                        }
                                    }
                                    
                                    // Cek proteksi akun aktif terakhir
                                    if ($user['other_active_count'] == 0 && $user['is_active']) {
                                        $is_protected = true;
                                        $protection_reason = 'Akun aktif terakhir';
                                        $can_delete = false;
                                    }
                                ?>
                                <tr class="<?php echo $user['id'] == $_SESSION['user_id'] ? 'table-info' : ''; ?> <?php echo $is_protected ? 'row-protected' : ''; ?>">
                                    <td><?php echo $no++; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar me-2" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                            </div>
                                            <?php echo htmlspecialchars($user['username']); ?>
                                            <?php if($user['id'] == $_SESSION['user_id']): ?>
                                                <span class="badge bg-info protection-badge">Anda</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $user['role'] == 'superadmin' ? 'danger' : 'primary'; ?>">
                                            <?php echo strtoupper($user['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $user['is_active'] ? 'AKTIF' : 'NONAKTIF'; ?>
                                        </span>
                                        <?php 
                                        // Tampilkan badge proteksi jika diperlukan
                                        if ($is_protected && $user['is_active']): ?>
                                            <span class="badge bg-warning protection-badge protection-tooltip" 
                                                  data-tooltip="<?php echo $protection_reason; ?>"
                                                  data-bs-toggle="tooltip" 
                                                  title="<?php echo $protection_reason; ?>">
                                                <i class="fas fa-shield-alt"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : '-'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" 
                                                onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                                <?php echo !$can_edit ? 'disabled' : ''; ?>
                                                <?php if(!$can_edit): ?>title="<?php echo $protection_reason; ?>"<?php endif; ?>>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if($can_delete && isSuperAdmin()): ?>
                                            <a href="?delete=<?php echo $user['id']; ?>" 
                                               class="btn btn-sm btn-danger" 
                                               onclick="return confirm('Yakin hapus admin ini?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-danger" disabled title="Hanya superadmin yang dapat menghapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Tambah (hanya untuk superadmin) -->
    <?php if(isSuperAdmin()): ?>
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Admin Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label>Role</label>
                            <select name="role" class="form-control" required>
                                <option value="admin">Admin</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_admin" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Edit -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Admin</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Password (Kosongkan jika tidak ingin mengubah)</label>
                            <input type="password" name="password" class="form-control">
                            <small class="text-muted">Minimal 6 karakter</small>
                        </div>
                        <div class="mb-3">
                            <label>Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        
                        <!-- Role selection (hanya untuk superadmin atau jika bukan superadmin) -->
                        <div class="mb-3">
                            <label>Role</label>
                            <select name="role" id="edit_role" class="form-control" required>
                                <option value="admin">Admin</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                        </div>
                        
                        <!-- Status aktif -->
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input">
                            <label class="form-check-label" for="edit_is_active">Aktif</label>
                        </div>
                        
                        <!-- Info proteksi -->
                        <div id="protection_info" class="alert alert-info d-none">
                            <small>
                                <i class="fas fa-info-circle"></i> 
                                <span id="protection_message"></span>
                            </small>
                        </div>
                        
                        <!-- Warning untuk akun aktif terakhir -->
                        <div id="last_active_warning" class="alert alert-warning d-none">
                            <small>
                                <i class="fas fa-exclamation-triangle"></i> 
                                <span id="last_active_message"></span>
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit_admin" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#usersTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                },
                "pageLength": 10
            });
            
            // Inisialisasi tooltip
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
        });
        
        function editUser(user) {
            $('#edit_id').val(user.id);
            $('#edit_username').val(user.username);
            $('#edit_email').val(user.email || '');
            $('#edit_role').val(user.role);
            $('#edit_is_active').prop('checked', user.is_active == 1);
            
            // Proteksi logika
            const protectionInfo = $('#protection_info');
            const protectionMessage = $('#protection_message');
            const lastActiveWarning = $('#last_active_warning');
            const lastActiveMessage = $('#last_active_message');
            const isActiveCheckbox = $('#edit_is_active');
            const roleSelect = $('#edit_role');
            const currentUserRole = '<?php echo $_SESSION['role']; ?>';
            const currentUserId = <?php echo $_SESSION['user_id']; ?>;
            const isSuperAdmin = currentUserRole === 'superadmin';
            
            // Reset semua proteksi
            protectionInfo.addClass('d-none');
            lastActiveWarning.addClass('d-none');
            isActiveCheckbox.prop('disabled', false).removeClass('checkbox-disabled');
            roleSelect.prop('disabled', false).removeClass('select-disabled');
            
            // 1. Jika ini adalah akun SUPERADMIN dan user yang login bukan superadmin
            if (user.role === 'superadmin' && !isSuperAdmin) {
                // Admin biasa tidak bisa mengedit superadmin sama sekali
                protectionInfo.removeClass('d-none');
                protectionMessage.text('Admin biasa tidak dapat mengedit akun superadmin.');
                
                // Nonaktifkan semua input
                $('#edit_username').prop('disabled', true);
                $('input[name="password"]').prop('disabled', true);
                $('#edit_email').prop('disabled', true);
                roleSelect.prop('disabled', true).addClass('select-disabled');
                isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                
                // Jika superadmin non-aktif, admin biasa bisa mengaktifkan
                if (user.is_active == 0) {
                    protectionMessage.text('Admin biasa dapat mengaktifkan akun superadmin yang non-aktif.');
                    isActiveCheckbox.prop('disabled', false).removeClass('checkbox-disabled');
                    isActiveCheckbox.prop('checked', true);
                }
            }
            
            // 2. Jika ini adalah akun aktif terakhir
            if (user.other_active_count == 0 && user.is_active == 1) {
                isActiveCheckbox.prop('checked', true);
                isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                lastActiveWarning.removeClass('d-none');
                lastActiveMessage.text('PERINGATAN: Ini adalah akun aktif terakhir. Tidak dapat dinonaktifkan.');
            }
            
            // 3. Jika user mencoba mengedit akun sendiri
            if (user.id == currentUserId) {
                // User tidak bisa mengubah role sendiri
                roleSelect.prop('disabled', true).addClass('select-disabled');
                
                // User tidak bisa menonaktifkan diri sendiri jika ini akun terakhir
                if (<?php echo $_SESSION['is_last_active'] ? 'true' : 'false'; ?>) {
                    isActiveCheckbox.prop('checked', true);
                    isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                    lastActiveWarning.removeClass('d-none');
                    lastActiveMessage.text('PERINGATAN: Anda adalah akun aktif terakhir. Tidak dapat dinonaktifkan.');
                } else {
                    isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                    protectionInfo.removeClass('d-none');
                    protectionMessage.text('Tidak dapat mengubah status aktif akun sendiri.');
                }
            }
            
            // 4. Validasi tambahan untuk admin biasa
            if (!isSuperAdmin) {
                // Admin biasa tidak bisa mengubah role menjadi superadmin
                roleSelect.find('option[value="superadmin"]').prop('disabled', true);
                
                // Admin biasa hanya bisa mengaktifkan akun yang non-aktif (kecuali superadmin)
                if (user.role !== 'superadmin' && user.is_active == 0) {
                    // Bisa mengaktifkan
                    isActiveCheckbox.prop('disabled', false).removeClass('checkbox-disabled');
                    isActiveCheckbox.prop('checked', true);
                    protectionInfo.removeClass('d-none');
                    protectionMessage.text('Anda dapat mengaktifkan akun admin ini.');
                } else if (user.role !== 'superadmin' && user.is_active == 1) {
                    // Tidak bisa menonaktifkan
                    isActiveCheckbox.prop('disabled', true).addClass('checkbox-disabled');
                    protectionInfo.removeClass('d-none');
                    protectionMessage.text('Admin biasa hanya dapat mengaktifkan akun non-aktif, tidak dapat menonaktifkan.');
                }
            }
            
            $('#editModal').modal('show');
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
        
        // Validasi form sebelum submit
        $('#editModal form').submit(function(e) {
            const userId = $('#edit_id').val();
            const userRole = $('#edit_role').val();
            const isActive = $('#edit_is_active').prop('checked');
            const currentUserRole = '<?php echo $_SESSION['role']; ?>';
            const currentUserId = <?php echo $_SESSION['user_id']; ?>;
            const isSuperAdmin = currentUserRole === 'superadmin';
            
            // Validasi: Admin biasa tidak bisa mengubah role menjadi superadmin
            if (!isSuperAdmin && userRole === 'superadmin') {
                e.preventDefault();
                alert('Error: Admin biasa tidak dapat membuat atau mengubah akun menjadi superadmin.');
                return false;
            }
            
            // Validasi: User tidak bisa menonaktifkan diri sendiri
            if (userId == currentUserId && !isActive) {
                e.preventDefault();
                alert('Error: Tidak dapat menonaktifkan akun sendiri.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>