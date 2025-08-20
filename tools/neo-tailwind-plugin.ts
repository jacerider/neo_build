
import plugin from 'tailwindcss/plugin'
// @ts-ignore
import * as fs from 'fs';
// @ts-ignore
import * as path from 'path';

const processData = (layer, theme) => {
  for (const key of Object.keys(layer)) {
    for (const prop of Object.keys(layer[key])) {
      if (prop === 'apply') {
        // We handle 'apply' instances by creating a new layer for the
        // applied styles.
        layer[key][layer[key][prop]] = {};
        delete layer[key][prop];
        continue;
      }
      if (typeof layer[key][prop] === 'string' && !layer[key][prop].includes('theme(')) {
        const themed = theme(layer[key][prop]);
        if (themed !== undefined) {
          layer[key][prop] = themed;
        }
        else {
          layer[key][prop] = layer[key][prop];
        }
      }
      if (typeof layer[key][prop] === 'object') {
        if (Array.isArray(layer[key][prop])) {
          layer[key][prop] = {};
        }
        else {
          const child = processData({'child': layer[key][prop]}, theme);
          layer[key][prop] = child.child || layer[key][prop];
        }
      }
    }
  }
  return layer;
}

let neo;

export default plugin.withOptions(
  ({ isMain = false, neoPath = './neo.json' }: any = {}) => {
    const fullNeoPath = path.isAbsolute(neoPath)
      ? neoPath
      // @ts-ignore
      : path.resolve(process.cwd(), neoPath);
    if (!fs.existsSync(fullNeoPath)) {
      throw new Error(`Neo configuration file not found at ${fullNeoPath}`);
    }
    const neoJson = fs.readFileSync(fullNeoPath, 'utf8');
    try {
      neo = JSON.parse(neoJson);
    } catch (parseError) {
      throw new Error(`Invalid JSON in sources config file ${fullNeoPath}: ${parseError.message}`);
    }

    return function({ addBase, addUtilities, addComponents, addVariant, theme }) {
      if (isMain) {
        const base = processData(JSON.parse(JSON.stringify(neo.tailwind.base)), theme);
        addBase(base);
      }

      const utilities = processData(JSON.parse(JSON.stringify(neo.tailwind.utilities)), theme);
      addUtilities(utilities);

      const components = processData(JSON.parse(JSON.stringify(neo.tailwind.components)), theme);
      addComponents(components);

      const variants = processData(JSON.parse(JSON.stringify(neo.tailwind.variants)), theme);
      for (const [key, value] of Object.entries(variants)) {
        if (typeof value === 'string' || Array.isArray(value)) {
          addVariant(key, value);
        }
      }
    }
  },
  () => {
    return {
      theme: neo.tailwind.theme || {},
    }
  }
);
