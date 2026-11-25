/**
 * Audience form: anonymous "great" / "potential" feedback, server-rendered and submitted here.
 * (The General Evaluator tool lives in general-evaluator.js.)
 */
( function ( T ) {
	const { h, run, __, sprintf } = T;

	/** Keeps the speaker list fresh. Only prepared speeches get audience feedback, not table topics. */
	function refreshAudienceOptions( form, meeting ) {
		const select = form.querySelector( 'select[name=agenda_item_id]' );
		if ( ! select ) {
			return;
		}
		const current = select.value;
		const speakers = meeting.agenda.filter( ( i ) => i.person_name && i.category === 'speech' );
		select.replaceChildren(
			h( 'option', { value: '', disabled: true, selected: true, hidden: true }, __( 'Choose a speaker', 'tm-meeting-tools' ) ),
			...speakers.map( ( i, n ) => h( 'option', { value: String( i.id ) }, sprintf( __( 'Speaker %d', 'tm-meeting-tools' ), n + 1 ) + ' - ' + i.person_name + ( i.speech_title ? ' (' + i.speech_title + ')' : '' ) ) )
		);
		select.value = current;
	}

	function enhanceAudienceForm( form ) {
		if ( form.dataset.ready ) {
			return;
		}
		form.dataset.ready = '1';
		const msg = form.querySelector( '.tmmt-msg' );
		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			const fd = new FormData( form );
			const item = Number( fd.get( 'agenda_item_id' ) );
			const great = String( fd.get( 'went_well' ) || '' ).trim();
			const potential = String( fd.get( 'potential' ) || '' ).trim();
			if ( ! item ) {
				T.say( msg, __( 'Please choose a speaker.', 'tm-meeting-tools' ), 'error' );
				return;
			}
			if ( ! great && ! potential ) {
				T.say( msg, __( 'Please write something in at least one of the boxes.', 'tm-meeting-tools' ), 'error' );
				return;
			}
			const btn = form.querySelector( 'button[type=submit]' );
			btn.disabled = true;
			const { error } = await run( 'submit-feedback', {
				meeting_id: Number( form.dataset.meeting ),
				kind: 'audience',
				agenda_item_id: item,
				went_well: great,
				potential,
				nonce: String( fd.get( 'nonce' ) || '' ),
				website: String( fd.get( 'website' ) || '' ),
			} );
			btn.disabled = false;
			if ( error ) {
				T.say( msg, error.status === 403 ? __( 'This form has expired. Please reload the page and try again.', 'tm-meeting-tools' ) : error.message, 'error' );
				return;
			}
			form.querySelector( '[name=went_well]' ).value = '';
			form.querySelector( '[name=potential]' ).value = '';
			form.querySelector( '[name=agenda_item_id]' ).value = '';
			const thanks = __( 'Thank you for your feedback!', 'tm-meeting-tools' );
			T.say( msg, thanks, 'ok' );
			// Only clear our own message: a newer error or "sending" text must stay visible.
			setTimeout( () => msg.textContent === thanks && T.say( msg, '' ), 7000 );
		} );
	}

	T.audience = { enhance: enhanceAudienceForm, refresh: refreshAudienceOptions };
} )( window.TMMT );
