import { Plugin } from "vite";
// @ts-ignore
import { resolve } from 'node:path';
// @ts-ignore
import { spawn } from "child_process";
import colors from 'picocolors';
import micromatch from 'micromatch';

declare var NodeJS: any;
declare var process: any;

interface ReloadOptions {
  cache?: boolean;
  restart?: boolean;
}

let neo: any;

const resolveFn = (fn: unknown, ...args: unknown[]) => {
  return Promise.resolve(fn instanceof Function ? fn(args) : fn);
};

let root: string;
const injectStr = (type: string, reference: string | string[], suffix?: Record<string, any>) => {
  let suffixStr = ';';
  if (suffix) {
    suffixStr = ` ${JSON.stringify(suffix)}`;
  }
  return (Array.isArray(reference) ? reference : [reference]).reduce(
    (acc, file) => `${acc}\n@${type} "${resolve(root, file)}"${suffixStr}`,
    ""
  );
};

const reload = (() => {
  let timer: any;

  return (ctx: { server: { ws: { send: (data: any) => void } } }, options: ReloadOptions = {}) => {
    const { cache = false, restart = false } = options;
    clearTimeout(timer);

    timer = setTimeout(() => {
      const commands: string[] = [];

      if (cache) {
        commands.push('drush neo-cc');
      }

      if (restart) {
        commands.push(`drush neo ${neo.scope}`);
      }

      const runCommand = commands.join(' && ');

      if (restart) {
        spawn(runCommand, [], { shell: true });
      } else if (cache) {
        const child = spawn(runCommand, [], { shell: true });
        child.on('close', () => {
          ctx.server.ws.send({ type: 'full-reload' });
        });
        process.stdout.write(
          `${colors.cyan('[neo]')} ${colors.dim('Page reload...')}\n`
        );
      } else {
        ctx.server.ws.send({ type: 'full-reload' });
        process.stdout.write(
          `${colors.cyan('[neo]')} ${colors.dim('Page reload...')}\n`
        );
      }
    }, 100);
  };
})();

const neoVite = (config:any): Plugin => {
  neo = config;
  const cssFile = neo.root + neo.docRoot + neo.primaryFile;
  const plugin = neo.root + neo.docRoot + neo.neoRoot + 'tools/neo-tailwind-plugin.ts';

  return {
    name: 'neo:vite',
    enforce: 'pre',
    configResolved: (config) => {
      root = config.root;
    },
    transform: async (code: string, id: string) => {
      if (id === cssFile) {
        // This is the main CSS file. We add sources and our tailwind plugin.

        const lastUseMatch = [...code.matchAll(/^\s*@import "tailwindcss".*\n/gm)].at(-1);
        if (!lastUseMatch) {
          return null;
        }
        const before = code.substring(0, lastUseMatch.index + lastUseMatch[0].length);
        let after = code.substring(lastUseMatch.index + lastUseMatch[0].length);

        // Imports.
        if (neo.tailwind.import) {
          for (const path of neo.tailwind.import) {
            after = `${injectStr('import', await resolveFn(path, code, id))}\n${after}`;
          }
        }

        // Sources.
        for (const [key, path] of Object.entries(neo.tailwind.source)) {
          after = `${injectStr('source', await resolveFn(path, code, id))}\n${after}`;
        }

        // Plugin.
        // after = `${injectStr('plugin', await resolveFn(plugin, code, id), {isMain: true})}\n${after}`;

        code = `${before}${after}`;

        return {
          code: code,
          map: null
        };
      }

      // Automatically apply @reference to all files not in scope.
      // @see https://tailwindcss.com/docs/functions-and-directives#reference-directive
      if (code.includes("@reference ")) return null;
      if (!code.includes("@apply ")) return null;

      const lastUseMatch = [...code.matchAll(/^\s*@use.*\n/gm)].at(-1);
      if (!lastUseMatch) {
        code = `${injectStr('reference', await resolveFn(cssFile, code, id))}\n${code}`;
      }
      else {
        const before = code.substring(0, lastUseMatch.index);
        const after = code.substring(lastUseMatch.index + lastUseMatch[0].length);
        code = `${before}${injectStr('reference', await resolveFn(cssFile, code, id))}\n${after}`;
      }

      code = `${injectStr('plugin', await resolveFn(plugin, code, id))}\n${code}`;

      return {
        code: code,
        map: null
      };
    },

    handleHotUpdate: (ctx) => {
      if (micromatch.isMatch(ctx.file, [
        '**/*.php',
      ], {})) {
        reload(ctx);
        return [];
      }
      if (micromatch.isMatch(ctx.file, [
        '**/*.twig',
        '**/*.module',
        '**/*.theme',
        '**/*.component.yml',
      ], {})) {
        reload(ctx, {cache: true});
        return [];
      }
      if (micromatch.isMatch(ctx.file, [
        '**/*.info.yml',
        '**/*.libraries.yml',
      ], {})) {
        reload(ctx, {cache: true, restart: true});
        return [];
      }
    }
  };
};

const neoVitePost = (config:any): Plugin => {

  return {
    name: 'neo:vite:post',

    /**
     * Wraps a chunk in an IIFE when it would otherwise declare globals.
     *
     * The lib build emits the `es` format (see neo-vite.cjs), which leaves
     * top-level `class`/`function` declarations exposed. Loaded as classic
     * scripts those become globals, and two chunks minified to the same name
     * (e.g. `class b`) collide. NeoBuild used to compensate by serving every
     * chunk as `type="module"`, but a module script is deferred, which silently
     * voids Drupal's asset dependency ordering — a chunk could run before
     * jQuery or Drupal existed. Scoping the chunk at build time instead lets it
     * load as an ordinary classic script that Drupal can order.
     *
     * Most entrypoints are authored as an IIFE already, so their compiled chunk
     * is a lone expression statement that declares nothing and needs no help.
     * Wrapping those too is harmless but pointless, so the decision is made off
     * the parsed chunk rather than guessed: if every top-level node is an
     * expression, there is no binding to contain. Anything else — a
     * declaration, a bare `for` initialiser — gets wrapped.
     *
     * A chunk that genuinely imports or exports cannot be wrapped at all: the
     * ESM syntax would become a parse error inside a function body. None do
     * today (the entrypoints are self-contained), but rather than emit a broken
     * chunk, leave it alone and say so — it needs `type="module"` to load, and
     * NeoBuild no longer applies it.
     */
    renderChunk: function (this: any, code: string, chunk: any) {
      if (chunk.imports?.length || chunk.exports?.length || chunk.dynamicImports?.length) {
        process.stdout.write(
          `${colors.yellow('[neo]')} ${chunk.fileName} uses ESM syntax, so it was left unwrapped. ` +
          `Its top-level names are globals and it will not be scoped. See neoVitePost().\n`
        );
        return null;
      }

      let declaresBindings = true;
      try {
        // Rollup's own parser — no guessing, and no extra dependency.
        const ast: any = this.parse(code);
        declaresBindings = !ast.body.every((node: any) => node.type === 'ExpressionStatement');
      }
      catch (e) {
        // Could not prove the chunk is clean, so assume it is not. Wrapping a
        // chunk that did not need it costs a closure; skipping one that did
        // costs a global collision at runtime.
      }

      if (!declaresBindings) {
        return null;
      }

      return {
        code: `(function(){\n${code}\n})();\n`,
        map: null
      };
    },

    transform: async (code: string, id: string) => {
      if (code.includes('@assets/')) {
        // Replace @assets with the correct path to the module or theme assets
        // directory.
        const parts = id.split('/src/');
        const path = parts[0].replace(neo.root + neo.docRoot, '/') + '/assets/';
        code = code.replaceAll('@assets', path);

        return {
          code: code,
          map: null
        };
      }
      return null;
    }
  };
};

export {
  neoVite,
  neoVitePost
};
