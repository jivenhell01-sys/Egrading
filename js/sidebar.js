$(document).ready(function () {
    // Toggle sidebar on button click
    $('#sidebarCollapse').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $('#sidebar').toggleClass('active');
        $('#content').toggleClass('expanded');
    });

    // Close sidebar when clicking outside on mobile
    $(document).on('click', function (e) {
        if ($(window).width() <= 768) {
            if (!$(e.target).closest('#sidebar, #sidebarCollapse').length) {
                $('#sidebar').removeClass('active');
                $('#content').addClass('expanded');
            }
        }
    });

    // Handle submenu toggling
    $('.has-submenu > a').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $submenu = $(this).next('.submenu');
        var $parent = $(this).parent();
        
        // Close other open submenus
        $('.has-submenu').not($parent).removeClass('active');
        $('.submenu').not($submenu).slideUp(200);
        
        // Toggle current submenu
        if ($submenu.is(':visible')) {
            $submenu.slideUp(200);
            $parent.removeClass('active');
        } else {
            $submenu.slideDown(250);
            $parent.addClass('active');
        }
    });
    
    // Close submenus when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.has-submenu').length) {
            $('.has-submenu').removeClass('active');
            $('.submenu').slideUp(200);
        }
    });

    // Handle window resize
    $(window).on('resize', function () {
        if ($(window).width() > 768) {
            $('#sidebar').removeClass('active');
            $('#content').removeClass('expanded');
        }
    });

    // Set active state for current page
    function setActiveMenu() {
        var currentPage = window.location.pathname.split('/').pop().split('.')[0];
        var pageParam = new URLSearchParams(window.location.search).get('page');
        
        if (currentPage === 'index' || currentPage === '') {
            currentPage = 'home';
        } else if (pageParam) {
            currentPage = pageParam;
        }
        
        // Remove active class from all links
        $('.nav-link').removeClass('active');
        
        // Find and activate the current page link
        $('.nav-link').each(function () {
            var href = $(this).attr('href');
            if (href) {
                var linkPage = href.split('page=')[1];
                if (linkPage === currentPage) {
                    $(this).addClass('active');
                    // Show and activate parent menus
                    $(this).closest('.submenu').addClass('show').show();
                    $(this).closest('.has-submenu').addClass('active');
                }
            }
        });
    }
    
    // Initialize menu state
    setActiveMenu();
    
    // Update menu state when navigating with browser back/forward buttons
    $(window).on('popstate', function() {
        setActiveMenu();
    });
});
