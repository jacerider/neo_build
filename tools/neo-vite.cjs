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
    // Refuse rather than compose `undefined:5173` as the origin. Neo's dev
    // server runs under DDEV and no other environment is supported, so an
    // absent variable is a broken environment, not a case to fall back for.
    // Every asset URL Vite hands the browser is built from this origin, so
    // composing one out of `undefined` produces a server that starts happily
    // and serves nothing.
    if (!process.env.DDEV_PRIMARY_URL) {
      throw new Error(
        'DDEV_PRIMARY_URL is not set. Neo runs its dev server under DDEV and no other ' +
        'environment is supported. Start the site with DDEV and run this again.'
      );
    }
    return {
      host: '0.0.0.0',
      origin: `${process.env.DDEV_PRIMARY_URL}:${neo.port}`,
      port: neo.port,
      strictPort: true,
      cors: {
        // Deliberately unchanged. Now that the server refuses to start without
        // the variable, this regex is unreachable outside DDEV, so relaxing it
        // would only widen what is accepted on the sites that do run it.
        origin: /https?:\/\/([A-Za-z0-9\-\.]+)?(\.ddev\.site)(?::\d+)?$/,
      }
    }
  }

};
