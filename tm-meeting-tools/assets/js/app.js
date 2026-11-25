/**
 * Boots every meeting block on the page: tabs (synced with the URL hash), lazy tool mounting
 * behind the tools password, and the audience feedback forms.
 */
( function ( T ) {
	const { h, run, __ } = T;
	const GATED = [ 'timer', 'ah-counter', 'feedback' ];

	async function bootMeeting( root ) {
		const meetingId = Number( root.dataset.tmmtMeeting );
		const tabs = Array.from( root.querySelectorAll( '[data-tab]' ) );
		const panels = Object.fromEntries( Array.from( root.querySelectorAll( '[data-panel]' ) ).map( ( p ) => [ p.dataset.panel, p ] ) );
		const nav = root.querySelector( '.tmmt-tabs' );
		const foot = root.querySelector( '.tmmt-foot' );
		const toolsLink = root.querySelector( '.tmmt-tools-link' );
		const alertBox = h( 'p', { class: 'tmmt-msg tmmt-msg--error tmmt-alert', role: 'alert', hidden: true } );
		nav.after( alertBox );
		const mounted = {};
		let roles = [];

		const ctx = {
			meeting: null,
			error( message ) {
				alertBox.textContent = message;
				alertBox.hidden = ! message;
				clearTimeout( ctx.errorTimer );
				ctx.errorTimer = setTimeout( () => ( alertBox.hidden = true ), 8000 );
			},
			async reload() {
				const { data, error } = await run( 'get-meeting', { meeting_id: meetingId }, 'read' );
				if ( error ) {
					ctx.error( error.message );
					return;
				}
				ctx.meeting = data;
				Object.values( mounted ).forEach( ( m ) => m && m.refresh && m.refresh() );
				root.querySelectorAll( '.tmmt-audience-form' ).forEach( ( f ) => T.audience.refresh( f, data ) );
			},
			/** Quick-added table topic speakers get the table topic role with the shortest time (the speaker, not the master). */
			tableTopicRole() {
				const tt = roles.filter( ( r ) => r.category === 'table_topic' && r.red_sec > 0 );
				return tt.sort( ( a, b ) => a.red_sec - b.red_sec )[ 0 ] || null;
			},
		};

		// Lock control, shown once the tools are unlocked on this device.
		const lockBar = h(
			'p',
			{ class: 'tmmt-lockbar', hidden: true },
			h( 'span', { class: 'tmmt-muted' }, __( 'Role holder tools are unlocked on this device.', 'tm-meeting-tools' ) ),
			h(
				'button',
				{
					type: 'button',
					class: 'tmmt-link',
					onclick: async () => {
						await run( 'lock-tools', {} );
						T.setUnlocked( false );
					},
				},
				__( 'Lock', 'tm-meeting-tools' )
			)
		);
		foot.append( lockBar );

		// The audience only sees the agenda and the feedback buttons; the tool tabs show once
		// this phone is unlocked (or for a logged-in admin), and stay until locked.
		let current = 'agenda';
		function applyAccess( unlocked ) {
			nav.hidden = ! unlocked;
			// Only offered on the agenda itself; the password and feedback screens have their own back link.
			toolsLink.hidden = !! unlocked || current !== 'agenda';
			lockBar.hidden = ! unlocked || T.cfg.isAdmin;
		}
		T.access.listeners.push( ( unlocked ) => {
			applyAccess( unlocked );
			// Locking from inside a tool goes back to the agenda. A direct link to a tool
			// (e.g. a bookmarked #timer) still shows its password form instead.
			if ( ! unlocked && GATED.includes( location.hash.slice( 1 ) ) ) {
				location.hash = 'agenda';
			}
		} );

		function mountTab( key ) {
			if ( mounted[ key ] !== undefined || ! ctx.meeting ) {
				return;
			}
			if ( GATED.includes( key ) ) {
				mounted[ key ] = null;
				T.gate( panels[ key ], ( panel ) => ( mounted[ key ] = T.tools[ key ].mount( panel, ctx ) ) );
			} else if ( key === 'unlock' ) {
				mounted[ key ] = null;
				// After unlocking, go back to the agenda, where the tool tabs now show.
				T.gate( panels[ key ], () => location.hash.slice( 1 ) === 'unlock' && ( location.hash = 'agenda' ) );
			} else {
				mounted[ key ] = null;
			}
		}

		function show( key ) {
			if ( ! panels[ key ] ) {
				key = 'agenda';
			}
			tabs.forEach( ( t ) => t.setAttribute( 'aria-selected', String( t.dataset.tab === key ) ) );
			Object.entries( panels ).forEach( ( [ k, p ] ) => ( p.hidden = k !== key ) );
			current = key;
			applyAccess( T.access.unlocked );
			mountTab( key );
		}

		tabs.forEach( ( t ) =>
			t.addEventListener( 'keydown', ( e ) => {
				// Arrow keys move between tabs (WAI-ARIA tabs pattern).
				const i = tabs.indexOf( t );
				const next = e.key === 'ArrowRight' ? tabs[ i + 1 ] : e.key === 'ArrowLeft' ? tabs[ i - 1 ] : null;
				if ( next ) {
					e.preventDefault();
					next.focus();
					next.click();
				}
			} )
		);
		window.addEventListener( 'hashchange', () => show( location.hash.slice( 1 ) ) );
		root.querySelectorAll( '.tmmt-audience-form' ).forEach( T.audience.enhance );
		// Feedback buttons on the agenda open the form with that speaker already chosen.
		root.querySelectorAll( '[data-feedback-for]' ).forEach( ( a ) =>
			a.addEventListener( 'click', () => {
				const select = panels.audience.querySelector( 'select[name=agenda_item_id]' );
				if ( select ) {
					select.value = a.dataset.feedbackFor;
				}
			} )
		);

		const [ meetingRes, rolesRes ] = await Promise.all( [ run( 'get-meeting', { meeting_id: meetingId }, 'read' ), run( 'list-role-types', null, 'read' ), T.loadAccess() ] );
		if ( meetingRes.error ) {
			ctx.error( meetingRes.error.message );
			return;
		}
		ctx.meeting = meetingRes.data;
		roles = rolesRes.data ? rolesRes.data.role_types : [];
		root.querySelectorAll( '.tmmt-audience-form' ).forEach( ( f ) => T.audience.refresh( f, ctx.meeting ) );
		show( location.hash.slice( 1 ) );
	}

	async function bootAudience( root ) {
		root.querySelectorAll( '.tmmt-audience-form' ).forEach( T.audience.enhance );
		const { data } = await run( 'get-meeting', { meeting_id: Number( root.dataset.tmmtMeeting ) }, 'read' );
		if ( data ) {
			root.querySelectorAll( '.tmmt-audience-form' ).forEach( ( f ) => T.audience.refresh( f, data ) );
		}
	}

	function boot() {
		document.querySelectorAll( '.tmmt-meeting[data-tmmt-meeting]' ).forEach( bootMeeting );
		document.querySelectorAll( '.tmmt-audience[data-tmmt-meeting]' ).forEach( bootAudience );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( window.TMMT );
