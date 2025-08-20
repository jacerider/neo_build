module.exports = {
  buildRoot: (neo) => {
    return neo.docRoot || 'web';
  },

  buildConfig: (neo) => {
    let config = {
      // outDir: './themes/tmp/dist',
      outDir: './' + neo.primaryRoot + '/dist',
      cssCodeSplit: true,
      manifest: 'manifest.json',
      server: {
        watch: {
          ignored: [
            '**/core/**/*',
            '**/.ddev/**/*',
          ],
        }
      },
      // rollupOptions: {
      //   output: {
      //     assetFileNames: (entry) => {
      //       console.log('assetFileNames');
      //       return '[name].[ext]';
      //     },
      //     chunkFileNames: (entry) => {
      //       console.log('chunkFileNames');
      //       return "[name].js";
      //     },
      //     entryFileNames: (entry) => {
      //       console.log('entryFileNames');
      //       return "[name].js";
      //     }
      //   }
      // }
    };
    if (process.env.NODE_ENV === 'development') {
      // config.rollupOptions = {
      //   input: neo.vite.lib,
      // };
    }
    else {
      config.lib = {
        entry: neo.vite.lib,
      };
    }
    return config;
  },

  buildServer: (neo) => {
    return {
      host: '0.0.0.0',
      origin: `${process.env.DDEV_PRIMARY_URL}:${neo.port}`,
      port: neo.port,
      strictPort: true,
      cors: {
        origin: /https?:\/\/([A-Za-z0-9\-\.]+)?(\.ddev\.site)(?::\d+)?$/,
      }
    }
    return {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,
      origin: `${process.env.DDEV_PRIMARY_URL.replace(/:\d+$/, "")}:${neo.port}`,
      cors: {
        origin: /https?:\/\/([A-Za-z0-9\-\.]+)?(\.ddev\.site)(?::\d+)?$/,
      }
    };
    let origin = '';
    if (process.env.DDEV_PRIMARY_URL) {
      neo.host = '0.0.0.0';
      origin = `${process.env.DDEV_PRIMARY_URL}:${neo.port}`;
      neo.https = false;
    }
    return {
      host: neo.host,
      origin: origin,
      strictPort: true,
      port: neo.port,
      https: neo.https,
      watch: {
        ignored: neo.ignored,
      },
    }

  }
};
