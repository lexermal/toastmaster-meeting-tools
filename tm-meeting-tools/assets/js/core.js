/**
 * Shared front-end helpers: ability calls, DOM builder, formatting, tools password gate.
 * No build step: plain browser JS, attached to window.TMMT.
 */
( function () {
	const cfg = window.TMMT_CONFIG || {};
	const { __, sprintf } = window.wp && window.wp.i18n ? window.wp.i18n : { __: ( s ) => s, sprintf: ( s, ...a ) => a.reduce( ( r, v ) => r.replace( /%[sd]/, v ), s ) };

	/** Flattens {a:{b:1}} to input[a][b]=1 for GET/DELETE abilities, which take input from the query string. */
	function toQuery( value, prefix, out ) {
		if ( value === null || value === undefined ) {
			return out;
		}
		if ( typeof value === 'object' ) {
			Object.keys( value ).forEach( ( k ) => toQuery( value[ k ], `${ prefix }[${ k }]`, out ) );
		} else {
			out.push( encodeURIComponent( prefix ) + '=' + encodeURIComponent( value ) );
		}
		return out;
	}

	/**
	 * Runs an ability through the Abilities REST API.
	 * kind must match the ability annotations: read = GET, delete = DELETE, write = POST.
	 *
	 * @return {Promise<{data?:any, error?:{message:string,status:number,code:string}}>}
	 */
	async function run( name, input, kind = 'write' ) {
		const method = kind === 'read' ? 'GET' : kind === 'delete' ? 'DELETE' : 'POST';
		let url = cfg.abilitiesUrl + 'tm-meeting-tools/' + name + '/run';
		const opts = {
			method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.restNonce || '' },
		};
		if ( method === 'POST' ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( { input: input || {} } );
		} else if ( input && Object.keys( input ).length ) {
			url += ( url.includes( '?' ) ? '&' : '?' ) + toQuery( input, 'input', [] ).join( '&' );
		}
		try {
			const res = await fetch( url, opts );
			const body = await res.json().catch( () => null );
			if ( ! res.ok ) {
				return { error: { status: res.status, code: ( body && body.code ) || 'http_error', message: ( body && body.message ) || __( 'Something went wrong. Please try again.', 'tm-meeting-tools' ) } };
			}
			return { data: body };
		} catch ( e ) {
			return { error: { status: 0, code: 'offline', message: __( 'No connection. Please check your internet and try again.', 'tm-meeting-tools' ) } };
		}
	}

	/** Tiny element builder: h('button', {class:'x', onclick: fn}, 'Text', childEl). */
	function h( tag, attrs, ...children ) {
		const el = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => {
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
				el.addEventListener( k.slice( 2 ), v );
			} else if ( k === 'class' ) {
				el.className = v;
			} else if ( k === 'value' ) {
				el.value = v;
			} else if ( v === true ) {
				el.setAttribute( k, '' );
			} else {
				el.setAttribute( k, v );
			}
		} );
		children.flat().forEach( ( c ) => {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			el.append( c instanceof Node ? c : document.createTextNode( String( c ) ) );
		} );
		return el;
	}

	function fmt( sec ) {
		const s = Math.max( 0, Math.floor( sec ) );
		return Math.floor( s / 60 ) + ':' + String( s % 60 ).padStart( 2, '0' );
	}

	function verdict( duration, green, red ) {
		const g = cfg.graceSec || 30;
		if ( duration < green - g ) {
			return 'under';
		}
		if ( duration > red + g ) {
			return 'over';
		}
		return 'within';
	}

	function verdictLabel( v ) {
		return {
			within: __( 'Within time', 'tm-meeting-tools' ),
			under: __( 'Under time', 'tm-meeting-tools' ),
			over: __( 'Over time', 'tm-meeting-tools' ),
			not_timed: __( 'Not timed', 'tm-meeting-tools' ),
			guest: __( 'Guest', 'tm-meeting-tools' ),
		}[ v ] || v;
	}

	/**
	 * Groups agenda items into the agenda sections (Meet and Greet, Introduction, Speeches, Break, Table Topics,
	 * Feedback), keeping agenda order. Roles before the first speech/break/table topic are the introduction,
	 * roles after are feedback. Same rules as includes/views/meeting.php.
	 * Returns [ { label, items } ] for the given items only.
	 */
	function sections( agenda, items ) {
		const labels = {
			meet_greet: __( 'Meet and Greet', 'tm-meeting-tools' ),
			intro: __( 'Introduction', 'tm-meeting-tools' ),
			speech: __( 'Speeches', 'tm-meeting-tools' ),
			break: __( 'Break', 'tm-meeting-tools' ),
			table_topic: __( 'Table Topics', 'tm-meeting-tools' ),
			feedback: __( 'Feedback', 'tm-meeting-tools' ),
		};
		const keys = {};
		let main = false;
		agenda.forEach( ( i ) => {
			let key = [ 'speech', 'break', 'table_topic' ].includes( i.category ) ? i.category : main ? 'feedback' : 'intro';
			if ( /meet\s*(and|&)\s*greet/i.test( i.role_name || '' ) ) {
				key = 'meet_greet';
			}
			main = main || ! [ 'meet_greet', 'intro', 'feedback' ].includes( key );
			keys[ i.id ] = key;
		} );
		const groups = [];
		items.forEach( ( i ) => {
			const last = groups[ groups.length - 1 ];
			if ( last && last.key === keys[ i.id ] ) {
				last.items.push( i );
			} else {
				groups.push( { key: keys[ i.id ], label: labels[ keys[ i.id ] ], items: [ i ] } );
			}
		} );
		return groups;
	}

	/** People on the agenda, each with the roles they hold. */
	function people( meeting ) {
		const map = new Map();
		( meeting.agenda || [] ).forEach( ( item ) => {
			const name = ( item.person_name || '' ).trim();
			if ( ! name ) {
				return;
			}
			if ( ! map.has( name ) ) {
				map.set( name, { name, items: [] } );
			}
			map.get( name ).items.push( item );
		} );
		return Array.from( map.values() );
	}

	/** localStorage can be missing or throw (private mode); never let that break a tool. */
	const store = {
		get( key, fallback ) {
			try {
				const v = window.localStorage.getItem( key );
				return v === null ? fallback : JSON.parse( v );
			} catch ( e ) {
				return fallback;
			}
		},
		set( key, value ) {
			try {
				window.localStorage.setItem( key, JSON.stringify( value ) );
			} catch ( e ) {}
		},
		remove( key ) {
			try {
				window.localStorage.removeItem( key );
			} catch ( e ) {}
		},
	};

	function vibrate( pattern ) {
		if ( navigator.vibrate ) {
			try {
				navigator.vibrate( pattern );
			} catch ( e ) {}
		}
	}

	/** Shared state of the tools password across tabs on the page. */
	const access = { unlocked: null, passwordSet: true, listeners: [] };

	async function loadAccess() {
		const { data } = await run( 'get-tools-status', null, 'read' );
		access.unlocked = !! ( data && data.unlocked );
		access.passwordSet = !! ( data && data.password_set );
		return access.unlocked;
	}

	function setUnlocked( value ) {
		access.unlocked = value;
		access.listeners.forEach( ( fn ) => fn( value ) );
	}

	/**
	 * Renders the tool when unlocked, otherwise a password form. Re-renders on unlock/lock.
	 */
	function gate( panel, renderTool ) {
		const draw = () => {
			panel.replaceChildren();
			if ( access.unlocked ) {
				renderTool( panel );
				return;
			}
			const msg = h( 'p', { class: 'tmmt-msg', role: 'status', 'aria-live': 'polite' } );
			const input = h( 'input', { class: 'tmmt-input', type: 'password', name: 'password', autocomplete: 'current-password', required: true, id: 'tmmt-pw-' + Math.random().toString( 36 ).slice( 2 ) } );
			const form = h(
				'form',
				{
					class: 'tmmt-form tmmt-gate',
					onsubmit: async ( e ) => {
						e.preventDefault();
						msg.textContent = '';
						const btn = form.querySelector( 'button' );
						btn.disabled = true;
						const { error } = await run( 'unlock-tools', { password: input.value } );
						btn.disabled = false;
						if ( error ) {
							msg.textContent = error.message;
							msg.className = 'tmmt-msg tmmt-msg--error';
							return;
						}
						setUnlocked( true );
					},
				},
				h( 'h3', { class: 'tmmt-h' }, __( 'For role holders', 'tm-meeting-tools' ) ),
				h( 'p', { class: 'tmmt-muted' }, access.passwordSet ? __( 'Enter the club tools password to unlock this tool.', 'tm-meeting-tools' ) : __( 'The tools password is not set up yet. Ask an admin.', 'tm-meeting-tools' ) ),
				h( 'label', { class: 'tmmt-label', for: input.id }, __( 'Tools password', 'tm-meeting-tools' ) ),
				input,
				msg,
				h(
					'div',
					{ class: 'tmmt-row' },
					h( 'button', { class: 'tmmt-btn tmmt-btn--primary', type: 'submit' }, __( 'Unlock', 'tm-meeting-tools' ) ),
					h( 'a', { class: 'tmmt-btn', href: '#agenda' }, __( 'Back to the agenda', 'tm-meeting-tools' ) )
				)
			);
			panel.append( form );
		};
		access.listeners.push( draw );
		draw();
	}

	/** Small inline status line. type: ok | error | '' */
	function say( el, text, type ) {
		el.textContent = text || '';
		el.className = 'tmmt-msg' + ( type ? ' tmmt-msg--' + type : '' );
	}

	window.TMMT = { cfg, __, sprintf, run, h, fmt, verdict, verdictLabel, sections, people, store, vibrate, access, loadAccess, setUnlocked, gate, say, tools: {} };
} )();
