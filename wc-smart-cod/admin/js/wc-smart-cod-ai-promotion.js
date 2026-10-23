(function($) {
  'use strict';

  $(document).on('click', '.wsc-ai-upgrade-notice .notice-dismiss', function() {
    var $notice = $(this).closest('.wsc-ai-upgrade-notice');
    $.post(ajaxurl, {
      action: 'wsc_dismiss_ai_offer',
      nonce: $notice.data('wscDismissNonce')
    });
  });
})(jQuery);
