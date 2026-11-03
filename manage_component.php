<?php
require_once("DBConnection.php");
if(isset($_GET['id'])){
$qry = $conn->query("SELECT * FROM `grading_components` where component_id = '{$_GET['id']}'");
    foreach($qry->fetchArray() as $k => $v){
        $$k = $v;
    }
}
?>
<div class="container-fluid">
    <form action="" id="component-form">
        <input type="hidden" name="id" value="<?php echo isset($component_id) ? $component_id : '' ?>">
        <div class="form-group">
            <label for="name" class="control-label">Name</label>
            <input type="text" name="name" id="name" required class="form-control form-control-sm rounded-0" value="<?php echo isset($name) ? $name : '' ?>">
        </div>
    </form>
</div>

<script>
    $(function(){
        // Function to capitalize first letter of each word
        function capitalizeWords(str) {
            if (!str) return str;
            return str.replace(/\b\w/g, function(char) {
                return char.toUpperCase();
            });
        }
        
        // Auto-convert name input to capitalize first letter of each word
        $('#name').on('input', function() {
            const $input = $(this);
            const currentValue = $input.val();
            const capitalized = capitalizeWords(currentValue);
            
            if (currentValue !== capitalized) {
                $input.val(capitalized);
                // Move cursor to end of input
                const len = capitalized.length;
                this.setSelectionRange(len, len);
            }
        });
        
        // Set focus when modal is shown
        $('#uni_modal').on('shown.bs.modal', function() {
            $('#name').focus();
        });
        
        $('#component-form').submit(function(e){
            e.preventDefault();
            $('.pop_msg').remove()
            var _this = $(this)
            var _el = $('<div>')
                _el.addClass('pop_msg')
            $('#uni_modal button').attr('disabled',true)
            $('#uni_modal button[type="submit"]').text('submitting form...')
            $.ajax({
                url:'./Actions.php?a=save_component',
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
                     if(window.showAppToast) window.showAppToast('danger', 'An error occurred while saving the component.')
                },
                success:function(resp){
                    if(resp.status == 'success'){
                        _el.addClass('alert alert-success')
                        if(window.showAppToast) window.showAppToast('success', resp.msg || 'Component saved successfully.')
                        var isAddMode = ("<?php echo isset($component_id) ? 1 : 0 ?>" != 1);
                        if(isAddMode) {
                            // Add mode: keep modal open for continuous adding
                            _this.get(0).reset();
                            // Refresh the table immediately so the new row appears behind the modal
                            if(window.updateComponentData) {
                                updateComponentData()
                            }
                            $('#name').focus();
                        } else {
                            // Edit mode: close modal after successful update
                            setTimeout(function(){
                                $('#uni_modal').modal('hide')
                                // Update any parent elements that might need refreshing
                                if(window.updateComponentData) {
                                    updateComponentData()
                                }
                            }, 1500)
                        }
                    }else{
                        _el.addClass('alert alert-danger')
                        if(window.showAppToast) window.showAppToast('danger', resp.msg || 'Failed to save the component.')
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