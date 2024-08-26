(function (Drupal, once) {

  Drupal.behaviors.neoBuildTestScopeBack.attach = (context:HTMLElement) => {
    console.log('Scope Back', context, once);
  }

})(Drupal, once);

export {};
