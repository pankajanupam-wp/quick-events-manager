/**
 * Playwright, for the accessibility checks only.
 *
 * This is not a general end-to-end suite and should not become one. PHP
 * behaviour is covered by tests/integration/ against real WordPress and real
 * MySQL, which is faster, easier to debug and does not need a browser. What a
 * browser is needed for is the thing PHP cannot answer: what the accessibility
 * tree actually looks like once the markup, the CSS and the script have all
 * been applied.
 *
 * One browser, deliberately. axe-core reports on the computed accessibility
 * tree, and that tree does not meaningfully differ between engines for static
 * markup — running three would triple CI time to re-derive the same result.
 */

const { defineConfig, devices } = require( '@playwright/test' );

/*
 * The wp-env development site. 8888 rather than the 8889 test site because the
 * accessibility run needs a site with content in it that survives the run,
 * and 8889 is the throwaway one the PHP integration suite resets.
 */
const baseURL = process.env.QEVM_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '**/*.spec.js',

	/*
	 * A violation fails the build, so a flaky pass is worse than useless.
	 * No retries: an accessibility result that only appears on the second
	 * attempt is a result nobody can act on.
	 */
	retries: 0,
	workers: 1,
	timeout: 60_000,

	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],

	use: {
		baseURL,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
