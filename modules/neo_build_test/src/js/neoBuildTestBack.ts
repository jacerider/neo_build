(function (Drupal, once) {

  Drupal.behaviors.neoBuildTestBack = {
    attach: (context: HTMLElement) => {
      once('neoBuildTestBack', '.neo-build-test--back', context).forEach((el) => {
        el.classList.add('neo-build-test--back-attached');
      });
    },
  };

})(Drupal, once);
