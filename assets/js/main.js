// Main JavaScript untuk halaman index

// Global variables
let ruanganData = {};

// Initialize on DOM loaded
document.addEventListener('DOMContentLoaded', function() {
    // Load room data from page attribute
    try {
        const ruanganAttr = document.body.getAttribute('data-ruangan');
        if (ruanganAttr) {
            ruanganData = JSON.parse(ruanganAttr);
        }
    } catch (e) {
        console.error('Error loading room data:', e);
    }

    // Setup auto-refresh untuk jadwal saat ini
    setupAutoRefresh();
    
    // Setup responsive behavior
    setupResponsiveBehavior();
    
    // Cek dan update waktu jadwal
    updateScheduleStatus();
    
    // Setup maintenance mode handling
    setupMaintenanceMode();
    
    // Update current time initially
    updateCurrentTime();
});

function showScheduleDetail(schedule) {
    // Get room data
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

function setupAutoRefresh() {
    // Auto-refresh current schedule every 30 seconds
    setInterval(() => {
        const now = new Date();
        const currentTime = now.toLocaleTimeString('id-ID', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: false
        });
        
        // Update time badge
        const timeBadge = document.getElementById('currentTime');
        if (timeBadge) {
            timeBadge.textContent = currentTime;
        }
        
        // Check if we need to reload for schedule changes
        const minutes = now.getMinutes();
        if (minutes === 0 || minutes === 30) {
            updateCurrentSchedule();
        }
        
        // Update schedule status
        updateScheduleStatus();
    }, 30000); // 30 seconds
}

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

async function updateCurrentSchedule() {
    try {
        // Get current day and time
        const now = new Date();
        const currentTime = now.getHours() * 60 + now.getMinutes();
        const currentDay = now.getDay(); // 0=Sunday, 1=Monday, etc.
        
        // Convert to our system (1=Monday, 5=Friday)
        const dayMap = { 1: 'SENIN', 2: 'SELASA', 3: 'RABU', 4: 'KAMIS', 5: 'JUMAT' };
        const currentDayText = dayMap[currentDay];
        
        if (!currentDayText) return; // Weekend
        
        // Make AJAX call to get current schedule
        const response = await fetch('api/get_current_schedule.php');
        if (response.ok) {
            const data = await response.json();
            updateCurrentScheduleCard(data);
        }
    } catch (error) {
        console.log('Gagal update jadwal saat ini:', error);
    }
}

function updateCurrentScheduleCard(data) {
    const currentCard = document.querySelector('.current-card .card-body');
    if (!currentCard) return;
    
    if (data && data.exists) {
        const html = `
            <div class="row align-items-center">
                <div class="col-md-3">
                    <div class="current-time">
                        <h4 class="text-primary">${escapeHtml(data.waktu)}</h4>
                        <p class="text-muted mb-0">Jam ke-${escapeHtml(data.jam_ke)}</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="current-info">
                        <h4 class="mb-2">${escapeHtml(data.mata_kuliah)}</h4>
                        <p class="mb-2">
                            <i class="fas fa-user-tie me-2"></i> 
                            ${escapeHtml(data.dosen)}
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-door-open me-2"></i> 
                            Ruang ${escapeHtml(data.ruang)}
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-users me-2"></i> 
                            Kelas ${escapeHtml(data.kelas)}
                        </p>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-primary btn-detail" 
                            data-schedule='${JSON.stringify(data)}'>
                        <i class="fas fa-info-circle me-2"></i> Detail
                    </button>
                </div>
            </div>
        `;
        currentCard.innerHTML = html;
        
        // Re-attach event listener to new button
        const newBtn = currentCard.querySelector('.btn-detail');
        if (newBtn) {
            newBtn.addEventListener('click', function() {
                const scheduleData = JSON.parse(this.getAttribute('data-schedule'));
                showScheduleDetail(scheduleData);
            });
        }
    } else {
        const today = new Date().toLocaleDateString('id-ID', { weekday: 'long' });
        currentCard.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                <h4 class="text-muted mb-2">Tidak ada jadwal saat ini</h4>
                <p class="text-muted mb-0">
                    ${['Sabtu', 'Minggu'].includes(today) 
                        ? 'Hari ini hari libur' 
                        : 'Tidak ada jadwal kuliah yang sedang berlangsung'}
                </p>
            </div>
        `;
    }
}

function updateScheduleStatus() {
    // Update status jadwal (berlangsung/selesai/mendatang)
    const scheduleCards = document.querySelectorAll('.schedule-card');
    const now = new Date();
    const currentHour = now.getHours();
    const currentMinute = now.getMinutes();
    const currentTime = currentHour * 60 + currentMinute;
    
    scheduleCards.forEach(card => {
        const waktuText = card.querySelector('.badge.bg-primary')?.textContent;
        if (waktuText && waktuText.includes(' - ')) {
            const [start, end] = waktuText.split(' - ');
            const [startHour, startMinute] = start.split(':').map(Number);
            const [endHour, endMinute] = end.split(':').map(Number);
            
            const startTime = startHour * 60 + startMinute;
            const endTime = endHour * 60 + endMinute;
            
            // Reset classes
            card.classList.remove('border-success', 'border-primary', 'border-secondary');
            
            if (currentTime >= startTime && currentTime <= endTime) {
                // Ongoing
                card.classList.add('border-success');
                card.style.borderWidth = '3px';
                card.style.boxShadow = '0 0 20px rgba(76, 201, 240, 0.3)';
            } else if (currentTime < startTime) {
                // Upcoming
                card.classList.add('border-primary');
                card.style.borderWidth = '2px';
                card.style.boxShadow = '0 0 15px rgba(67, 97, 238, 0.2)';
            } else {
                // Finished
                card.classList.add('border-secondary');
                card.style.borderWidth = '1px';
                card.style.boxShadow = 'none';
                card.style.opacity = '0.85';
            }
        }
    });
}

function setupResponsiveBehavior() {
    // Handle responsive filter buttons
    const filterButtons = document.querySelectorAll('.filter-btn');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            // Remove active class from siblings in same group
            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                const groupName = radio.name;
                const groupButtons = document.querySelectorAll(`.filter-btn input[name="${groupName}"]`);
                groupButtons.forEach(input => {
                    input.parentElement.classList.remove('active');
                });
                this.classList.add('active');
            }
        });
    });
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            updateScheduleCardLayout();
        }, 250);
    });
    
    // Initial layout update
    updateScheduleCardLayout();
}

function updateScheduleCardLayout() {
    const width = window.innerWidth;
    const cards = document.querySelectorAll('.schedule-card');
    
    if (width < 768) {
        // Mobile layout adjustments
        cards.forEach(card => {
            const body = card.querySelector('.card-body');
            const header = card.querySelector('.card-header');
            
            // Make time badge more prominent on mobile
            const timeBadge = header?.querySelector('.badge');
            if (timeBadge) {
                timeBadge.classList.add('fs-6', 'px-3', 'py-2');
            }
            
            // Adjust card body padding
            if (body) {
                body.style.padding = '15px';
            }
        });
        
        // Adjust filter buttons for mobile
        const filterBtns = document.querySelectorAll('.filter-btn');
        filterBtns.forEach(btn => {
            btn.style.padding = '12px 10px';
            btn.style.fontSize = '14px';
        });
    } else {
        // Desktop layout
        cards.forEach(card => {
            const timeBadge = card.querySelector('.badge');
            if (timeBadge) {
                timeBadge.classList.remove('fs-6', 'px-3', 'py-2');
            }
        });
    }
}

function setupMaintenanceMode() {
    const maintenanceModal = document.getElementById('maintenanceModal');
    if (maintenanceModal) {
        // Prevent interaction with background
        document.body.style.overflow = 'hidden';
        
        // Focus on modal
        maintenanceModal.focus();
        
        // Block keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Tab' || e.key === 'Escape') {
                e.preventDefault();
            }
        });
        
        // Add click outside to prevent interaction
        maintenanceModal.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Add blur effect to body
        document.body.classList.add('maintenance-active');
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Export functions for global use
window.showScheduleDetail = showScheduleDetail;
window.updateCurrentTime = updateCurrentTime;
window.updateScheduleStatus = updateScheduleStatus;