import { readFile, writeFile } from 'node:fs/promises';
import { defineConfig } from 'vite-plus';

export default defineConfig({
  pack: [
    {
      name: 'configuration-fixture',
      entry: { configuration: 'tests/browser/configuration-runtime.ts' },
      outDir: 'tests/test_app/webroot/js',
      format: 'iife',
      platform: 'browser',
      target: 'es2022',
      minify: true,
      dts: false,
      outputOptions: { entryFileNames: '[name].js' },
      deps: {
        alwaysBundle: [/./],
        onlyBundle: ['@alpinejs/csp', '@alpinejs/morph', 'eventsource-parser'],
        onlyImport: [],
      },
    },
    {
      name: 'demo-widgets',
      entry: { widgets: 'tests/test_app/resources/ts/widgets.ts' },
      outDir: 'tests/test_app/webroot/js',
      format: 'iife',
      platform: 'browser',
      target: 'es2022',
      minify: true,
      dts: false,
      outputOptions: { entryFileNames: '[name].js' },
      deps: {
        alwaysBundle: [/./],
        onlyBundle: [
          'tom-select',
          '@orchidjs/sifter',
          '@orchidjs/unicode-variants',
        ],
        onlyImport: [],
      },
      hooks: {
        'build:done': async () => {
          await writeFile(
            'tests/test_app/webroot/js/widgets.css',
            await readFile(
              'node_modules/tom-select/dist/css/tom-select.default.css',
              'utf8',
            ),
          );
        },
      },
    },
    {
      name: 'npm',
      entry: { index: 'resources/ts/index.ts' },
      outDir: 'build/js',
      format: 'esm',
      platform: 'browser',
      target: 'es2022',
      minify: false,
      dts: { generator: 'tsgo' },
      tsconfig: 'tsconfig.build.json',
      deps: {
        neverBundle: ['alpinejs', '@alpinejs/morph', 'eventsource-parser'],
        onlyImport: ['alpinejs', '@alpinejs/morph', 'eventsource-parser'],
      },
    },
    {
      name: 'standalone',
      entry: { runtime: 'resources/ts/standalone.ts' },
      outDir: 'webroot/js',
      format: 'iife',
      outputOptions: { entryFileNames: '[name].js' },
      platform: 'browser',
      target: 'es2022',
      minify: true,
      dts: false,
      inputOptions: { preserveEntrySignatures: false },
      deps: {
        alwaysBundle: [/./],
        onlyBundle: ['@alpinejs/csp', '@alpinejs/morph', 'eventsource-parser'],
        onlyImport: [],
      },
      banner: '/*! Bundled third-party licenses: THIRD-PARTY-LICENSES.txt */',
      hooks: {
        'build:done': async () => {
          const notices = await Promise.all([
            readFile('resources/licenses/alpine.txt', 'utf8'),
            readFile('node_modules/@vue/reactivity/LICENSE', 'utf8'),
            readFile('node_modules/@vue/shared/LICENSE', 'utf8'),
            readFile('node_modules/eventsource-parser/LICENSE', 'utf8'),
          ]);
          await writeFile(
            'webroot/js/THIRD-PARTY-LICENSES.txt',
            notices.join('\n\n'),
          );
        },
      },
    },
  ],
  server: {
    ws: false,
  },
  lint: {
    plugins: ['typescript'],
    categories: { correctness: 'error' },
    env: {
      builtin: true,
    },
    ignorePatterns: [
      'vendor/**',
      'node_modules/**',
      'build/**',
      'webroot/js/**',
      'tests/test_app/webroot/js/**',
      'docs/.vitepress/dist/**',
      'docs/.vitepress/cache/**',
      'playwright-report/**',
      'test-results/**',
    ],
    rules: {
      'for-direction': 'error',
      'no-async-promise-executor': 'error',
      'no-case-declarations': 'error',
      'no-compare-neg-zero': 'error',
      'no-cond-assign': ['error', 'except-parens'],
      'no-constant-binary-expression': 'error',
      'no-constant-condition': [
        'error',
        {
          checkLoops: 'allExceptWhileTrue',
        },
      ],
      'no-control-regex': 'error',
      'no-debugger': 'error',
      'no-delete-var': 'error',
      'no-dupe-else-if': 'error',
      'no-duplicate-case': 'error',
      'no-empty': [
        'error',
        {
          allowEmptyCatch: false,
        },
      ],
      'no-empty-character-class': 'error',
      'no-empty-pattern': [
        'error',
        {
          allowObjectPatternsAsParameters: false,
        },
      ],
      'no-empty-static-block': 'error',
      'no-ex-assign': 'error',
      'no-extra-boolean-cast': ['error', {}],
      'no-fallthrough': [
        'error',
        {
          allowEmptyCase: false,
          reportUnusedFallthroughComment: false,
        },
      ],
      'no-global-assign': [
        'error',
        {
          exceptions: [],
        },
      ],
      'no-invalid-regexp': ['error', {}],
      'no-irregular-whitespace': [
        'error',
        {
          skipComments: false,
          skipJSXText: false,
          skipRegExps: false,
          skipStrings: true,
          skipTemplates: false,
        },
      ],
      'no-loss-of-precision': 'error',
      'no-misleading-character-class': [
        'error',
        {
          allowEscape: false,
        },
      ],
      'no-nonoctal-decimal-escape': 'error',
      'no-prototype-builtins': 'error',
      'no-regex-spaces': 'error',
      'no-self-assign': [
        'error',
        {
          props: true,
        },
      ],
      'no-shadow-restricted-names': [
        'error',
        {
          reportGlobalThis: false,
        },
      ],
      'no-sparse-arrays': 'error',
      'no-unexpected-multiline': 'error',
      'no-unsafe-finally': 'error',
      'no-unsafe-optional-chaining': [
        'error',
        {
          disallowArithmeticOperators: false,
        },
      ],
      'no-unused-labels': 'error',
      'no-unused-private-class-members': 'error',
      'no-useless-backreference': 'error',
      'no-useless-catch': 'error',
      'no-useless-escape': [
        'error',
        {
          allowRegexCharacters: [],
        },
      ],
      'require-yield': 'error',
      'use-isnan': [
        'error',
        {
          enforceForIndexOf: false,
          enforceForSwitchCase: true,
        },
      ],
      'valid-typeof': [
        'error',
        {
          requireStringLiterals: false,
        },
      ],
      'typescript/ban-ts-comment': 'error',
      'no-array-constructor': 'error',
      'typescript/no-duplicate-enum-values': 'error',
      'typescript/no-empty-object-type': 'error',
      'typescript/no-explicit-any': 'error',
      'typescript/no-extra-non-null-assertion': 'error',
      'typescript/no-misused-new': 'error',
      'typescript/no-namespace': 'error',
      'typescript/no-non-null-asserted-optional-chain': 'error',
      'typescript/no-require-imports': 'error',
      'typescript/no-this-alias': 'error',
      'typescript/no-unnecessary-type-constraint': 'error',
      'typescript/no-unsafe-declaration-merging': 'error',
      'typescript/no-unsafe-function-type': 'error',
      'no-unused-expressions': [
        'error',
        {
          allowShortCircuit: false,
          allowTaggedTemplates: false,
          allowTernary: false,
        },
      ],
      'no-unused-vars': 'error',
      'typescript/no-wrapper-object-types': 'error',
      'typescript/prefer-as-const': 'error',
      'typescript/prefer-namespace-keyword': 'error',
      'typescript/triple-slash-reference': 'error',
    },
    overrides: [
      {
        files: ['tests/Fixture/browser/*.js'],
        globals: { window: 'readonly', document: 'readonly' },
      },
      {
        files: ['**/*.ts'],
        rules: {
          'no-var': 'error',
          'prefer-const': [
            'error',
            {
              destructuring: 'any',
              ignoreReadBeforeAssign: false,
            },
          ],
          'prefer-rest-params': 'error',
          'prefer-spread': 'error',
          'typescript/no-non-null-assertion': 'error',
          'typescript/consistent-type-imports': 'error',
        },
      },
      {
        files: ['**/*.js', '**/*.mjs', '**/*.cjs'],
        rules: {
          'constructor-super': 'error',
          'getter-return': [
            'error',
            {
              allowImplicit: false,
            },
          ],
          'no-class-assign': 'error',
          'no-const-assign': 'error',
          'no-dupe-class-members': 'error',
          'no-dupe-keys': 'error',
          'no-func-assign': 'error',
          'no-import-assign': 'error',
          'no-new-native-nonconstructor': 'error',
          'no-obj-calls': 'error',
          'no-redeclare': [
            'error',
            {
              builtinGlobals: true,
            },
          ],
          'no-setter-return': 'error',
          'no-this-before-super': 'error',
          'no-undef': [
            'error',
            {
              typeof: false,
            },
          ],
          'no-unreachable': 'error',
          'no-unsafe-negation': [
            'error',
            {
              enforceForOrderingRelations: false,
            },
          ],
          'no-with': 'error',
        },
      },
      {
        files: ['scripts/*.mjs'],
        globals: {
          process: 'readonly',
          console: 'readonly',
        },
      },
    ],
    options: {
      typeAware: true,
      typeCheck: true,
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/js/**/*.test.ts'],
    restoreMocks: true,
    coverage: {
      provider: 'v8',
      include: ['resources/ts/**/*.ts'],
      exclude: ['resources/ts/**/*.d.ts'],
      reportsDirectory: 'build/coverage-js',
      reporter: ['text', 'json-summary', 'lcov'],
      thresholds: {
        lines: 95,
      },
    },
  },
  fmt: {
    singleQuote: true,
    trailingComma: 'all',
    printWidth: 80,
    sortPackageJson: false,
    ignorePatterns: [
      'vendor',
      'node_modules',
      'build',
      'webroot/js',
      'tests/test_app/webroot/js',
      'composer.lock',
      'package-lock.json',
      '.git',
      'docs/.vitepress/dist',
      'docs/.vitepress/cache',
      'playwright-report',
      'test-results',
      '*.php',
    ],
  },
});
