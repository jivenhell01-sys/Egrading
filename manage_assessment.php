<?php
require_once("DBConnection.php");
if(isset($_GET['id'])){
$qry = $conn->query("SELECT * FROM `assessment_list` where assessment_id = '{$_GET['id']}'");
    foreach($qry->fetchArray() as $k => $v){
        $$k = $v;
    }
}
?>
<div class="container-fluid">
    <form action="" id="assessment-form">
        <input type="hidden" name="id" value="<?php echo isset($assessment_id) ? $assessment_id : '' ?>">
        <div class="col-12">
            <div class="row">
                <div class="col-md-12">

                    <div class="form-group">
                        <label for="class_id" class="control-label">Class</label>
                        <select name="class_id" id="class_id" class="form-select form-select-sm rounded-0" required>
                            <option <?php echo (!isset($class_id)) ? 'selected' : '' ?> disabled>Please Select Here</option>
                            <option value="all" <?php echo (isset($class_id) && $class_id == 'all' ) ? 'selected' : '' ?>>Select All Classes</option>
                            <?php
                            $dept_qry = $conn->query("SELECT c.*, (s.name || ' ' || c.grade || ' - ' || c.section) as class FROM `class_list` c inner join `subjects` s on c.subject_id = s.subject_id order by `class` asc");
                            while($row= $dept_qry->fetchArray()):
                            ?>
                                <option value="<?php echo $row['class_id'] ?>" <?php echo (isset($class_id) && $class_id == $row['class_id'] ) ? 'selected' : '' ?>><?php echo $row['class'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="component_id" class="control-label">Component</label>
                        <select name="component_id" id="component_id" class="form-select form-select-sm rounded-0" required>
                            <option <?php echo (!isset($component_id)) ? 'selected' : '' ?> disabled>Please Select Here</option>
                            <?php
                            // If class_id is set, get components for that class
                            if(isset($class_id) && $class_id != 'all') {
                                $comp_qry = $conn->query("SELECT gc.* 
                                    FROM grading_components gc
                                    INNER JOIN component_subject_percentage csp ON gc.component_id = csp.component_id
                                    INNER JOIN class_list cl ON csp.subject_id = cl.subject_id
                                    WHERE cl.class_id = '{$class_id}'
                                    AND CAST(csp.percentage AS REAL) > 0
                                    ORDER BY gc.name ASC");
                                while($row= $comp_qry->fetchArray()):
                                ?>
                                    <option value="<?php echo $row['component_id'] ?>" <?php echo (isset($component_id) && $component_id == $row['component_id'] ) ? 'selected' : '' ?>><?php echo $row['name'] ?></option>
                                <?php endwhile; 
                            } else {
                                // Show all components if no class is selected or "Select All Classes" is chosen
                                $dept_qry = $conn->query("SELECT * FROM `grading_components` order by `name` asc");
                                while($row= $dept_qry->fetchArray()):
                                ?>
                                    <option value="<?php echo $row['component_id'] ?>" <?php echo (isset($component_id) && $component_id == $row['component_id'] ) ? 'selected' : '' ?>><?php echo $row['name'] ?></option>
                                <?php endwhile; 
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="quarter" class="control-label">Quarter</label>
                        <select name="quarter" id="quarter" class="form-select form-select-sm rounded-0" required>
                            <option value="" disabled>Please Select Here</option>
                            <?php
                            if(isset($class_id) && $class_id != 'all') {
                                // Load quarters from quarter_list table for the selected class
                                $quarter_qry = $conn->query("SELECT * FROM `quarter_list` WHERE class_id = '{$class_id}' ORDER BY quarter ASC");
                                $has_quarters = false;
                                while($row = $quarter_qry->fetchArray()):
                                    $has_quarters = true;
                                ?>
                                    <option value="<?php echo $row['quarter'] ?>" <?php echo (isset($quarter) && $quarter == $row['quarter']) ? 'selected' : '' ?>><?php echo htmlspecialchars($row['name']) ?></option>
                                <?php endwhile; 
                                
                                // If no custom quarters, show default options
                                if(!$has_quarters):
                                ?>
                                    <option value="1" <?php echo (isset($quarter) && $quarter == 1)? 'selected' : '' ?>>Prelim</option>
                                    <option value="2" <?php echo (isset($quarter) && $quarter == 2)? 'selected' : '' ?>>Midterm</option>
                                    <option value="3" <?php echo (isset($quarter) && $quarter == 3)? 'selected' : '' ?>>Pre-Final</option>
                                    <option value="4" <?php echo (isset($quarter) && $quarter == 4)? 'selected' : '' ?>>Final</option>
                                <?php endif;
                            } else {
                                // Show default options if no class selected
                                ?>
                                    <option value="1" <?php echo (isset($quarter) && $quarter == 1)? 'selected' : '' ?>>Prelim</option>
                                    <option value="2" <?php echo (isset($quarter) && $quarter == 2)? 'selected' : '' ?>>Midterm</option>
                                    <option value="3" <?php echo (isset($quarter) && $quarter == 3)? 'selected' : '' ?>>Pre-Final</option>
                                    <option value="4" <?php echo (isset($quarter) && $quarter == 4)? 'selected' : '' ?>>Final</option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name" class="control-label">Name</label>
                        <input type="text" name="name" id="name" required class="form-control form-control-sm rounded-0" value="<?php echo isset($name) ? $name : '' ?>">
                    </div>
                    <div class="form-group">
                        <label for="percentage" class="control-label">Percentage (%)</label>
                        <div class="input-group input-group-sm mb-1">
                            <input type="number" step="0.01" min="0" max="100" name="percentage" id="percentage" required class="form-control form-control-sm rounded-0 text-end" value="<?php echo isset($percentage) ? $percentage : '' ?>">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted" id="remaining-percentage">
                            <?php 
                            if(isset($class_id) && isset($component_id) && isset($quarter)) {
                                
                                $total_percentage = $conn->query("SELECT SUM(percentage) as total FROM assessment_list WHERE class_id = '{$class_id}' AND component_id = '{$component_id}' AND quarter = '{$quarter}'" . (isset($assessment_id) ? " AND assessment_id != '{$assessment_id}'" : ""))->fetchArray()['total'];
                                $remaining = 100 - (float)$total_percentage;
                                echo "Total: <span class='fw-bold text-primary'>" . number_format($total_percentage, 1) . "%</span> | Remaining: <span class='fw-bold'>" . number_format($remaining, 1) . "%</span>";
                            } else {
                                echo "Select Class, Component, and Quarter to see percentage totals";
                            }
                            ?>
                        </small>
                    </div>
                    <div class="form-group">
                        <label for="hps" class="control-label">HPS</label>
                        <input type="number" step="any" name="hps" id="hps" required class="form-control form-control-sm rounded-0 text-end" value="<?php echo isset($hps) ? $hps : '' ?>">
                    </div>
            </div>
        </div>
    </form>
</div>

<script>
    $(function(){
        // Store the original remaining percentage
        var originalRemaining = 0;
        var lastValidPercentage = 0;
        
        // Function to update the remaining display
        function updateRemainingDisplay(remaining, totalUsed) {
            var currentVal = parseFloat($('#percentage').val()) || 0;
            
            var label = 'Divided';
            var valueToShow = currentVal;
            
            // Do not show negative or Exceeds message for auto-divided components
            if (valueToShow < 0) valueToShow = 0;

            // Show actual total used and divided value
            var remainingText = 'Total: <span class="fw-bold text-primary">' + totalUsed.toFixed(1) + '%</span> | ' + label + ': <span class="fw-bold text-success">' + valueToShow.toFixed(1) + '%</span>';

            if (valueToShow < 0) {
                remainingText = '<span class="text-danger">' + remainingText + ' (Exceeds limit!)</span>';
            }
            $('#remaining-percentage').html(remainingText);
            return remaining;
        }
        
        function updateRemainingPercentage() {
            var deferred = $.Deferred();
            
            var class_id = $('#class_id').val();
            var component_id = $('#component_id').val();
            var quarter = $('#quarter').val();
            var current_id = '<?php echo isset($assessment_id) ? $assessment_id : 0; ?>';
            
            // Skip percentage validation when "Select All Classes" is chosen
            if(class_id === 'all') {
                $('#remaining-percentage').html('<span class="text-info">Will be validated for each class individually when saved</span>');
                $('#percentage').removeAttr('max');
                if (!$('#percentage').val()) {
                    $('#percentage').val(0);
                }
                deferred.resolve();
                return deferred.promise();
            }
            
            if(class_id && component_id && quarter) {
                $.ajax({
                    url: './Actions.php?a=get_remaining_percentage',
                    method: 'POST',
                    data: {
                        class_id: class_id,
                        component_id: component_id,
                        quarter: quarter,
                        current_id: current_id
                    },
                    dataType: 'json',
                    success: function(response) {
                        if(response.status === 'success') {
                            originalRemaining = parseFloat(response.remaining);
                            var totalUsed = parseFloat(response.total_used);
                            updateRemainingDisplay(originalRemaining, totalUsed);
                            // Update max attribute of percentage input
                            $('#percentage').attr('max', originalRemaining);
                            // Reset last valid percentage when dropdowns change
                            lastValidPercentage = 0;
                            deferred.resolve();
                        }
                    },
                    error: function() {
                        deferred.reject();
                    }
                });
            } else {
                $('#remaining-percentage').text('Select Class, Component, and Quarter to see percentage totals');
                deferred.resolve();
            }
            return deferred.promise();
        }

        function updatePercentageReadonly() {
            // Always make percentage readonly for auto-distribution
            $('#percentage').prop('readonly', true);
        }
        
        function updateComponentsForClass() {
            var deferred = $.Deferred();
            var class_id = $('#class_id').val();
            var component_id = $('#component_id').val();
            
            // Skip for "Select All Classes" - show all components
            if(class_id === 'all') {
                // Load all components
                $.ajax({
                    url: './Actions.php?a=get_components',
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if(response.status === 'success') {
                            $('#component_id').html('<option disabled>Please Select Here</option>');
                            response.data.forEach(function(component) {
                                var selected = (component.component_id == component_id) ? 'selected' : '';
                                $('#component_id').append('<option value="' + component.component_id + '" ' + selected + '>' + component.name + '</option>');
                            });
                        }
                        deferred.resolve();
                    },
                    error: function() {
                        deferred.reject();
                    }
                });
                return deferred.promise();
            }
            
            if(!class_id) {
                $('#component_id').html('<option selected disabled>Please Select Here</option>');
                deferred.resolve();
                return deferred.promise();
            }
            
            $.ajax({
                url: './Actions.php?a=get_components_for_class',
                method: 'POST',
                data: { class_id: class_id },
                dataType: 'json',
                success: function(response) {
                    if(response.status === 'success') {
                        $('#component_id').html('<option disabled>Please Select Here</option>');
                        response.data.forEach(function(component) {
                            var selected = (component.component_id == component_id) ? 'selected' : '';
                            $('#component_id').append('<option value="' + component.component_id + '" ' + selected + '>' + component.name + '</option>');
                        });
                        
                        // If no components found, show message
                        if(response.data.length === 0) {
                            $('#component_id').html('<option disabled>No components with percentage assigned to this class</option>');
                        }
                    } else {
                        $('#component_id').html('<option disabled>Error loading components</option>');
                    }
                    deferred.resolve();
                },
                error: function() {
                    $('#component_id').html('<option disabled>Error loading components</option>');
                    deferred.reject();
                }
            });
            
            return deferred.promise();
        }
        
        function autoFillComponentPercentage() {
            var currentId = $('input[name="id"]').val();
            // Only apply for new assessments (no id)
            if (currentId) return;

            var class_id = $('#class_id').val();
            var component_id = $('#component_id').val();
            var quarter = $('#quarter').val();

            // Skip for "Select All Classes" or when not all selectors are chosen
            if (class_id === 'all' || !class_id || !component_id || !quarter) return;

            $.ajax({
                url: './Actions.php?a=get_component_suggested_percentage',
                method: 'POST',
                dataType: 'json',
                data: {
                    class_id: class_id,
                    component_id: component_id,
                    quarter: quarter
                },
                success: function(resp) {
                    if (resp.status === 'success' && typeof resp.suggested !== 'undefined') {
                        $('#percentage').val(resp.suggested).trigger('input');
                    }
                }
            });
        }
        
        // Initialize on page load
        updateComponentsForClass().then(function(){
            updateRemainingPercentage().then(function(){
                autoFillComponentPercentage();
                updatePercentageReadonly();
                            
                // Focus on name field after initialization (only for new assessments)
                if(!$('input[name="id"]').val()) {
                    setTimeout(function() {
                        $('#name').focus();
                    }, 100);
                }

            });
        });
    
        // Update on dropdown change
        $('#class_id').on('change', function() {
            updateComponentsForClass().then(function(){
                updateRemainingPercentage().then(function(){
                    autoFillComponentPercentage();
                    updatePercentageReadonly();
                });
            });
        });
        
        $('#component_id, #quarter').on('change', function() {
            updateRemainingPercentage().then(function(){
                autoFillComponentPercentage();
                updatePercentageReadonly();
            });
        });
        
        // Update remaining percentage when typing in the percentage field
        $('#percentage').on('input', function() {
            var currentValue = parseFloat($(this).val()) || 0;
            var remainingElement = $('#remaining-percentage .fw-bold');
            
            if (remainingElement.length) {
                // Calculate new remaining based on the original remaining minus the current value
                var newRemaining = originalRemaining - currentValue;
                
                // Get the current total from the display (parse it from the text)
                var currentTotalText = $('#remaining-percentage .text-primary').text();
                var currentTotal = parseFloat(currentTotalText) || 0;
                
                // Update the display
                updateRemainingDisplay(newRemaining, currentTotal);
                
                // Update the max attribute to prevent entering more than the remaining plus current value
                $(this).attr('max', originalRemaining);
            } else {
                updateRemainingPercentage();
            }
        });
        
        $('#assessment-form input, #assessment-form select').on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#assessment-form').submit();
            }
        });
        
        $('#assessment-form').submit(function(e){
            e.preventDefault();
            $('.pop_msg').remove();
            
            // Get the entered percentage and remaining percentage
            var enteredPercentage = parseFloat($('#percentage').val()) || 0;
            var remainingPercentage = parseFloat($('#remaining-percentage .fw-bold').text()) || 0;
            
            // Skip percentage validation for all components (auto-distributed)
            var componentName = $('#component_id option:selected').text();
            
            // No validation needed for auto-distributed components
            var _this = $(this)
            var _el = $('<div>')
            $('#uni_modal button').attr('disabled',true)
            $('#uni_modal button[type="submit"]').text('submitting form...')
            $.ajax({
                url:'./Actions.php?a=save_assessment',
                data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
                error:err=>{
                    console.log(err)
                    _el.addClass('alert alert-danger')
                    _el.text("An error occurred.")
                    _el.hide()
                    _this.prepend(_el)
                    _el.show('slow')
                    // Auto-hide error message after 1 second
                    setTimeout(function(){
                        _el.fadeOut('fast', function(){
                            $(this).remove();
                        });
                    }, 1000);
                     $('#uni_modal button').attr('disabled',false)
                     $('#uni_modal button[type="submit"]').text('Save')
                },
                success:function(resp){
                    if(resp.status == 'success'){
                        _el.addClass('alert alert-success')
                        _el.text(resp.msg)
                        _el.hide()
                        _this.prepend(_el)
                        _el.show('slow')
                        
                        // Auto-hide success message and trigger table update
                        setTimeout(function(){
                            _el.fadeOut('slow', function(){
                                $(this).remove();
                            });
                            
                            // Define parentWindow for accessing the parent window
                            var parentWindow = window.opener || window.parent;
                            
                            // Handle multiple assessments created for all classes
                            if(resp.created_assessments && Array.isArray(resp.created_assessments)) {
                                // Update parent table with multiple new rows
                                resp.created_assessments.forEach(function(assessmentData) {
                                    updateAssessmentTable(assessmentData);
                                });
                                
                                // Show notification about successful creation count
                                if(typeof parentWindow.showDynamicUpdateNotification === 'function') {
                                    parentWindow.showDynamicUpdateNotification('success', resp.msg);
                                }
                            } else if(resp.assessment_data) {
                                // Update or add the row in the parent assessments table (single assessment)
                                updateAssessmentTable(resp.assessment_data);
                            }
                            
                            // Only clear name, percentage, and hps fields for new entries
                            if("<?php echo isset($assessment_id) ?>" != 1) {
                                $('#name, #percentage, #hps').val('');
                                // Focus on name field after clearing
                                setTimeout(() => $('#name').focus(), 0);
                            } else {
                                // For edits, just close the modal after a short delay
                                setTimeout(function(){
                                    $('#uni_modal').modal('hide');
                                }, 500);
                            }
                        }, 1000);
                    }else{
                        _el.addClass('alert alert-danger')
                        _el.text(resp.msg)
                        _el.hide()
                        _this.prepend(_el)
                        _el.show('slow')
                    }
                     $('#uni_modal button').attr('disabled',false)
                     $('#uni_modal button[type="submit"]').text('Save')
                }
            })
        })
        
        // Function to update the assessments table dynamically
        function updateAssessmentTable(assessmentData) {
            // Try to access the parent window's assessments table
            try {
                var parentWindow = window.opener || window.parent;
                var parentTable = parentWindow.$('#assessmentsTable');
                
                if (parentTable.length) {
                    var quarters = {
                        '1': 'Prelim', 
                        '2': 'Midterm', 
                        '3': 'Pre-Final', 
                        '4': 'Final'
                    };
                    
                    var quarterName = quarters[assessmentData.quarter] || 'N/A';
                    var classTitle = assessmentData.class || '';
                    var componentTitle = assessmentData.component || '';
                    var assessmentName = assessmentData.name || '';
                    var deleteTitle = `${classTitle} - ${componentTitle}: ${assessmentName}`;
                    
                    // Check if this is an edit (existing row) or add (new row)
                    var existingRow = parentWindow.$(".edit_data[data-id='" + assessmentData.assessment_id + "']").closest('tr');
                    
                    if (existingRow.length) {
                        // Update existing row
                        var rowHtml = '<td class="text-center component-cell">' + existingRow.find('td').eq(0).text() + '</td>' +
                            '<td class="text-center component-cell" title="' + classTitle + '">' + classTitle + '</td>' +
                            '<td class="text-center component-cell" title="' + componentTitle + '">' + componentTitle + '</td>' +
                            '<td class="text-center component-cell">' + quarterName + '</td>' +
                            '<td class="text-center component-cell" title="' + assessmentName + '">' + assessmentName + '</td>' +
                            '<td class="text-center component-cell">' + assessmentData.percentage + '%</td>' +
                            '<td class="text-center component-cell">' + assessmentData.hps + '</td>' +
                            '<td class="text-center component-cell">' +
                                '<div class="btn-group btn-group-sm" role="group">' +
                                    '<button type="button" class="btn btn-sm btn-outline-primary edit_data" style="margin-right:.5rem; border-radius:5px;"' +
                                        ' data-id="' + assessmentData.assessment_id + '"' +
                                        ' title="Edit">' +
                                        '<i class="fas fa-edit"></i>' +
                                        ' Edit' +
                                    '</button>' +
                                    '<button type="button" class="btn btn-sm btn-outline-danger delete_data" style="border-radius:5px;"' +
                                        ' data-id="' + assessmentData.assessment_id + '"' +
                                        ' data-name="' + deleteTitle + '"' +
                                        ' title="Delete">' +
                                        '<i class="fas fa-trash"></i>' +
                                        ' Delete' +
                                    '</button>' +
                                '</div>' +
                            '</td>';
                        
                        existingRow.html(rowHtml);
                        existingRow.addClass('highlight-updated');
                        
                        // Re-attach event handlers to the updated buttons
                        parentWindow.$('.edit_data').off('click').on('click', function(){
                            parentWindow.uni_modal('Edit Assessment Details In Class',"manage_assessment.php?id="+$(this).attr('data-id'))
                        });
                        parentWindow.$('.delete_data').off('click').on('click', function(){
                            parentWindow._conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from list?",'delete_data',[$(this).attr('data-id')])
                        });
                        
                        // Remove highlight after animation
                        setTimeout(function(){
                            existingRow.removeClass('highlight-updated');
                        }, 1500);
                        
                    } else {
                        // Add new row - get the DataTable instance and add the row
                        var dataTable = parentWindow.$('#assessmentsTable').DataTable();
                        var rowIndex = dataTable.data().length;
                        
                        var newRow = [
                            rowIndex + 1, // #
                            classTitle, // Class
                            componentTitle, // Component
                            quarterName, // Quarter
                            assessmentName, // Name
                            assessmentData.percentage + '%', // %
                            assessmentData.hps, // HPS
                            '<div class="btn-group btn-group-sm" role="group">' +
                                '<button type="button" class="btn btn-sm btn-outline-primary edit_data" style="margin-right:.5rem; border-radius:5px;"' +
                                    ' data-id="' + assessmentData.assessment_id + '"' +
                                    ' title="Edit">' +
                                    '<i class="fas fa-edit"></i>' +
                                    ' Edit' +
                                '</button>' +
                                '<button type="button" class="btn btn-sm btn-outline-danger delete_data" style="border-radius:5px;"' +
                                    ' data-id="' + assessmentData.assessment_id + '"' +
                                    ' data-name="' + deleteTitle + '"' +
                                    ' title="Delete">' +
                                    '<i class="fas fa-trash"></i>' +
                                    ' Delete' +
                                '</button>' +
                            '</div>' // Action
                        ];
                        
                        var rowNode = dataTable.row.add(newRow).draw().node();
                        parentWindow.$(rowNode).addClass('highlight-new');
                        
                        // Re-attach event handlers for all buttons
                        parentWindow.$('.edit_data').off('click').on('click', function(){
                            parentWindow.uni_modal('Edit Assessment Details In Class',"manage_assessment.php?id="+$(this).attr('data-id'))
                        });
                        parentWindow.$('.delete_data').off('click').on('click', function(){
                            parentWindow._conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from list?",'delete_data',[$(this).attr('data-id')])
                        });
                        
                        // Remove highlight after animation
                        setTimeout(function(){
                            parentWindow.$(rowNode).removeClass('highlight-new');
                        }, 2000);
                    }
                    
                    // If this is any component, recompute percentages for the group
                    if (typeof parentWindow.recomputeComponentPercentages === 'function') {
                        parentWindow.recomputeComponentPercentages(classTitle, componentTitle, quarterName);
                    }
                    
                    // Show success notification in parent window
                    if (typeof parentWindow.showDynamicUpdateNotification === 'function') {
                        parentWindow.showDynamicUpdateNotification('success', assessmentData.assessment_id ? 'Assessment updated successfully!' : 'Assessment added successfully!');
                    }
                    
                } else {
                    console.log('Parent assessments table not found');
                }
            } catch (e) {
                console.log('Could not update parent table:', e);
                // Fallback: show notification that manual refresh may be needed
                alert('Assessment saved successfully. Please refresh the page to see the changes.');
            }
        }
    })
</script>