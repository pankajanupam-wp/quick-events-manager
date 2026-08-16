/**
 * axe-core over every public screen. A violation fails the build.
 *
 * docs/engineering-standards.md §15 makes accessibility a build standard rather
 * than an audit at the end, and this file is what turns that from an intention
 * into something that can fail.
 *
 * **What axe can and cannot do.** It catches contrast, missing labels, broken
 * ARIA references, heading order and landmark problems — roughly a third of
 * WCAG, and the third that is mechanical. It cannot tell whether a label makes
 * sense, whether focus order is logical, or whether an error message helps.
 * A clean run here is a floor, not a certificate; docs/accessibility.md
 * (stage 10) records what was checked by hand.
 *
 * @package QuickEventsManager
 */

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const SLUG = process.env.QEVM_EVENT_SLUG || 'accessibility-fixture';

/**
 * Run axe against the current page and fail with something readable.
 *
 * The default failure is a wall of JSON. What a person needs is which rule,
 * how badly, and which element — so the assertion message is built by hand.
 *
 * @param {import('@playwright/test').Page} page  Page under test.
 * @param {string}                          where Human name for the screen.
 */
async function expectNoViolations( page, where ) {
	const results = await new AxeBuilder( { page } )
		/*
		 * WCAG 2.2 AA, which is what the standard commits to. `best-practice`
		 * is deliberately not included: it flags things that are advice rather
		 * than conformance, and a build that fails on advice gets ignored.
		 */
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )

		/*
		 * The theme is excluded, and this was not the original intention.
		 *
		 * The first run of this suite failed every page on one violation:
		 * Twenty Twenty-Five's navigation block puts a <div> directly inside a
		 * <ul>, which axe correctly reports as `list`. It is a real defect and
		 * it is in WordPress, not here. Left in, this suite would fail on every
		 * commit for a reason nobody working on the plugin can fix — and a
		 * build that always fails is a build everybody learns to ignore, which
		 * costs more than the rule was worth.
		 *
		 * So the scan covers what the plugin renders. The cost is honest and
		 * worth writing down: page-level rules that need the whole document —
		 * landmark uniqueness, and heading order relative to the theme's own
		 * headings — are no longer checked here, and belong to the manual pass
		 * in docs/accessibility.md.
		 */
		.exclude( 'header' )
		.exclude( 'footer' )
		.exclude( '.wp-block-navigation' )
		.exclude( '.wp-site-blocks > header' )
		.analyze();

	const summary = results.violations
		.map( ( v ) => {
			const nodes = v.nodes
				.map( ( n ) => `      ${ n.target.join( ' ' ) }` )
				.join( '\n' );

			return `  [${ v.impact }] ${ v.id }: ${ v.help }\n${ nodes }`;
		} )
		.join( '\n' );

	expect(
		results.violations,
		`axe found ${ results.violations.length } violation(s) on ${ where }:\n${ summary }`
	).toEqual( [] );
}

/**
 * Answer the fixture's required custom question.
 *
 * The fixture asks a required "Which session?" so the axe scans see a real
 * select, a radio group and a checkbox group rather than only text inputs. Any
 * test that expects a submission to succeed has to answer it — which is itself
 * the proof that a required custom question is enforced end to end, since
 * leaving this out is exactly what made four tests fail when it was added.
 */
async function answerRequiredQuestions( page ) {
	await page.selectOption( '#qevm_field-1-fsession0001', 'Morning' );
}

test.describe( 'Public screens', () => {
	test( 'event archive', async ( { page } ) => {
		await page.goto( '/?post_type=qevm_event' );
		await expectNoViolations( page, 'the event archive' );
	} );

	test( 'single event', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );
		await expect( page.locator( '.qevm-event-details' ) ).toBeVisible();
		await expectNoViolations( page, 'a single event' );
	} );

	test( 'registration form', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );
		await expect( page.locator( '[data-qevm-registration-form]' ) ).toBeVisible();
		await expectNoViolations( page, 'the registration form' );
	} );

	test( 'registration form with guest rows revealed', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		// Three places reveals two guest rows, which are hidden until asked for.
		await page.fill( '[data-qevm-quantity]', '3' );
		await expect( page.locator( '[data-qevm-guest]:not([hidden])' ) ).toHaveCount( 2 );

		await expectNoViolations( page, 'the registration form with guest rows shown' );
	} );

	test( 'registration form in its error state', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		/*
		 * A real failed submission, not a hand-built URL. The error state is
		 * assembled from a transient the handler wrote, so faking the query
		 * string would test markup that never renders in production.
		 *
		 * The name field is emptied and an invalid address typed in, so the
		 * server returns two field errors rather than one — the case where the
		 * summary list and the per-field messages both have to be right.
		 */
		await page.fill( '#qevm-email', 'not-an-address' );
		await page.evaluate( () => {
			// Defeat the browser's own validation so the request reaches PHP.
			document
				.querySelector( '[data-qevm-registration-form]' )
				.setAttribute( 'novalidate', 'novalidate' );
		} );

		await page.click( '[data-qevm-registration-form] button[type="submit"]' );

		const summary = page.locator( '[data-qevm-error-summary]' );

		await expect( summary ).toBeVisible();
		await expect( page.locator( '#qevm-email[aria-invalid="true"]' ) ).toBeVisible();

		await expectNoViolations( page, 'the registration form in its error state' );
	} );

	test( 'confirmation', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		const unique = `axe-${ Date.now() }@example.com`;

		await page.fill( '#qevm-name', 'Axe Tester' );
		await page.fill( '#qevm-email', unique );

		/*
		 * The consent box is required out of the box, so a submission that
		 * skips it never leaves the browser — which is correct behaviour and
		 * looked like a missing confirmation the first time this ran.
		 */
		await page.check( '#qevm-consent' );
		await answerRequiredQuestions( page );

		await page.click( '[data-qevm-registration-form] button[type="submit"]' );

		await expect( page.locator( '.qevm-notice--success, .qevm-notice--info' ) ).toBeVisible();

		await expectNoViolations( page, 'the confirmation' );
	} );
} );

test.describe( 'Keyboard and focus', () => {
	test( 'the form is completable with the keyboard alone', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		await page.locator( '#qevm-name' ).focus();
		await page.keyboard.type( 'Keyboard Only' );
		await page.keyboard.press( 'Tab' );

		await expect( page.locator( '#qevm-email' ) ).toBeFocused();

		await page.keyboard.type( `kbd-${ Date.now() }@example.com` );

		await page.check( '#qevm-consent' );
		await answerRequiredQuestions( page );

		/*
		 * Tab through to the submit button rather than clicking it. If anything
		 * in between is unreachable — a control with a negative tabindex, or a
		 * hidden guest row that is focusable when it should not be — this never
		 * arrives and the test says so.
		 */
		const submit = page.locator( '[data-qevm-registration-form] button[type="submit"]' );

		for ( let i = 0; i < 30 && ! ( await submit.evaluate( ( el ) => el === document.activeElement ) ); i++ ) {
			await page.keyboard.press( 'Tab' );
		}

		await expect( submit ).toBeFocused();

		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '.qevm-notice--success, .qevm-notice--info' ) ).toBeVisible();
	} );

	test( 'a failed submission moves focus to the field that failed', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		await page.fill( '#qevm-email', 'not-an-address' );
		await page.evaluate( () => {
			document
				.querySelector( '[data-qevm-registration-form]' )
				.setAttribute( 'novalidate', 'novalidate' );
		} );
		await page.click( '[data-qevm-registration-form] button[type="submit"]' );

		/*
		 * Not just "the error is on the page". Post/redirect/get means the
		 * error arrives in the initial HTML, and a live region that is already
		 * present when the document parses announces nothing at all — so
		 * without focus moving here, a screen reader user is told nothing and
		 * lands at the top of a page that looks unchanged.
		 */
		await expect( page.locator( '#qevm-name' ) ).toBeFocused();
	} );

	test( 'hidden guest rows are not reachable by keyboard', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		const hidden = page.locator( '[data-qevm-guest][hidden] input' );

		await expect( hidden.first() ).toBeAttached();

		for ( const input of await hidden.all() ) {
			await expect( input ).toBeDisabled();
		}
	} );
} );

test.describe( 'Reflow', () => {
	test( 'the form is usable at 320px without sideways scrolling', async ( { page } ) => {
		await page.setViewportSize( { width: 320, height: 800 } );
		await page.goto( `/?qevm_event=${ SLUG }` );

		const overflows = await page.evaluate(
			() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
		);

		expect( overflows, 'the page scrolls sideways at 320px' ).toBe( false );

		await expectNoViolations( page, 'the registration form at 320px' );
	} );
} );

/*
 * AC-3.1: the form works with JavaScript switched off.
 *
 * This is the criterion the whole post/redirect/get design exists for, and
 * nothing tested it. It is checked in a browser rather than in PHP because the
 * claim is about what a *browser without script* does with the markup — PHP
 * cannot answer that, and asserting it against the handler would prove only
 * that the handler works, which was never in doubt.
 *
 * With script off the guest rows stay hidden and disabled, which is why the
 * template ships them that way rather than building them in JavaScript: the
 * degraded form is a working form for one person, not a broken one.
 */
test.describe( 'Without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'a logged-out visitor can still register', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		await expect( page.locator( '[data-qevm-registration-form]' ) ).toBeVisible();

		await page.fill( '#qevm-name', 'No Script' );
		await page.fill( '#qevm-email', `nojs-${ Date.now() }@example.com` );
		await page.check( '#qevm-consent' );
		await answerRequiredQuestions( page );

		await page.click( '[data-qevm-registration-form] button[type="submit"]' );

		await expect( page.locator( '.qevm-notice--success, .qevm-notice--info' ) ).toBeVisible();
	} );

	test( 'the noscript hint explains the guest fields', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		/*
		 * The hint lives outside the hidden fieldset on purpose — inside it, the
		 * one message explaining why the guest fields are missing would itself
		 * be hidden.
		 */
		await expect( page.locator( '.qevm-registration noscript' ) ).toHaveCount( 1 );
	} );

	test( 'an error is still explained without script', async ( { page } ) => {
		await page.goto( `/?qevm_event=${ SLUG }` );

		await page.fill( '#qevm-name', 'No Script' );
		await page.fill( '#qevm-email', 'not-an-address' );
		await page.check( '#qevm-consent' );

		/*
		 * No novalidate here: with scripting off the browser still runs its own
		 * constraint validation, so an invalid address is caught client-side and
		 * never reaches PHP. Submitting a *valid* address that PHP rejects is
		 * the honest way to reach the server-side error path — a duplicate is
		 * the one refusal a visitor can trigger on demand.
		 *
		 * The address is unique per run. A fixed one worked the first time and
		 * failed every time after, because the booking it made survived into
		 * the next run and turned the *first* submission into the duplicate.
		 * The fixture is only reseeded by hand, so a test that leaves state
		 * behind is a test that passes once.
		 */
		const address = `nojs-dup-${ Date.now() }@example.com`;

		await page.fill( '#qevm-email', address );
		await answerRequiredQuestions( page );
		await page.click( '[data-qevm-registration-form] button[type="submit"]' );
		await expect( page.locator( '.qevm-notice--success, .qevm-notice--info' ) ).toBeVisible();

		// The same address again is refused, and must say why.
		await page.goto( `/?qevm_event=${ SLUG }` );
		await page.fill( '#qevm-name', 'No Script' );
		await page.fill( '#qevm-email', address );
		await page.check( '#qevm-consent' );
		await answerRequiredQuestions( page );
		await page.click( '[data-qevm-registration-form] button[type="submit"]' );

		const summary = page.locator( '[data-qevm-error-summary]' );

		await expect( summary ).toBeVisible();
		await expect( summary ).toContainText( /already registered/i );

		// And the field it belongs to is marked, without any script running.
		await expect( page.locator( '#qevm-email[aria-invalid="true"]' ) ).toBeVisible();
	} );
} );

test.describe( 'Closed registration', () => {
	test( 'a finished event explains itself instead of showing nothing', async ( { page } ) => {
		await page.goto( '/?qevm_event=accessibility-fixture-past' );

		const closed = page.locator( '.qevm-registration--closed' );

		await expect( closed ).toBeVisible();
		await expect( closed ).toContainText( /already taken place/i );
		await expect( page.locator( '[data-qevm-registration-form]' ) ).toHaveCount( 0 );

		await expectNoViolations( page, 'a closed registration notice' );
	} );
} );
