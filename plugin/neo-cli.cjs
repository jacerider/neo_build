#!/usr/bin/env node

/**
 * Neo Build CLI
 * A command-line tool for Drupal Neo build operations
 */

const { execSync } = require('child_process');
const colors = require('picocolors');
const prompts = require('@inquirer/prompts');

// Configuration
const CONFIG = {
  commands: {
    getDrushScopes: 'drush neo-scopes --format=json',
    getDrushGroups: 'drush neo-groups --format=json',
    setDrushDev: 'drush neo-dev-',
    drushNeo: 'drush neo',
    drushBuildEnd: 'drush neo-build-end',
    vite: 'vite'
  }
};

// CLI state
const state = {
  scopes: null,
  runScopes: [],
  target: process.env.npm_config_target || null,
  scope: process.env.npm_config_scope || null,
  group: process.env.npm_config_group || null,
  scopeAll: process.env.npm_config_scope_all || false
};

/**
 * Safely execute a command and return the output
 * @param {string} command - Command to execute
 * @param {Object} options - Options for execSync
 * @returns {string|null} Command output or null on error
 */
function safeExec(command, options = {}) {
  try {
    return execSync(command, { ...options }).toString();
  } catch (error) {
    if (options.throwError) {
      throw error;
    }
    return null;
  }
}

/**
 * Execute a command and display output
 * @param {string} command - Command to execute
 * @returns {boolean} Success status
 */
function execAndShow(command) {
  try {
    execSync(command, { stdio: 'inherit' });
    return true;
  } catch (error) {
    return false;
  }
}

function clear() {
  try {
    process.stdout.write('\x1Bc');
  } catch (error) {
    console.error('Error clearing screen:', error);
  }
}

/**
 * Get available scopes from Drupal
 * @returns {Object} Available scopes
 */
function getScopes() {
  if (!state.scopes) {
    const scopesJson = safeExec(CONFIG.commands.getDrushScopes);
    if (!scopesJson) {
      console.error(`${colors.red('[neo]')} Failed to fetch scopes`);
      process.exit(1);
    }
    state.scopes = JSON.parse(scopesJson);
  }
  return state.scopes;
}

/**
 * Get available groups from Drupal
 * @returns {Object} Available groups
 */
function getGroups() {
  const groupsJson = safeExec(CONFIG.commands.getDrushGroups);
  if (!groupsJson) {
    console.error(`${colors.red('[neo]')} Failed to fetch groups`);
    process.exit(1);
  }
  return JSON.parse(groupsJson);
}

/**
 * Set development mode
 * @param {string} mode - 'enable' or 'disable'
 */
function setDevMode(mode) {
  const command = `${CONFIG.commands.setDrushDev}${mode}`;
  execAndShow(command);
}

/**
 * Call for cleanup
 * @param {string} mode - 'enable' or 'disable'
 */
function cleanup() {
  const command = `drush neo-dev-cleanup`;
  execAndShow(command);
}

/**
 * Run Drupal Neo command for a scope and group
 * @param {string} scope - The scope
 * @param {string} group - The group (optional)
 */
function runDrushNeo(scope, group) {
  const groupParam = group ? ` ${group}` : '';
  const command = `${CONFIG.commands.drushNeo} ${scope}${groupParam}`;
  execAndShow(command);
}

/**
 * Run Vite for production
 * @param {string} group - The group
 */
async function runViteProd(group) {
  clear();
  console.log(`\n${colors.cyan('[neo]')} ${colors.yellow('Building for production...')}`);
  const command = `VITE_BUILD=true npm start --target=prod --scope-all --group=${group}`;
  execAndShow(command);
}

/**
 * Run Vite for a specific scope and group
 * @param {string} scope - The scope
 * @param {string} target - 'dev' or 'prod'
 * @param {string} group - The group (optional)
 * @returns {Promise<void>}
 */
async function runVite(scope, target, group) {
  return new Promise((resolve, reject) => {
    let env = `NEO_SCOPE=${scope} `;
    if (group) {
      env += `NEO_GROUP=${group} `;
    }

    let viteCommand = CONFIG.commands.vite;
    if (target !== 'dev') {
      viteCommand += ' build && tsc';
    }

    execAndShow(`${env}${viteCommand}`);
    resolve();
  });
}

/**
 * Initialize CLI setup
 */
function initializeState() {
  // Handle scope-all flag
  if (state.scopeAll) {
    const scopes = getScopes();
    state.runScopes = Object.keys(scopes);
  } else if (state.scope) {
    state.runScopes.push(state.scope);
  }

  let count = 0;
  // Silently catch SIGINT and exit.
  process.on('SIGINT', () => {
    if (count > 0) {
      process.exit(0);
    }
    count++;
  });
}

/**
 * Prompt for target if not provided
 * @returns {Promise<string>}
 */
async function promptForTarget() {
  if (state.target) return state.target;

  return prompts.select({
    message: 'Select target',
    choices: [
      {
        name: 'DEV',
        value: 'dev',
        description: 'Will enable hot module replacement for Neo assets.'
      },
      {
        name: 'PROD',
        value: 'prod',
        description: 'Will aggregate and compress Neo assets.'
      },
    ],
  }).catch(() => process.exit(1));
}

/**
 * Prompt for scope if not provided
 * @returns {Promise<string>}
 */
async function promptForScope() {
  if (state.scopeAll || state.scope) return state.scope;

  const scopes = getScopes();
  const options = Object.entries(scopes).map(([key, scope]) => ({
    name: scope.label,
    value: scope.id,
    description: scope.description,
  }));

  const selectedScope = await prompts.select({
    message: 'Select scope',
    choices: options,
  }).catch(() => process.exit(1));

  state.runScopes.push(selectedScope);
  return selectedScope;
}

/**
 * Prompt for group if in dev mode and not provided
 * @returns {Promise<string>}
 */
async function promptForGroup() {
  if (state.target !== 'dev' || state.group) return state.group;

  const groups = getGroups();
  const options = Object.entries(groups).map(([key, group]) => ({
    name: group.label,
    value: group.id,
    description: group.description,
  }));

  return prompts.select({
    message: 'Select group',
    choices: options,
  }).catch(() => process.exit(1));
}

/**
 * Run the CLI
 */
async function cli() {
  try {

    initializeState();

    // Interactive prompts
    state.target = await promptForTarget();
    await promptForScope();
    state.group = await promptForGroup();

    // Set dev mode based on target
    const devMode = state.target === 'prod' ? false : true;
    if (devMode) {
      clear();
      console.log(`${colors.cyan('[neo]')} ${colors.yellow('Build CLI')}\n`);
      setDevMode('enable');
    }
    else {
      setDevMode('disable');
    }

    // Run Drupal Neo command for each scope
    for (const scope of state.runScopes) {
      runDrushNeo(scope, state.group);
    }

    // Run Vite for each scope
    const scopePromises = state.runScopes.map(scope =>
      runVite(scope, state.target, state.group)
    );

    await Promise.all(scopePromises);
    if (!devMode) {
      cleanup();
      console.log(`\n${colors.green('✔')} ${colors.cyan('[neo]')} All builds completed successfully`);
      process.exit(1);
    }
    else {
      runViteProd(state.group);
    }
  } catch (error) {
    console.error(`\n${colors.red('✘')} ${colors.cyan('[neo]')} Build failed:`, error.message);
    process.exit(1);
  }
}

// Start the CLI
cli();
