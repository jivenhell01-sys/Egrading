<style>
    .compact-table th, .compact-table td {
        padding: 0.3rem 0.5rem !important;
        font-size: 0.85rem;
    }
    .compact-table th {
        font-weight: 500;
    }
    .card-header {
        padding: 0.5rem 1rem;
    }
    .card-title {
        font-size: 1.1rem;
        margin: 0;
    }
    .btn-sm {
        padding: 0.15rem 0.4rem;
        font-size: 0.8rem;
    }
    .form-control, .form-select {
        padding: 0.25rem 0.5rem;
        font-size: 0.85rem;
        height: calc(1.5em + 0.5rem + 2px);
    }
    .form-group {
        margin-bottom: 0.5rem;
    }
    
    /* Component cell styling for proper centering and overflow */
    .component-cell {
        text-align: center !important;
        vertical-align: middle !important;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    /* Highlight animation for new rows */
    .highlight-new {
        animation: highlightNewRow 2s ease-in-out;
        background-color: #d4edda !important;
        transition: background-color 2s ease-in-out;
    }
    
    @keyframes highlightNewRow {
        0% {
            background-color: #28a745 !important;
            color: white !important;
        }
        50% {
            background-color: #d4edda !important;
            color: inherit !important;
        }
        100% {
            background-color: transparent !important;
            color: inherit !important;
        }
    }
    
    /* Highlight animation for updated rows */
    .highlight-updated {
        animation: highlightUpdatedRow 1.5s ease-in-out;
        background-color: #fff3cd !important;
        transition: background-color 1.5s ease-in-out;
    }
    
    @keyframes highlightUpdatedRow {
        0% {
            background-color: #ffc107 !important;
            color: black !important;
        }
        100% {
            background-color: transparent !important;
            color: inherit !important;
        }
    }
    
    /* Highlight animation for removing rows */
    .highlight-removing {
        animation: highlightRemovingRow 0.3s ease-in-out;
        background-color: #f8d7da !important;
        transition: background-color 0.3s ease-in-out;
    }
    
    @keyframes highlightRemovingRow {
        0% {
            background-color: transparent !important;
        }
        100% {
            background-color: #dc3545 !important;
            color: white !important;
        }
    }
    
        /* From Uiverse.io by cssbuttons-io */ 
button {
 position: relative;
 border: none;
 background: transparent;
 padding: 2px;
 cursor: pointer;
 outline-offset: 4px;
 transition: filter 250ms;
 user-select: none;
 touch-action: manipulation;
}

.shadow {
 position: absolute;
 top: 0;
 left: 0;
 width: 100%;
 height: 100%;
 border-radius: 12px;
 background: hsl(0deg 0% 0% / 0.25);
 will-change: transform;
 transform: translateY(2px);
 transition: transform
    600ms
    cubic-bezier(.3, .7, .4, 1);
}

.edge {
 position: absolute;
 top: 0;
 left: 0;
 width: 100%;
 height: 100%;
 border-radius: 12px;
 background: linear-gradient(
    to left,
    hsl(340deg 100% 16%) 0%,
    hsl(340deg 100% 32%) 8%,
    hsl(340deg 100% 32%) 92%,
    hsl(340deg 100% 16%) 100%
  );
}

.front {
 display: block;
 position: relative;
 padding: 8px 20px;
 border-radius: 12px;
 font-size: 1rem;
 color: white;
 background: hsl(345deg 100% 47%);
 will-change: transform;
 transform: translateY(-4px);
 transition: transform
    600ms
    cubic-bezier(.3, .7, .4, 1);
}

button:hover {
 filter: brightness(110%);
}

button:hover .front {
 transform: translateY(-6px);
 transition: transform
    250ms
    cubic-bezier(.3, .7, .4, 1.5);
}

button:active .front {
 transform: translateY(-2px);
 transition: transform 34ms;
}

button:hover .shadow {
 transform: translateY(4px);
 transition: transform
    250ms
    cubic-bezier(.3, .7, .4, 1.5);
}

button:active .shadow {
 transform: translateY(1px);
 transition: transform 34ms;
}

button:focus:not(:focus-visible) {
 outline: none;
}
</style>
<div class="horizon-card">
    <div class="horizon-card-header">
        <div class="d-flex align-items-center gap-2">
            <div class="horizon-widget-icon-box brand" style="width: 40px; height: 40px; min-width: 40px; font-size: 16px;">
                <i class="fas fa-tasks"></i>
            </div>
            <div>
                <h4 class="mb-0 fw-bold">Assessment List</h4>
                <small class="text-muted">Manage student assessments and grades</small>
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="create_new" style="padding: 8px 10px;">
            <i class="fas fa-plus me-1"></i> Add Assessments
        </button>
    </div>
    <div class="card-body p-2">
        <div class="row mb-2">
            <div class="col-md-3">
                <div class="form-group mb-2">
                    <label for="sectionFilter" class="form-label small mb-1">Filter by Section:</label>
                    <select id="sectionFilter" class="form-select form-select-sm">
                        <option value="">All Sections</option>
                        <?php 
                        $sections = $conn->query("SELECT DISTINCT (grade || ' - ' || section) as section FROM `class_list` ORDER BY grade, section");
                        while($row = $sections->fetchArray()):
                        ?>
                        <option value="<?php echo $row['section'] ?>"><?php echo $row['section'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
        </div>
        <table class="table table-sm table-hover table-striped table-bordered compact-table w-100" id="assessmentsTable">
            <colgroup>
                <col width="3%">
                <col width="25%">
                <col width="20%">
                <col width="8%">
                <col width="5%">
                <col width="4%">
                <col width="5%">
                <col width="15%">
            </colgroup>
            <thead>
                <tr>
                    <th class="text-center">#</th>
                    <th class="text-center">Class</th>
                    <th class="text-center">Component</th>
                    <th class="text-center">Quarter</th>
                    <th class="text-center">Name</th>
                    <th class="text-center">%</th>
                    <th class="text-center">HPS</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                try {
                    // Prepare the SQL query with proper joins and ordering
                    $sql = "SELECT 
                                a.*, 
                                (s.name || ' ' || c.grade || ' - ' || c.section) as class, 
                                cc.name as component,
                                q.name as quarter_name,
                                a.percentage as percentage 
                            FROM `assessment_list` a 
                            INNER JOIN `class_list` c ON a.class_id = c.class_id 
                            INNER JOIN `subjects` s ON c.subject_id = s.subject_id 
                            INNER JOIN `grading_components` cc ON a.component_id = cc.component_id
                            LEFT JOIN `quarter_list` q ON q.class_id = a.class_id AND q.quarter = a.quarter
                            ORDER BY class ASC, component ASC, quarter ASC, a.name ASC, hps ASC";
                    
                    $qry = $conn->query($sql);
                    
                    if (!$qry) {
                        throw new Exception("Failed to fetch assessments: " . $conn->lastErrorMsg());
                    }
                    
                    $i = 1;
                    $quarters = [
                        '1' => 'Prelim', 
                        '2' => 'Midterm', 
                        '3' => 'Pre-Final', 
                        '4' => 'Final'
                    ];
                    
                    while($row = $qry->fetchArray(SQLITE3_ASSOC)): 
                        // Ensure all required fields exist
                        if (!isset($row['class'], $row['component'], $row['quarter'], $row['name'], $row['percentage'], $row['hps'], $row['assessment_id'])) {
                            continue; // Skip invalid entries
                        }
                        
                        $quarterName = $row['quarter_name'] ?: ($quarters[$row['quarter']] ?? 'Quarter ' . $row['quarter']);
                        $classTitle = htmlspecialchars($row['class']);
                        $componentTitle = htmlspecialchars($row['component']);
                        $assessmentName = htmlspecialchars($row['name']);
                        $deleteTitle = sprintf("%s - %s: %s", $classTitle, $componentTitle, $assessmentName);
                ?>
                <tr>
                    <td class="text-center"><?php echo $i++; ?></td>
                    <td class="text-center" title="<?php echo $classTitle; ?>">
                        <?php echo $classTitle; ?>
                    </td>
                    <td class="text-center component-cell" title="<?php echo $componentTitle; ?>">
                        <?php echo $componentTitle; ?>
                    </td>
                    <td class="text-center"><?php echo $quarterName; ?></td>
                    <td class="text-center" title="<?php echo $assessmentName; ?>">
                        <?php echo $assessmentName; ?>
                    </td>
                    <td class="text-center"><?php echo htmlspecialchars($row['percentage']); ?>%</td>
                    <td class="text-center"><?php echo htmlspecialchars($row['hps']); ?></td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-sm btn-outline-primary edit_data" style="margin-right:.5rem; border-radius:5px;"
                                data-id='<?php echo (int)$row['assessment_id']; ?>' 
                                title="Edit">
                                <i class="fas fa-edit"></i>
                                Edit
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger delete_data" style="border-radius:5px;" 
                                data-id='<?php echo (int)$row['assessment_id']; ?>' 
                                data-name='<?php echo htmlspecialchars($deleteTitle); ?>' 
                                title="Delete">
                                <i class="fas fa-trash"></i>
                                Delete
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php 
                } catch (Exception $e) {
                    echo '<tr><td colspan="8" class="text-center text-danger">Error: '.htmlspecialchars($e->getMessage()).'</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<script>
    $(function(){
        var table; // Declare table variable in a higher scope
        
        $('#create_new').click(function(){
            uni_modal('Add New Assessment In Class',"manage_assessment.php")
        })
        $('.edit_data').click(function(){
            uni_modal('Edit Assessment Details In Class',"manage_assessment.php?id="+$(this).attr('data-id'))
        })
        $('.delete_data').click(function(){
            _conf("Are you sure to delete <b>"+$(this).attr('data-name')+"</b> from list?",'delete_data',[$(this).attr('data-id')])
        })
        $('table td,table th').addClass('align-middle')
        
        // Initialize DataTable and store the reference
        table = $('#assessmentsTable').DataTable({
            stateSave: true, // Enable state saving
            stateSaveCallback: function(settings, data) {
                // Save only the page and search state
                localStorage.setItem('DataTables_' + settings.sInstance, JSON.stringify({
                    time: Date.now(),
                    start: data.start,
                    length: data.length,
                    order: data.order,
                    search: data.search,
                    page: data.start / data.length
                }));
            },
            stateLoadCallback: function(settings) {
                var state = localStorage.getItem('DataTables_' + settings.sInstance);
                if (state) {
                    state = JSON.parse(state);
                    // Only return state if it's less than 1 hour old
                    if (state.time > Date.now() - 60 * 60 * 1000) {
                        return state;
                    }
                }
                return null;
            },
            responsive: true,
            pageLength: 10,
            lengthMenu: [[10, 15, 25, 50, -1], [10, 15, 25, 50, 'All']],
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: [0, 7] },
                { searchable: false, targets: [0, 7] },
                { className: 'text-center', targets: [0, 1, 2, 3, 4, 5, 6, 7] },
                { className: 'component-cell', targets: 2 },
                { width: '30px', targets: 0 },
                { width: '80px', targets: 7 }
            ],
            language: {
                search: 'Search:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'No entries to show',
                infoFiltered: '(filtered from _MAX_ total entries)',
                paginate: {
                    first: 'First',
                    last: 'Last',
                    next: 'Next',
                    previous: 'Previous'
                }
            },
            dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row'<'col-sm-12'<'table-responsive'tr>>>" +
                 "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
            initComplete: function(settings, json) {
                var api = this.api();
                var state = api.state.loaded();
                
                // Restore page if it exists in the state
                if (state) {
                    api.page(state.page).draw('page');
                }
                
                // Custom search function for section filtering
                $.fn.dataTable.ext.search.push(
                    function(settings, data, dataIndex) {
                        var selectedSection = $('#sectionFilter').val();
                        if (!selectedSection || selectedSection === '') return true; // Show all if no section selected
                        
                        // Get the class text from the second column (index 1)
                        var classText = data[1] || '';
                        // Check if the class text contains the selected section
                        return classText.indexOf(selectedSection) !== -1;
                    }
                );

                // Restore section filter from localStorage if available
                var savedFilter = localStorage.getItem('assessmentSectionFilter');
                if (savedFilter !== null) {
                    $('#sectionFilter').val(savedFilter);
                    api.draw();
                }

                // Apply the section filter and save to localStorage
                $('#sectionFilter').on('change', function() {
                    var section = $(this).val();
                    // Save to localStorage
                    if (section) {
                        localStorage.setItem('assessmentSectionFilter', section);
                    } else {
                        localStorage.removeItem('assessmentSectionFilter');
                    }
                    api.draw();
                });
            }
        })
    })
    function recomputeComponentPercentages(classText, componentText, quarterText) {
        var rows = [];

        $('#assessmentsTable tbody tr').each(function(){
            var $tr = $(this);
            var tClass = $tr.find('td').eq(1).text().trim();
            var tComp = $tr.find('td').eq(2).text().trim();
            var tQuarter = $tr.find('td').eq(3).text().trim();

            if (tClass === classText && tComp === componentText && tQuarter === quarterText) {
                rows.push($tr);
            }
        });

        var count = rows.length;
        if (count === 0) return;

        // Mirror backend logic: equal share truncated to 2 decimals, last row gets remainder
        var evenShare = Math.floor((100 / count) * 100) / 100; // truncate to 2 decimals
        var runningTotal = 0;

        rows.forEach(function($tr, idx){
            var value;
            if (idx === count - 1) {
                value = 100 - runningTotal;
            } else {
                value = evenShare;
                runningTotal += value;
            }
            // Percentage column is index 5 (0:#,1:Class,2:Component,3:Quarter,4:Name,5:%,6:HPS,7:Action)
            $tr.find('td').eq(5).text(value.toFixed(2) + '%');
            $tr.addClass('highlight-updated');
            setTimeout(function(){
                $tr.removeClass('highlight-updated');
            }, 1500);
        });
    }

    function recomputeQuizzesPercentages(classText, componentText, quarterText) {
        // Only adjust if component is Quizzes
        if (componentText !== 'Quizzes') return;
        
        // Call the generic function for backward compatibility
        recomputeComponentPercentages(classText, componentText, quarterText);
    }

    function delete_data($id){
        $('#confirm_modal button').attr('disabled',true)
        $.ajax({
            url:'./Actions.php?a=delete_assessment',
            method:'POST',
            data:{id:$id},
            dataType:'JSON',
            error:err=>{
                console.log(err)
                showDynamicUpdateNotification('error', 'An error occurred while deleting the assessment.')
                $('#confirm_modal button').attr('disabled',false)
            },
            success:function(resp){
                if(resp.status == 'success'){
                    var $row = $(".delete_data[data-id='" + $id + "']").closest('tr');
                    var classText = $row.find('td').eq(1).text().trim();
                    var componentText = $row.find('td').eq(2).text().trim();
                    var quarterText = $row.find('td').eq(3).text().trim();

                    // Add removal animation
                    $row.addClass('highlight-removing');
                    
                    // Remove the deleted row from the table after animation
                    setTimeout(function(){
                        $row.fadeOut(400, function() {
                            $(this).remove();
                            
                            // Update row numbers
                            updateRowNumbers();
                            
                            // If this was any component and we have updated assessments data
                            if (resp.deleted_assessment && resp.updated_assessments) {
                                // Update percentages for remaining assessments in the component
                                resp.updated_assessments.forEach(function(updatedAssessment) {
                                    var $updateRow = $(".edit_data[data-id='" + updatedAssessment.assessment_id + "']").closest('tr');
                                    if ($updateRow.length) {
                                        $updateRow.find('td').eq(5).text(parseFloat(updatedAssessment.percentage).toFixed(2) + '%');
                                        $updateRow.addClass('highlight-updated');
                                        setTimeout(function(){
                                            $updateRow.removeClass('highlight-updated');
                                        }, 1500);
                                    }
                                });
                            }
                            
                            // Check if table is empty and show appropriate message
                            checkTableEmpty();
                        });
                    }, 200);
                    $('#confirm_modal button').attr('disabled',false)

                    $('#confirm_modal').modal('hide');
                }else{
                    showDynamicUpdateNotification('error', 'An error occurred while deleting the assessment.')
                    $('#confirm_modal button').attr('disabled',false)
                }
            }
        })
    }
    
    // Helper function to update row numbers after deletion
    function updateRowNumbers() {
        $('#assessmentsTable tbody tr').each(function(index) {
            $(this).find('td').eq(0).text(index + 1);
        });
    }
    
    // Helper function to check if table is empty and show message
    function checkTableEmpty() {
        var rowCount = $('#assessmentsTable tbody tr').length;
        if (rowCount === 0) {
            $('#assessmentsTable tbody').html('<tr><td colspan="8" class="text-center">No assessment records found.</td></tr>');
        }
    }
    
    // Function to show dynamic update notifications
    function showDynamicUpdateNotification(type, message) {
        var alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        var icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
        
        var notification = $('<div class="alert ' + alertClass + ' alert-dismissible fade show position-fixed" style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;">' +
            '<i class="' + icon + ' me-2"></i>' +
            '<strong>' + (type === 'success' ? 'Success!' : 'Error!') + '</strong> ' + message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
        '</div>');
        
        $('body').append(notification);
        
        // Auto-hide after 1 second
        setTimeout(function() {
            notification.fadeOut(500, function() {
                $(this).remove();
            });
        }, 1000);
    }
</script>