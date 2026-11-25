/**
 * Screenshots of the tools at phone width (390 px) with the phone in dark mode.
 * The tools always follow the site theme's colours.
 *   node tests/dark.mjs <baseUrl> <screenshotDir> <prefix>
 */
const pwPath = process.env.PLAYWRIGHT || '/home/mmoobil2/code/toastmaster-club-api/node_modules/playwright/index.mjs';
const { chromium } = await import( pwPath );
const [ BASE = 'http://localhost:8087', SHOTS = './screenshots', PREFIX = 'dark' ] = process.argv.slice( 2 );
const browser = await chromium.launch();
const ctx = await browser.newContext( { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, colorScheme: 'dark' } );
const page = await ctx.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
await page.goto( BASE + '/meeting/' );
await page.screenshot( { path: `${ SHOTS }/${ PREFIX }-agenda.png`, fullPage: true } );
await page.getByRole( 'link', { name: 'Role tools' } ).click();
await page.locator( 'input[type=password]' ).fill( 'club2026' );
await page.getByRole( 'button', { name: 'Unlock' } ).click();
await page.locator( 'a[data-tab=timer]' ).click();
await page.locator( '.tmmt-stage' ).waitFor();
await page.screenshot( { path: `${ SHOTS }/${ PREFIX }-timer.png`, fullPage: true } );
await page.locator( 'a[data-tab="ah-counter"]' ).click();
await page.locator( '.tmmt-ah-btn' ).first().waitFor();
await page.screenshot( { path: `${ SHOTS }/${ PREFIX }-ah.png`, fullPage: true } );
await page.goto( BASE + '/feedback/' );
await page.screenshot( { path: `${ SHOTS }/${ PREFIX }-audience.png`, fullPage: true } );
const w = await page.evaluate( () => document.documentElement.scrollWidth );
console.log( errors.length ? 'FAIL JS errors: ' + errors.join( ' | ' ) : 'PASS no JS errors', '| scrollWidth', w );
await browser.close();
