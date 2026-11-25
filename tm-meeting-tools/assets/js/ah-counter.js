/**
 * Ah-Counter: pick a speaker (agenda item), tap a button per filler word. Every tap saves on its own
 * (one record per agenda item, overwritten), and the live report below lists every person's counts.
 * Taps not yet saved survive a reload or a locked phone (kept in localStorage).
 */
( function ( T ) {
	const { h, run, __, sprintf, store, vibrate } = T;

	function mount( panel, ctx ) {
		const fillers = T.cfg.fillerWords || [];
		// The word of the day gets its own button; it is counted but is not a filler word.
		const wotd = () => ( ctx.meeting.word_of_day && ! fillers.includes( ctx.meeting.word_of_day ) ? ctx.meeting.word_of_day : '' );
		const words = () => ( wotd() ? fillers.concat( wotd() ) : fillers );
		const key = 'tmmt_ah_' + ctx.meeting.id;
		const empty = () => Object.fromEntries( words().map( ( w ) => [ w, 0 ] ) );
		const saved = store.get( key, {} );
		const st = {
			// Selected agenda item id.
			sel: Number( saved.sel ) || 0,
			// Unsaved changes per agenda item id: { counts, notes }.
			pending: saved.pend || {},
		};
		let history = [];
		let queue = Promise.resolve();
		let timer = null;
		const persist = () => store.set( key, { sel: st.sel, pend: st.pending } );

		/**
		 * Who the Ah-Counter counts: people with a speaking slot, up to the Ah-Counter's own report.
		 * The report and the roles after it (timekeeper, general evaluator) are not counted.
		 * Matched by label because role names are free text; the last match is the report, earlier
		 * ones are the introduction.
		 */
		function speakers() {
			const agenda = ctx.meeting.agenda;
			let cut = agenda.length;
			agenda.forEach( ( i, n ) => {
				if ( /ah-counter/i.test( i.label ) ) {
					cut = n;
				}
			} );
			// Guests are not rated, so the Ah-Counter does not count them.
			return agenda.slice( 0, cut ).filter( ( i ) => i.person_name && i.red_sec > 0 && ! i.is_guest );
		}

		const savedFor = ( id ) => history.find( ( x ) => x.agenda_item_id === id );

		/** What is shown for an agenda item: the unsaved version if there is one, else the saved record. */
		function entryFor( id ) {
			const r = savedFor( id );
			const p = st.pending[ id ];
			return {
				counts: Object.assign( empty(), p ? p.counts : r ? r.counts : {} ),
				notes: p ? p.notes : r ? r.notes || '' : '',
			};
		}
		const countsFor = ( id ) => entryFor( id ).counts;

		function change( id, part ) {
			st.pending[ id ] = Object.assign( entryFor( id ), part );
			persist();
			clearTimeout( timer );
			// Short wait so a burst of taps or keystrokes becomes one save.
			timer = setTimeout( flush, 500 );
		}

		async function loadHistory() {
			const { data } = await run( 'list-filler-counts', { meeting_id: ctx.meeting.id }, 'read' );
			history = data ? data.filler_counts : [];
			render();
			flush();
		}

		// Saves run one after another, so a speaker's first save has created the record before the next one overwrites it.
		function flush() {
			clearTimeout( timer );
			Object.keys( st.pending ).forEach( ( id ) => {
				queue = queue.then( () => send( Number( id ) ) );
			} );
		}

		async function send( id ) {
			const entry = st.pending[ id ];
			const item = ctx.meeting.agenda.find( ( i ) => i.id === id );
			if ( ! entry || ! item ) {
				return;
			}
			const { data, error } = await run( 'save-filler-counts', { meeting_id: ctx.meeting.id, agenda_item_id: id, person_name: item.person_name, counts: entry.counts, notes: entry.notes } );
			if ( error ) {
				ctx.error( error.message );
				return;
			}
			history = history.filter( ( x ) => x.agenda_item_id !== id ).concat( data );
			// Only drop the pending change if nothing new came in while this save was on its way.
			if ( st.pending[ id ] === entry ) {
				delete st.pending[ id ];
				persist();
			}
			render();
		}

		function bump( word, by ) {
			const counts = countsFor( st.sel );
			const next = Math.max( 0, ( counts[ word ] || 0 ) + by );
			if ( next === ( counts[ word ] || 0 ) ) {
				return;
			}
			counts[ word ] = next;
			vibrate( 15 );
			change( st.sel, { counts } );
			render();
		}

		function go( id ) {
			flush();
			st.sel = id;
			persist();
			render();
		}

		/** The two most used filler words, e.g. "11x um, 4x like" with the numbers in bold. Null when none. */
		function summary( counts ) {
			const top = fillers
				.filter( ( w ) => counts[ w ] > 0 )
				.sort( ( a, b ) => counts[ b ] - counts[ a ] )
				.slice( 0, 2 );
			return top.length ? top.flatMap( ( w, n ) => [ n ? ', ' : '', h( 'strong', null, counts[ w ] + 'x' ), ' ' + w ] ) : null;
		}

		/**
		 * Live report, one entry per person. Someone with one role gets one line, the role under the name;
		 * someone with more roles gets their name as a heading and a line per role.
		 * Columns: filler words, and the word of the day on its own. Read-only.
		 */
		function report( list ) {
			const people = [];
			list.forEach( ( i ) => {
				const p = people.find( ( x ) => x.name === i.person_name );
				if ( p ) {
					p.items.push( i );
				} else {
					people.push( { name: i.person_name, items: [ i ] } );
				}
			} );
			const row = ( i, who ) => {
				const { counts, notes } = entryFor( i.id );
				const top = summary( counts );
				return h(
					'li',
					{ class: 'tmmt-report-row' + ( wotd() ? ' tmmt-ah-row' : '' ) },
					who,
					top ? h( 'span', { class: 'tmmt-ah-summary' }, top ) : h( 'span', { class: 'tmmt-report-num' }, '-' ),
					wotd() ? h( 'span', { class: 'tmmt-report-num tmmt-ah-wotd' }, counts[ wotd() ] ? String( counts[ wotd() ] ) : '-' ) : null,
					notes.trim() ? h( 'p', { class: 'tmmt-ah-note' }, notes.trim() ) : null
				);
			};
			return h(
				'div',
				{ class: 'tmmt-live-report' },
				h( 'h3', { class: 'tmmt-h' }, __( 'Ah-Counter report', 'tm-meeting-tools' ) ),
				h(
					'div',
					{ class: 'tmmt-report-row tmmt-ah-head' + ( wotd() ? ' tmmt-ah-row' : '' ) },
					h( 'span' ),
					h( 'span', null, __( 'Top filler words', 'tm-meeting-tools' ) ),
					wotd() ? h( 'span', { class: 'tmmt-ah-wotd' }, wotd() ) : null
				),
				people.map( ( p ) =>
					p.items.length === 1
						? h(
								'ul',
								{ class: 'tmmt-report-rows tmmt-ah-single' },
								row( p.items[ 0 ], h( 'span', { class: 'tmmt-report-who' }, h( 'strong', null, p.name ), h( 'span', { class: 'tmmt-muted' }, p.items[ 0 ].label ) ) )
						  )
						: h(
								'section',
								{ class: 'tmmt-ah-multi' },
								h( 'p', { class: 'tmmt-ah-person' }, h( 'strong', null, p.name ) ),
								h( 'ul', { class: 'tmmt-report-rows' }, p.items.map( ( i ) => row( i, h( 'span', { class: 'tmmt-report-who' }, i.label ) ) ) )
						  )
				)
			);
		}

		/** The Word of the Day is often only announced at the meeting: let the Ah-Counter set it. */
		function wotdCard() {
			const input = h( 'input', { class: 'tmmt-input', id: 'tmmt-ah-wotd', type: 'text', maxlength: 190, autocomplete: 'off' } );
			const btn = h( 'button', { type: 'submit', class: 'tmmt-btn tmmt-btn--primary' }, __( 'Set', 'tm-meeting-tools' ) );
			return h(
				'form',
				{
					class: 'tmmt-card tmmt-wotd-card',
					onsubmit: async ( e ) => {
						e.preventDefault();
						const word = input.value.trim();
						if ( ! word ) {
							return;
						}
						btn.disabled = true;
						const { error } = await run( 'update-meeting', { meeting_id: ctx.meeting.id, word_of_day: word } );
						btn.disabled = false;
						if ( error ) {
							ctx.error( error.message );
							return;
						}
						await ctx.reload();
					},
				},
				h( 'label', { class: 'tmmt-label', for: 'tmmt-ah-wotd' }, __( 'What is the Word of the Day?', 'tm-meeting-tools' ) ),
				h( 'div', { class: 'tmmt-row' }, input, btn )
			);
		}

		let shown = '';
		const signature = () => JSON.stringify( [ speakers().map( ( i ) => [ i.id, i.person_name, i.label ] ), wotd() ] );

		// Polled sync: only re-render when the speaker list changed, so a tap is never lost to a re-render.
		function refresh() {
			if ( signature() !== shown ) {
				render();
			}
		}

		function render() {
			// Saves re-render the panel; keep someone typing in the notes box where they are.
			const typing = document.activeElement && document.activeElement.id === 'tmmt-ah-notes' ? [ document.activeElement.selectionStart, document.activeElement.selectionEnd ] : null;
			const word = panel.querySelector( '#tmmt-ah-wotd' );
			const wordTyped = word ? [ word.value, document.activeElement === word ] : null;
			draw();
			const wordBox = wordTyped && panel.querySelector( '#tmmt-ah-wotd' );
			if ( wordBox ) {
				wordBox.value = wordTyped[ 0 ];
				if ( wordTyped[ 1 ] ) {
					wordBox.focus();
				}
			}
			const box = typing && panel.querySelector( '#tmmt-ah-notes' );
			if ( box ) {
				box.focus();
				box.setSelectionRange( typing[ 0 ], typing[ 1 ] );
			}
		}

		function draw() {
			shown = signature();
			const list = speakers();
			if ( ! list.length ) {
				panel.replaceChildren( h( 'div', { class: 'tmmt-ah' }, h( 'h3', { class: 'tmmt-h' }, __( 'Ah-Counter', 'tm-meeting-tools' ) ), h( 'p', { class: 'tmmt-muted' }, __( 'Nobody on the agenda yet.', 'tm-meeting-tools' ) ) ) );
				return;
			}
			// By default the first speaker on the agenda is selected.
			let idx = list.findIndex( ( i ) => i.id === st.sel );
			if ( idx < 0 ) {
				idx = 0;
				st.sel = list[ 0 ].id;
			}
			const item = list[ idx ];
			const next = list[ idx + 1 ];
			const short = ( text ) => ( text.length > 10 ? text.slice( 0, 10 ).trimEnd() + '.' : text );
			const counts = countsFor( item.id );
			panel.replaceChildren(
				h(
					'div',
					{ class: 'tmmt-ah' },
					h( 'h3', { class: 'tmmt-h' }, __( 'Ah-Counter', 'tm-meeting-tools' ) ),
					ctx.meeting.word_of_day ? null : wotdCard(),
					h(
						'div',
						{ class: 'tmmt-timer-box' },
						h(
							'div',
							{ class: 'tmmt-card tmmt-card--pick' },
							// Invisible select over the card, same as the timer: tapping opens the phone's own picker.
							h(
								'select',
								{ class: 'tmmt-card-select', 'aria-label': __( 'Choose the speaker', 'tm-meeting-tools' ), onchange: ( e ) => go( Number( e.target.value ) ) },
								list.map( ( i ) => h( 'option', { value: String( i.id ), selected: i.id === item.id }, i.label + ': ' + i.person_name ) )
							),
							h( 'span', { class: 'tmmt-card-chevron', 'aria-hidden': 'true' }, '▾' ),
							h( 'p', { class: 'tmmt-card-role' }, item.label ),
							h( 'p', { class: 'tmmt-card-person' }, item.person_name )
						),
						h(
							'div',
							{ class: 'tmmt-ah-grid' },
							words().map( ( w ) =>
								h(
									'div',
									{ class: 'tmmt-ah-cell' + ( w === wotd() ? ' tmmt-ah-cell--wotd' : '' ) },
									// The count stays small and light in brackets, so the word is what the eye finds.
									h( 'button', { type: 'button', class: 'tmmt-ah-btn', onclick: () => bump( w, 1 ), 'aria-label': sprintf( __( '%1$s, count %2$d', 'tm-meeting-tools' ), w, counts[ w ] || 0 ) }, h( 'span', { class: 'tmmt-ah-word' }, w, counts[ w ] > 0 ? [ ' ', h( 'span', { class: 'tmmt-ah-count' }, '(' + counts[ w ] + ')' ) ] : null ), w === wotd() ? h( 'span', { class: 'tmmt-ah-tag' }, __( 'Word of the day', 'tm-meeting-tools' ) ) : null ),
									counts[ w ] > 0 ? h( 'button', { type: 'button', class: 'tmmt-ah-minus', onclick: () => bump( w, -1 ), 'aria-label': sprintf( __( 'Remove one %s', 'tm-meeting-tools' ), w ) }, '-1' ) : null
								)
							)
						),
						h( 'label', { class: 'tmmt-label', for: 'tmmt-ah-notes' }, __( 'Notes', 'tm-meeting-tools' ) ),
						h( 'textarea', {
							class: 'tmmt-input tmmt-ah-notes',
							id: 'tmmt-ah-notes',
							rows: 3,
							maxlength: 5000,
							placeholder: sprintf( __( 'Notes on %s', 'tm-meeting-tools' ), item.person_name ),
							value: entryFor( item.id ).notes,
							oninput: ( e ) => change( item.id, { notes: e.target.value } ),
						} ),
						h(
							'div',
							{ class: 'tmmt-nav-row' },
							h( 'button', { type: 'button', class: 'tmmt-btn', onclick: () => go( list[ idx - 1 ].id ), disabled: idx === 0 }, __( 'Previous', 'tm-meeting-tools' ) ),
							h(
								'button',
								{ type: 'button', class: 'tmmt-btn', onclick: () => next && go( next.id ), disabled: ! next },
								next ? sprintf( __( 'Next (%s)', 'tm-meeting-tools' ), short( next.label ) ) : __( 'Next', 'tm-meeting-tools' )
							)
						)
					),
					report( list )
				)
			);
		}

		render();
		loadHistory();
		// Table topic speakers are added by the timer on another phone; pick them up every 5 s while this tool is open.
		setInterval( () => {
			if ( ! panel.closest( '[hidden]' ) && document.visibilityState === 'visible' ) {
				ctx.reload();
			}
		}, 5000 );
		return { refresh };
	}

	T.tools[ 'ah-counter' ] = { mount };
} )( window.TMMT );
