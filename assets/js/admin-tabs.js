jQuery(function($){
    $('.octopus-inner-tabs .nav-tab').on('click', function(e){
        e.preventDefault();
        var target = $(this).attr('href');
        $('.octopus-inner-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.octopus-settings .octopus-tab').removeClass('active').hide();
        $(target).addClass('active').show();
    });
});
