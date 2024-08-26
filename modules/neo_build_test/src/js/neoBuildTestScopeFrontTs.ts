(function (Drupal, once) {

  Drupal.behaviors.neoBuildTestScopeFront.attach = (context: HTMLElement) => {
    console.log('Scope Front', context, once);
  }

})(Drupal, once);

export {};
