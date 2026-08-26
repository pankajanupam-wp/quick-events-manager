/**
 * The four blocks, opened in the editor.
 *
 * CI already builds them on every push and every block registers a PHP
 * `render_callback`, so "the build works" and "the front end ships no block JS"
 * are structurally true and checked elsewhere. What neither of those can tell
 * you is whether a block actually *inserts* — a block whose edit component
 * throws shows the editor's "this block has encountered an error" panel, builds
 * perfectly, and is useless.
 *
 * That is the gap this file closes, and it is the whole of what C10.4 asks for.
 *
 * @package QuickEventsManager
 */

const { test, expect } = require( '@playwright/test' );

const USER = process.env.QEVM_ADMIN_USER || 'admin';
const PASSWORD = process.env.QEVM_ADMIN_PASSWORD || 'password';

const BLOCKS = [
	{ title: 'Event List', name: 'qevm/event-list' },
	{ title: 'Event Details', name: 'qevm/event-details' },
	{ title: 'Event Registration', name: 'qevm/event-registration' },
	{ title: 'Event Calendar', name: 'qevm/event-calendar' },
];

/**
 * Sign in, the way a person does.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 */
async function signIn( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASSWORD );
	await page.click( '#wp-submit' );

	await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 30_000 } );
}

/**
 * Open a new event in the block editor, past whatever it opens with.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 */
async function openTheEditor( page ) {
	await page.goto( '/wp-admin/post-new.php?post_type=qevm_event' );

	// The welcome panel, when the account has not dismissed it.
	const welcome = page.getByRole( 'button', { name: 'Close', exact: true } );

	if ( await welcome.isVisible().catch( () => false ) ) {
		await welcome.click();
	}

	/*
	 * The canvas lives in an iframe in current Gutenberg, and did not in older
	 * ones. Waiting for the frame when there is one and the document when there
	 * is not keeps this working across both rather than pinning it to whichever
	 * WordPress happens to be installed today.
	 */
	await expect(
		canvas( page ).locator( '.editor-styles-wrapper' )
	).toBeVisible( { timeout: 60_000 } );
}

/**
 * Wherever the editor is drawing its content.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {import('@playwright/test').FrameLocator|import('@playwright/test').Page} The canvas.
 */
function canvas( page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

/**
 * Insert one block by its title.
 *
 * @param {import('@playwright/test').Page} page  Page under test.
 * @param {string}                          title Block title.
 */
async function insert( page, title ) {
	await page.locator( '.editor-document-tools__inserter-toggle' ).click();

	const search = page.getByPlaceholder( /Search/ ).first();

	await search.fill( title );

	/*
	 * By the item's own title element. The inserter's options carry a longer
	 * accessible name than the block's title — the description is folded into
	 * it — so an exact match on the role finds nothing, which is what the first
	 * version of this did while all four blocks were sitting there in the list.
	 */
	await page
		.locator( '.block-editor-block-types-list__item-title', { hasText: new RegExp( `^${ title }$` ) } )
		.first()
		.click();
}

test.describe( 'blocks in the editor', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	for ( const block of BLOCKS ) {
		test( `${ block.title } inserts and previews`, async ( { page } ) => {
			await openTheEditor( page );
			await insert( page, block.title );

			const inserted = canvas( page ).locator( `[data-type="${ block.name }"]` );

			await expect( inserted ).toBeVisible();

			/*
			 * The failure this test exists for. A block whose edit component
			 * throws still registers, still builds, and shows this panel
			 * instead of itself.
			 */
			await expect(
				canvas( page ).locator( '.block-editor-warning' ),
				`${ block.title } showed the editor's error panel`
			).toHaveCount( 0 );
		} );
	}

	test( 'all four are offered in the inserter', async ( { page } ) => {
		await openTheEditor( page );

		await page.locator( '.editor-document-tools__inserter-toggle' ).click();

		const search = page.getByPlaceholder( /Search/ ).first();

		await search.fill( 'Event' );

		for ( const block of BLOCKS ) {
			await expect(
				page
					.locator( '.block-editor-block-types-list__item-title', { hasText: new RegExp( `^${ block.title }$` ) } )
					.first(),
				`${ block.title } should be in the inserter`
			).toBeVisible();
		}
	} );
} );
