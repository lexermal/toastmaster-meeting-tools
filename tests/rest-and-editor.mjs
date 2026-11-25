/**
 * REST checks for /wp-abilities/v1 (visitor and admin) and block editor registration.
 *   node tests/rest-and-editor.mjs [baseUrl]
 */
const pwPath = process.env.PLAYWRIGHT || '/home/mmoobil2/code/toastmaster-club-api/node_modules/playwright/index.mjs';
const { chromium } = await import( pwPath );
const BASE = process.argv[ 2 ] || 'http://localhost:8087';
let fails = 0;
const ok = ( c, m ) => ( console.log( ( c ? 'PASS ' : 'FAIL ' ) + m ), c || fails++ );
const A = BASE + '/wp-json/wp-abilities/v1';

// Visitor.
let r = await fetch( A + '/abilities/tm-meeting-tools/get-meeting/run' );
let j = await r.json();
ok( r.status === 200 && Array.isArray( j.agenda ), 'visitor GET get-meeting (current meeting) 200' );
const mid = j.id;
r = await fetch( A + '/abilities/tm-meeting-tools/get-meeting/run', { method: 'POST' } );
ok( r.status === 405, 'read-only ability refuses POST (405)' );
r = await fetch( A + '/abilities/tm-meeting-tools/create-meeting/run', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { input: { date: '2030-01-01' } } ) } );
ok( r.status === 401, 'visitor create-meeting refused (401)' );
r = await fetch( A + '/abilities/tm-meeting-tools/record-timing/run', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { input: { agenda_item_id: 1, duration_sec: 5 } } ) } );
ok( r.status === 401, 'visitor record-timing refused (401)' );
r = await fetch( A + '/abilities/tm-meeting-tools/get-meeting/run?input[meeting_id]=abc' );
ok( r.status === 400, 'invalid input rejected by schema (400)' );

// Admin via cookie + REST nonce.
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
await page.goto( BASE + '/wp-login.php' );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'admin' );
await page.click( '#wp-submit' );
await page.waitForURL( /wp-admin/ );
const out = await page.evaluate( async ( { A, mid } ) => {
	const nonce = window.wpApiSettings ? window.wpApiSettings.nonce : await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
	const call = async ( name, input, method = 'POST' ) => {
		let url = A + '/abilities/tm-meeting-tools/' + name + '/run';
		const opts = { method, headers: { 'X-WP-Nonce': nonce } };
		if ( method === 'POST' ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( { input } );
		} else if ( input ) {
			url += '?' + Object.entries( input ).map( ( [ k, v ] ) => `input[${ k }]=${ encodeURIComponent( v ) }` ).join( '&' );
		}
		const res = await fetch( url, opts );
		return { status: res.status, body: await res.json() };
	};
	const list = await ( await fetch( A + '/abilities?category=tm-meeting-tools&per_page=100', { headers: { 'X-WP-Nonce': nonce } } ) ).json();
	const created = await call( 'create-meeting', { date: '2031-03-03', theme: 'REST test', template: 'empty' } );
	const roles = await call( 'list-role-types', null, 'GET' );
	const speaker = roles.body.role_types.find( ( x ) => x.name === 'Speaker' );
	const item = await call( 'add-agenda-item', { meeting_id: created.body.id, role_type_id: speaker.id, person_name: 'Rest Person', speech_category: 'long' } );
	const removed = await call( 'remove-agenda-item', { item_id: item.body.id }, 'DELETE' );
	const deleted = await call( 'delete-meeting', { meeting_id: created.body.id }, 'DELETE' );
	const fb = await call( 'list-feedback', { meeting_id: mid }, 'GET' );
	return { count: list.length, names: list.map( ( a ) => a.name ), created, item, removed, deleted, fb };
}, { A, mid } );
ok( out.count === 27, `abilities listed under the category: ${ out.count }` );
ok( out.created.status === 200 && out.created.body.theme === 'REST test', 'admin create-meeting over REST' );
ok( out.item.status === 200 && out.item.body.red_sec === 600, 'admin add-agenda-item with Long speech category (10:00 red)' );
ok( out.removed.status === 200 && out.deleted.status === 200, 'admin DELETE remove-agenda-item and delete-meeting' );
ok( out.fb.status === 200 && Array.isArray( out.fb.body.feedback ), 'admin list-feedback over REST' );

// Block editor.
await page.goto( BASE + '/wp-admin/post-new.php?post_type=page' );
await page.waitForFunction( () => window.wp && window.wp.blocks && window.wp.blocks.getBlockType( 'tm-meeting-tools/meeting' ), null, { timeout: 30000 } );
const types = await page.evaluate( () => [ 'meeting', 'audience-feedback', 'report' ].map( ( n ) => !! window.wp.blocks.getBlockType( 'tm-meeting-tools/' + n ) ) );
ok( types.every( Boolean ), 'all three blocks registered in the editor' );
await page.evaluate( () => window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'tm-meeting-tools/meeting' ) ) );
const frame = page.frameLocator( 'iframe[name=editor-canvas]' );
await frame.locator( '.tmmt-agenda' ).first().waitFor( { timeout: 30000 } );
ok( true, 'meeting block previews the agenda in the editor' );
await page.screenshot( { path: ( process.argv[ 3 ] || '.' ) + '/15-editor.png' } );
ok( errors.length === 0, 'no JS errors in admin/editor: ' + errors.join( ' | ' ) );
await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
