jQuery(document).ready(function($){
    let frame;
    $('#octopus-ai-upload-logo-button').on('click', function(e){
        e.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'Selecteer of upload een logo',
            button: {
                text: 'Gebruik dit logo'
            },
            multiple: false
        });

        frame.on('select', function(){
            const attachment = frame.state().get('selection').first().toJSON();
            $('#octopus-ai-logo-url').val(attachment.url);
            $('#octopus-ai-logo-preview').attr('src', attachment.url).show();
        });

        frame.open();
    });
});
