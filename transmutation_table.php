<?php 
error_reporting(0);
ini_set('display_errors', 0);
require_once('DBConnection.php'); 
$conn = new DBConnection();
ensure_transmutation_systems($conn);
$active_trans_system = get_active_transmutation_system($conn);
$view_trans_system = strtoupper(trim($_GET['system'] ?? $active_trans_system));
if ($view_trans_system !== 'CHED' && $view_trans_system !== 'DEPED') {
    $view_trans_system = $active_trans_system;
}
function transmutation_remarks($grade, $system) {
    $equiv_num = floatval($grade);
    if ($system === 'DEPED') {
        // Official DepEd table: transmuted 75+ passes, below 75 fails.
        return $equiv_num >= 75 ? 'PASSED' : 'FAILED';
    }
    if ($equiv_num >= 5.5) {
        return 'DROPPED';
    }
    if ($equiv_num >= 3.1 && $equiv_num <= 3.5) {
        return 'CONDITIONAL';
    }
    if ($equiv_num > 3.5) {
        return 'FAILED';
    }
    return 'PASSED';
}
?>

<style>
    #uni_modal .modal-footer{
        display: none !important;
    }
    .editable-cell {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .editable-cell:hover {
        background-color: #f8f9fa;
    }
    .editable-cell:focus {
        outline: 2px solid #007bff;
        background-color: #fff;
    }
    .changed {
        background-color: #fff3cd !important;
    }
</style>
<div class="container-fluid">
    <div class="col-12">
        <div class="row align-items-end mb-2">
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-1" for="transSystemSelect">Grading System</label>
                <select id="transSystemSelect" class="form-select form-select-sm fw-bold">
                    <option value="CHED" <?php echo $view_trans_system === 'CHED' ? 'selected' : ''; ?>>CHED (Tertiary 1.0–5.0)</option>
                    <option value="DEPED" <?php echo $view_trans_system === 'DEPED' ? 'selected' : ''; ?>>DEPED (K-12, pass 75)</option>
                </select>
            </div>
            <div class="col-md-8 d-flex align-items-center gap-2 flex-wrap">
                <span class="badge <?php echo $active_trans_system === 'DEPED' ? 'bg-success' : 'bg-primary'; ?>" id="transActiveBadge">Active in computation: <?php echo $active_trans_system; ?></span>
                <button class="btn btn-sm btn-outline-light rounded-1 fw-bold" id="setActiveSystem" type="button" title="Use the viewed system for all grade computations">
                    <i class="fas fa-check-circle me-1"></i>Use <span id="setActiveSystemLabel"><?php echo $view_trans_system; ?></span> for computation
                </button>
                <small class="text-muted" id="transSystemHint"><?php echo $view_trans_system === 'DEPED' ? 'Official DepEd (DO 8, s. 2015) table: initial → transmuted (60 floor, 60.00–61.59 → 75 pass). E.g. 83.33 → 89.' : 'CHED rows are the original tertiary 1.0–5.0 table.'; ?></small>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12 overflow-auto" style="height:70vh">
                <table class="table table-hover table-striped table-bordered">
                    <thead >
                        <tr class="position-sticky" >
                            <th class="px-1 py-0 text-center" style="height:20px !important;">
                                <input type="checkbox" id="selectAll" class="form-check-input">
                            </th>
                            <th class="px-1 py-0 text-center" style="height:20px !important;">FROM</th>
                            <th class="px-1 py-0 text-center" style="height:20px !important;">To</th>
                            <th class="px-1 py-0 text-center" style="height:20px !important;">Equivalent (1.0–5.0)</th>
                            <th class="px-1 py-0 text-center" style="height:20px !important;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $transmutaion_query = $conn->query("SELECT * FROM `transmutation_table` order by `system` asc, CAST(grade as integer) asc");
                        while($row = $transmutaion_query->fetchArray()):
                            $row_system = strtoupper(trim($row['system'] ?? 'CHED'));
                            if ($row_system !== 'CHED' && $row_system !== 'DEPED') $row_system = 'CHED';
                        ?>
                        <tr class="trans-item" data-id="<?php echo $row['trans_id']; ?>" data-system="<?php echo $row_system; ?>" style="<?php echo $row_system === $view_trans_system ? '' : 'display:none;'; ?>">
                            <td class="px-1 py-0 text-center">
                                <input type="checkbox" class="row-select form-check-input" data-id="<?php echo $row['trans_id']; ?>">
                            </td>
                            <td class="px-1 py-0 text-center from editable-cell" contenteditable="true"><?php echo $row['from'] ?></td>
                            <td class="px-1 py-0 text-center to editable-cell" contenteditable="true"><?php echo $row['to'] ?></td>
                            <td class="px-1 py-0 text-center grade editable-cell" contenteditable="true"><?php 
                                echo number_format($row['grade'], 1);
                            ?></td>
                            <td class="px-1 py-0 text-center remarks"><?php echo transmutation_remarks($row['grade'], $row_system); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="row justify-content-between">
            <div class="col-auto px-2">
                <button class="btn btn-sm btn-success rounded-1 fw-bold " id="addRow" style="margin-top: 15px; margin-bottom: 5px;" type="button">
                    <i class="fas fa-plus me-1"></i>Add Row
                </button>
                <button class="btn btn-sm btn-danger rounded-1 fw-bold " id="deleteSelected" style="margin-top: 15px; margin-bottom: 5px; margin-left: 5px;" type="button" disabled>
                    <i class="fas fa-trash me-1"></i>Delete Selected
                </button>
            </div>
            <div class="col-auto px-2">
                <button class="btn btn-sm btn-primary rounded-1 fw-bold " id="saveTransmutation" style="margin-top: 15px; margin-bottom: 5px;" type="button">Save Changes</button>
                <button class="btn btn-sm btn-dark rounded-1 fw-bold " style="margin-top: 15px; margin-bottom: 5px; margin-left: 5px;" type="button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
    // Custom toast notification function
    function showAlert(message, type = 'info') {
        // Remove any existing toasts
        $('.custom-toast').remove();
        
        // Create toast element
        var toastClass = 'bg-' + (type === 'danger' ? 'danger' : type === 'success' ? 'success' : 'info');
        var icon = type === 'danger' ? 'fas fa-exclamation-circle' : type === 'success' ? 'fas fa-check-circle' : 'fas fa-info-circle';
        
        var toast = $('<div class="custom-toast ' + toastClass + ' text-white position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999; min-width: 300px;">' +
            '<div class="d-flex align-items-center p-3">' +
            '<i class="' + icon + ' me-2"></i>' +
            '<div class="flex-grow-1">' + message + '</div>' +
            '</div>' +
            '</div>');
        
        $('body').append(toast);
        
        // Auto remove after 1.5 seconds
        setTimeout(function() {
            toast.fadeOut(300, function() {
                $(this).remove();
            });
        }, 1500);
    }

    $(function(){
        var transSystemHints = {
            'CHED': 'CHED rows are the original tertiary 1.0–5.0 table.',
            'DEPED': 'Official DepEd (DO 8, s. 2015) table: initial → transmuted (60 floor, 60.00–61.59 → 75 pass). E.g. 83.33 → 89.'
        };
        function currentTransSystem() {
            return $('#transSystemSelect').val() === 'DEPED' ? 'DEPED' : 'CHED';
        }
        function applyTransSystemFilter() {
            var sys = currentTransSystem();
            $('.trans-item').each(function(){
                var show = ($(this).data('system') || 'CHED') === sys;
                $(this).toggle(show);
                if (!show) {
                    $(this).find('.row-select').prop('checked', false);
                }
            });
            $('#selectAll').prop('checked', false);
            updateDeleteButton();
            $('#setActiveSystemLabel').text(sys);
            $('#transSystemHint').text(transSystemHints[sys] || '');
        }
        $('#transSystemSelect').on('change', applyTransSystemFilter);

        // Set the viewed system as the one used by all grade computations
        $('#setActiveSystem').on('click', function(){
            var sys = currentTransSystem();
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url: 'set_transmutation_system.php',
                method: 'POST',
                data: { system: sys },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#transActiveBadge')
                            .text('Active in computation: ' + sys)
                            .removeClass('bg-primary bg-success')
                            .addClass(sys === 'DEPED' ? 'bg-success' : 'bg-primary');
                        showAlert(response.message, 'success');
                    } else {
                        showAlert('Error: ' + response.message, 'danger');
                    }
                },
                error: function() {
                    showAlert('Error setting active system. Please try again.', 'danger');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });
        // Handle select all checkbox (visible rows of the current system only)
        $('#selectAll').on('change', function(){
            var isChecked = $(this).is(':checked');
            $('.trans-item:visible .row-select').prop('checked', isChecked);
            updateDeleteButton();
        });

        // Handle individual row checkboxes
        $('.row-select').on('change', function(){
            updateDeleteButton();
            // Update select all checkbox state
            var allChecked = $('.trans-item:visible .row-select').length === $('.trans-item:visible .row-select:checked').length;
            $('#selectAll').prop('checked', allChecked);
        });

        // Update delete button state
        function updateDeleteButton() {
            var selectedCount = $('.row-select:checked').length;
            $('#deleteSelected').prop('disabled', selectedCount === 0);
            if (selectedCount > 0) {
                $('#deleteSelected').html('<i class="fas fa-trash me-1"></i>Delete Selected (' + selectedCount + ')');
            } else {
                $('#deleteSelected').html('<i class="fas fa-trash me-1"></i>Delete Selected');
            }
        }

        // Handle delete selected rows
        $('#deleteSelected').on('click', function(){
            var selectedIds = [];
            $('.row-select:checked').each(function(){
                var $checkbox = $(this);
                var id = $checkbox.data('id');
                console.log('Checkbox ID:', id, 'Type:', typeof id, 'Value:', id);
                
                // Ensure ID is converted to number
                id = parseInt(id, 10);
                console.log('Parsed ID:', id, 'Type:', typeof id, 'isNaN:', isNaN(id));
                
                if (!isNaN(id) && id > 0) {
                    selectedIds.push(id);
                    console.log('Added ID:', id);
                } else {
                    console.log('Invalid ID skipped:', id);
                }
            });

            console.log('Final selected IDs:', selectedIds);
            console.log('Number of checkboxes checked:', $('.row-select:checked').length);

            if (selectedIds.length === 0) {
                showAlert('No rows selected for deletion.', 'info');
                return;
            }

            if (confirm('Are you sure you want to delete ' + selectedIds.length + ' row(s)?')) {
                deleteSelectedRows(selectedIds);
            }
        });

        // Delete selected rows via AJAX
        function deleteSelectedRows(ids) {
            $.ajax({
                url: 'delete_transmutation.php',
                method: 'POST',
                data: {
                    ids: JSON.stringify(ids)
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showAlert('Deleted ' + response.deleted_count + ' row(s) successfully.', 'success');
                        // Remove deleted rows from table
                        $('.row-select:checked').closest('tr').fadeOut(300, function(){
                            $(this).remove();
                            updateDeleteButton();
                        });
                    } else {
                        showAlert('Error deleting rows: ' + response.message, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', status, error);
                    showAlert('Error deleting rows. Please try again.', 'danger');
                }
            });
        }

        // Handle add row button
        $('#addRow').on('click', function(){
            addNewRow();
        });

        // Add new row to the table
        function addNewRow() {
            // Get the current highest trans_id from the table
            var maxId = 0;
            $('.trans-item').each(function(){
                var id = parseInt($(this).data('id'), 10);
                if (!isNaN(id) && id > maxId) {
                    maxId = id;
                }
            });
            
            // New row will have a temporary negative ID to distinguish from existing rows
            var newId = -(maxId + 1);
            var newSys = currentTransSystem();

            var newRow = $('<tr class="trans-item" data-id="' + newId + '" data-system="' + newSys + '">' +
                '<td class="px-1 py-0 text-center">' +
                '<input type="checkbox" class="row-select form-check-input" data-id="' + newId + '">' +
                '</td>' +
                '<td class="px-1 py-0 text-center from editable-cell" contenteditable="true">0</td>' +
                '<td class="px-1 py-0 text-center to editable-cell" contenteditable="true">0</td>' +
                '<td class="px-1 py-0 text-center grade editable-cell" contenteditable="true">5.5</td>' +
                '<td class="px-1 py-0 text-center remarks">DROPPED</td>' +
                '</tr>');
            
            // Add the row to the end of the table body
            $('table tbody').append(newRow);
            
            // Store original values for the new row
            newRow.find('.editable-cell').each(function(){
                var $cell = $(this);
                $cell.data('original', $cell.text().trim());
            });
            
            // Add event handlers for the new row
            newRow.find('.editable-cell').on('input', function(){
                var $cell = $(this);
                var original = $cell.data('original');
                var current = $cell.text().trim();
                
                if (original !== current) {
                    $cell.addClass('changed');
                } else {
                    $cell.removeClass('changed');
                }
            });
            
            // Add enter key handler for new row
            newRow.find('.editable-cell').on('keypress', function(e){
                if (e.which === 13) {
                    e.preventDefault();
                    $('#saveTransmutation').click();
                }
            });
            
            // Add focus handler for new row
            newRow.find('.editable-cell').on('focus', function(){
                var $cell = $(this);
                var range = document.createRange();
                var sel = window.getSelection();
                range.selectNodeContents($cell[0]);
                sel.removeAllRanges();
                sel.addRange(range);
            });
            
            // Add checkbox handler for new row
            newRow.find('.row-select').on('change', function(){
                updateDeleteButton();
                // Update select all checkbox state
                var allChecked = $('.trans-item:visible .row-select').length === $('.trans-item:visible .row-select:checked').length;
                $('#selectAll').prop('checked', allChecked);
            });
            
            // Focus on the first cell of the new row for editing
            newRow.find('.from').focus();
            
            showAlert('New row added. Edit the values and click Save Changes.', 'info');
        }

        // Store original values
        $('.trans-item').each(function(){
            var $row = $(this);
            $row.find('.editable-cell').each(function(){
                var $cell = $(this);
                $cell.data('original', $cell.text().trim());
            });
        });

        // Handle cell editing
        $('.editable-cell').on('input', function(){
            var $cell = $(this);
            var original = $cell.data('original');
            var current = $cell.text().trim();
            
            if (original !== current) {
                $cell.addClass('changed');
            } else {
                $cell.removeClass('changed');
            }
        });

        // Handle Enter key press in editable cells
        $('.editable-cell').on('keypress', function(e){
            if (e.which === 13) {
                e.preventDefault();
                $('#saveTransmutation').click();
            }
        });

        // Handle cell focus - select all text
        $('.editable-cell').on('focus', function(){
            var $cell = $(this);
            var range = document.createRange();
            var sel = window.getSelection();
            range.selectNodeContents($cell[0]);
            sel.removeAllRanges();
            sel.addRange(range);
        });

        // Save changes
        $('#saveTransmutation').on('click', function(){
            console.log('Save button clicked');
            var $btn = $(this);
            var changes = [];
            var newRows = [];
            var hasErrors = false;

            $('.trans-item').each(function(){
                var $row = $(this);
                var id = $row.data('id');
                console.log('Processing row ID:', id);
                var rowChanges = {};

                $row.find('.editable-cell').each(function(){
                    var $cell = $(this);
                    var original = $cell.data('original');
                    var current = $cell.text().trim();
                    console.log('Cell:', original, '->', current);
                    
                    if (original !== current) {
                        var cellType = $cell.hasClass('from') ? 'from' : 
                                       $cell.hasClass('to') ? 'to' : 
                                       $cell.hasClass('grade') ? 'grade' : '';
                        
                        if (cellType) {
                            // Validation (grade range depends on the row's system)
                            var rowSys = $row.data('system') || 'CHED';
                            if (cellType === 'grade') {
                                var grade = parseFloat(current);
                                var gmin = rowSys === 'DEPED' ? 60 : 1;
                                var gmax = rowSys === 'DEPED' ? 100 : 5.5;
                                if (isNaN(grade) || grade < gmin || grade > gmax) {
                                    $cell.addClass('bg-danger text-white');
                                    hasErrors = true;
                                    return;
                                }
                            } else {
                                var num = parseInt(current);
                                if (isNaN(num) || num < 0 || num > 100) {
                                    $cell.addClass('bg-danger text-white');
                                    hasErrors = true;
                                    return;
                                }
                            }
                            
                            rowChanges[cellType] = current;
                            console.log('Added change:', cellType, '=', current);
                        }
                    }
                });

                // Check if this is a new row (negative ID)
                if (id < 0) {
                    // For new rows, collect all data
                    var rowData = {
                        temp_id: id,
                        from: $row.find('.from').text().trim(),
                        to: $row.find('.to').text().trim(),
                        grade: $row.find('.grade').text().trim(),
                        system: $row.data('system') || currentTransSystem()
                    };
                    
                    // Validate new row data (grade range depends on system)
                    var grade = parseFloat(rowData.grade);
                    var from = parseInt(rowData.from);
                    var to = parseInt(rowData.to);
                    var newSys = rowData.system === 'DEPED' ? 'DEPED' : 'CHED';
                    var ngmin = newSys === 'DEPED' ? 60 : 1;
                    var ngmax = newSys === 'DEPED' ? 100 : 5.5;

                    if (isNaN(grade) || grade < ngmin || grade > ngmax ||
                        isNaN(from) || from < 0 || from > 100 ||
                        isNaN(to) || to < 0 || to > 100) {
                        hasErrors = true;
                        return;
                    }
                    
                    newRows.push(rowData);
                } else if (Object.keys(rowChanges).length > 0) {
                    // For existing rows, only collect changes
                    changes.push({
                        id: id,
                        changes: rowChanges
                    });
                }
            });

            console.log('Total changes:', changes);
            console.log('New rows:', newRows);
            console.log('Has errors:', hasErrors);

            if (hasErrors) {
                showAlert('Please correct the highlighted errors before saving.', 'danger');
                return;
            }

            if (changes.length === 0 && newRows.length === 0) {
                showAlert('No changes to save.', 'info');
                return;
            }

            // Save via AJAX
            $btn.prop('disabled', true).text('Saving...');
            console.log('Sending AJAX request...');
            
            // First add new rows if any
            if (newRows.length > 0) {
                addNewRows(newRows, changes, $btn);
            } else {
                saveChanges(changes, $btn);
            }
        });

        // Add new rows via AJAX
        function addNewRows(newRows, existingChanges, $btn) {
            $.ajax({
                url: 'add_transmutation.php',
                method: 'POST',
                data: {
                    new_rows: JSON.stringify(newRows),
                    system: currentTransSystem()
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Update temporary IDs with real database IDs
                        response.added_rows.forEach(function(addedRow) {
                            $('tr[data-id="' + addedRow.temp_id + '"]').attr('data-id', addedRow.new_id);
                            $('tr[data-id="' + addedRow.new_id + '"] .row-select').attr('data-id', addedRow.new_id);
                        });
                        
                        // Then save any existing changes
                        if (existingChanges.length > 0) {
                            saveChanges(existingChanges, $btn);
                        } else {
                            showAlert('New rows added successfully!', 'success');
                            resetButtonState($btn);
                        }
                    } else {
                        showAlert('Error adding new rows: ' + response.message, 'danger');
                        resetButtonState($btn);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', status, error);
                    showAlert('Error adding new rows. Please try again.', 'danger');
                    resetButtonState($btn);
                }
            });
        }

        // Save existing changes via AJAX
        function saveChanges(changes, $btn) {
            $.ajax({
                url: 'save_transmutation.php',
                method: 'POST',
                data: {
                    changes: JSON.stringify(changes)
                },
                dataType: 'json',
                success: function(response) {
                    console.log('AJAX response:', response);
                    if (response.success) {
                        showAlert('Changes saved successfully!', 'success');
                        // Update original values and remove changed class
                        $('.editable-cell').each(function(){
                            var $cell = $(this);
                            $cell.data('original', $cell.text().trim());
                            $cell.removeClass('changed bg-danger text-white');
                        });
                        // Update remarks if grade changed
                        updateRemarks();
                    } else {
                        showAlert('Update Failed: ' + response.message, 'danger');
                    }
                    resetButtonState($btn);
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', status, error);
                    console.log('Response text:', xhr.responseText);
                    showAlert('Error saving changes. Please try again.', 'danger');
                    resetButtonState($btn);
                }
            });
        }

        // Reset button state
        function resetButtonState($btn) {
            $btn.prop('disabled', false).text('Save Changes');
        }

        // Update remarks based on new grades (system-aware: DepEd pass mark is 75)
        function updateRemarks() {
            $('.trans-item').each(function(){
                var $row = $(this);
                var $gradeCell = $row.find('.grade');
                var $remarksCell = $row.find('.remarks');
                var grade = parseFloat($gradeCell.text().trim());
                var sys = $row.data('system') || 'CHED';

                var remark = '';
                if (sys === 'DEPED') {
                    remark = grade >= 75 ? 'PASSED' : 'FAILED';
                } else if (grade >= 5.5) {
                    remark = 'DROPPED';
                } else if (grade >= 3.1 && grade <= 3.5) {
                    remark = 'CONDITIONAL';
                } else if (grade > 3.5) {
                    remark = 'FAILED';
                } else {
                    remark = 'PASSED';
                }

                $remarksCell.text(remark);
            });
        }

        // Original grade filter functionality
        $('#grade').on('input',function(){
            var grade = $(this).val()
            if(grade != ''){
                $('.trans-item').each(function(){
                    var from = $(this).find('.from').text()
                    var to = $(this).find('.to').text()
                    if(from <= grade && grade <= to){
                        $(this).toggle(true)
                    }else{
                        $(this).toggle(false)
                    }
                })
            }else{
                $('.trans-item').toggle(true)
            }
        })
    })
</script>