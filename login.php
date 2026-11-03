<?php
// Configure session for ngrok compatibility
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', 
        'secure' => false, 
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
    if (isset($_SESSION['visited_website']) && $_SESSION['visited_website'] === true) {
        header("Location: website/website.php");
        exit();
    }
}

// Check if already logged in
if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0){
    header("Location:./");
    exit;
}

require_once('DBConnection.php');

// Auto-login via "Keep me logged in" cookies (restores session after
// browser restart even though the PHP session cookie itself is short-lived)
if (!isset($_SESSION['admin_id']) && isset($_COOKIE['hgs_remember_user'], $_COOKIE['hgs_remember_token'])) {
    $rm_uid = (int)$_COOKIE['hgs_remember_user'];
    $rm_token = (string)$_COOKIE['hgs_remember_token'];
    $rm_valid = false;
    if ($rm_uid > 0 && preg_match('/^[a-f0-9]{64}$/', $rm_token) && isset($conn)) {
        $rm_row = $conn->query("SELECT * FROM admin_list WHERE admin_id = '{$rm_uid}'")->fetchArray(SQLITE3_ASSOC);
        if ($rm_row && (int)($rm_row['status'] ?? 0) === 1
            && !empty($rm_row['remember_token']) && hash_equals((string)$rm_row['remember_token'], hash('sha256', $rm_token))
            && !empty($rm_row['remember_expiry']) && strtotime($rm_row['remember_expiry']) > time()) {
            foreach ($rm_row as $k => $v) {
                if (!is_numeric($k)) {
                    $_SESSION[$k] = $v;
                }
            }
            unset($_SESSION['remember_token'], $_SESSION['remember_expiry']);
            if (($rm_row['role'] ?? '') === 'faculty' && !empty($rm_row['db_path']) && file_exists($rm_row['db_path'])) {
                $_SESSION['db_file'] = $rm_row['db_path'];
            }
            // Rotate token on each auto-login
            $rm_new = bin2hex(random_bytes(32));
            $rm_exp = date('Y-m-d H:i:s', time() + 30 * 86400);
            $conn->exec("UPDATE admin_list SET remember_token = '" . hash('sha256', $rm_new) . "', remember_expiry = '{$rm_exp}', last_activity = CURRENT_TIMESTAMP WHERE admin_id = '{$rm_uid}'");
            $rm_cookie_exp = time() + 30 * 86400;
            setcookie('hgs_remember_user', (string)$rm_uid, ['expires' => $rm_cookie_exp, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
            setcookie('hgs_remember_token', $rm_new, ['expires' => $rm_cookie_exp, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
            $rm_valid = true;
        }
    }
    if ($rm_valid) {
        header("Location:./");
        exit;
    } else {
        // Stale/invalid cookies - drop them so we don't retry every visit
        setcookie('hgs_remember_user', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        setcookie('hgs_remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="uploads/Eastern_Samar_State_University_logo.ico" type="image/x-icon">
    <meta name="theme-color" content="#4318FF">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="HORIZON">
    <link rel="apple-touch-icon" href="students/icons/apple-touch-icon.png">
    <title>Sign In | Horizon Grading System</title>
    
    <!-- CSS & Libraries -->
    <link rel="stylesheet" href="./css/bootstrap.min.css">
    <link rel="stylesheet" href="./Font-Awesome-master/css/all.min.css">
    <link rel="stylesheet" href="./css/horizon-theme.css">
    
    <script src="./js/jquery-3.6.0.min.js"></script>
    <script src="./js/popper.min.js"></script>
    <script src="./js/bootstrap.min.js"></script>
    <script src="./js/horizon.js"></script>
    <script>window.HORIZON_SYNC_SERVER = 'https://essuc-academic.yzz.me/';</script>

    <style>
        .horizon-auth-wrapper {
            min-height: 100vh;
            display: flex;
            background-color: var(--horizon-bg-main);
            transition: background-color 0.3s ease;
        }

        .horizon-auth-form-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 40px;
            max-width: 580px;
            margin: 0 auto;
        }

        .horizon-auth-hero-column {
            flex: 1.1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: var(--horizon-brand-linear);
            border-bottom-left-radius: 160px;
            padding: 60px;
            color: #FFFFFF;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .horizon-auth-hero-column::after {
            content: '';
            position: absolute;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
            top: 10%;
            right: 10%;
            pointer-events: none;
        }

        .horizon-auth-badge {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 16px;
            padding: 16px 20px;
            margin-top: 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            text-align: left;
            width: 100%;
            max-width: 440px;
            transition: transform 0.2s ease;
        }

        .horizon-auth-badge:hover {
            transform: translateX(6px);
            background: rgba(255, 255, 255, 0.2);
        }

        .horizon-auth-badge-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 12px;
            background: #FFFFFF;
            color: var(--horizon-brand-500);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .horizon-floating-theme-btn {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 1050;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--horizon-card-bg);
            border: 1px solid var(--horizon-card-border);
            box-shadow: var(--horizon-card-shadow);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--horizon-text-title);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .horizon-floating-theme-btn:hover {
            transform: scale(1.1);
        }
    </style>
</head>
<body>
    <div class="horizon-auth-wrapper">
        <!-- LEFT: Sign In Form Section (Horizon UI SignIn Layout) -->
        <div class="horizon-auth-form-column">
            <!-- Brand Info -->
            <div class="d-flex align-items-center gap-3 mb-4">
                <img src="uploads/Eastern_Samar_State_University_logo.ico" alt="ESSU" style="width: 42px; height: 42px; border-radius: 12px;" onerror="this.style.display='none'">
                <div>
                    <h5 class="m-0 fw-bold" style="letter-spacing: -0.5px;">HORIZON <span class="text-primary">GRADING</span></h5>
                    <small class="text-muted fw-semibold">Eastern Samar State University</small>
                </div>
            </div>

            <!-- Header Titles -->
            <h2 class="fw-bold mb-1" style="font-size: 32px; letter-spacing: -0.03em;">Sign In</h2>
            <p class="text-muted mb-4 fs-6">Enter your username and password to access your dashboard!</p>

            <!-- Access Denied Banner -->
            <div id="access-denied-alert" class="horizon-flash-banner horizon-flash-danger" style="display: none;">
                <i class="fas fa-exclamation-triangle fs-5"></i>
                <div id="access-denied-message" class="fw-bold"></div>
            </div>

            <!-- Login Form -->
            <form action="" method="POST" id="login-form">
                <!-- Username Field -->
                <div class="mb-3">
                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user text-muted"></i></span>
                        <input type="text" id="username" name="username" class="form-control" placeholder="e.g. admin or faculty_id" required autocomplete="username">
                    </div>
                </div>

                <!-- Password Field -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="password" class="form-label mb-0">Password <span class="text-danger">*</span></label>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="btn btn-outline-secondary" style="border-radius: 0 12px 12px 0;" onclick="togglePassword()" aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="toggle-icon"></i>
                        </button>
                    </div>
                </div>

                <!-- Checkbox & Forgot Info -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="rememberMe" name="rememberMe" value="1">
                        <label class="form-check-label" for="rememberMe">Keep me logged in</label>
                    </div>
                    <span class="text-muted" style="font-size: 13px;">Faculty / Admin Portal</span>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-gradient w-100 py-3 mb-3 fw-bold" style="font-size: 15px;">
                    <span class="btn-text"><i class="fas fa-sign-in-alt me-2"></i> Sign In</span>
                </button>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">
                    <i class="fas fa-shield-alt text-success me-1"></i> Protected by ESSU Role Authentication
                </small>
            </div>
        </div>

        <!-- RIGHT: Horizon Hero Banner (Curved gradient split layout) -->
        <div class="horizon-auth-hero-column d-none d-lg-flex">
            <div class="mb-4 text-center">
                <div style="width: 100px; height: 100px; border-radius: 28px; background: rgba(255,255,255,0.2); backdrop-filter: blur(15px); border: 2px solid rgba(255,255,255,0.3); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <img src="uploads/Eastern_Samar_State_University_logo.ico" alt="University Seal" style="width: 70px; height: 70px;" onerror="this.style.display='none'">
                </div>
                <h3 class="text-white fw-bold mb-2" style="font-size: 28px; letter-spacing: -0.5px;">ESSU Grading & Performance System</h3>
                <p class="text-white-50" style="max-width: 480px; margin: 0 auto; font-size: 15px;">
                    Next-generation academic evaluation, transmutation calculation, and AI-driven performance prediction.
                </p>
            </div>

            <!-- Feature Highlight Badges -->
            <div class="horizon-auth-badge">
                <div class="horizon-auth-badge-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div>
                    <h6 class="text-white fw-bold mb-0" style="font-size: 14px;">Multi-Faculty Class Management</h6>
                    <small class="text-white-50">Seamless database switching per term & section</small>
                </div>
            </div>

            <div class="horizon-auth-badge">
                <div class="horizon-auth-badge-icon">
                    <i class="fas fa-brain"></i>
                </div>
                <div>
                    <h6 class="text-white fw-bold mb-0" style="font-size: 14px;">AI Student Performance Prediction</h6>
                    <small class="text-white-50">Component-based risk analysis & trend forecasting</small>
                </div>
            </div>

            <div class="horizon-auth-badge">
                <div class="horizon-auth-badge-icon">
                    <i class="fas fa-file-export"></i>
                </div>
                <div>
                    <h6 class="text-white fw-bold mb-0" style="font-size: 14px;">Automated Grade Transmutation</h6>
                    <small class="text-white-50">Export to official Word & Excel report sheets</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts for Authentication & Security -->
    <script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggle-icon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.className = 'fas fa-eye-slash';
        } else {
            passwordInput.type = 'password';
            toggleIcon.className = 'fas fa-eye';
        }
    }
    
    $(function(){
        // Restore remembered username (password is never stored by us -
        // the browser's own password manager handles that)
        try {
            var savedUser = localStorage.getItem('hgs_remember_username');
            if (savedUser) {
                $('#username').val(savedUser);
                $('#rememberMe').prop('checked', true);
            }
        } catch(e) {}
        $('#login-form').submit(function(e){
            e.preventDefault();
            $('.pop_msg').remove();
            var _this = $(this);
            var _el = $('<div>').addClass('pop_msg horizon-flash-banner horizon-flash-info mb-3');
            
            _this.find('button[type="submit"]').attr('disabled', true);
            _this.find('.btn-text').html('<i class="fas fa-spinner fa-spin me-2"></i> Signing in...');
            
            $.ajax({
                url: './Actions.php?a=login',
                method: 'POST',
                data: $(this).serialize(),
                dataType: 'JSON',
                error: function(err){
                    console.error(err);
                    _el.removeClass('horizon-flash-info').addClass('horizon-flash-danger');
                    _el.html('<i class="fas fa-exclamation-circle fs-5 me-2"></i> An unexpected connection error occurred.');
                    _this.prepend(_el);
                    _this.find('button[type="submit"]').attr('disabled', false);
                    _this.find('.btn-text').html('<i class="fas fa-sign-in-alt me-2"></i> Sign In');
                },
                success: function(resp){
                    if(resp.status === 'success'){
                        try {
                            if ($('#rememberMe').is(':checked')) {
                                localStorage.setItem('hgs_remember_username', $('#username').val());
                            } else {
                                localStorage.removeItem('hgs_remember_username');
                            }
                            // Cache session for offline workspace display (offline.html)
                            if (window.HorizonSync) {
                                window.HorizonSync.saveSessionSnapshot({ username: $('#username').val() });
                            }
                        } catch(e) {}
                        _el.removeClass('horizon-flash-info horizon-flash-danger').addClass('horizon-flash-success');
                        _el.html('<i class="fas fa-check-circle fs-5 me-2"></i> ' + resp.msg + ' Redirecting...');
                        _this.prepend(_el);
                        setTimeout(() => {
                            location.replace('./');
                        }, 1200);
                    } else {
                        _el.removeClass('horizon-flash-info').addClass('horizon-flash-danger');
                        _el.html('<i class="fas fa-exclamation-triangle fs-5 me-2"></i> ' + resp.msg);
                        _this.prepend(_el);
                        _this.find('button[type="submit"]').attr('disabled', false);
                        _this.find('.btn-text').html('<i class="fas fa-sign-in-alt me-2"></i> Sign In');
                    }
                }
            });
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        const accessDenied = sessionStorage.getItem('accessDenied');
        if (accessDenied) {
            const alertDiv = document.getElementById('access-denied-alert');
            const messageDiv = document.getElementById('access-denied-message');
            
            messageDiv.textContent = accessDenied;
            alertDiv.style.display = 'flex';
            sessionStorage.removeItem('accessDenied');
            
            setTimeout(() => {
                alertDiv.style.display = 'none';
            }, 10000);
        }
    });
    </script>
<script>
// One-time cleanup: the offline service worker (sw.js) was retired and deleted
// from the server, but browsers that installed it keep running the stale copy,
// which rejects every fetch (e.g. favicon.ico) with "Failed to convert value
// to 'Response'". Force-unregister it and drop its caches. Safe to delete this
// block once every device has loaded any page at least once.
(function(){
  if (!('serviceWorker' in navigator)) return;
  try {
    navigator.serviceWorker.getRegistrations().then(function(regs){
      regs.forEach(function(r){ try { r.unregister(); } catch(e){} });
    }).catch(function(){});
    if (window.caches && caches.keys) {
      caches.keys().then(function(keys){
        keys.forEach(function(k){
          if (/horizon/i.test(k)) { try { caches.delete(k); } catch(e){} }
        });
      }).catch(function(){});
    }
  } catch(e){}
})();
</script>
</body>
</html>