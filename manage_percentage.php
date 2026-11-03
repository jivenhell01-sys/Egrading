<?php
require_once("DBConnection.php");
$data = array();
if(isset($_GET['id'])){
$qry = $conn->query("SELECT * FROM `component_subject_percentage` where subject_id = '{$_GET['id']}'");
    while($row = $qry->fetchArray()){
        $data[$row['component_id']] = $row['percentage'];
    }
}
?>
<div class="container-fluid" >
    <form action="" id="percentage-form" style="color: white !important;">
        <input type="hidden" name="id" value="<?php echo isset($_GET['id']) ? $_GET['id'] : '' ?>">
        <table class="table table-striped table-hover">
            <colgroup>
                <col width="75%">
                <col width="25%">
            </colgroup>
            <thead>
                <tr>
                    <th class="fw-medium text-center" >Component</th>
                    <th class="fw-medium text-center" >Percentage (%)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $component = $conn->query("SELECT * FROM `grading_components` order by component_id asc");
                $total = 0;
                while($row = $component->fetchArray()):
                    $total += isset($data[$row['component_id']]) ? $data[$row['component_id']] : 0;
                ?>
                <tr>
                    <td class="text-primary mb-0" ><input type="hidden" name="component_id[]" value='<?php echo $row['component_id'] ?>'><?php echo $row['name'] ?></td>
                    <td class="py-0 px-1" style="color: white !important;">
                        <input type="number" step="any" class="form-control form-control-sm rounded-0 w-100 text-end" name="percentage[]" value="<?php echo (isset($data[$row['component_id']])) ? $data[$row['component_id']] : 0 ?>" required>
                    </td>
                </tr>
                <?php endwhile; ?>
                <tfoot>
                    <tr>
                        <th class="py-0 px-1 text-center" style="color: white !important;">Total</th>
                        <th class="py-0 px-1 text-center text-end" style="color: white !important;" id="total"><?php echo $total ?>%</th>
                    </tr>
                </tfoot>
            </tbody>
        </table>
    </form>
</div>

<script>
    $(function(){
        $('input[name="percentage[]"]').on('input',function(){
            var total = 0;
            $('input[name="percentage[]"]').each(function(){
                var _perc = $.isNumeric($(this).val()) === true ? $(this).val() : 0;
                total += parseFloat(_perc)
            })
            $('#total').text(total+"%")
        })
        $('#percentage-form').submit(function(e){
            e.preventDefault();
            $('.pop_msg').remove()
            var _this = $(this)
            var total = $('#total').text()
                total = total.replace(/\%/gi,'')
                console.log(total)
            if(parseFloat(total) !== 100)
            {
                alert("Total Percentage must be 100%");
                return false;
            }
            var _el = $('<div>')
                _el.addClass('pop_msg')
            $('#uni_modal button').attr('disabled',true)
            $('#uni_modal button[type="submit"]').text('submitting form...')
            $.ajax({
                url:'./Actions.php?a=save_percentage',
                method:'POST',
                data:$(this).serialize(),
                dataType:'JSON',
                error:err=>{
                    console.log(err)
                    _el.addClass('alert alert-danger')
                    _el.text("An error occurred.")
                    _this.prepend(_el)
                    _el.show('slow')
                     $('#uni_modal button').attr('disabled',false)
                     $('#uni_modal button[type="submit"]').text('Save')
                },
                success:function(resp){
                    if(resp.status == 'success'){
                        _el.addClass('alert alert-success')
                        // Close modal after successful submission without page reload
                        setTimeout(function(){
                            $('#uni_modal').modal('hide')
                            // Update any parent elements that might need refreshing
                            if(window.updatePercentageData) {
                                updatePercentageData()
                            }
                        }, 1500)
                    }else{
                        _el.addClass('alert alert-danger')
                    }
                    _el.text(resp.msg)

                    _el.hide()
                    _this.prepend(_el)
                    _el.show('slow')
                     $('#uni_modal button').attr('disabled',false)
                     $('#uni_modal button[type="submit"]').text('Save')
                }
            })
        })
    })
</script>