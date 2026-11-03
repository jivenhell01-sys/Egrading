<?php
/**
 * Modern Design Application Script
 * This script will apply modern design to all specified pages
 */

// Pages to update
$pages = [
    'assessments.php',
    'attendance.php', 
    'maintenance.php',
    'marks.php',
    'manage_account.php',
    'manage_assessment.php',
    'manage_class.php',
    'manage_component.php',
    'manage_percentage.php',
    'manage_student.php',
    'manage_subject.php',
    'manage_user.php',
    'report_of_grades.php',
    'reports.php',
    'students.php',
    'transmutation_table.php',
    'users.php'
];

// Modern header template content
$header_template = '<?php
// Set page variables
$page_icon = "fas fa-file";
$page_title = ucfirst(str_replace("_", " ", basename(__FILE__, ".php")));
$page_subtitle = "Manage your system efficiently";

// Include modern header
require_once("includes/modern-header.php");
?>';

// Modern footer template content
$footer_template = '
<script>
// Modern interactions
document.addEventListener("DOMContentLoaded", function() {
    // Add hover effects to cards
    const cards = document.querySelectorAll(".card");
    cards.forEach(card => {
        card.addEventListener("mouseenter", function() {
            this.style.transform = "translateY(-5px)";
        });
        card.addEventListener("mouseleave", function() {
            this.style.transform = "translateY(0)";
        });
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll(\'a[href^="#"]\').forEach(anchor => {
        anchor.addEventListener("click", function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute("href")).scrollIntoView({
                behavior: "smooth"
            });
        });
    });
});
</script>';

echo "Modern design application script ready.\n";
echo "Pages to update: " . implode(", ", $pages) . "\n";
echo "\nTo apply modern design to a page, add this at the beginning:\n";
echo $header_template;
echo "\nAnd add this at the end:\n";
echo $footer_template;
?>
