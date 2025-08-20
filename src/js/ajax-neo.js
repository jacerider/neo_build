// alert('hit');

(function (
  AjaxCommands,
) {

  /**
   * This is a less than ideal workaround for Vite loading CSS as JS.
   *
   * This can be removed when Drupal supports the nomodule type which is used
   * by Vite.
   *
   * @see https://www.drupal.org/project/drupal/issues/3334704
   *
   * @param {Drupal.Ajax} ajax
   *   The Ajax object.
   * @param {Object} response
   *   The response from the server.
   * @param {string} status
   *   The status of the request.
   */
  AjaxCommands.prototype.add_js = function (ajax, response, status) {
    const parentEl = document.querySelector(response.selector || 'body');
    const settings = ajax.settings || drupalSettings;
    const allUniqueBundleIds = response.data.map((script) => {
      const uniqueBundleId = script.src;
      if (!loadjs.isDefined(uniqueBundleId)) {
        loadjs(script.src, uniqueBundleId, {
          // The default loadjs behavior is to load script with async, in Drupal
          // we need to explicitly tell scripts to load async, this is set in
          // the before callback below if necessary.
          async: false,
          before(path, scriptEl) {
            // This allows all attributes to be added, like defer, async and
            // crossorigin.
            Object.keys(script).forEach((attributeKey) => {
              if (!path.endsWith('.ts') && ['type', 'nomodule'].includes(attributeKey)) {
                // These attributes are handled with path modifiers passed to
                // loadjs.
                return;
              }
              scriptEl.setAttribute(attributeKey, script[attributeKey]);
            });

            // By default, loadjs appends the script to the head. When scripts
            // are loaded via AJAX, their location has no impact on
            // functionality. But, since non-AJAX loaded scripts can choose
            // their parent element, we provide that option here for the sake of
            // consistency.
            parentEl.appendChild(scriptEl);
            // Return false to bypass loadjs' default DOM insertion mechanism.
            return false;
          },
        });
      }
      return uniqueBundleId;
    });
    // Returns the promise so that the next AJAX command waits on the
    // completion of this one to execute, ensuring the JS is loaded before
    // executing.
    return new Promise((resolve, reject) => {
      loadjs.ready(allUniqueBundleIds, {
        success() {
          Drupal.attachBehaviors(parentEl, settings);
          // All JS files were loaded and new and old behaviors have
          // been attached. Resolve the promise and let the remaining
          // commands execute.
          resolve();
        },
        error(depsNotFound) {
          const message = Drupal.t(
            `The following files could not be loaded: @dependencies`,
            { '@dependencies': depsNotFound.join(', ') },
          );
          reject(message);
        },
      });
    });
  }

})(Drupal.AjaxCommands);
