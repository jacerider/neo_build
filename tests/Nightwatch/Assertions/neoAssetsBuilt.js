/**
 * Assert the page loaded compiled Neo assets, not the Vite dev server.
 *
 * The test-side counterpart to `neo test`'s dev-mode guard. Nightwatch drives a
 * real browser, so it tests whatever the page actually serves — and in dev mode
 * that is HMR output from the Vite dev server, one scope at a time, rather than
 * the compiled dist/ files that ship.
 *
 * The CLI refuses to start a run in dev mode, but a run started some other way
 * (a bare `yarn test:nightwatch`, CI, someone enabling dev mode mid-run) has no
 * such protection. Asserting it in the test turns "mysteriously wrong results"
 * into one clear failure.
 *
 * Usage: browser.assert.neoAssetsBuilt();
 */
module.exports.assertion = function neoAssetsBuilt() {
  this.message = 'Testing that the page loaded compiled Neo assets, not the Vite dev server';
  this.expected = false;
  this.pass = (devServerDetected) => devServerDetected === this.expected;
  this.value = (result) => result.value;
  this.command = function command(callback) {
    return this.api.execute(
      // eslint-disable-next-line func-names
      function () {
        // Vite injects its HMR client in dev; compiled builds never contain it.
        return (
          document.querySelectorAll('script[src*="@vite/client"]').length > 0 ||
          typeof window.__vite_plugin_react_preamble_installed__ !== 'undefined'
        );
      },
      [],
      callback,
    );
  };
};
