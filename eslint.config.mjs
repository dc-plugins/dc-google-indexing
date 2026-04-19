import globals from 'globals';
import js from '@eslint/js';

export default [
	js.configs.recommended,
	{
		files: [ 'assets/*.js' ],
		languageOptions: {
			ecmaVersion: 2015,
			sourceType: 'script',
			globals: {
				...globals.browser,
			},
		},
		rules: {
			'no-console': 'warn',
			// offsetHeight reads are intentional force-reflow side effects.
			'no-unused-expressions': 'error',
			// Catch-block exception variables are intentionally unused.
			'no-unused-vars': [ 'error', { caughtErrors: 'none' } ],
		},
	},
];
