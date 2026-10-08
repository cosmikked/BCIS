'use strict';

const js = require('@eslint/js');
const globals = require('globals');

module.exports = [
    { ignores: ['node_modules/**', 'dist/**', 'out/**'] },
    js.configs.recommended,
    {
        files: ['**/*.js'],
        languageOptions: {
            ecmaVersion: 2023,
            sourceType: 'commonjs',
            globals: { ...globals.node },
        },
        rules: {
            'no-eval': 'error',
            'no-implied-eval': 'error',
            'no-new-func': 'error',
            'no-var': 'off',
        },
    },
    {
        // Runs inside the local error page, not in Node.
        files: ['offline/offline.js'],
        languageOptions: {
            sourceType: 'script',
            globals: { ...globals.browser },
        },
    },
];
