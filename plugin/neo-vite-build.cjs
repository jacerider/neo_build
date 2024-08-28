import { spawn } from "child_process";
import colors from 'picocolors';
import micromatch from 'micromatch';

const debounce = (func, timeout = 300) => {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => { func.apply(this, args); }, timeout);
  };
}

const reload = debounce((ctx, cache, restart) => {
  let cmd = [];
  cache = typeof cache !== 'undefined' && cache === true;
  restart = typeof restart !== 'undefined' && restart === true;
  if (cache) {
    cmd.push('drush neo-cc');
  }
  if (restart) {
    const restartCmd = 'neo ' + process.env.NEO_SCOPE + ' ' + process.env.NEO_GROUP;
    cmd.push('drush ' + restartCmd);
  }
  cmd = cmd.join(' && ');
  if (restart) {
    spawn(cmd, [], { shell: true });
  }
  else if (cache) {
    const child = spawn(cmd, [], { shell: true });
    child.on('close', code => {
      ctx.server.ws.send({ type: 'full-reload' });
    });
    process.stdout.write(
      `${colors.cyan('[neo]')} ${colors.dim('Page reload...')}\n`
    );
  }
  else {
    ctx.server.ws.send({ type: 'full-reload' });
    process.stdout.write(
      `${colors.cyan('[neo]')} ${colors.dim('Page reload...')}\n`
    );
  }
}, 100);

export default function neoBuild(scope, group) {
  return {
    name: 'vite:neo-build',
    generateBundle: {
      order: 'post',
      handler(options, bundle, isWrite) {
        for (const [fileName, bundleValue] of Object.entries(bundle)) {
          // We always allow the manifest files to be written. It will
          // include all files even if they are ignored from generation.
          if (fileName.startsWith('manifest.')) {
            continue;
          }
          if (group.files.includes(fileName.replace('.map', ''))) {
            if (typeof bundleValue.dynamicImports === 'object') {
              // If file has dynamic imports, make sure those files are built.
              group.files = group.files.concat(bundleValue.dynamicImports);
            }
            continue;
          }
          delete bundle[fileName];
        }
      }
    },
    buildStart: () => {
      process.stdout.write(
        `${colors.cyan('[neo]')} Build Scope: ${colors.yellow(scope.label)}\n`
      );
      process.stdout.write(
        `${colors.cyan('[neo]')} Build Group: ${colors.yellow(group.label)}\n`
      );
      return new Promise((resolve, reject) => {
        let run = 'drush neo-build-start';
        const child = spawn(run, [], { shell: true });
        child.on('close', code => {
          resolve();
        });
      });
    },
    handleHotUpdate: (ctx) => {
      if (micromatch.isMatch(ctx.file, [
        '**/*.php',
      ])) {
        reload(ctx);
        return [];
      }
      if (micromatch.isMatch(ctx.file, [
        '**/*.html.twig',
        '**/*.module',
        '**/*.theme',
      ])) {
        reload(ctx, true);
        return [];
      }
      if (micromatch.isMatch(ctx.file, [
        '**/*.info.yml',
      ])) {
        reload(ctx, true, true);
        return [];
      }
    }
  }
}
