/**
 * End-to-end check of the front-end tools against the local Docker site.
 *   node tests/e2e.mjs [baseUrl] [screenshotDir]
 * Needs: docker compose stack running, tests/seed.php applied (meeting with people, pages, tools password club2026).
 * PLAYWRIGHT env var can point at a playwright index.mjs if it is not resolvable from here.
 */
const pwPath = process.env.PLAYWRIGHT || '/home/mmoobil2/code/toastmaster-club-api/node_modules/playwright/index.mjs';
const { chromium, devices } = await import( pwPath );

const BASE = process.argv[ 2 ] || 'http://localhost:8087';
const SHOTS = process.argv[ 3 ] || './screenshots';
const fs = await import( 'node:fs' );
fs.mkdirSync( SHOTS, { recursive: true } );

let fails = 0;
const ok = ( cond, msg ) => {
	console.log( ( cond ? 'PASS ' : 'FAIL ' ) + msg );
	if ( ! cond ) {
		fails++;
	}
};

const browser = await chromium.launch();
const phone = { ...devices[ 'iPhone 13' ], viewport: { width: 390, height: 844 } };
delete phone.defaultBrowserType;

function watch( page, label ) {
	const errors = [];
	// WordPress' interactivity script reads performance.timing, which Playwright's fake clock removes.
	page.on( 'pageerror', ( e ) => ! e.message.includes( 'domContentLoadedEventStart' ) && errors.push( label + ' pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => m.type() === 'error' && ! m.text().startsWith( 'Failed to load resource' ) && errors.push( label + ' console: ' + m.text() ) );
	// HTTP errors are expected only for the deliberate wrong-password attempt and visitor permission check.
	page.on( 'response', ( r ) => r.status() >= 400 && ! r.url().includes( 'unlock-tools' ) && ! r.url().includes( 'list-feedback' ) && errors.push( label + ' HTTP ' + r.status() + ' ' + r.url() ) );
	page.on( 'dialog', ( d ) => d.accept() );
	return errors;
}

async function noOverflow( page, label ) {
	const w = await page.evaluate( () => document.documentElement.scrollWidth );
	ok( w <= 390, `${ label }: no horizontal scroll at 390px (scrollWidth ${ w })` );
}

async function noEmDash( page, label ) {
	const text = await page.evaluate( () => Array.from( document.querySelectorAll( '.tmmt' ) ).map( ( e ) => e.innerText ).join( '\n' ) );
	ok( ! text.includes( '—' ), `${ label }: no em dashes in tool text` );
}

const phase = ( page ) => page.locator( '.tmmt-stage' ).getAttribute( 'data-phase' );
// No "Saved" label: a saved time shows straight away in the live timing report under the timer.
const savedAs = async ( page, name, time, dot ) => {
	const row = page.locator( 'li.tmmt-report-row', { hasText: name } ).filter( { hasText: time } );
	await row.waitFor();
	const verdict = { green: 'within', yellow: 'under', red: 'over' }[ dot ];
	return dot ? ( await row.locator( '.tmmt-verdict--' + verdict ).count() ) === 1 : true;
};
// The report re-renders after each save and agenda reload; retry a check until it settles.
const until = async ( fn, ms = 5000 ) => {
	const end = Date.now() + ms;
	let v = await fn();
	while ( ! v && Date.now() < end ) {
		await new Promise( ( r ) => setTimeout( r, 200 ) );
		v = await fn();
	}
	return v;
};
const forward = async ( page, sec ) => {
	await page.clock.runFor( sec * 1000 );
};
const plus = async ( page, s, times = 1 ) => {
	for ( let i = 0; i < times; i++ ) {
		await page.locator( '.tmmt-plus-row button', { hasText: ( s > 0 ? '+' : '' ) + s + 's' } ).click();
	}
};

// ---------- Role holder phone ----------
const ctx = await browser.newContext( phone );
const page = await ctx.newPage();
// Fake clock: the running timer has no +time buttons any more, so tests move time forward instead.
await page.clock.install();
const errors = watch( page, 'phone' );
await page.goto( BASE + '/meeting/' );
ok( ( await page.locator( '.tmmt-agenda-item' ).count() ) === 19, 'agenda shows 19 items (break is a section heading)' );
ok( ( await page.locator( '.tmmt-section-head' ).allInnerTexts() ).map( ( t ) => t.replace( /^\S+\s+/, '' ) ).join( ',' ) === 'Intro,Speeches,Break,Table Topics,Feedback,Meeting ending', 'agenda sections' );
await page.screenshot( { path: SHOTS + '/01-agenda-light.png', fullPage: true } );
await noOverflow( page, 'agenda' );
await noEmDash( page, 'agenda' );

// Visitors see the agenda and a feedback button, not the tool tabs.
ok( await page.locator( '.tmmt-tabs' ).isHidden(), 'tool tabs hidden before unlocking' );
ok( ( await page.locator( '[data-feedback-for]' ).count() ) === 3, 'feedback button on each prepared speech with a speaker' );
await page.getByRole( 'link', { name: 'Give feedback to Erik' } ).click();
const picked = page.locator( '.tmmt-panel[data-panel=audience] select[name=agenda_item_id]' );
await picked.waitFor();
ok( ( await picked.locator( 'option:checked' ).innerText() ).includes( 'Erik' ), 'feedback form opens with the speaker selected' );
await page.getByRole( 'link', { name: 'Back', exact: true } ).click();

// Role tools link, then the timer behind the password.
await page.getByRole( 'link', { name: 'Role tools' } ).click();
await page.locator( 'input[type=password]' ).fill( 'wrong' );
await page.getByRole( 'button', { name: 'Unlock' } ).click();
await page.getByText( 'Wrong password.' ).waitFor();
ok( true, 'wrong tools password rejected' );
await page.locator( 'input[type=password]' ).fill( 'club2026' );
await page.getByRole( 'button', { name: 'Unlock' } ).click();
await page.locator( '.tmmt-tabs:not([hidden])' ).waitFor();
// An unlocked phone keeps the tool tabs after a reload.
await page.reload();
await page.locator( '.tmmt-tabs:not([hidden])' ).waitFor();
ok( true, 'tool tabs stay visible after reload when unlocked' );
ok( await page.getByRole( 'link', { name: 'Role tools' } ).isHidden(), 'role tools link hidden once unlocked' );
await page.locator( 'a[data-tab=timer]' ).click();
await page.locator( '.tmmt-stage' ).waitFor();
ok( ( await page.locator( '.tmmt-card-person' ).innerText() ) === 'Lena', 'timer starts at the first timed item (Lena)' );
ok( ( await page.locator( '.tmmt-nav-row button' ).last().innerText() ) === 'Next (Toastmaste.)', 'next button names the next role, cut to 10 characters' );
ok( ( await page.locator( '.tmmt-tt-name' ).count() ) === 0, 'no table topic name field outside table topics' );
await page.screenshot( { path: SHOTS + '/02-timer-ready.png', fullPage: true } );

// President's Introduction 6:00 / 7:00 / 8:00.
ok( await page.getByText( 'Click to start timer' ).isVisible(), 'idle hint says click to start' );
await page.locator( '.tmmt-stage-tap' ).click();
await page.getByText( 'Click to stop' ).waitFor();
ok( ( await page.locator( '.tmmt-plus-row' ).count() ) === 0, 'no +time buttons while running' );
ok( ( await page.locator( '.tmmt-overlay' ).count() ) === 0, 'no full-screen overlay while timing' );
ok( ( await phase( page ) ) === 'idle', 'timer neutral before green' );
await forward( page, 360 );
ok( ( await phase( page ) ) === 'green', 'green after 6:00' );
await page.screenshot( { path: SHOTS + '/03-timer-green.png' } );
await forward( page, 60 );
ok( ( await phase( page ) ) === 'yellow', 'yellow after 7:00' );
await page.screenshot( { path: SHOTS + '/04-timer-yellow.png' } );
await page.locator( '.tmmt-stage-tap' ).click();
ok( await savedAs( page, 'Lena', '7:00', 'green' ), 'President 7:00 within, saved' );
ok( await page.locator( '.tmmt-stage-tap' ).isDisabled(), 'stopped clock cannot be restarted by tapping' );
ok( ( await page.locator( '.tmmt-plus-row button' ).allInnerTexts() ).join( ',' ) === '-10s,+10s,Reset', 'after stopping: -10s, +10s, Reset' );
ok( ( await page.locator( '.tmmt-timer-box' ).innerText() ).match( /Saved|Within time|Under time|Over time/ ) === null, 'no saved/verdict text in the timer box' );

// Toastmaster 3:00 / 3:30 / 4:00, overrun.
await page.getByRole( 'button', { name: 'Next' } ).click();
ok( ( await page.locator( '.tmmt-card-person' ).innerText() ) === 'Omar', 'next item is Omar' );
// Full screen: only the clock; stopping leaves full screen again.
await page.getByRole( 'button', { name: 'Full screen' } ).click();
ok( await page.locator( '.tmmt-stage--full' ).isVisible(), 'full screen shows the clock' );
await page.locator( '.tmmt-stage-tap' ).click();
ok( await page.getByText( 'Click to stop' ).isVisible(), 'full screen: click to stop shown while running' );
await forward( page, 300 );
ok( ( await phase( page ) ) === 'red', 'red after 4:00' );
ok( await page.getByText( 'Finish speech!' ).isVisible(), 'full screen: "Finish speech!" 30 s past red' );
await page.screenshot( { path: SHOTS + '/05-timer-red.png' } );
await page.locator( '.tmmt-stage-tap' ).click();
ok( ( await page.locator( '.tmmt-stage--full' ).count() ) === 0, 'stopping leaves full screen' );
ok( await savedAs( page, 'Omar', '5:00', 'red' ), 'Toastmaster 5:00 over' );

// Correction after stopping is saved again (5:00 -> 5:10).
await plus( page, 10 );
ok( await savedAs( page, 'Omar', '5:10', 'red' ), 'post-stop correction saved as 5:10' );
await plus( page, -10 );
ok( await savedAs( page, 'Omar', '5:00', 'red' ), '-10s correction saved as 5:00' );
// Reset clears the time so the item can be timed again.
await page.getByRole( 'button', { name: 'Reset' } ).click();
await page.getByText( 'Click to start timer' ).waitFor();
await page.locator( '.tmmt-stage-tap' ).click();
await forward( page, 310 );
await page.locator( '.tmmt-stage-tap' ).click();
ok( await savedAs( page, 'Omar', '5:10', 'red' ), 'discard then re-time saves the new time' );

// Survives a reload while running (timestamps, not ticks).
await page.getByRole( 'button', { name: 'Next' } ).click();
await page.locator( '.tmmt-stage-tap' ).click();
await forward( page, 31 );
await page.reload();
// The URL keeps #timer, so the reloaded page opens the running timer directly.
await page.getByText( 'Click to stop' ).waitFor();
const t = await page.locator( '.tmmt-stage .tmmt-digits' ).innerText();
const sec = Number( t.split( ':' )[ 0 ] ) * 60 + Number( t.split( ':' )[ 1 ] );
// The fake clock carries over the reload with some drift, so only check it kept counting.
ok( sec >= 31 && sec < 120, `still running after reload (${ t })` );
await page.locator( '.tmmt-stage-tap' ).click();
await savedAs( page, 'General Evaluator Introduction', ':' );

// Table topics: on the master, type a name and start the clock; the speaker is added and timed.
// Tapping the role card opens a picker with every timed item.
const pick = page.locator( '.tmmt-card-select' );
await pick.selectOption( { label: 'Table Topics Master: Karl' } );
ok( ( await page.locator( '.tmmt-card-person' ).innerText() ) === 'Karl', 'role card picker jumps to Table Topics Master (Karl)' );
ok( ( await page.locator( '.tmmt-card .tmmt-window' ).innerText() ).includes( '2:00' ), 'master clock uses the table topic speaker times' );
ok( ( await page.locator( '.tmmt-nav-row button' ).allInnerTexts() ).join( '|' ) === 'Previous|Next Table Topic Speaker|Next (Evaluator.)', 'nav row: Previous, Next Table Topic Speaker, Next (skips table topics)' );
ok( ( await page.locator( 'li.tmmt-report-row', { hasText: 'Table Topics Master' } ).count() ) === 0, 'report does not list the Table Topics Master' );
await page.locator( '.tmmt-tt-name' ).fill( 'Guest Bo' );
await page.locator( '.tmmt-stage-tap' ).click();
await until( async () => ( await page.locator( '.tmmt-card-person' ).innerText() ) === 'Guest Bo' );
ok( ( await page.locator( '.tmmt-card-person' ).innerText() ) === 'Guest Bo', 'starting on the master adds and times Guest Bo' );
await forward( page, 60 );
ok( ( await phase( page ) ) === 'green', 'table topic green after 1:00' );
await page.locator( '.tmmt-stage-tap' ).click();
await savedAs( page, 'Guest Bo', '1:00', 'green' );
await page.getByRole( 'button', { name: 'Next Table Topic Speaker' } ).click();
ok( ( await page.locator( '.tmmt-tt-name' ).inputValue() ) === '', 'next table topic speaker starts with an empty name' );
await page.locator( '.tmmt-tt-name' ).fill( 'Guest C' );
await page.locator( '.tmmt-stage-tap' ).click();
await until( async () => ( await page.locator( '.tmmt-card-person' ).innerText() ) === 'Guest C' );
await forward( page, 180 );
await page.locator( '.tmmt-stage-tap' ).click();
ok( await savedAs( page, 'Guest C', '3:00', 'red' ), 'Guest C 3:00 over (2:00 + 30 s grace)' );
// The name can be corrected afterwards.
await page.locator( '.tmmt-tt-name' ).fill( 'Guest Cy' );
await page.locator( '.tmmt-tt-name' ).press( 'Tab' );
ok( await savedAs( page, 'Guest Cy', '3:00', 'red' ), 'table topic speaker renamed to Guest Cy' );
ok(
	await until( async () => {
		const labels = await page.locator( 'li.tmmt-report-row .tmmt-report-who' ).allInnerTexts();
		const iBo = labels.findIndex( ( l ) => l.includes( 'Guest Bo' ) );
		return iBo >= 0 && ( labels[ iBo + 1 ] || '' ).includes( 'Guest Cy' );
	} ),
	'table topic speakers listed in order'
);
await noOverflow( page, 'timer' );
await noEmDash( page, 'timer' );
await page.screenshot( { path: SHOTS + '/06-timer-after-tt.png', fullPage: true } );

// Live timing report under the timer (no separate "Complete meeting" step any more).
ok( ( await page.getByRole( 'button', { name: 'Complete meeting' } ).count() ) === 0, 'no complete meeting button' );
let rows = [];
const row = ( name ) => rows.find( ( r ) => r.includes( name ) ) || '';
await until( async () => {
	rows = await page.locator( 'li.tmmt-report-row' ).allInnerTexts();
	return row( 'Lena' ).includes( 'Within time' ) && row( 'Guest Bo' ).includes( '1:00' );
} );
ok( row( 'Lena' ).includes( '7:00' ) && row( 'Lena' ).includes( 'Within time' ), 'report: Lena 7:00 within' );
ok( row( 'Omar' ).includes( '5:10' ) && row( 'Omar' ).includes( 'Over time' ), 'report: Omar 5:10 over' );
ok( row( 'Guest Bo' ).includes( '1:00' ) && row( 'Guest Bo' ).includes( 'Within time' ), 'report: Guest Bo 1:00 within' );
ok( row( 'Anna' ).includes( '-' ) && ! row( 'Anna' ).includes( 'time' ), 'report: untimed speech has no time yet' );
ok( ( await page.locator( '.tmmt-live-report button' ).count() ) === 0, 'timing report is read-only' );
await page.screenshot( { path: SHOTS + '/07-report.png', fullPage: true } );
await noOverflow( page, 'report' );
await noEmDash( page, 'report' );

// Ah-Counter: first speaker preselected, every tap saves, report below, no save/undo/reset/delete.
await page.locator( 'a[data-tab="ah-counter"]' ).click();
await page.locator( '.tmmt-ah-btn' ).first().waitFor();
ok( ( await page.locator( '.tmmt-ah .tmmt-card-person' ).innerText() ) === 'Lena', 'ah-counter preselects the first speaker' );
ok( ! ( await page.locator( '.tmmt-ah select option' ).allTextContents() ).some( ( t ) => /Report|General Evaluator:/.test( t ) ), 'ah-counter skips its own report and the roles after' );
ok( ( await page.locator( '.tmmt-ah button', { hasText: /^(Undo|Reset|Delete|Save)/ } ).count() ) === 0, 'ah-counter has no save, undo, reset or delete' );
ok( ( await page.locator( '.tmmt-ah select option', { hasText: 'Add a name' } ).count() ) === 0, 'ah-counter has no add-a-name option' );
await page.getByRole( 'button', { name: 'Next (Toastmaste.)' } ).click();
ok( ( await page.locator( '.tmmt-ah .tmmt-card-person' ).innerText() ) === 'Omar', 'ah-counter Next moves to the next speaker' );
await page.locator( '.tmmt-ah select' ).selectOption( { label: 'Speaker 1: Anna' } );
const cell = ( w ) => page.locator( '.tmmt-ah-btn' ).filter( { has: page.locator( '.tmmt-ah-word', { hasText: new RegExp( '^' + w + '$' ) } ) } );
for ( let i = 0; i < 3; i++ ) {
	await cell( 'ah' ).click();
}
await cell( 'um' ).click();
await page.locator( '.tmmt-ah-cell', { has: cell( 'um' ) } ).locator( '.tmmt-ah-minus' ).click();
await cell( 'you know' ).click();
await cell( 'you know' ).click();
await page.locator( '.tmmt-ah-cell--wotd .tmmt-ah-btn' ).click();
await page.screenshot( { path: SHOTS + '/08-ah-counter.png', fullPage: true } );
await page.waitForTimeout( 1500 );
await page.reload();
const anna = page.locator( '.tmmt-ah .tmmt-ah-single', { hasText: 'Anna' } );
await anna.locator( 'li.tmmt-report-row', { hasText: '3x ah' } ).waitFor();
const annaAh = await anna.innerText();
ok( annaAh.includes( '3x ah, 2x you know' ) && /Momentum/.test( await page.locator( '.tmmt-ah-head' ).innerText() ), 'ah-counter report: top two filler words, word of the day in its own column' );
await noOverflow( page, 'ah-counter' );

// Evaluator feedback.
await page.locator( 'a[data-tab=feedback]' ).click();
await page.getByLabel( 'Your name' ).fill( 'Eva' );
await page.getByLabel( 'Feedback for' ).selectOption( { value: 'Anna' } );
ok( ( await page.getByLabel( 'About' ).inputValue() ) === 'Speaker 1: My first job', 'about prefilled from the agenda' );
await page.getByLabel( 'Your feedback' ).fill( 'Strong opening story. Try a longer pause before the conclusion.' );
await page.getByRole( 'button', { name: 'Save feedback' } ).click();
await page.getByText( 'Saved your feedback for Anna.' ).waitFor();
ok( true, 'evaluation saved' );
await page.screenshot( { path: SHOTS + '/09-evaluation.png', fullPage: true } );
ok( errors.length === 0, 'no JS errors on the phone: ' + errors.join( ' | ' ) );
await ctx.close();

// ---------- Audience member: no password, standalone form ----------
const aud = await browser.newContext( phone );
const ap = await aud.newPage();
const audErrors = watch( ap, 'audience' );
await ap.goto( BASE + '/feedback/' );
const options = await ap.locator( 'select[name=agenda_item_id] option' ).allInnerTexts();
ok( options.length === 4 && ! options.some( ( o ) => o.includes( 'Guest Bo' ) || o.includes( 'Karl' ) ), 'audience list has prepared speakers only' );
await ap.locator( 'select[name=agenda_item_id]' ).selectOption( { label: 'Speaker 1 - Anna (My first job)' } );
await ap.getByLabel( 'What did they do great?' ).fill( 'Lovely energy and eye contact.' );
await ap.getByLabel( 'Where do they have potential?' ).fill( 'Use the whole stage.' );
await ap.getByRole( 'button', { name: 'Send feedback' } ).click();
await ap.getByText( 'Thank you for your feedback!' ).waitFor();
ok( true, 'audience feedback sent without login' );
await ap.screenshot( { path: SHOTS + '/10-audience.png', fullPage: true } );
await noOverflow( ap, 'audience' );
await noEmDash( ap, 'audience' );
// Tools stay locked for the audience.
await ap.goto( BASE + '/meeting/#timer' );
await ap.locator( 'input[type=password]' ).waitFor();
ok( true, 'timer locked for a visitor without the password' );
const direct = await ap.evaluate( async ( base ) => ( await fetch( base + '/wp-json/wp-abilities/v1/abilities/tm-meeting-tools/list-feedback/run' ) ).status, BASE );
ok( direct === 401 || direct === 403, 'list-feedback ability refused for a visitor (HTTP ' + direct + ')' );
// Password protected results page.
await ap.goto( BASE + '/results/' );
ok( ( await ap.locator( '.tmmt-report' ).count() ) === 0, 'results hidden before the page password' );
await ap.locator( 'input[name=post_password]' ).fill( 'board2026' );
await ap.locator( 'input[name=post_password]' ).press( 'Enter' );
await ap.locator( '.tmmt-report' ).waitFor();
await ap.locator( 'select[name=tm_person]' ).selectOption( 'Anna' );
await ap.getByRole( 'button', { name: 'Show' } ).click();
await ap.locator( '.tmmt-fb' ).first().waitFor();
ok( ( await ap.locator( '.tmmt-fb' ).count() ) === 2, 'front-end results show both feedback entries for Anna' );
await ap.screenshot( { path: SHOTS + '/11-results-front.png', fullPage: true } );
await noOverflow( ap, 'results' );
ok( audErrors.length === 0, 'no JS errors for the audience: ' + audErrors.join( ' | ' ) );
await aud.close();

// ---------- Admin ----------
const adm = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
const ad = await adm.newPage();
const admErrors = watch( ad, 'admin' );
await ad.goto( BASE + '/wp-login.php' );
await ad.locator( '#user_login' ).fill( 'admin' );
await ad.locator( '#user_pass' ).fill( 'admin' );
await ad.locator( '#wp-submit' ).click();
await ad.waitForURL( /wp-admin/ );
await ad.goto( BASE + '/wp-admin/admin.php?page=tmmt-feedback' );
await ad.locator( 'select[name=tm_meeting]' ).selectOption( { index: 1 } );
await ad.getByRole( 'button', { name: 'Show' } ).click();
const body = await ad.locator( '#wpbody-content' ).innerText();
ok( body.includes( 'Guest Bo' ) && body.includes( 'Over time' ), 'admin: timings listed' );
ok( body.includes( 'ah x3, you know x2' ), 'admin: ah-counts listed' );
ok( body.includes( 'Lovely energy' ) && body.includes( 'Audience (anonymous)' ) && body.includes( 'From Eva' ), 'admin: audience and evaluation feedback listed' );
await ad.screenshot( { path: SHOTS + '/12-admin-results.png', fullPage: true } );
await ad.goto( BASE + '/wp-admin/admin.php?page=tmmt-settings' );
ok( ( await ad.locator( '.tmmt-roles tbody tr' ).count() ) >= 15, 'admin: role settings list the seeded roles' );
await ad.screenshot( { path: SHOTS + '/13-admin-settings.png', fullPage: true } );
await ad.goto( BASE + '/wp-admin/admin.php?page=tmmt-meetings' );
await ad.locator( 'table.widefat a' ).first().click();
await ad.locator( '.tmmt-agenda-admin' ).waitFor();
const before = await ad.locator( '.tmmt-agenda-admin tbody tr' ).nth( 1 ).locator( 'input[name$="[person_name]"]' ).inputValue();
await ad.locator( '.tmmt-agenda-admin tbody tr' ).nth( 1 ).locator( 'button[value$=":up"]' ).click();
await ad.locator( '.notice-success' ).waitFor();
const after = await ad.locator( '.tmmt-agenda-admin tbody tr' ).nth( 0 ).locator( 'input[name$="[person_name]"]' ).inputValue();
ok( before === after, 'admin: move up reorders the agenda' );
await ad.locator( '.tmmt-agenda-admin tbody tr' ).nth( 0 ).locator( 'button[value$=":down"]' ).click();
await ad.locator( '.notice-success' ).waitFor();
await ad.screenshot( { path: SHOTS + '/14-admin-agenda.png', fullPage: true } );
ok( admErrors.length === 0, 'no JS errors in admin: ' + admErrors.join( ' | ' ) );
await adm.close();

await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
