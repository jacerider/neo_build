module.exports = {
  buildRoot: (neo) => {
    return neo.docRoot || 'web';
  },

  buildConfig: (neo) => {
    let config = {
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
    };
    if (process.env.NODE_ENV !== 'development') {
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
  }

};
