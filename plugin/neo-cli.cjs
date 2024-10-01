const execSync = require('child_process').execSync;
const spawn = require('child_process').spawn;
const colors = require('picocolors');
const prompts = require('@inquirer/prompts');
let scopes = null;
const getScopes = function () {
  if (!scopes) {
    const cmd = 'drush neo-scopes --format=json';
    scopes = JSON.parse(execSync(cmd).toString());
  }
  return scopes;
};
const runScopes = [];

let target = process.env.npm_config_target || null;
let scope = process.env.npm_config_scope || null;
let group = process.env.npm_config_group || null;
let scopeAll = process.env.npm_config_scope_all || false;

if (scopeAll) {
  const scopes = getScopes();
  for (const key in scopes) {
    if (Object.hasOwnProperty.call(scopes, key)) {
      runScopes.push(key);
    }
  }
}
else if (scope) {
  runScopes.push(scope);
}

const cli = async function () {
  process.stdout.write(
    `${colors.cyan('[neo]')} ${colors.yellow('Build CLI')}\n\n`
  );

  if (!target) {
    target = await prompts.select({
      message: 'Select target',
      choices: [
        { name: 'DEV', value: 'dev', 'description': 'Will enable hot module replacement for Neo assets.' },
        { name: 'PROD', value: 'prod', 'description': 'Will aggregate and compress Neo assets.' },
      ],
    }).catch(function () {
      process.exit(1);
    });
  }

  if (!scopeAll && !scope) {
    const scopes = getScopes();
    const options = [];
    for (const key in scopes) {
      if (Object.hasOwnProperty.call(scopes, key)) {
        const scope = scopes[key];
        options.push({
          name: scope.label,
          value: scope.id,
          description: scope.description,
        });
      }
    }

    scope = await prompts.select({
      message: 'Select scope',
      choices: options,
    }).catch(function () {
      process.exit(1);
    });

    runScopes.push(scope);
  }

  if (target === 'dev' && !group) {
    const cmd = 'drush neo-groups --format=json';
    const groups = JSON.parse(execSync(cmd).toString());
    const options = [];
    for (const key in groups) {
      if (Object.hasOwnProperty.call(groups, key)) {
        const group = groups[key];
        options.push({
          name: group.label,
          value: group.id,
          description: group.description,
        });
      }
    }

    group = await prompts.select({
      message: 'Select group',
      choices: options,
    }).catch(function () {
      process.exit(1);
    });
  }

  let prefixDrush = '';
  let suffixVite = '';
  let timeout = 0;
  let dev = 'enable';
  if (target === 'prod') {
    suffixVite += ' build && tsc';
    dev = 'disable';
  }

  try {
    execSync(prefixDrush + 'drush neo-dev-' + dev, { stdio: 'inherit' });
  }
  catch (e) {
  }

  try {
    execSync(prefixDrush + 'drush neo ' + scope + ' ' + group, { stdio: 'inherit' });
  }
  catch (e) {
  }

  runScopes.forEach(async function (scope) {
    await new Promise((resolve, reject) => {
      try {
        let prefixVite = 'NEO_SCOPE=' + scope + ' ';
        if (group) {
          prefixVite += 'NEO_GROUP=' + group + ' ';
        }

        setTimeout(function () {
          try {
            execSync(prefixVite + 'vite' + suffixVite, { stdio: 'inherit' });
            resolve();
          }
          catch (e) {
            if (target !== 'dev') {
              return;
            }
            // Build for production.
            // When running in dev mode, we want to build for production.
            const doBuild = process.env.NODE_ENV !== 'production' && typeof process.env.VITE_BUILD === 'undefined';
            let run;
            if (doBuild) {
              run = `VITE_BUILD=true npm start --target=prod --scope=${scope} --group=${group}`;
            }
            else {
              run = 'drush neo-build-end';
            }
            const child = spawn(run, [], { shell: true })
            if (doBuild) {
              let dots = '.';
              child.stdout.on('data', data => {
                process.stdout.write('  ' + dots + '\r');
                dots += '.';
              })
              child.stderr.on('data', data => {
                process.stdout.write(`  ${colors.yellow(data.toString())}`)
              })
              process.stdout.write(
                `\n  ${colors.cyan('[neo]')} ${colors.yellow('Building for production...')}\n`
              );
            }
            child.on('close', code => {
              if (doBuild) {
                process.stdout.write(
                  `  ${colors.green('✔')} Production build complete\n`
                );
              }
              resolve();
            });
          }
        }, timeout);
      }
      catch (e) {
        reject();
      }
    });
  });
}

cli();
