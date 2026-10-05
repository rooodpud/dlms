/**
 * ESLint config: the @wordpress/scripts defaults, plus one adjustment.
 *
 * `@wordpress/*` packages are WordPress-provided externals (the build maps
 * them to the global `wp.*` objects), so they are intentionally not installed
 * as npm dependencies and the import resolution rules don't apply to them.
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaults,
	{
		ignores: [ 'tools/**' ],
	},
	{
		files: [ 'blocks/**/*.js' ],
		settings: {
			'import/core-modules': [
				'@wordpress/block-editor',
				'@wordpress/blocks',
				'@wordpress/components',
				'@wordpress/core-data',
				'@wordpress/data',
				'@wordpress/html-entities',
				'@wordpress/i18n',
				'@wordpress/server-side-render',
			],
		},
	},
	{
		// Plain browser scripts loaded as-is (no build): jQuery + wp globals.
		files: [ 'assets/**/*.js' ],
		languageOptions: {
			globals: {
				jQuery: 'readonly',
				wp: 'readonly',
			},
		},
	},
];
