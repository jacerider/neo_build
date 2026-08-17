/**
 * Wait until Drupal has settled after an AJAX request.
 *
 * Drupal exposes no "behaviors have finished attaching" signal, so this waits
 * on the two things that are observable: Drupal itself being present, and no
 * AJAX throbber being in the document. Core adds `.ajax-progress` for the
 * lifetime of every AJAX request, so its absence is the closest honest proxy
 * for "the request finished and behaviors have run".
 *
 * Needed constantly when testing modals: opening one from a `use-ajax` link is
 * a request, and asserting against the modal before it lands is the single
 * most common source of flake.
 *
 * This provides a custom command, .neoWaitForAjax()
 *
 * @param {number} [timeout=10000]
 *   How long to wait, in milliseconds.
 * @param {function} callback
 *   A callback which will be called.
 * @return {object}
 *   The 'browser' object.
 */
exports.command = function neoWaitForAjax(timeout, callback) {
  const self = this;
  const waitFor = typeof timeout === 'number' ? timeout : 10000;
  const done = typeof timeout === 'function' ? timeout : callback;

  this.waitUntil(
    function waitForSettled() {
      return this.execute(
        // eslint-disable-next-line func-names
        function () {
          if (document.readyState !== 'complete') {
            return false;
          }
          if (typeof Drupal === 'undefined') {
            return false;
          }
          return document.querySelectorAll('.ajax-progress').length === 0;
        },
        [],
      );
    },
    waitFor,
    'Timed out waiting for Drupal AJAX to settle.',
  );

  if (typeof done === 'function') {
    done.call(self);
  }
  return this;
};
