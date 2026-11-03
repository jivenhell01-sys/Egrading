<?php
$role = $role ?? ($_SESSION['role'] ?? 'admin');
$allowedPages = $allowedPages ?? [];

$assessmentTabs = [
    'marks' => [
        'label' => 'Marks',
        'icon' => 'fas fa-star-half-alt',
        'file' => 'marks.php',
    ],
    'attendance' => [
        'label' => 'Attendance',
        'icon' => 'fas fa-calendar-check',
        'file' => 'attendance.php',
    ],
];

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'marks';
if (!isset($assessmentTabs[$activeTab])) {
    $activeTab = 'marks';
}

$visibleTabs = [];
foreach ($assessmentTabs as $key => $tab) {
    if (has_page_access($key, $role, $allowedPages)) {
        $visibleTabs[$key] = $tab;
    }
}

if (!isset($visibleTabs[$activeTab]) && !empty($visibleTabs)) {
    $activeTab = array_key_first($visibleTabs);
}

if (!function_exists('assessments_tab_url')) {
    function assessments_tab_url($tab)
    {
        $params = ['page' => 'assessments'];
        if ($tab !== 'assessments') {
            $params['tab'] = $tab;
        }
        return './?' . http_build_query($params);
    }
}
?>
<style>
    .assessments-hub .assessments-tabs-wrapper {
        background: #f1f5f9 !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 14px !important;
        padding: 6px !important;
        margin-bottom: 1.25rem !important;
        box-shadow: 0 2px 12px rgba(15, 23, 42, 0.06) !important;
    }

    .assessments-hub .assessments-tabs {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 6px !important;
        list-style: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border-bottom: none !important;
    }

    .assessments-hub .assessments-tabs .nav-item {
        flex: 1 1 auto !important;
        min-width: 140px !important;
        margin-bottom: 0 !important;
        border-radius: 10px !important;
        overflow: hidden !important;
        transition: all 0.25s ease !important;
    }

    .assessments-hub .assessments-tabs .nav-item:hover:not(.active) {
        background-color: #ffffff !important;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08) !important;
    }

    .assessments-hub .assessments-tabs .nav-item.active {
        background-color: #2563eb !important;
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35) !important;
    }

    .assessments-hub .assessments-tabs .nav-link {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.5rem !important;
        width: 100% !important;
        padding: 0.65rem 1.1rem !important;
        font-size: 0.9rem !important;
        font-weight: 500 !important;
        color: #64748b !important;
        text-decoration: none !important;
        border: none !important;
        border-radius: 10px !important;
        background: transparent !important;
        background-color: transparent !important;
        box-shadow: none !important;
        transition: all 0.25s ease !important;
    }

    .assessments-hub .assessments-tabs .nav-link i,
    .assessments-hub .assessments-tabs .nav-link span {
        color: inherit !important;
    }

    .assessments-hub .assessments-tabs .nav-link i {
        font-size: 0.95rem !important;
        opacity: 0.9 !important;
        transition: transform 0.25s ease, opacity 0.25s ease !important;
    }

    .assessments-hub .assessments-tabs .nav-item:hover:not(.active) .nav-link {
        color: #334155 !important;
    }

    .assessments-hub .assessments-tabs .nav-item:hover:not(.active) .nav-link i {
        transform: scale(1.06) !important;
        opacity: 1 !important;
    }

    .assessments-hub .assessments-tabs .nav-item.active .nav-link,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link:hover,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link:focus,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link:active {
        color: #ffffff !important;
        font-weight: 600 !important;
        background: transparent !important;
        background-color: transparent !important;
        border: none !important;
        box-shadow: none !important;
    }

    .assessments-hub .assessments-tabs .nav-item.active .nav-link i,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link span,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link:hover i,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link:hover span,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link:focus i,
    .assessments-hub .assessments-tabs .nav-item.active .nav-link:focus span {
        color: #ffffff !important;
        opacity: 1 !important;
    }

    .assessments-tab-panel {
        animation: assessmentsTabFadeIn 0.3s ease;
    }

    @keyframes assessmentsTabFadeIn {
        from {
            opacity: 0;
            transform: translateY(6px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width: 576px) {
        .assessments-hub .assessments-tabs .nav-item {
            min-width: 100% !important;
        }

        .assessments-hub .assessments-tabs .nav-link {
            justify-content: flex-start !important;
            padding: 0.75rem 1rem !important;
        }
    }
</style>

<div class="assessments-hub">
    <?php if (count($visibleTabs) > 1): ?>
    <div class="assessments-tabs-wrapper">
        <ul class="assessments-tabs" role="tablist">
            <?php foreach ($visibleTabs as $key => $tab): ?>
            <li class="nav-item <?php echo ($activeTab === $key) ? 'active' : '' ?>" role="presentation">
                <a class="nav-link"
                   href="<?php echo assessments_tab_url($key); ?>"
                   role="tab"
                   aria-selected="<?php echo ($activeTab === $key) ? 'true' : 'false'; ?>">
                    <i class="<?php echo $tab['icon']; ?>"></i>
                    <span><?php echo $tab['label']; ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="assessments-tab-panel">
        <?php
        if (isset($visibleTabs[$activeTab])) {
            include $visibleTabs[$activeTab]['file'];
        }
        ?>
    </div>
</div>
