/**
 * What a block shows in the editor when it has nothing to render yet.
 *
 * Every block here renders in PHP and previews through `ServerSideRender`, and
 * every one of them can legitimately come back empty: an Event Details block on
 * an event that has not been saved yet, an Event List on a site with no events,
 * a registration form for an event that is not taking bookings. The default
 * behaviour for that is an empty `<div>` — a block that is in the document,
 * takes up no space, and gives the person who just inserted it no sign that
 * anything happened at all.
 *
 * Found in C10.4 by opening each block in the editor, which is the only way this
 * kind of thing shows up: it builds cleanly, registers cleanly, and renders
 * perfectly on the front end.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label What this block will show once it has something.
 * @return {Element} The placeholder.
 */

import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Empty( { label } ) {
	return (
		<Placeholder
			label={ label }
			instructions={ __(
				'Nothing to show yet. This block fills in once the event is saved, or once there is something to list.',
				'quick-events-manager'
			) }
		/>
	);
}
