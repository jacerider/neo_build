console.log('Neo Vite Dev: Success');

const init = async () => {
  // @ts-ignore
  await import('../../lib/cdn.min.js').then(() => {
    // @todo - this path should be dynamically supplied. It will also not be
    // available in production without running `drush vite` first.
    fetch('/api/neo/vite.config.json')
      .then((response) => response.json())
      .then((viteConfig) => {
        const htmlContent = document.querySelector('.neo-build-test')?.outerHTML ?? '';
        const tailwind = createTailwindcss({
          tailwindConfig: Object.assign({
            corePlugins: { preflight: false }
          }, viteConfig.tailwind),
        });

        tailwind.generateStylesFromContent(`
          @tailwind components;
          @tailwind utilities;
        `, [htmlContent]).then((css:string) => {
          const node = document.createElement('pre');
          node.innerHTML = '<br><hr><br>' + css
          document.querySelector('.neo-build-test--jit')?.appendChild(node);
        });

      });
  });
}
init();

(function (Drupal, once) {

  Drupal.behaviors.neoBuildTest = {};

  Drupal.behaviors.neoBuildTest.attach = (context:HTMLElement) => {
    once('neoBase', '.neo-build-test--dynamic', context).forEach(el => {
      el.innerHTML = '<div class="p-2 mt-2 text-white bg-green-500 border-4 border-green-700 rounded neo-build-test--js">This should be in green. (injected via js)<div class="neo-build-test--css">This should be purple. (styled with css)<div class="neo-build-test--jit">JIT Compiling</div></div></div>';
    });
  }

})(Drupal, once);

export {};
