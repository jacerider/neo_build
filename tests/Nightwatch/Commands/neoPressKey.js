/**
 * Send a key to the page.
 *
 * Nightwatch's `.keys()` is deprecated and does nothing under W3C — it only
 * works over the legacy Selenium JSONWire protocol, and DDEV's selenium add-on
 * sets DRUPAL_TEST_WEBDRIVER_W3C=true. Its documented replacement, the Actions
 * API via `.perform()`, silently delivers nothing here either: verified against
 * a headless Chromium session where the window had focus and the expected
 * element was active, yet the handler never fired. Both failure modes look
 * identical to "the key did nothing", which is a miserable thing to debug.
 *
 * So this dispatches a real KeyboardEvent at the focused element and lets it
 * bubble, which is the path a genuine keypress takes once the browser has
 * routed it. The deliberate trade-off: this does NOT exercise the browser's own
 * key routing — but that is Chrome's behaviour, not the application's, and it
 * buys determinism in exchange. Everything downstream of the event reaching the
 * DOM is the real thing.
 *
 * This provides a custom command, .neoPressKey()
 *
 * @param {string} key
 *   The key value, e.g. 'Escape' or browser.Keys.ESCAPE.
 * @param {function} callback
 *   A callback which will be called.
 * @return {object}
 *   The 'browser' object.
 */
exports.command = function neoPressKey(key, callback) {
  const self = this;

  this.execute(
    // eslint-disable-next-line func-names
    function (pressed) {
      // Nightwatch's Keys.* are unicode control characters; map the ones we
      // use back to the KeyboardEvent key values the DOM expects.
      const CONTROL_KEYS = {
        '\uE00C': 'Escape',
        '\uE007': 'Enter',
        '\uE004': 'Tab',
        '\uE00D': ' ',
        '\uE013': 'ArrowUp',
        '\uE014': 'ArrowRight',
        '\uE015': 'ArrowDown',
        '\uE012': 'ArrowLeft',
      };
      const value = CONTROL_KEYS[pressed] || pressed;
      const target = document.activeElement || document.body;
      target.dispatchEvent(
        new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true }),
      );
      target.dispatchEvent(
        new KeyboardEvent('keyup', { key: value, bubbles: true, cancelable: true }),
      );
      return value;
    },
    [key],
  );

  if (typeof callback === 'function') {
    callback.call(self);
  }
  return this;
};
