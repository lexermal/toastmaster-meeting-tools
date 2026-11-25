/**
 * Timekeeper: step through the timed agenda items, full-screen colour signals,
 * auto-save every time, table topic speakers added from the master, and a live timing report below.
 *
 * Elapsed time is always computed from Date.now() and a stored start timestamp, never from
 * counting ticks, so a locked phone or a reloaded page still shows the right time.
 */
( function ( T ) {
	const { h, fmt, run, __, sprintf, store, vibrate } = T;
	const COLORS = { idle: 'idle', green: 'green', yellow: 'yellow', red: 'red' };

	function phaseOf( sec, item ) {
		if ( ! item ) {
			return COLORS.idle;
		}
		if ( sec >= item.red_sec ) {
			return COLORS.red;
		}
		if ( sec >= item.yellow_sec ) {
			return COLORS.yellow;
		}
		if ( sec >= item.green_sec ) {
			return COLORS.green;
		}
		return COLORS.idle;
	}

	function mount( panel, ctx ) {
		const key = 'tmmt_timer_' + ctx.meeting.id;
		const pendingKey = key + '_pending';
		// startedAt: ms epoch while running. stopped: final seconds (corrected with -10/+10).
		const st = Object.assign( { currentId: null, startedAt: null, stopped: null }, store.get( key, {} ) );
		let pending = store.get( pendingKey, {} );
		let recorded = {};
		let lastPhase = COLORS.idle;
		let tickTimer = null;
		let retryTimer = null;
		let wakeLock = null;
		// True while a new table topic speaker is being created on the server (the clock already runs).
		let creating = false;
		let nameTimer = null;
		// Full screen shows only the coloured clock; stopping the timer leaves it again.
		let full = false;
		const els = {};

		// The Table Topics Master is not timed itself but stays in the timer: speakers are added from it.
		const timed = () => ctx.meeting.agenda.filter( ( i ) => i.timed || isMaster( i ) );
		const current = () => timed().find( ( i ) => i.id === st.currentId ) || null;
		const running = () => st.startedAt !== null;
		const elapsed = () => ( running() ? ( Date.now() - st.startedAt ) / 1000 : st.stopped !== null ? st.stopped : 0 );
		const save = () => store.set( key, st );

		if ( ! current() && timed().length ) {
			st.currentId = timed()[ 0 ].id;
		}

		async function loadRecorded() {
			const { data } = await run( 'get-timing-report', { meeting_id: ctx.meeting.id }, 'read' );
			if ( data ) {
				recorded = {};
				data.rows.forEach( ( r ) => {
					if ( r.agenda_item_id && r.duration_sec !== null ) {
						recorded[ r.agenda_item_id ] = r.duration_sec;
					}
				} );
				render();
			}
		}

		/** Sends every unsaved time. Network errors keep them queued and retry. */
		async function flush() {
			clearTimeout( retryTimer );
			for ( const id of Object.keys( pending ) ) {
				const dur = pending[ id ];
				const { error } = await run( 'record-timing', { agenda_item_id: Number( id ), duration_sec: dur } );
				if ( ! error ) {
					recorded[ id ] = dur;
					if ( pending[ id ] === dur ) {
						delete pending[ id ];
					}
				} else if ( error.status === 0 || error.status >= 500 ) {
					retryTimer = setTimeout( flush, 5000 );
					break;
				} else {
					delete pending[ id ];
					ctx.error( error.message );
					if ( error.status === 401 || error.status === 403 ) {
						T.setUnlocked( false );
					}
				}
			}
			store.set( pendingKey, pending );
			render();
		}

		function record( id, seconds ) {
			pending[ id ] = Math.floor( seconds );
			store.set( pendingKey, pending );
			flush();
		}

		async function keepAwake( on ) {
			try {
				if ( on && 'wakeLock' in navigator && ! wakeLock ) {
					wakeLock = await navigator.wakeLock.request( 'screen' );
					wakeLock.addEventListener( 'release', () => ( wakeLock = null ) );
				} else if ( ! on && wakeLock ) {
					await wakeLock.release();
					wakeLock = null;
				}
			} catch ( e ) {
				wakeLock = null;
			}
		}

		function start() {
			// While a typed name is being added as a speaker, wait: starting now would add a second one.
			if ( ! current() || creating ) {
				return;
			}
			if ( isMaster( current() ) ) {
				startNewSpeaker();
				return;
			}
			st.startedAt = Date.now();
			st.stopped = null;
			lastPhase = COLORS.idle;
			save();
			vibrate( 40 );
			keepAwake( true );
			render();
		}

		function stop() {
			st.stopped = Math.floor( elapsed() );
			st.startedAt = null;
			save();
			vibrate( [ 60, 60, 60 ] );
			keepAwake( false );
			record( st.currentId, st.stopped );
			if ( full ) {
				setFull( false );
			}
		}

		/**
		 * The clock area is stretched over the page with CSS (works on every phone, also iPhone);
		 * where the browser allows it, real full screen hides the browser bars too.
		 */
		function setFull( on ) {
			full = on;
			document.documentElement.classList.toggle( 'tmmt-noscroll', on );
			try {
				if ( on && document.documentElement.requestFullscreen && ! document.fullscreenElement ) {
					document.documentElement.requestFullscreen().catch( () => {} );
				} else if ( ! on && document.fullscreenElement ) {
					document.exitFullscreen().catch( () => {} );
				}
			} catch ( e ) {}
			render();
		}

		// Leaving full screen with the back button or Esc: show the normal page again, keep timing.
		document.addEventListener( 'fullscreenchange', () => {
			if ( full && ! document.fullscreenElement ) {
				full = false;
				document.documentElement.classList.remove( 'tmmt-noscroll' );
				render();
			}
		} );

		/** Correction after stopping (e.g. a late start); the corrected time is saved again. */
		function add( seconds ) {
			if ( st.stopped === null ) {
				return;
			}
			st.stopped = Math.max( 0, st.stopped + seconds );
			record( st.currentId, st.stopped );
			save();
			tick();
		}

		function go( id ) {
			if ( running() ) {
				return;
			}
			st.currentId = id;
			st.stopped = null;
			save();
			render();
		}

		function step( dir ) {
			const list = timed();
			const i = list.findIndex( ( x ) => x.id === st.currentId );
			const next = list[ i + dir ];
			if ( next ) {
				go( next.id );
			}
		}

		async function discard() {
			const id = st.currentId;
			delete pending[ id ];
			store.set( pendingKey, pending );
			st.stopped = null;
			save();
			const { error } = await run( 'clear-timing', { agenda_item_id: id }, 'delete' );
			if ( error ) {
				ctx.error( error.message );
			}
			delete recorded[ id ];
			render();
		}

		/**
		 * On the Table Topics Master the clock times a new speaker: it starts at once, then the speaker
		 * is added after the last table topic item and becomes the current item.
		 */
		/** Adds a table topic speaker after the last table topic item and makes it the current item. */
		async function addSpeaker( name ) {
			const role = ctx.tableTopicRole();
			if ( ! role ) {
				ctx.error( __( 'No table topic role exists. Add one in the settings.', 'tm-meeting-tools' ) );
				return false;
			}
			const input = { meeting_id: ctx.meeting.id, role_type_id: role.id, person_name: name };
			const tt = ctx.meeting.agenda.filter( ( i ) => i.category === 'table_topic' );
			if ( tt.length ) {
				input.after_item_id = tt[ tt.length - 1 ].id;
			}
			creating = true;
			const { data, error } = await run( 'add-agenda-item', input );
			creating = false;
			if ( error ) {
				ctx.error( error.message );
				return false;
			}
			st.currentId = data.id;
			save();
			await ctx.reload();
			return true;
		}

		/** Clock started on the master before a name was typed: start right away, add the speaker meanwhile. */
		async function startNewSpeaker() {
			const field = panel.querySelector( '.tmmt-tt-name' );
			clearTimeout( nameTimer );
			st.startedAt = Date.now();
			st.stopped = null;
			lastPhase = COLORS.idle;
			save();
			vibrate( 40 );
			keepAwake( true );
			creating = true;
			render();
			if ( ! ( await addSpeaker( field ? field.value.trim() : '' ) ) ) {
				st.startedAt = null;
				save();
				keepAwake( false );
				render();
			}
		}

		/** Saves a changed table topic speaker name. */
		async function rename( item, name ) {
			if ( name === item.person_name ) {
				return;
			}
			const { error } = await run( 'update-agenda-item', { item_id: item.id, person_name: name } );
			if ( error ) {
				ctx.error( error.message );
				return;
			}
			await ctx.reload();
		}

		/**
		 * Table topic name typed: saved shortly after each keystroke, so the Ah-Counter sees the speaker
		 * at once. On the master the first save adds the speaker; later keystrokes rename it.
		 */
		function nameTyped() {
			clearTimeout( nameTimer );
			nameTimer = setTimeout( async () => {
				const field = panel.querySelector( '.tmmt-tt-name' );
				const item = current();
				if ( ! field || ! item ) {
					return;
				}
				if ( creating ) {
					nameTyped();
					return;
				}
				const name = field.value.trim();
				if ( isMaster( item ) ) {
					if ( name ) {
						await addSpeaker( name );
					}
				} else if ( item.category === 'table_topic' ) {
					await rename( item, name );
				}
			}, 500 );
		}

		async function markGuest( item, on ) {
			const { error } = await run( 'update-agenda-item', { item_id: item.id, is_guest: on } );
			if ( error ) {
				ctx.error( error.message );
			}
			await ctx.reload();
		}

		/** The clock on the Table Topics Master uses the table topic speaker's times. */
		function timingOf( item ) {
			return item && isMaster( item ) && ctx.tableTopicRole() ? ctx.tableTopicRole() : item;
		}

		/** Updates only the moving parts (time, colour) so typing elsewhere is not disturbed. */
		function tick() {
			const item = current();
			const sec = elapsed();
			const phase = running() || st.stopped !== null ? phaseOf( sec, timingOf( item ) ) : COLORS.idle;
			if ( running() && phase !== lastPhase ) {
				// Phones only (desktop browsers ignore it): short buzz at yellow, one long 1 s buzz at red.
				if ( phase === COLORS.yellow ) {
					vibrate( 200 );
				} else if ( phase === COLORS.red ) {
					vibrate( 1000 );
				}
				lastPhase = phase;
			}
			if ( els.stageTime ) {
				els.stageTime.textContent = fmt( sec );
				els.stage.dataset.phase = phase;
				// Past red plus the grace period the speaker is over time: full screen tells them to finish.
				const w = timingOf( item );
				els.stage.classList.toggle( 'tmmt-stage--overtime', running() && !! w && sec > w.red_sec + ( T.cfg.graceSec || 30 ) );
			}
		}

		function loop() {
			clearInterval( tickTimer );
			if ( running() ) {
				tickTimer = setInterval( tick, 250 );
			}
		}

		/** Table Topics Master: a table topic item that is not the (shorter) speaker role. */
		function isMaster( i ) {
			const speakerRole = ctx.tableTopicRole();
			return i.category === 'table_topic' && ( ! speakerRole || i.role_type_id !== speakerRole.id );
		}

		/**
		 * Agenda section of every item, like the agenda tab: roles before the first speech, break or
		 * table topic are the intro, roles after them are feedback.
		 */
		/** Live timing report, grouped by agenda section: every timed item, its time and verdict. Read-only. */
		function renderReport( list ) {
			// The Table Topics Master is not timed; the report lists only the speakers under it.
			const groups = T.sections( ctx.meeting.agenda, list.filter( ( i ) => ! isMaster( i ) ) );
			const row = ( i ) => {
				const time = recorded[ i.id ] !== undefined ? recorded[ i.id ] : pending[ i.id ];
				// Guests are timed but not rated: their badge just says Guest.
				const verdict = time === undefined ? null : i.is_guest ? 'guest' : T.verdict( time, i.green_sec, i.red_sec );
				return h(
					'li',
					{ class: 'tmmt-report-row' },
					// A break has nobody assigned; show its name instead of "Open".
					i.category === 'break' && ! i.person_name
						? h( 'span', { class: 'tmmt-report-who' }, h( 'strong', null, i.label ) )
						: h( 'span', { class: 'tmmt-report-who' }, h( 'strong', null, i.person_name || __( 'Open', 'tm-meeting-tools' ) ), h( 'span', { class: 'tmmt-muted' }, i.label ) ),
					h(
						'span',
						{ class: 'tmmt-report-time' },
						// The badge carries the time, e.g. "Within time (7:00)"; the allowed range small underneath.
						verdict
							? h( 'span', { class: 'tmmt-verdict tmmt-verdict--' + verdict }, T.verdictLabel( verdict ) + ' (' + fmt( time ) + ')' )
							: h( 'span', { class: 'tmmt-report-num' }, '-' ),
						h( 'small', { class: 'tmmt-muted tmmt-report-range' }, sprintf( __( '%1$s to %2$s', 'tm-meeting-tools' ), fmt( i.green_sec ), fmt( i.red_sec ) ) )
					)
				);
			};
			return h(
				'div',
				{ class: 'tmmt-live-report' },
				h( 'h3', { class: 'tmmt-h' }, __( 'Timing report', 'tm-meeting-tools' ) ),
				groups.map( ( g ) =>
					h(
						'section',
						{ class: 'tmmt-report-section' },
						h( 'h4', { class: 'tmmt-report-section-head' }, g.label ),
						h( 'ul', { class: 'tmmt-report-rows' }, g.items.map( row ) )
					)
				)
			);
		}

		function fullButton() {
			const btn = h( 'button', { type: 'button', class: 'tmmt-full-btn', 'aria-label': __( 'Full screen', 'tm-meeting-tools' ), title: __( 'Full screen', 'tm-meeting-tools' ), onclick: () => setFull( true ) } );
			// Static icon markup (four corners), no user data.
			btn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';
			return btn;
		}

		function renderTimer() {
			const list = timed();
			const item = current();
			if ( ! item ) {
				return h( 'p', { class: 'tmmt-empty' }, __( 'There are no timed items on the agenda yet.', 'tm-meeting-tools' ) );
			}
			const idx = list.indexOf( item );
			const stopped = st.stopped !== null;
			const tt = item.category === 'table_topic';
			const master = isMaster( item );
			const w = timingOf( item );
			const masterItem = list.find( isMaster );
			// During table topics "Next" skips the remaining table topic speakers.
			const next = list.slice( idx + 1 ).find( ( i ) => ! tt || i.category !== 'table_topic' );
			const short = ( text ) => ( text.length > 10 ? text.slice( 0, 10 ).trimEnd() + '.' : text );

			// Table topics: the name of the speaker being timed. On the master it names the next new speaker.
			const ttField = tt
				? h(
						'div',
						{ class: 'tmmt-tt-field' },
						h( 'label', { class: 'tmmt-label', for: 'tmmt-tt-name' }, __( 'Table topic speaker', 'tm-meeting-tools' ) ),
						h( 'input', {
							class: 'tmmt-input tmmt-tt-name',
							id: 'tmmt-tt-name',
							type: 'text',
							placeholder: __( 'Name', 'tm-meeting-tools' ),
							maxlength: 190,
							value: master ? '' : item.person_name,
							oninput: nameTyped,
						} ),
						// Only once the speaker exists (not on the master): guests are timed but not rated.
						master
							? null
							: h(
									'label',
									{ class: 'tmmt-check' },
									h( 'input', { type: 'checkbox', checked: item.is_guest, onchange: ( e ) => markGuest( item, e.target.checked ) } ),
									' ',
									__( 'Guest (not rated)', 'tm-meeting-tools' )
							  )
				  )
				: null;

			els.stageTime = h( 'span', { class: 'tmmt-digits' }, fmt( elapsed() ) );
			// The coloured area holds the big start/stop button plus the time corrections. Buttons can't
			// be nested, so the area itself is a div and only the clock part is the tap target.
			els.stage = h(
				'div',
				{ class: 'tmmt-stage' + ( full ? ' tmmt-stage--full' : '' ), 'data-phase': stopped ? phaseOf( st.stopped, w ) : 'idle' },
				stopped || full ? null : fullButton(),
				h(
					'button',
					// A stopped time is only cleared with Reset, so a stray tap cannot overwrite it.
					{ type: 'button', class: 'tmmt-stage-tap', onclick: running() ? stop : start, disabled: stopped || creating },
					els.stageTime,
					full ? h( 'span', { class: 'tmmt-finish', role: 'status' }, __( 'Finish speech!', 'tm-meeting-tools' ) ) : null,
					stopped ? null : h( 'span', { class: 'tmmt-stage-hint' }, running() ? __( 'Click to stop', 'tm-meeting-tools' ) : __( 'Click to start timer', 'tm-meeting-tools' ) )
				),
				stopped
					? h(
							'div',
							{ class: 'tmmt-plus-row' },
							h( 'button', { type: 'button', class: 'tmmt-btn tmmt-btn--small', onclick: () => add( -10 ) }, '-10s' ),
							h( 'button', { type: 'button', class: 'tmmt-btn tmmt-btn--small', onclick: () => add( 10 ) }, '+10s' ),
							h( 'button', { type: 'button', class: 'tmmt-btn tmmt-btn--small', onclick: discard }, __( 'Reset', 'tm-meeting-tools' ) )
					  )
					: null
			);

			return h(
				'div',
				{ class: 'tmmt-timer' },
				h( 'h3', { class: 'tmmt-h' }, __( 'Timer', 'tm-meeting-tools' ) ),
				// One bordered box for everything about the current role, so the timekeeper sees where to time.
				h(
					'div',
					{ class: 'tmmt-timer-box' },
					h(
						'div',
						{ class: 'tmmt-card tmmt-card--pick' },
						// Invisible select over the card: tapping it opens the phone's own picker to jump to any item.
						h(
							'select',
							{
								class: 'tmmt-card-select',
								'aria-label': __( 'Choose the agenda item to time', 'tm-meeting-tools' ),
								disabled: running(),
								onchange: ( e ) => go( Number( e.target.value ) ),
							},
							list.map( ( i ) => h( 'option', { value: String( i.id ), selected: i.id === item.id }, i.label + ( i.person_name ? ': ' + i.person_name : '' ) ) )
						),
						h( 'span', { class: 'tmmt-card-chevron', 'aria-hidden': 'true' }, '\u25BE' ),
						h( 'p', { class: 'tmmt-card-role' }, item.label ),
						item.category === 'break' && ! item.person_name ? null : h( 'p', { class: 'tmmt-card-person' }, item.person_name || __( 'Open', 'tm-meeting-tools' ) ),
						item.speech_title ? h( 'p', { class: 'tmmt-muted' }, item.speech_title ) : null,
						h(
							'p',
							{ class: 'tmmt-window' },
							h( 'span', { class: 'tmmt-dot tmmt-dot--green' } ), fmt( w.green_sec ),
							h( 'span', { class: 'tmmt-dot tmmt-dot--yellow' } ), fmt( w.yellow_sec ),
							h( 'span', { class: 'tmmt-dot tmmt-dot--red' } ), fmt( w.red_sec )
						)
					),
					ttField,
					els.stage,
					h(
						'div',
						{ class: 'tmmt-nav-row' },
						h( 'button', { type: 'button', class: 'tmmt-btn', onclick: () => step( -1 ), disabled: idx === 0 || running() }, __( 'Previous', 'tm-meeting-tools' ) ),
						tt && masterItem
							? h(
									'button',
									{ type: 'button', class: 'tmmt-btn' + ( stopped ? ' tmmt-btn--primary' : '' ), onclick: () => go( masterItem.id ), disabled: master || running() },
									__( 'Next Table Topic Speaker', 'tm-meeting-tools' )
							  )
							: null,
						h(
							'button',
							{ type: 'button', class: 'tmmt-btn' + ( stopped && ! tt ? ' tmmt-btn--primary' : '' ), onclick: () => next && go( next.id ), disabled: ! next || running() },
							next ? sprintf( __( 'Next (%s)', 'tm-meeting-tools' ), short( next.label ) ) : __( 'Next', 'tm-meeting-tools' )
						)
					)
				),
				renderReport( list )
			);
		}

		function render() {
			const focused = document.activeElement;
			const typing = focused && panel.contains( focused ) && focused.classList.contains( 'tmmt-tt-name' ) ? focused.value : null;
			panel.replaceChildren( renderTimer() );
			if ( typing !== null ) {
				const input = panel.querySelector( '.tmmt-tt-name' );
				if ( input ) {
					// The name is saved while typing and every save re-renders; keep the text, focus and caret.
					input.value = typing;
					input.focus();
					input.setSelectionRange( typing.length, typing.length );
				}
			}
			tick();
			loop();
		}

		document.addEventListener( 'visibilitychange', () => {
			if ( document.visibilityState === 'visible' && running() ) {
				keepAwake( true );
				tick();
			}
		} );
		window.addEventListener( 'online', flush );

		if ( running() ) {
			keepAwake( true );
		}
		render();
		loadRecorded();
		if ( Object.keys( pending ).length ) {
			flush();
		}
		return { refresh: render };
	}

	T.tools.timer = { mount };
} )( window.TMMT );
