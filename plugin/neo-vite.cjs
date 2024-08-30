import path from 'path';
let lastPath = [];

module.exports = {
  buildConfig: (scope) => {
    return {
      outDir: './',
      emptyOutDir: false,
      sourcemap: true,
      cssCodeSplit: true,
      manifest: 'manifest.' + process.env.NEO_SCOPE + '.json',
      lib: {
        entry: scope.vite.lib,
        formats: ["es"],
      },
      server: {
        watch: {
          ignored: [
            '**/core/**/*',
            '**/.ddev/**/*',
          ],
        }
      },
      rollupOptions: {
        output: {
          assetFileNames: (entry) => {
            const { name } = entry;
            let dir = path.dirname(name);
            if (dir === '.') {
              dir = lastPath.shift();
            }
            else {
              dir = dir.replace('web/', '').replace('/src/', '/dist/');
              lastPath.push(dir);
            }
            return dir + '/[name].[ext]';
          },
          chunkFileNames: (entry) => {
            const { facadeModuleId } = entry;
            let fileName = "[name].js";
            if (!facadeModuleId) {
              return fileName;
            }
            fileName = path.parse(facadeModuleId).name + '.js';
            const relativeDir = (path.relative(
              path.resolve(process.cwd(), 'src'),
              path.dirname(facadeModuleId),
            ).replace('../web/', '') + '/').replace('/src/', '/dist/').replace('/lib/', '/dist/lib/');
            return path.join(relativeDir, fileName);
          },
          entryFileNames: (entry) => {
            const { facadeModuleId } = entry;
            let fileName = "[name].js";
            if (!facadeModuleId) {
              return fileName;
            }
            fileName = path.parse(facadeModuleId).name + '.js';
            const relativeDir = (path.relative(
              path.resolve(process.cwd(), 'src'),
              path.dirname(facadeModuleId),
            ).replace('../web/', '') + '/').replace('/src/', '/dist/');
            return path.join(relativeDir, fileName);
          },
        }
      }
    };
  },
  buildBase: (scope, group) => {
    return process.env.DDEV_PRIMARY_URL ? '/neo-assets/' : '/';
  },
  buildServer: (host, port, https, ignored) => {
    let origin = '';
    if (process.env.DDEV_PRIMARY_URL) {
      host = '0.0.0.0';
      origin = `${process.env.DDEV_PRIMARY_URL}:${port}`;
      https = false;
    }
    return {
      host: host,
      origin: origin,
      strictPort: true,
      port: port,
      https: https,
      watch: {
        ignored: ignored,
      },
    }
  },
  buildCss: (scope) => {
    return {
      preprocessorOptions: {
        scss: {
          includePaths: scope.vite.scssInclude,
          additionalData: scope.vite.scssAdditionalData,
        },
      },
    }
  },
  checkerConfig: {
    typescript: true,
  },
  postcssConfig: {},
}
