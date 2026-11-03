

<?php
// Security Protection System
// This file provides security protection across all pages
// Applies to logged-in users globally when enabled by admin
?>


<!-- Security Protection CSS -->
<style>
    @keyframes securitySlideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .security-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: Arial, sans-serif;
    }
    
    .security-message-box {
        background: white;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        max-width: 400px;
        text-align: center;
        animation: securitySlideIn 0.3s ease-out;
    }
</style>

<!-- Security Protection JavaScript -->
<script>
// Security Protection System - Global Protection Only
let securityEnabled = false;
let userLoggedIn = <?php echo (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) ? 'true' : 'false'; ?>;

// Check current security status from database
function checkSecurityStatus() {
    // First check for global security state (admin toggle)
    checkGlobalSecurityState();
    
    // Then check database if no global state is set
    const globalState = localStorage.getItem('globalSecurityState');
    if (!globalState) {
        // First try to get from database, fallback to localStorage
        $.ajax({
            url: './security_settings.php',
            method: 'GET',
            dataType: 'JSON',
            success: function(response) {
                if (response.status === 'success') {
                    securityEnabled = response.security_enabled;
                    // // console.log('Security status from DB - Enabled:', securityEnabled);
                    
                    // Sync with localStorage for backup
                    localStorage.setItem('securityProtection', securityEnabled.toString());
                    
                    if (securityEnabled && userLoggedIn) {
                        enableBasicProtection();
                    }
                } else {
                    // console.log('Failed to get DB setting, using localStorage');
                    fallbackToLocalStorage();
                }
            },
            error: function() {
                // console.log('DB request failed, using localStorage');
                fallbackToLocalStorage();
            }
        });
    }
}

// Fallback to localStorage method
function fallbackToLocalStorage() {
    // Only apply if user is logged in
    if (!userLoggedIn) {
        // console.log('User is not logged in - skipping security protection');
        return;
    }
    
    securityEnabled = localStorage.getItem('securityProtection') === 'true';
    // console.log('Security status from localStorage - Enabled:', securityEnabled);
    
    if (securityEnabled) {
        enableBasicProtection();
    }
}

// Enable basic security protection (right-click, dev tools, view source, inspect)
function enableBasicProtection() {
    // Enable for logged-in users regardless of domain
    if (!userLoggedIn) {
        // console.log('User is not logged in - security protection disabled');
        return;
    }
    
    // console.log('Enabling basic security protection for logged-in user...');
    
    // Remove any existing security event listeners to prevent duplicates
    removeSecurityEventListeners();
    
    // Store event listener references for proper removal
    window.contextMenuHandler = function(e) {
        e.preventDefault();
        e.stopPropagation();
        showSecurityMessage('Right-click is disabled for security protection.');
        return false;
    };
    
    window.keyboardHandler = function(e) {
        if (e.keyCode === 123 || // F12
            (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) || // Ctrl+Shift+I/J/C
            (e.ctrlKey && e.keyCode === 85)) { // Ctrl+U
            e.preventDefault();
            e.stopPropagation();
            showSecurityMessage('Developer tools shortcut disabled for security protection.');
            return false;
        }
    };
    
    // Add event listeners with proper references
    document.addEventListener('contextmenu', window.contextMenuHandler, true);
    document.addEventListener('keydown', window.keyboardHandler, true);
    
    // Start developer tools detection
    startDevToolsDetection();
    
    // console.log('Basic security protection enabled for logged-in user');
}

// Separate handler functions for easy removal
function contextMenuHandler(e) {
    e.preventDefault();
    e.stopPropagation();
    showSecurityMessage('Right-click is disabled for security protection.');
    return false;
}

function keyboardHandler(e) {
    if (e.keyCode === 123 || // F12
        (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) || // Ctrl+Shift+I/J/C
        (e.ctrlKey && e.keyCode === 85)) { // Ctrl+U
        e.preventDefault();
        e.stopPropagation();
        showSecurityMessage('Developer tools shortcut disabled for security protection.');
        return false;
    }
}

function startDevToolsDetection() {
    // Clear any existing detection interval
    if (window.devToolsInterval) {
        clearInterval(window.devToolsInterval);
    }
    
    // Start new detection interval
    window.devToolsInterval = setInterval(() => {
        if (securityEnabled && userLoggedIn) {
            const threshold = 160;
            if (window.outerHeight - window.innerHeight > threshold || 
                window.outerWidth - window.innerWidth > threshold) {
                // Make page blank instead of showing message
                makePageBlank();
            }
        }
    }, 1000);
}

// Make page blank when developer tools are detected
function makePageBlank() {
    // console.log('Developer tools detected - making page blank');
    
    // Hide entire body content
    document.body.style.visibility = 'hidden';
    document.body.innerHTML = '';
    
    // Add a blank background with Access Denied text
    document.body.style.background.color = 'black';
    document.body.style.margin = '0';
    document.body.style.padding = '0';
    document.body.style.height = '100vh';
    document.body.style.width = '100vw';
    document.body.style.display = 'flex';
    document.body.style.alignItems = 'center';
    document.body.style.justifyContent = 'center';
    document.body.style.fontFamily = 'Arial, sans-serif';
    document.body.style.position = 'relative';
    document.body.style.visibility = 'visible';
    
    // Add Access Denied text
    const accessDeniedText = document.createElement('div');
    accessDeniedText.style.color = '#dc3545';
    accessDeniedText.style.fontSize = '78px';
    accessDeniedText.style.fontWeight = 'bold';
    accessDeniedText.style.textAlign = 'center';
    accessDeniedText.style.textShadow = '2px 2px 4px rgba(0,0,0,0.8), 0 0 20px rgba(220,53,69,0.5)';
    accessDeniedText.style.backgroundColor = 'rgba(0,0,0,0.7)';
    accessDeniedText.style.padding = '20px 40px';
    accessDeniedText.style.borderRadius = '10px';
    accessDeniedText.style.border = '2px solid #dc3545';
    accessDeniedText.textContent = 'Access Denied | University Policy!!';
    document.body.appendChild(accessDeniedText);
    
    // Prevent any further interaction
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
    }, true);
    
    document.addEventListener('keydown', function(e) {
        e.preventDefault();
        return false;
    }, true);
    
    // Stop all scripts and intervals
    if (window.devToolsInterval) {
        clearInterval(window.devToolsInterval);
    }
    
    // Clear console
    // console.clear();
}

function removeSecurityEventListeners() {
    // console.log('Removing security event listeners...');
    
    // Remove context menu handler if it exists
    if (window.contextMenuHandler) {
        document.removeEventListener('contextmenu', window.contextMenuHandler, true);
        delete window.contextMenuHandler;
        // console.log('Context menu handler removed');
    }
    
    // Remove keyboard handler if it exists
    if (window.keyboardHandler) {
        document.removeEventListener('keydown', window.keyboardHandler, true);
        delete window.keyboardHandler;
        // console.log('Keyboard handler removed');
    }
    
    // Clear dev tools detection
    if (window.devToolsInterval) {
        clearInterval(window.devToolsInterval);
        window.devToolsInterval = null;
        // console.log('Dev tools detection cleared');
    }
    
    // Remove any existing security overlays
    const overlays = document.querySelectorAll('.security-modal-overlay');
    overlays.forEach(overlay => {
        overlay.remove();
        // console.log('Security overlay removed');
    });
    
    // console.log('All security event listeners removed');
}

// Show security message popup
function showSecurityMessage(message) {
    // Don't show messages if user is not logged in OR if security is disabled
    if (!userLoggedIn || !securityEnabled) {
        // console.log('Skipping security message - user not logged in or security disabled');
        return;
    }

    // Remove any existing security messages
    const existingOverlay = document.querySelector('.security-modal-overlay');
    if (existingOverlay) {
        existingOverlay.remove();
    }

    // Create modal overlay
    const overlay = document.createElement('div');
    overlay.className = 'security-modal-overlay';

    // Create message box
    const messageBox = document.createElement('div');
    messageBox.className = 'security-message-box';

    messageBox.innerHTML = `
        <div style="color: #dc3545; font-size: 48px; margin-bottom: 15px;">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h3 style="color: #dc3545; margin-bottom: 15px;">Security Alert</h3>
        <p style="color: #666; margin-bottom: 20px; line-height: 1.5;">${message}</p>
        <button onclick="this.closest('.security-modal-overlay').remove()" 
                style="background: #dc3545; color: white; border: none; padding: 10px 20px; 
                       border-radius: 5px; cursor: pointer; font-size: 14px;">
            Mr. Cada Enabled the Security !
        </button>
    `;

    overlay.appendChild(messageBox);
    document.body.appendChild(overlay);

    // Auto-close after 2 seconds
    setTimeout(() => {
        if (overlay.parentNode) {
            overlay.remove();
        }
    }, 2000);

    // Close on overlay click
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.remove();
        }
    });
}

// Listen for global security changes from admin panel
    window.addEventListener('globalSecurityChange', function(event) {
        const { enabled, timestamp, source } = event.detail;
        // console.log('Security protection received global change event:', enabled, 'from:', source);
        
        // Update local security state
        securityEnabled = enabled;
        
        // Apply the security change immediately for all logged-in users
        if (enabled && userLoggedIn) {
            enableBasicProtection();
        } else {
            disableSecurityProtection();
        }
    });

    // Listen for storage events (cross-tab synchronization)
    window.addEventListener('storage', function(event) {
        if (event.key === 'globalSecurityState') {
            try {
                const securityState = JSON.parse(event.newValue);
                // console.log('Security protection state changed in another tab:', securityState);
                
                // Update local security state
                securityEnabled = securityState.enabled;
                
                // Apply the change for logged-in users
                if (securityState.enabled && userLoggedIn) {
                    enableBasicProtection();
                } else {
                    disableSecurityProtection();
                }
            } catch (e) {
                // console.error('Error parsing global security state:', e);
            }
        }
    });

    // Listen for postMessage security changes
    window.addEventListener('message', function(event) {
        if (event.data && event.data.type === 'SECURITY_CHANGE') {
            // console.log('Security protection received change via postMessage:', event.data);
            
            // Update local security state
            securityEnabled = event.data.enabled;
            
            // Apply the change for logged-in users
            if (event.data.enabled && userLoggedIn) {
                enableBasicProtection();
            } else {
                disableSecurityProtection();
            }
        }
    });

    // Check for global security state on page load
    function checkGlobalSecurityState() {
        try {
            const globalState = localStorage.getItem('globalSecurityState');
            // console.log('Checking global security state:', globalState);
            
            if (globalState) {
                const securityState = JSON.parse(globalState);
                // console.log('Found global security state on load:', securityState);
                
                // Update local security state
                securityEnabled = securityState.enabled;
                // console.log('Updated securityEnabled from global state:', securityEnabled);
                
                // Apply the state for logged-in users
                if (securityState.enabled && userLoggedIn) {
                    // console.log('Global state is ENABLED and user is logged in - applying security');
                    enableBasicProtection();
                } else {
                    // console.log('Global state is DISABLED or user not logged in - removing security');
                    disableSecurityProtection();
                }
                return true; // Global state found and applied
            }
            // console.log('No global security state found');
            return false; // No global state found
        } catch (e) {
            // console.error('Error checking global security state:', e);
            return false;
        }
    }

// Initialize security status on page load
function initializeSecurity() {
    try {
        checkGlobalSecurityState();

        if (userLoggedIn) {
            checkSecurityStatus();
        }
    } catch (error) {
        // console.error('Error initializing security protection:', error);
    }
}

// Multiple initialization methods to ensure it loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSecurity);
} else {
    // DOM is already loaded
    initializeSecurity();
}

// Fallback initialization
window.addEventListener('load', function() {
    if (!window.securityEnabled) {
        // console.log('Fallback security initialization');
        initializeSecurity();
    }
});

function disableSecurityProtection() {
    // Disable security protection instantly
    // console.log('Security protection disabled instantly - removing all restrictions');
    securityEnabled = false;
    
    // Update localStorage
    localStorage.setItem('securityProtection', 'false');
    
    // Remove security event listeners and clear intervals
    removeSecurityEventListeners();
    
    // console.log('All security protection removed without page reload');
}

// Make functions globally accessible
window.checkSecurityStatus = checkSecurityStatus;
window.enableBasicProtection = enableBasicProtection;
window.disableSecurityProtection = disableSecurityProtection;
window.contextMenuHandler = contextMenuHandler;
window.keyboardHandler = keyboardHandler;
window.startDevToolsDetection = startDevToolsDetection;
window.removeSecurityEventListeners = removeSecurityEventListeners;
window.makePageBlank = makePageBlank;
window.securityEnabled = securityEnabled;
window.userLoggedIn = userLoggedIn;
</script>
