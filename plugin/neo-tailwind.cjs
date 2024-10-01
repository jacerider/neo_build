const plugin = require('tailwindcss/plugin');

module.exports = {
  configBuild: function (tailwind, scope) {

    function deepMerge(target, source) {
      const result = { ...target, ...source };
      for (const key of Object.keys(result)) {
        result[key] =
          typeof target[key] == 'object' && typeof source[key] == 'object'
            ? deepMerge(target[key], source[key])
            : structuredClone(result[key]);
      }
      return result;
    }

    let scopeBase;
    if (typeof tailwind.base == 'object' && typeof scope.tailwind.base == 'object') {
      scopeBase = { ...tailwind.base, ...scope.tailwind.base };
    }

    let scopeComponents;
    if (typeof tailwind.components == 'object' && typeof scope.tailwind.components == 'object') {
      scopeComponents = { ...tailwind.components, ...scope.tailwind.components };
    }

    let scopeUtilities;
    if (typeof tailwind.utilities == 'object' && typeof scope.tailwind.utilities == 'object') {
      scopeUtilities = { ...tailwind.utilities, ...scope.tailwind.utilities };
    }

    let scopeVariants;
    if (typeof tailwind.variants == 'object' && typeof scope.tailwind.variants == 'object') {
      scopeVariants = { ...tailwind.variants, ...scope.tailwind.variants };
    }

    const process = (layer, theme) => {
      for (const key of Object.keys(layer)) {
        for (const prop of Object.keys(layer[key])) {
          if (typeof layer[key][prop] === 'string') {
            const themed = theme(layer[key][prop]);
            if (themed !== undefined) {
              layer[key][prop] = themed;
            }
            else {
              layer[key][prop] = layer[key][prop];
            }
          }
          if (typeof layer[key][prop] === 'object') {
            layer[key][prop] = {};
          }
        }
      }
      return layer;
    }

    const config = {
      // Dark mode is handled by schemes.
      darkMode: null,
      content: {},
      theme: {},
      safelist: [],
      plugins: [
        require('@tailwindcss/forms'),
        require('@tailwindcss/typography'),
        require('@tailwindcss/aspect-ratio'),
        require('@tailwindcss/container-queries'),
        plugin(function ({ addBase, addComponents, addUtilities, addVariant, theme }) {
          if (scopeBase) {
            const base = process(JSON.parse(JSON.stringify(scopeBase)), theme);
            addBase(base);
          }
          if (scopeComponents) {
            const components = process(JSON.parse(JSON.stringify(scopeComponents)), theme);
            addComponents(components);
          }
          if (scopeUtilities) {
            const utilities = process(JSON.parse(JSON.stringify(scopeUtilities)), theme);
            addUtilities(utilities);
          }
          if (scopeVariants) {
            for (const [key, value] of Object.entries(scopeVariants)) {
              addVariant(key, value);
            }
          }
        }),
      ]
    };

    tailwind.theme = deepMerge(tailwind.theme, scope.tailwind.theme);
    tailwind.content = tailwind.content.concat(scope.tailwind.content);
    tailwind.safelist = tailwind.safelist.concat(scope.tailwind.safelist);
    return { ...config, ...scope.tailwind, ...tailwind };
  }
}
