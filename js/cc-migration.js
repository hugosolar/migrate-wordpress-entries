jQuery(document).ready(function($){
  var button = $('#do_migration');
  if (button.length > 0) {
    $('#cc_migration_result').hide();
    button.on('click', function(e){
      if ( CC_entries.length > 0 ) {
        $('#cc_migration_result').html('');
        button.text('Loading...');
        button.prop('disabled', true);
        $('#cc_migration_result').show();
        CC_entries.forEach(function(entry_id, index){
          $.when(
            $.ajax({
              type:"POST",
              url: ajaxurl,
              data: {
                action: 'do_migration',
                id: entry_id,
                nonce: $('#cc_get_posts_nonce').val()
              }
            })
          ).then(function(data, textStatus, jqXHR){
            
            if ( index == CC_entries.length -1 ) {
              button.text('Migrate');
              button.prop('disabled', false); 
            }
            $('#cc_migration_result').append(data);
          });
        });

      }
    });
  }
});
        