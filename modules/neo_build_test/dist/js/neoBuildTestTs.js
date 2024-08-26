console.log("Neo Vite Dev: Success");
const l = async () => {
  await import("../lib/cdn.min.js").then(() => {
    fetch("/api/neo/vite.config.json").then((e) => e.json()).then((e) => {
      var t;
      const n = ((t = document.querySelector(".neo-build-test")) == null ? void 0 : t.outerHTML) ?? "";
      createTailwindcss({
        tailwindConfig: Object.assign({
          corePlugins: { preflight: !1 }
        }, e.tailwind)
      }).generateStylesFromContent(`
          @tailwind components;
          @tailwind utilities;
        `, [n]).then((d) => {
        var s;
        const o = document.createElement("pre");
        o.innerHTML = "<br><hr><br>" + d, (s = document.querySelector(".neo-build-test--jit")) == null || s.appendChild(o);
      });
    });
  });
};
l();
(function(e, n) {
  e.behaviors.neoBuildTest = {}, e.behaviors.neoBuildTest.attach = (i) => {
    n("neoBase", ".neo-build-test--dynamic", i).forEach((t) => {
      t.innerHTML = '<div class="p-2 mt-2 text-white bg-green-500 border-4 border-green-700 rounded neo-build-test--js">This should be in green. (injected via js)<div class="neo-build-test--css">This should be purple. (styled with css)<div class="neo-build-test--jit">JIT Compiling</div></div></div>';
    });
  };
})(Drupal, once);
//# sourceMappingURL=neoBuildTestTs.js.map
