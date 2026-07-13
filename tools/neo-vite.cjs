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
        // Emit only the ES module format. In lib mode with multiple entries
        // Vite also builds a `cjs` format whose chunks use the `.cjs`
        // extension, and (being built last) those win the manifest. NeoBuild
        // loads these chunks as `type="module"`, but some hosts (e.g. Pantheon
        // nginx) serve `.cjs` as `text/plain`, so strict MIME checking rejects
        // the module script and the chunk never runs. The `es` output uses the
        // `.js` extension, which every server serves as JavaScript.
        formats: ['es'],
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
