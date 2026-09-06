/**
 * Redirects after a Contact Form 7 form is sent, when the plugin supplied a target.
 *
 * The URL is validated server-side against wp_validate_redirect(), so it is always
 * same-origin by the time it reaches this listener.
 */
(function () {
  'use strict';

  document.addEventListener('wpcf7mailsent', function (event) {
    const response = event.detail && event.detail.apiResponse;
    const target = response && response.formRedirect;

    if (typeof target !== 'string' || target === '') {
      return;
    }

    let resolved;

    try {
      resolved = new URL(target, window.location.origin);
    } catch (error) {
      return;
    }

    // Belt and braces: never follow a cross-origin target, even if one somehow
    // made it into the response.
    if (resolved.origin !== window.location.origin) {
      return;
    }

    window.location.assign(resolved.href);
  }, false);
})();
