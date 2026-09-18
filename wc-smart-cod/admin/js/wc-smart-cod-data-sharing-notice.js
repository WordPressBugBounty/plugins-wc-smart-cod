(function($) {
  'use strict';

  $(document).on('click', '.wsc-data-sharing-notice .notice-dismiss', function() {
    var $notice = $(this).closest('.wsc-data-sharing-notice');

    $.post(ajaxurl, {
      action: 'wsc_dismiss_data_sharing_notice',
      nonce: $notice.data('wscDismissNonce')
    });
  });
})(jQuery);
