/**
 * axe-core over every admin screen this plugin adds.
 *
 * The public screens are covered next door. This file began because the stage 8
 * gate asked for a clean scan of the **check-in screen** and nothing could reach
 * it — the other suite has no login, and every admin screen is behind one — and
 * grew in C10.1 to cover the rest.
 *
 * The door is the screen that most deserves it. It is used one-handed, at arm's
 * length, in bad light, by somebody who may be a volunteer rather than the site
 * owner — and it is the one screen where a person is standing in front of you
 * while you use it.
 *
 * @package QuickEventsManager
 */

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const USER = process.env.QEVM_ADMIN_USER || 'admin';
const PASSWORD = process.env.QEVM_ADMIN_PASSWORD || 'password';

/*
 * The seeded event by name, not "the first link on the page". The picker is
 * ordered by date and the fixture file also seeds an event in the past with
 * nobody booked on it — which is what the first version of this spec opened,
 * scanning an empty list and proving nothing.
 */
const EVENT = process.env.QEVM_EVENT_TITLE || 'Accessibility fixture event';

/**
 * Open the door for the seeded event.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 */
async function openTheDoor( page ) {
	await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-checkin' );

	await page.getByRole( 'link', { name: EVENT, exact: true } ).first().click();

	await expect( page.locator( '.qevm-door__list li' ).first() ).toBeVisible();
}

/**
 * Run axe against the current page and fail with something readable.
 *
 * @param {import('@playwright/test').Page} page  Page under test.
 * @param {string}                          where Human name for the screen.
 */
async function expectNoViolations( page, where ) {
	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )

		/*
		 * WordPress's own chrome is excluded, for the reason the public suite
		 * excludes the theme: the admin menu, toolbar and footer are core's
		 * markup, their violations are core's to fix, and a build that fails on
		 * somebody else's markup is a build everybody learns to ignore.
		 *
		 * The cost is the same and worth writing down: page-level rules that
		 * need the whole document are not checked here.
		 */
		.exclude( '#adminmenumain' )
		.exclude( '#wpadminbar' )
		.exclude( '#wpfooter' )
		.exclude( '#screen-meta' )
		.exclude( '#screen-meta-links' )
		.analyze();

	const summary = results.violations
		.map( ( violation ) => {
			const nodes = violation.nodes
				.map( ( node ) => `      ${ node.target.join( ' ' ) }` )
				.join( '\n' );

			return `  [${ violation.impact }] ${ violation.id }: ${ violation.help }\n${ nodes }`;
		} )
		.join( '\n' );

	expect(
		results.violations,
		`${ where } has accessibility violations:\n${ summary }`
	).toEqual( [] );
}

/**
 * Sign in, the way a person does.
 *
 * Through the login form rather than by forging a cookie: the form is what
 * every real session goes through, and a harness that skips it can pass while
 * the thing it is testing is unreachable.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 */
async function signIn( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASSWORD );
	await page.click( '#wp-submit' );

	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

test.describe( 'admin screens', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'the check-in door, before an event is chosen', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-checkin' );

		await expect( page.locator( '.qevm-door' ) ).toBeVisible();

		await expectNoViolations( page, 'the check-in door (choosing an event)' );
	} );

	test( 'the check-in door, running a door', async ( { page } ) => {
		/*
		 * The list is the point of the screen. Opening a door with nobody on it
		 * still scans, and proves nothing — the same mistake the calendar scan
		 * made in stage 4, which is why openTheDoor() asserts the list first.
		 */
		await openTheDoor( page );

		await expectNoViolations( page, 'the check-in door (running a door)' );
	} );

	test( 'the door after somebody has been checked in', async ( { page } ) => {
		await openTheDoor( page );

		await page.locator( '.qevm-door__person button' ).first().click();

		// The result banner is a live region, and it is what gets announced.
		await expect( page.locator( '.qevm-door__result' ) ).toBeVisible();

		await expectNoViolations( page, 'the check-in door (after an arrival)' );
	} );

	test( 'the door is workable with the keyboard alone', async ( { page } ) => {
		await openTheDoor( page );

		/*
		 * Tab until the first person's button has focus, then press it. A
		 * screen that can only be operated by tapping is a screen somebody
		 * using a switch or a keyboard cannot work a door with.
		 */
		const button = page.locator( '.qevm-door__person button' ).first();

		await expect( button ).toBeVisible();

		let reached = false;

		for ( let press = 0; press < 40 && ! reached; press++ ) {
			await page.keyboard.press( 'Tab' );

			reached = await button.evaluate( ( node ) => node === document.activeElement );
		}

		expect( reached, 'the check-in button cannot be reached by tabbing' ).toBe( true );

		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '.qevm-door__result' ) ).toBeVisible();
	} );
} );

test.describe( 'every other admin screen', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	/*
	 * One test per screen rather than a loop over URLs. A loop reports "the
	 * admin screens failed" and a name reports which one, and the name is what
	 * somebody reads at eight in the morning when the build is red.
	 */

	test( 'the events list', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event' );

		/*
		 * A longer wait than the default, and only here. This is the first
		 * admin list a freshly installed site renders, and WordPress does its
		 * update checks on that request: six seconds against a five-second
		 * default is a red build that says nothing about accessibility.
		 */
		await expect( page.locator( '#the-list tr' ).first() ).toBeVisible( { timeout: 30_000 } );

		await expectNoViolations( page, 'the events list' );
	} );

	test( 'the event editor, with every box this plugin adds', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event' );

		await page.getByRole( 'link', { name: EVENT, exact: true } ).first().click();

		/*
		 * The editor itself first, and it matters. Meta boxes are printed
		 * server-side, so they are in the document even when the editor never
		 * boots — which is exactly what happened: a fatal error in one of this
		 * plugin's own meta boxes killed every event editor screen, the page
		 * still returned 200, and this test passed against a blank editor for
		 * as long as it only looked for a meta box.
		 */
		await expect(
			page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.editor-styles-wrapper' ),
			'the block editor did not start — check the PHP error log'
		).toBeVisible( { timeout: 60_000 } );

		/*
		 * Attached rather than visible, for the meta box: the block editor
		 * renders it into a panel that starts collapsed, so the markup is in
		 * the document and the element is not on screen — and it is the markup
		 * axe reads.
		 */
		await expect( page.locator( '#qevm-event-details' ) ).toBeAttached( { timeout: 60_000 } );

		await expectNoViolations( page, 'the event editor' );
	} );

	test( 'the attendee screen, choosing an event', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-attendees' );

		await expect( page.locator( '#qevm-event-picker' ) ).toBeVisible();

		await expectNoViolations( page, 'the attendee screen (choosing an event)' );
	} );

	test( 'the attendee screen, with attendees on it', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-attendees' );

		/*
		 * By the option's own text. The picker appends the date to each title,
		 * so an exact label match finds nothing and a regular expression is not
		 * something selectOption takes.
		 */
		const value = await page
			.locator( `#qevm-event-picker option:has-text("${ EVENT }")` )
			.first()
			.getAttribute( 'value' );

		await page.locator( '#qevm-event-picker' ).selectOption( value );
		await page.locator( '#qevm-event-picker' ).press( 'Enter' );

		/*
		 * The list is the point of the screen. A scan of the empty state proves
		 * only that the empty state is fine — the same mistake the calendar
		 * scan made in stage 4.
		 */
		await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toBeVisible();

		await expectNoViolations( page, 'the attendee screen (with attendees)' );
	} );

	test( 'the features screen', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-features' );

		await expect( page.locator( '.qevm-module' ).first() ).toBeVisible();

		await expectNoViolations( page, 'the features screen' );
	} );

	test( 'the settings screen', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-settings' );

		await expect( page.locator( 'form' ).first() ).toBeVisible();

		await expectNoViolations( page, 'the settings screen' );
	} );

	test( 'the email templates screen', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-email-templates' );

		await expect( page.locator( 'form' ).first() ).toBeVisible();

		await expectNoViolations( page, 'the email templates screen' );
	} );

	test( 'the dates screen of a repeating event', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=qevm_event&page=qevm-dates' );

		await expect( page.locator( 'body' ) ).toBeVisible();

		await expectNoViolations( page, 'the dates screen' );
	} );
} );
