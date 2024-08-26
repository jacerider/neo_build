module.exports = {
  stylelintBuildConfig: {
    lintInWorker: true,
    lintOnStart: true,
    chokidar: true,
    fix: true,
  },
  buildStylelint: (scope) => {
    return {
      includes: scope.stylelint,
      extends: [
        'stylelint-config-recommended-scss',
        'stylelint-config-idiomatic-order',
      ],
      plugins: ['stylelint-prettier'],
      rules: {
        'prettier/prettier': true,
        'no-invalid-position-at-import-rule': null,
        'scss/at-rule-no-unknown': [
          true,
          {
            ignoreAtRules: [
              'extends',
              'ignores',
              'mixin',
              'function',
              'include',
              'if',
              'each',
              'else',
              'warn',
              'return',
              'error',
              'extend',
              'for',
              'use',
              'tailwind',
              'apply',
            ],
          },
        ],
        // Because stylint doesn't know about the DOM, this rule gets in more
        // trouble than it's worth. It's better to just disable it.
        'no-descending-specificity': null,
        'at-rule-empty-line-before': [
          'always',
          {
            except: ['first-nested'],
            ignore: ['after-comment', 'blockless-after-blockless'],
            ignoreAtRules: [
              'extends',
              'ignores',
              'mixin',
              'function',
              'include',
              'if',
              'each',
              'else',
              'warn',
              'return',
              'error',
              'extend',
            ],
          },
        ],
        'scss/at-import-partial-extension': null,
        'scss/at-import-partial-extension-allowed-list': ['scss'],
        'selector-class-pattern': /^([a-zA-Z0-9_-]+-?)+$/,
      },
    }
  },
  stylelintConfig: {
    extends: [
      'stylelint-config-recommended-scss',
      'stylelint-config-idiomatic-order',
    ],
    plugins: ['stylelint-prettier'],
    rules: {
      'prettier/prettier': true,
      'no-invalid-position-at-import-rule': null,
      'scss/at-rule-no-unknown': [
        true,
        {
          ignoreAtRules: [
            'extends',
            'ignores',
            'mixin',
            'function',
            'include',
            'if',
            'each',
            'else',
            'warn',
            'return',
            'error',
            'extend',
            'for',
            'use',
            'tailwind',
            'apply',
          ],
        },
      ],
      // Because stylint doesn't know about the DOM, this rule gets in more
      // trouble than it's worth. It's better to just disable it.
      'no-descending-specificity': null,
      'at-rule-empty-line-before': [
        'always',
        {
          except: ['first-nested'],
          ignore: ['after-comment', 'blockless-after-blockless'],
          ignoreAtRules: [
            'extends',
            'ignores',
            'mixin',
            'function',
            'include',
            'if',
            'each',
            'else',
            'warn',
            'return',
            'error',
            'extend',
          ],
        },
      ],
      'scss/at-import-partial-extension': null,
      'scss/at-import-partial-extension-allowed-list': ['scss'],
      'selector-class-pattern': /^([a-zA-Z0-9_-]+-?)+$/,
    },
  },
}
