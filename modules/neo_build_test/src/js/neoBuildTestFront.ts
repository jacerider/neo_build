(function (Drupal, once) {

  Drupal.behaviors.neoBuildTestFront = {
    attach: (context: HTMLElement) => {
      once('neoBuildTestFront', '.neo-build-test--front', context).forEach((el) => {
        el.classList.add('neo-build-test--front-attached');
      });
    },
  };

})(Drupal, once);
