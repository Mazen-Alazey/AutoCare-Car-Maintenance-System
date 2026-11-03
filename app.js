/**
 * AutoCare - Lightweight Vanilla JavaScript Helper
 * Fast, pure JS for DOM interactions, modals, responsive menu & Light/Dark theme switching
 */

// 1. Theme Management (Light / Dark Mode)
function getSavedTheme() {
    let saved = localStorage.getItem('autocare_theme');
    if (!saved) {
        const match = document.cookie.match(new RegExp('(^| )autocare_theme=([^;]+)'));
        if (match) saved = match[2];
    }
    return saved === 'light' ? 'light' : 'dark';
}

function initTheme() {
    const theme = getSavedTheme();
    applyTheme(theme);
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('autocare_theme', theme);
    document.cookie = `autocare_theme=${theme};path=/;max-age=31536000;SameSite=Lax`;
    
    // Update all theme toggle buttons across the page
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        const icon = btn.querySelector('.theme-icon');
        if (theme === 'dark') {
            if (icon) icon.textContent = '☀️';
            btn.setAttribute('title', 'التبديل إلى المظهر النهاري');
        } else {
            if (icon) icon.textContent = '🌙';
            btn.setAttribute('title', 'التبديل إلى المظهر الليلي');
        }
    });
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme') || getSavedTheme();
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    applyTheme(newTheme);
}

function setTheme(theme) {
    if (theme === 'light' || theme === 'dark') {
        applyTheme(theme);
    }
}

// Auto init theme on page load immediately
initTheme();
document.addEventListener('DOMContentLoaded', function() {
    initTheme();
});

// 2. Mobile Sidebar Toggle
function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    if (sidebar) {
        sidebar.classList.toggle('open');
    }
}

// 3. Modal Helper Functions
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal when clicking outside content
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(m => {
            m.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
});

