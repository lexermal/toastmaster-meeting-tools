/**
 * General Evaluator: pick a role or agenda section, write notes on it. Notes save on their own while
 * typing (one record per topic, overwritten), and the live report below lists every topic's notes.
 * Notes not yet saved survive a reload or a locked phone (kept in localStorage).
 */
( function ( T ) {
	const { h, run, __, sprintf, store } = T;

	function mount( panel, ctx ) {
		// Key => label. Same list as the server; shown in agenda order (see ordered()).
		const topics = Object.entries( T.cfg.geTopics || {} ).map( ( [ key, label ] ) => ( { key, label } ) );
		const key = 'tmmt_ge_' + ctx.meeting.id;
		const saved = store.get( key, {} );
		const st = { sel: saved.sel || '', pending: saved.pend || {} };
		let history = [];
		let queue = Promise.resolve();
		let timer = null;
		const persist = () => store.set( key, { sel: st.sel, pend: st.pending } );

		/**
		 * The agenda items a topic is about. Roles are matched by label because role names are free text;
		 * the last match wins, so "Grammarian and Ah-Counter Report" beats its introduction. A section is all its items.
		 */
		// Each evaluator is their own entry, keyed "evaluator:<agenda item id>".
		const topicOf = ( k ) => k.split( ':' )[ 0 ];
		const itemIdOf = ( k ) => Number( k.split( ':' )[ 1 ] ) || null;
		const evaluators = () => ctx.meeting.agenda.filter( ( i ) => i.person_name && /evaluator/i.test( i.label ) && ! /general/i.test( i.label ) );

		function itemsFor( topicKey ) {
			const agenda = ctx.meeting.agenda;
			if ( itemIdOf( topicKey ) ) {
				return agenda.filter( ( i ) => i.id === itemIdOf( topicKey ) );
			}
			// Toastmaster must start the label, so "Table Topics Master" does not match.
			const role = { toastmaster: /^toastmaster\b/i, ah_counter: /ah-counter/i, grammarian: /grammarian/i, timer: /timekeeper|timer/i, meet_greet: /meet\s*(and|&)\s*greet/i }[ topicKey ];
			if ( role ) {
				const hits = agenda.filter( ( i ) => role.test( i.label ) );
				return hits.slice( -1 );
			}
			// The opening is the President's welcome (first match, a closing word may come later).
			if ( topicKey === 'opening' ) {
				return agenda.filter( ( i ) => /president|opening/i.test( i.label ) ).slice( 0, 1 );
			}
			if ( topicKey === 'tt_master' ) {
				return agenda.filter( ( i ) => i.category === 'table_topic' && /master/i.test( i.label ) ).slice( 0, 1 );
			}
			const section = { break: 'break' }[ topicKey ];
			const group = T.sections( agenda, agenda ).find( ( g ) => g.key === section );
			return group ? group.items : [];
		}
		const peopleFor = ( topicKey ) => [ ...new Set( itemsFor( topicKey ).map( ( i ) => i.person_name ).filter( Boolean ) ) ];

		/**
		 * Topics in agenda order: by where the role or section starts. A role and a section starting at the
		 * same item keep the configured order; topics not on the agenda go last.
		 */
		function ordered() {
			const at = ( t ) => {
				// Setting up the room is not on the agenda but comes before everything.
				if ( t.key === 'setup' ) {
					return -Infinity;
				}
				// Closing and the overall impression come after everything, in that order.
				if ( t.key === 'closing' || t.key === 'overall' ) {
					return Infinity;
				}
				const first = itemsFor( t.key )[ 0 ];
				return first ? first.position : Infinity;
			};
			const list = topics.flatMap( ( t ) => ( t.key === 'evaluator' ? evaluators().map( ( i ) => ( { key: 'evaluator:' + i.id, label: t.label, option: t.label + ': ' + i.person_name, short: i.person_name } ) ) : [ t ] ) );
			return list.sort( ( a, b ) => at( a ) - at( b ) );
		}
		/** Questions shown under the picker for inspiration. Yes/no plus "why" pushes toward specific feedback. */
		const questions = {
			setup: [
				__( 'Was the room ready, with chairs, lectern and timing lights set up, before members arrived?', 'tm-meeting-tools' ),
				__( 'Were agendas and materials available and correct?', 'tm-meeting-tools' ),
				__( 'Did the meeting start on time?', 'tm-meeting-tools' ),
			],
			meet_greet: [
				__( 'Were guests welcomed personally at the door?', 'tm-meeting-tools' ),
				__( 'Did guests get help finding a seat and understanding the agenda?', 'tm-meeting-tools' ),
				__( 'Did the welcome feel warm and genuine, not rushed?', 'tm-meeting-tools' ),
			],
			opening: [
				__( 'Was the opening clear and energetic?', 'tm-meeting-tools' ),
				__( "Were the club, the meeting's purpose and any theme explained for guests?", 'tm-meeting-tools' ),
				__( 'Did it hand over smoothly to the Toastmaster?', 'tm-meeting-tools' ),
			],
			toastmaster: [
				__( 'Did they introduce each role and speaker clearly and with enthusiasm?', 'tm-meeting-tools' ),
				__( 'Were transitions smooth, with no awkward gaps or confusion?', 'tm-meeting-tools' ),
				__( 'Did they keep the meeting on schedule?', 'tm-meeting-tools' ),
			],
			break: [
				__( 'Did the break start and end on time?', 'tm-meeting-tools' ),
				__( 'Did members use it to talk with guests?', 'tm-meeting-tools' ),
				__( 'Was it clear when and how the meeting would continue?', 'tm-meeting-tools' ),
			],
			tt_master: [
				__( 'Were the questions clear, varied and easy to answer on the spot?', 'tm-meeting-tools' ),
				__( 'Did they explain the rules well for first-timers and guests?', 'tm-meeting-tools' ),
				__( "Did they encourage participation, especially from people who hadn't spoken yet?", 'tm-meeting-tools' ),
			],
			evaluator: [
				__( 'Did they name specific strengths with concrete examples from the speech?', 'tm-meeting-tools' ),
				__( 'Were the improvement points clear, actionable and delivered constructively?', 'tm-meeting-tools' ),
				__( 'Did they stay within time and close on an encouraging note?', 'tm-meeting-tools' ),
			],
			ah_counter: [
				__( 'Was the role explained clearly at the start?', 'tm-meeting-tools' ),
				__( 'Was the report accurate and easy to follow?', 'tm-meeting-tools' ),
				__( 'Was it delivered in a supportive way, not as public shaming?', 'tm-meeting-tools' ),
			],
			grammarian: [
				__( 'Was the Word of the Day introduced clearly and used by speakers?', 'tm-meeting-tools' ),
				__( 'Did the report highlight good language use, not just mistakes?', 'tm-meeting-tools' ),
				__( 'Were the examples specific and helpful?', 'tm-meeting-tools' ),
			],
			timer: [
				__( 'Were the timing rules and signals explained clearly?', 'tm-meeting-tools' ),
				__( 'Were the signals shown reliably and visibly?', 'tm-meeting-tools' ),
				__( 'Was the final report concise and accurate?', 'tm-meeting-tools' ),
			],
			closing: [
				__( 'Did the meeting end on time?', 'tm-meeting-tools' ),
				__( 'Was the closing clear, including announcements and the next meeting date?', 'tm-meeting-tools' ),
				__( 'Were guests invited to share impressions or come back?', 'tm-meeting-tools' ),
			],
			overall: [
				__( 'What was the single best moment of the meeting, and why?', 'tm-meeting-tools' ),
				__( 'What one thing should the club do differently next time?', 'tm-meeting-tools' ),
				__( 'Would a first-time guest want to come back? Why or why not?', 'tm-meeting-tools' ),
			],
		};
		const isRole = ( topicKey ) => [ 'opening', 'evaluator', 'toastmaster', 'ah_counter', 'grammarian', 'timer', 'meet_greet', 'tt_master' ].includes( topicOf( topicKey ) );
		const labelOf = ( topicKey ) => ( topics.find( ( t ) => t.key === topicOf( topicKey ) ) || {} ).label;
		// The saved record for an entry: same label, and for an evaluator the same agenda item.
		const isRecordOf = ( x, topicKey ) => x.about === labelOf( topicKey ) && ( ! itemIdOf( topicKey ) || x.agenda_item_id === itemIdOf( topicKey ) );

		function notesFor( topicKey ) {
			if ( topicKey in st.pending ) {
				return st.pending[ topicKey ];
			}
			const r = history.find( ( x ) => isRecordOf( x, topicKey ) );
			return r ? r.body : '';
		}

		function change( topicKey, text ) {
			st.pending[ topicKey ] = text;
			persist();
			clearTimeout( timer );
			// Short wait so a burst of keystrokes becomes one save.
			timer = setTimeout( flush, 500 );
		}

		async function loadHistory() {
			const { data } = await run( 'list-general-evaluation', { meeting_id: ctx.meeting.id }, 'read' );
			history = data ? data.feedback : [];
			render();
			flush();
		}

		// Saves run one after another, so the first save has created the record before the next one overwrites it.
		function flush() {
			clearTimeout( timer );
			Object.keys( st.pending ).forEach( ( k ) => {
				queue = queue.then( () => send( k ) );
			} );
		}

		async function send( topicKey ) {
			if ( ! ( topicKey in st.pending ) ) {
				return;
			}
			const text = st.pending[ topicKey ];
			const who = peopleFor( topicKey );
			const input = { meeting_id: ctx.meeting.id, topic: topicOf( topicKey ), person_name: isRole( topicKey ) && who[ 0 ] ? who[ 0 ] : '', body: text };
			if ( itemIdOf( topicKey ) ) {
				// The evaluator was taken off the agenda: nothing left to save the notes on.
				if ( ! itemsFor( topicKey ).length ) {
					delete st.pending[ topicKey ];
					persist();
					return;
				}
				input.agenda_item_id = itemIdOf( topicKey );
			}
			const { data, error } = await run( 'save-general-evaluation', input );
			if ( error ) {
				ctx.error( error.message );
				if ( error.status === 401 || error.status === 403 ) {
					T.setUnlocked( false );
				}
				return;
			}
			history = history.filter( ( x ) => ! isRecordOf( x, topicKey ) ).concat( data.feedback ? [ data.feedback ] : [] );
			// Only drop the pending text if nothing new was typed while this save was on its way.
			if ( st.pending[ topicKey ] === text ) {
				delete st.pending[ topicKey ];
				persist();
			}
			render();
		}

		function go( topicKey ) {
			flush();
			st.sel = topicKey;
			persist();
			render();
		}

		/** Live report: one line per topic, who it is about under it, the notes below. Read-only. */
		function report() {
			return h(
				'div',
				{ class: 'tmmt-live-report' },
				h( 'h3', { class: 'tmmt-h' }, __( 'General Evaluator report', 'tm-meeting-tools' ) ),
				h(
					'ul',
					{ class: 'tmmt-report-rows tmmt-ge-rows' },
					ordered().map( ( t ) => {
						const notes = notesFor( t.key ).trim();
						const who = peopleFor( t.key ).join( ', ' );
						return h(
							'li',
							{ class: 'tmmt-report-row' },
							h( 'span', { class: 'tmmt-report-who' }, h( 'strong', null, t.label ), who ? h( 'span', { class: 'tmmt-muted' }, who ) : null ),
							notes ? null : h( 'span', { class: 'tmmt-report-num' }, '-' ),
							notes ? h( 'p', { class: 'tmmt-ah-note' }, notes ) : null
						);
					} )
				)
			);
		}

		let shown = '';
		const signature = () => JSON.stringify( ordered().map( ( t ) => [ t.key, peopleFor( t.key ) ] ) );

		// After a reload only re-render when the people changed, so typing is not interrupted for nothing.
		function refresh() {
			if ( signature() !== shown ) {
				render();
			}
		}

		function render() {
			// Saves re-render the panel; keep someone typing in the notes box where they are.
			const el = document.activeElement;
			const typing = el && el.id === 'tmmt-ge-notes' ? [ el.selectionStart, el.selectionEnd ] : null;
			draw();
			const box = typing && panel.querySelector( '#tmmt-ge-notes' );
			if ( box ) {
				box.focus();
				box.setSelectionRange( typing[ 0 ], typing[ 1 ] );
			}
		}

		function draw() {
			shown = signature();
			const topics = ordered();
			let idx = topics.findIndex( ( t ) => t.key === st.sel );
			if ( idx < 0 ) {
				idx = 0;
				st.sel = topics[ 0 ].key;
			}
			const topic = topics[ idx ];
			const next = topics[ idx + 1 ];
			const who = peopleFor( topic.key );
			const short = ( text ) => ( text.length > 10 ? text.slice( 0, 10 ).trimEnd() + '.' : text );
			panel.replaceChildren(
				h(
					'div',
					{ class: 'tmmt-ge' },
					h( 'h3', { class: 'tmmt-h' }, __( 'General Evaluator', 'tm-meeting-tools' ) ),
					h(
						'div',
						{ class: 'tmmt-timer-box' },
						h(
							'div',
							{ class: 'tmmt-card tmmt-card--pick' },
							// Invisible select over the card, same as the timer: tapping opens the phone's own picker.
							h(
								'select',
								{ class: 'tmmt-card-select', 'aria-label': __( 'Choose what to evaluate', 'tm-meeting-tools' ), onchange: ( e ) => go( e.target.value ) },
								topics.map( ( t ) => h( 'option', { value: t.key, selected: t.key === topic.key }, t.option || t.label ) )
							),
							h( 'span', { class: 'tmmt-card-chevron', 'aria-hidden': 'true' }, '▾' ),
							h( 'p', { class: 'tmmt-card-role' }, isRole( topic.key ) ? __( 'Role', 'tm-meeting-tools' ) : __( 'Meeting part', 'tm-meeting-tools' ) ),
							h( 'p', { class: 'tmmt-card-person' }, topic.label ),
							who.length ? h( 'p', { class: 'tmmt-muted tmmt-ge-who' }, who.join( ', ' ) ) : null
						),
						questions[ topicOf( topic.key ) ] ? h( 'ul', { class: 'tmmt-ge-questions', 'aria-label': __( 'Questions for inspiration', 'tm-meeting-tools' ) }, questions[ topicOf( topic.key ) ].map( ( q ) => h( 'li', null, q ) ) ) : null,
						h( 'label', { class: 'tmmt-label', for: 'tmmt-ge-notes' }, __( 'Notes', 'tm-meeting-tools' ) ),
						h( 'textarea', {
							class: 'tmmt-input tmmt-ah-notes',
							id: 'tmmt-ge-notes',
							rows: 6,
							maxlength: 5000,
							placeholder: sprintf( __( 'Notes on %s', 'tm-meeting-tools' ), topic.short || topic.label ),
							value: notesFor( topic.key ),
							oninput: ( e ) => change( topic.key, e.target.value ),
						} ),
						h(
							'div',
							{ class: 'tmmt-nav-row' },
							h( 'button', { type: 'button', class: 'tmmt-btn', onclick: () => go( topics[ idx - 1 ].key ), disabled: idx === 0 }, __( 'Previous', 'tm-meeting-tools' ) ),
							h(
								'button',
								{ type: 'button', class: 'tmmt-btn', onclick: () => next && go( next.key ), disabled: ! next },
								next ? sprintf( __( 'Next (%s)', 'tm-meeting-tools' ), short( next.short || next.label ) ) : __( 'Next', 'tm-meeting-tools' )
							)
						)
					),
					report()
				)
			);
		}

		if ( ! topics.length ) {
			return { refresh() {} };
		}
		render();
		loadHistory();
		return { refresh };
	}

	T.tools.feedback = { mount };
} )( window.TMMT );
