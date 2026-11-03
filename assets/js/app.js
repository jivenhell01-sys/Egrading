// Modern JavaScript for enhanced functionality
class GradingSystem {
    constructor() {
        this.initSidebar();
        this.initTooltips();
        this.initModals();
        this.initDataTables();
        this.addEventListeners();
    }

    initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('sidebarToggle');
        
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Restore sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }
    }

    initTooltips() {
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    initModals() {
        // Handle modal shown event
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('shown.bs.modal', function () {
                // Find first input in modal that's not hidden
                const input = this.querySelector('input:not([type="hidden"]), select, textarea');
                if (input) {
                    // Use setTimeout to prevent autofocus conflict
                    setTimeout(() => {
                        input.focus({ preventScroll: true });
                    }, 50);
                }
            });
        });
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.gradingSystem = new GradingSystem();
});