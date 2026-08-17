/**
 * Wait until Neo animations inside an element have finished.
 *
 * Neo drives entrances and exits with the neo-animate--* classes, and strips
 * `neo-animate--animated` in the animationend handler. So the absence of that
 * class is a precise "the animation finished and its callback ran" signal.
 *
 * This matters more than it looks. An element is *visible* long before it has
 * finished opening, and Neo hangs real work off the completion callback — the
 * modal attaches its keyboard handlers in finishOpen(), which runs from the
 * open animation's callback. Asserting immediately after waitForElementVisible()
 * races that, and the failure is deeply misleading: the element is right there
 * on screen, but Escape does nothing because the listener is not bound yet.
 *
 * It doubles as a guard on the animation plumbing itself. If a completion
 * callback stops firing — an animation name with no CSS behind it, a bubbling
 * animationend completing an ancestor's run — this fails here with a clear
 * message rather than somewhere later for an unrelated-looking reason.
 *
 * This provides a custom command, .neoWaitForAnimations()
 *
 * @param {string} [selector='body']
 *   The element whose descendants are checked for in-flight animations.
 * @param {number} [timeout=5000]
 *   How long to wait, in milliseconds.
 * @return {object}
 *   The 'browser' object.
 */
exports.command = function neoWaitForAnimations(selector, timeout) {
  const target = typeof selector === 'string' ? selector : 'body';
  const waitFor = typeof timeout === 'number' ? timeout : 5000;

  // waitForElementNotPresent is used rather than waitUntil because its
  // condition is evaluated by the driver rather than by a callback whose
  // return value has to be threaded back out of .execute().
  return this.waitForElementNotPresent(
    `${target} .neo-animate--animated`,
    waitFor,
  );
};
