// Global variables
let ruanganData = {};
let filterState = {};

// Initialize on DOM loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    
    // Load room data from page attribute
    try {
        const ruanganAttr = document.body.getAttribute('data-ruangan');
        if (ruanganAttr) {
            ruanganData = JSON.parse(ruanganAttr);
            console.log('Room data loaded:', Object.keys(ruanganData).length, 'rooms');
        }
    } catch (e) {
        console.error('Error loading room data:', e);
    }

    // Load filter state
    initializeFilterState();
    
    // Setup auto-refresh
    setupAutoRefresh();
    
    // Setup responsive behavior
    setupResponsiveBehavior();
    
    // Setup event listeners - using event delegation
    setupEventDelegation();
    
    // Update UI based on filter state
    updateFilterUI();
    
    console.log('Initialization complete');
});

function initializeFilterState() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        
        filterState = {
            hari: urlParams.get('hari') || null,
            semua_hari: urlParams.get('semua_hari') === '1',
            kelas: urlParams.get('kelas') || null,
            semua_kelas: urlParams.get('semua_kelas') === '1'
        };
        
        console.log('Filter state initialized:', filterState);
    } catch (e) {
        console.error('Error loading filter state:', e);
        filterState = {
            hari: null,
            semua_hari: false,
            kelas: null,
            semua_kelas: false
        };
    }
}

function setupEventDelegation() {
    console.log('Setting up event delegation...');
    
    // Single event listener for all clicks using delegation
    document.addEventListener('click', function(e) {
        // Handle filter tabs
        const filterTab = e.target.closest('.filter-tab');
        if (filterTab) {
            e.preventDefault();
            e.stopPropagation();
            handleFilterTabClick(filterTab);
            return;
        }
        
        // Handle detail buttons
        const detailBtn = e.target.closest('.btn-detail');
        if (detailBtn) {
            e.preventDefault();
            e.stopPropagation();
            handleDetailButtonClick(detailBtn);
            return;
        }
        
        // Handle mobile filter toggle
        const filterToggle = e.target.closest('.filter-toggle-btn');
        if (filterToggle) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
            return;
        }
        
        // Handle sidebar close button
        const closeBtn = e.target.closest('.sidebar-filter .btn-close');
        if (closeBtn) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
            return;
        }
        
        // Handle refresh button
        const refreshBtn = e.target.closest('.btn-refresh');
        if (refreshBtn) {
            e.preventDefault();
            e.stopPropagation();
            refreshCurrentSchedule(e);
            return;
        }
        
        // Handle overlay click
        const overlay = e.target.closest('#sidebarOverlay');
        if (overlay) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
            return;
        }
    });
    
    // Handle keyboard events
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const sidebar = document.getElementById('mobileSidebar');
            if (sidebar && sidebar.classList.contains('show')) {
                toggleSidebar();
            }
        }
    });
}

function handleFilterTabClick(filterTab) {
    const type = filterTab.getAttribute('data-type');
    const value = filterTab.getAttribute('data-value');
    
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
    
    applyFilter();
}

function handleDetailButtonClick(detailBtn) {
    try {
        const scheduleData = detailBtn.getAttribute('data-schedule');
        if (scheduleData) {
            const schedule = JSON.parse(scheduleData);
            showScheduleDetail(schedule);
        }
    } catch (error) {
        console.error('Error parsing schedule data:', error);
        alert('Terjadi kesalahan saat memuat detail jadwal');
    }
}

function showScheduleDetail(schedule) {
    const ruang = ruanganData[schedule.ruang] || {};
    
    const modalBody = document.getElementById('scheduleDetail');
    if (!modalBody) {
        console.error('Modal body not found');
        return;
    }
    
    const waktuParts = schedule.waktu.split(' - ');
    const waktuFormatted = waktuParts.length === 2 ? 
        `${waktuParts[0]} - ${waktuParts[1]}` : schedule.waktu;
    
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
    
    const html = `
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
    
    const modalElement = document.getElementById('scheduleModal');
    if (modalElement) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
        
        modalElement.addEventListener('hidden.bs.modal', function() {
            modalBody.innerHTML = '';
        });
    }
}

function updateFilterUI() {
    // Reset all active tabs
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Activate based on filter state
    if (filterState.semua_hari) {
        document.querySelectorAll('.filter-tab[data-type="semua_hari"]').forEach(tab => {
            tab.classList.add('active');
        });
    } else if (filterState.hari) {
        document.querySelectorAll(`.filter-tab[data-type="hari"][data-value="${filterState.hari}"]`).forEach(tab => {
            tab.classList.add('active');
        });
    }
    
    if (filterState.semua_kelas) {
        document.querySelectorAll('.filter-tab[data-type="semua_kelas"]').forEach(tab => {
            tab.classList.add('active');
        });
    } else if (filterState.kelas) {
        document.querySelectorAll(`.filter-tab[data-type="kelas"][data-value="${filterState.kelas}"]`).forEach(tab => {
            tab.classList.add('active');
        });
    }
    
    updateFilterInfoDisplay();
}

function updateFilterInfoDisplay() {
    const hariMap = {
        '1': 'SENIN',
        '2': 'SELASA',
        '3': 'RABU',
        '4': 'KAMIS',
        '5': 'JUMAT'
    };
    
    const hariFilter = filterState.semua_hari ? 'Semua Hari' : 
                      (filterState.hari ? hariMap[filterState.hari] : 'Hari Ini');
    
    const kelasFilter = filterState.semua_kelas ? 'Semua Kelas' : 
                       (filterState.kelas || 'Pilih Kelas');
    
    const infoBox = document.querySelector('.filter-info-display');
    if (infoBox) {
        infoBox.innerHTML = `
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-primary">
                    <i class="fas fa-calendar me-1"></i> ${hariFilter}
                </span>
                <span class="badge bg-success">
                    <i class="fas fa-users me-1"></i> ${kelasFilter}
                </span>
                ${filterState.semua_hari ? '<span class="badge bg-warning text-dark"><i class="fas fa-eye me-1"></i> Semua Hari Aktif</span>' : ''}
                ${filterState.semua_kelas ? '<span class="badge bg-info"><i class="fas fa-layer-group me-1"></i> Semua Kelas Aktif</span>' : ''}
            </div>
        `;
    }
}

function applyFilter() {
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
    
    // Simpan filter ke localStorage sebelum redirect
    try {
        const filterData = {
            hari: filterState.hari,
            semua_hari: filterState.semua_hari,
            kelas: filterState.kelas,
            semua_kelas: filterState.semua_kelas,
            timestamp: new Date().getTime()
        };
        localStorage.setItem('jadwalFilter', JSON.stringify(filterData));
    } catch (e) {
        // Silent fail
        console.error('Error saving filter to localStorage:', e);
    }
    
    window.location.href = 'index.php?' + params.toString();
}

function setupAutoRefresh() {
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
        
        const minutes = now.getMinutes();
        if (minutes === 0 || minutes === 30) {
            updateCurrentSchedule();
        }
        
        updateScheduleStatus();
    }, 30000);
}

function setupResponsiveBehavior() {
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            updateScheduleCardLayout();
        }, 250);
    });
    
    updateScheduleCardLayout();
}

function updateScheduleCardLayout() {
    const width = window.innerWidth;
    const cards = document.querySelectorAll('.jadwal-card');
    
    if (width < 768) {
        cards.forEach(card => {
            const body = card.querySelector('.jadwal-body');
            if (body) {
                body.style.padding = '15px';
            }
        });
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Make functions globally available
window.showScheduleDetail = showScheduleDetail;
window.toggleSidebar = toggleSidebar;
window.handleFilterClick = handleFilterTabClick;
window.applyFilter = applyFilter;
window.updateFilterUI = updateFilterUI;

// Helper functions for current schedule
function updateCurrentSchedule() {
    // This function can be expanded to update the current schedule display
    console.log('Updating current schedule...');
}

function updateScheduleStatus() {
    // This function can be expanded to update schedule status indicators
    console.log('Updating schedule status...');
}