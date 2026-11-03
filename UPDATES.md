                                            <?php echo htmlspecialchars($assessment_row['name'] . ' - ' . $assessment_row['component_name'] . ' (' . $assessment_row['percentage'] . '%, HPS: ' . $assessment_row['hps'] . ')') ?>
                                             
                                             'percentage' => $row['percentage'],
                            'hps' => $row['hps']

# Grading System v2.0.0 Update

## Update Summary
This update includes significant improvements to the system's structure, design, and responsiveness.

## Changes Made

### File Structure
- Reorganized assets into a dedicated `/assets` directory
- Created a modular structure for better code organization
- Added proper separation of concerns

### Design Updates
- Modern, clean UI with improved typography
- Responsive design that works on all devices
- Consistent color scheme and spacing
- Improved sidebar navigation

### Performance
- Optimized asset loading
- Minified CSS and JavaScript
- Improved database queries

## How to Apply Updates
1. Backup your database before proceeding
2. Upload all new files to your server
3. Run `update.php` in your browser
4. Clear your browser cache

## Rollback Instructions
If you need to rollback:
1. Restore your database from backup
2. Replace the updated files with your backup
3. Clear your browser cache

## Support
For any issues, please contact support@example.com