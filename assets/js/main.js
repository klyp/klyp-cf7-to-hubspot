(function($)
{
    'use strict';
    $(function()
    {
        $('#klyp-cf7-to-hubspot-map-add-new-map').on('click', function(e) {
            e.preventDefault();
            let tfoot = $('#klyp-cf7-to-hubspot-tfoot-map').html();
            $('#klyp-cf7-to-hubspot-tbody-map').prepend(tfoot);
        });

        $('#klyp-cf7-to-hubspot-map-add-new-dealbreaker').on('click', function(e) {
            e.preventDefault();
            let tfoot = $('#klyp-cf7-to-hubspot-tfoot-dealbreaker').html();
            $('#klyp-cf7-to-hubspot-tbody-dealbreaker').prepend(tfoot);
        });

        $('body').on('click', '.klyp-cf7-to-hubspot-cf-remove-map, .klyp-cf7-to-hubspot-cf-remove-dealbreaker', function(e) {
            e.preventDefault();
            $(this).parent().parent().remove();
        });

        $('#klyp-cf7-to-hubspot-dealbreaker-allow').on('click', function(e) {
            if ($(this).is(':checked')) {
                $('#klyp-cf7-to-hubspot-dealbreakers').hide();
            } else {
                $('#klyp-cf7-to-hubspot-dealbreakers').show();
            }
        });
    });
})(jQuery);
