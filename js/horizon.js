/**
 * Horizon UI Interactive Engine
 * Handles sidebar drawer, toast notifications, and UI states.
 */

(function () {
    'use strict';

    // Mobile Sidebar Drawer Management
    document.addEventListener('DOMContentLoaded', function () {

        const themeToggle = document.getElementById('horizon-theme-toggle');
        let savedTheme = 'light';

        try {
            savedTheme = localStorage.getItem('horizon-theme') || 'light';
        } catch (error) { }

        function setDarkMode(enabled) {
            document.body.classList.toggle('dark', enabled);
            document.documentElement.setAttribute('data-theme', enabled ? 'dark' : 'light');

            if (themeToggle) {
                themeToggle.setAttribute('aria-label', enabled ? 'Enable light mode' : 'Enable dark mode');
                themeToggle.setAttribute('title', enabled ? 'Enable light mode' : 'Enable dark mode');
                themeToggle.innerHTML = `<i class="fas fa-${enabled ? 'sun' : 'moon'}" aria-hidden="true"></i>`;
            }
        }

        setDarkMode(savedTheme === 'dark');

        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                const enabled = !document.body.classList.contains('dark');
                setDarkMode(enabled);

                try {
                    localStorage.setItem('horizon-theme', enabled ? 'dark' : 'light');
                } catch (error) { }

                // Reload so every server-rendered / JS-painted component
                // picks up the new theme from a clean state.
                window.location.reload();
            });
        }

        const sidebar = document.getElementById('horizon-sidebar');
        const toggleBtn = document.getElementById('horizon-mobile-toggle');
        const overlay = document.getElementById('horizon-sidebar-overlay');
        const appLayout = document.getElementById('app-layout');
        const sidebarToggle = document.getElementById('horizon-sidebar-toggle');
        const sidebarExpand = document.getElementById('horizon-sidebar-expand');

        const DESKTOP_QUERY = window.matchMedia('(min-width: 1200px)');

        function openSidebar() {
            if (sidebar) sidebar.classList.add('show');
            if (overlay) overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            if (sidebar) sidebar.classList.remove('show');
            if (overlay) overlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        function setDesktopCollapsed(collapsed) {
            if (appLayout) appLayout.classList.toggle('sidebar-collapsed', collapsed);
            try {
                localStorage.setItem('horizon-sidebar-collapsed', collapsed ? '1' : '0');
            } catch (error) { }
        }

        // Apply saved desktop collapse state (icon-only rail)
        if (DESKTOP_QUERY.matches && appLayout) {
            try {
                if (localStorage.getItem('horizon-sidebar-collapsed') === '1') {
                    appLayout.classList.add('sidebar-collapsed');
                }
            } catch (error) { }
        }

        // Sidebar toggle: hamburger for mobile/tablet drawer
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (sidebar && sidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        // Desktop collapse/expand — buttons live inside the sidebar & on its edge
        // The in-sidebar toggle also closes the mobile drawer
        function bindCollapseToggle(btn) {
            if (!btn) return;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (DESKTOP_QUERY.matches) {
                    const collapsed = appLayout && appLayout.classList.contains('sidebar-collapsed');
                    setDesktopCollapsed(!collapsed);
                } else {
                    closeSidebar();
                }
            });
        }

        bindCollapseToggle(sidebarToggle);
        bindCollapseToggle(sidebarExpand);

        // ---- Collapsed Sidebar Hover Flyout (desktop only) ----
        const flyout = document.createElement('div');
        flyout.className = 'horizon-flyout';
        document.body.appendChild(flyout);

        let flyoutHideTimer = null;

        function hideFlyout() {
            flyoutHideTimer = setTimeout(function () {
                flyout.classList.remove('show');
            }, 150);
        }

        function cancelFlyoutHide() {
            if (flyoutHideTimer) {
                clearTimeout(flyoutHideTimer);
                flyoutHideTimer = null;
            }
        }

        function showFlyout(item) {
            cancelFlyoutHide();

            if (!DESKTOP_QUERY.matches || !appLayout || !appLayout.classList.contains('sidebar-collapsed')) {
                return;
            }

            const submenu = item.querySelector(':scope > .horizon-submenu');
            const navLink = item.querySelector(':scope > .horizon-nav-link');
            if (!navLink) return;

            flyout.innerHTML = '';

            if (submenu && submenu.children.length > 0) {
                // Clickable title — parent link is a '#' accordion, so navigate to first submenu entry
                const firstLink = submenu.querySelector('a');
                const label = navLink.querySelector('span');
                const title = document.createElement('a');
                title.className = 'horizon-flyout-title';
                title.href = firstLink ? firstLink.getAttribute('href') : '#';
                title.innerHTML = label ? label.textContent.trim() : '';
                flyout.appendChild(title);

                submenu.querySelectorAll('a').forEach(function (link) {
                    flyout.appendChild(link.cloneNode(true));
                });
            } else {
                const label = navLink.querySelector('span');
                if (!label) return;

                // Real clickable link mirroring the nav item
                const link = document.createElement('a');
                link.href = navLink.getAttribute('href');
                link.className = 'horizon-flyout-title';
                link.innerHTML = label.textContent.trim();
                flyout.appendChild(link);
            }

            const rect = item.getBoundingClientRect();

            // Measure with panel invisible, then position and reveal
            flyout.classList.add('show');
            const flyoutHeight = flyout.offsetHeight;
            const top = Math.max(10, Math.min(rect.top - 6, window.innerHeight - flyoutHeight - 10));
            flyout.style.top = top + 'px';
            flyout.style.left = (rect.right + 8) + 'px';
        }

        document.querySelectorAll('#horizon-sidebar .horizon-nav-item').forEach(function (item) {
            item.addEventListener('mouseenter', function () {
                showFlyout(item);
            });
            item.addEventListener('mouseleave', hideFlyout);
        });

        flyout.addEventListener('mouseenter', cancelFlyoutHide);
        flyout.addEventListener('mouseleave', hideFlyout);

        // Hide the flyout whenever the sidebar expands/collapses or viewport changes
        function dismissFlyout() {
            cancelFlyoutHide();
            flyout.classList.remove('show');
        }

        if (sidebarToggle) sidebarToggle.addEventListener('click', dismissFlyout);
        if (sidebarExpand) sidebarExpand.addEventListener('click', dismissFlyout);
        DESKTOP_QUERY.addEventListener('change', dismissFlyout);

        // Close sidebar when clicking overlay
        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        // Leaving desktop: drop the collapsed class so the mobile drawer behaves correctly
        DESKTOP_QUERY.addEventListener('change', function (e) {
            if (!e.matches && appLayout) {
                appLayout.classList.remove('sidebar-collapsed');
            }
        });

        // Submenu Accordions
        document.querySelectorAll('.has-horizon-submenu > a').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();

                const parent = this.parentElement;
                const submenu = parent.querySelector('.horizon-submenu');

                if (submenu) {
                    const isOpen = submenu.classList.contains('show');

                    // Close all submenus
                    document.querySelectorAll('.horizon-submenu')
                        .forEach(m => m.classList.remove('show'));

                    document.querySelectorAll('.has-horizon-submenu')
                        .forEach(p => p.classList.remove('open'));

                    // Open selected submenu
                    if (!isOpen) {
                        submenu.classList.add('show');
                        parent.classList.add('open');
                    }
                }
            });
        });
    });


    // Toast Notification System
    window.showAppToast = function (type, message, subtitle, duration) {

        type = (type === 'error') ? 'danger' : (type || 'info');
        duration = (duration === undefined) ? 4000 : duration;

        const icons = {
            success: 'fa-check-circle',
            danger: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        let stack = document.getElementById('horizon-toast-stack');

        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'horizon-toast-stack';
            stack.className = 'horizon-toast-stack';

            document.body.appendChild(stack);
        }

        const toast = document.createElement('div');
        toast.className = `horizon-toast horizon-toast-${type}`;

        const titleHtml = `
            <div class="horizon-toast-title">
                ${type.charAt(0).toUpperCase() + type.slice(1)}
            </div>
        `;

        const subHtml = subtitle
            ? `<div class="horizon-toast-message">${subtitle}</div>`
            : '';

        const msgHtml = `
            <div class="horizon-toast-message">
                ${message}
            </div>
        `;

        toast.innerHTML = `
            <div class="horizon-toast-icon">
                <i class="fas ${icons[type] || icons.info}"></i>
            </div>

            <div class="horizon-toast-content">
                ${titleHtml}
                ${msgHtml}
                ${subHtml}
            </div>

            <button type="button"
                    class="horizon-toast-close"
                    aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        `;

        // Close button
        toast.querySelector('.horizon-toast-close')
            .addEventListener('click', function () {

                toast.classList.add('hiding');

                setTimeout(() => toast.remove(), 300);
            });

        stack.appendChild(toast);

        // Auto close
        if (duration > 0) {
            setTimeout(function () {

                if (toast.parentNode) {

                    toast.classList.add('hiding');

                    setTimeout(() => toast.remove(), 300);
                }

            }, duration);
        }
    };

})();