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
