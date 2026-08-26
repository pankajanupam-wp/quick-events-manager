<?php
/**
 * The registration form.
 *
 * Override by copying to `your-theme/quick-events-manager/registration-form.php`.
 *
 * @package QuickEventsManager
 *
 * @var \QuickEventsManager\Events\Event $event Event being registered for.
 * @var array<int, array{id: int, label: string, full: bool}> $dates Dates to choose between, or empty when there is only one.
 * @var array<int, array{id: int, name: string, description: string, price: string, remaining: int|null, full: bool, opens: string}> $ticket_types Kinds of place to choose between, or empty when the event offers one.
 * @var bool              $is_full      Whether every place is taken.
 * @var int|null          $remaining    Places left, or null when uncapped.
 * @var array|null        $result       Outcome of the previous submission.
 * @var int               $max_places   Largest booking the service will accept.
 * @var string            $consent_text Wording to agree to, or '' when consent is not asked for.
 * @var \QuickEventsManager\CustomFields\Field[] $fields       Custom questions to ask, or an empty array.
 * @var array<string, string|string[]>          $field_values Previously submitted answers, keyed by field key.
 * @var array<string, string>                   $field_errors Messages, keyed by field key.
 */

use QuickEventsManager\Frontend\Templates;
use QuickEventsManager\Registration\FormHandler;

defined( 'ABSPATH' ) || exit;
?>
<div class="qevm-registration" id="qevm-registration">
	<h2 class="qevm-registration__heading"><?php esc_html_e( 'Register for this event', 'quick-events-manager' ); ?></h2>

	<?php if ( null !== $result ) : ?>
		<?php if ( 'success' === $result['status'] ) : ?>
			<p class="qevm-notice qevm-notice--success" role="status">
				<?php esc_html_e( 'You are registered. We have sent a confirmation to your email address.', 'quick-events-manager' ); ?>
			</p>
		<?php elseif ( 'waitlisted' === $result['status'] ) : ?>
			<p class="qevm-notice qevm-notice--info" role="status">
				<?php esc_html_e( 'This event is full, so you have been added to the waiting list. We will email you if a place becomes available.', 'quick-events-manager' ); ?>
			</p>
		<?php else : ?>
			<?php
			/*
			 * `tabindex="-1"` so the script can move focus here. A live region
			 * that is already in the page when it parses announces nothing —
			 * screen readers only speak a region that *changes* after load, and
			 * this arrives with the document. Moving focus to it is what makes
			 * the error heard, and it also puts the keyboard where the problem
			 * is instead of back at the top of the page.
			 */
			?>
			<div class="qevm-notice qevm-notice--error" role="alert" tabindex="-1"
				id="qevm-form-error" data-qevm-error-summary>
				<p>
					<?php
					echo '' !== $result['message']
						? esc_html( $result['message'] )
						: esc_html__( 'Your registration could not be completed. Please try again.', 'quick-events-manager' );
					?>
				</p>

				<?php if ( ! empty( $result['errors'] ) ) : ?>
					<ul class="qevm-notice__list">
						<?php foreach ( $result['errors'] as $qevm_field => $qevm_message ) : ?>
							<li>
								<?php if ( in_array( $qevm_field, array( 'name', 'email', 'phone', 'quantity', 'consent' ), true ) ) : ?>
									<a href="#qevm-<?php echo esc_attr( $qevm_field ); ?>"><?php echo esc_html( $qevm_message ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $qevm_message ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $is_full ) : ?>
		<p class="qevm-notice qevm-notice--info">
			<?php esc_html_e( 'This event is full. You can still join the waiting list below.', 'quick-events-manager' ); ?>
		</p>
	<?php elseif ( null !== $remaining && $remaining <= 10 ) : ?>
		<p class="qevm-registration__remaining">
			<?php
			printf(
				/* translators: %s: Number of places remaining. */
				esc_html( _n( 'Only %s place left.', 'Only %s places left.', $remaining, 'quick-events-manager' ) ),
				esc_html( number_format_i18n( $remaining ) )
			);
			?>
		</p>
	<?php endif; ?>

	<form class="qevm-registration__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-qevm-registration-form>
		<input type="hidden" name="action" value="qevm_register" />
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />
		<?php wp_nonce_field( FormHandler::NONCE . '_' . $event->id() ); ?>

		<?php $qevm_error = FormHandler::error( $result, 'name' ); ?>
		<p class="qevm-field">
			<label for="qevm-name"><?php esc_html_e( 'Name', 'quick-events-manager' ); ?> <span class="qevm-required" aria-hidden="true">*</span></label>
			<input type="text" id="qevm-name" name="qevm_name" required autocomplete="name"
				value="<?php echo esc_attr( (string) FormHandler::value( $result, 'name' ) ); ?>"
				<?php
				if ( '' !== $qevm_error ) {
					echo 'aria-invalid="true" aria-describedby="qevm-name-error"';
				}
				?>
				/>
			<?php if ( '' !== $qevm_error ) : ?>
				<span class="qevm-field__error" id="qevm-name-error"><?php echo esc_html( $qevm_error ); ?></span>
			<?php endif; ?>
		</p>

		<?php $qevm_error = FormHandler::error( $result, 'email' ); ?>
		<p class="qevm-field">
			<label for="qevm-email"><?php esc_html_e( 'Email', 'quick-events-manager' ); ?> <span class="qevm-required" aria-hidden="true">*</span></label>
			<input type="email" id="qevm-email" name="qevm_email" required autocomplete="email"
				value="<?php echo esc_attr( (string) FormHandler::value( $result, 'email' ) ); ?>"
				<?php
				if ( '' !== $qevm_error ) {
					echo 'aria-invalid="true" aria-describedby="qevm-email-error"';
				}
				?>
				/>
			<?php if ( '' !== $qevm_error ) : ?>
				<span class="qevm-field__error" id="qevm-email-error"><?php echo esc_html( $qevm_error ); ?></span>
			<?php endif; ?>
		</p>

		<p class="qevm-field">
			<label for="qevm-phone"><?php esc_html_e( 'Phone', 'quick-events-manager' ); ?></label>
			<input type="tel" id="qevm-phone" name="qevm_phone" autocomplete="tel"
				value="<?php echo esc_attr( (string) FormHandler::value( $result, 'phone' ) ); ?>" />
		</p>

		<?php if ( array() !== $dates ) : ?>
			<?php $qevm_error = FormHandler::error( $result, 'occurrence_id' ); ?>
			<p class="qevm-field">
				<label for="qevm-date"><?php esc_html_e( 'Which date', 'quick-events-manager' ); ?> <span class="qevm-required" aria-hidden="true">*</span></label>
				<select id="qevm-date" name="qevm_occurrence_id" required
					<?php
					if ( '' !== $qevm_error ) {
						echo 'aria-invalid="true" aria-describedby="qevm-date-error"';
					}
					?>
					>
					<option value=""><?php esc_html_e( 'Choose a date', 'quick-events-manager' ); ?></option>
					<?php foreach ( $dates as $qevm_date ) : ?>
						<option value="<?php echo esc_attr( (string) $qevm_date['id'] ); ?>"
							<?php selected( (string) $qevm_date['id'], (string) FormHandler::value( $result, 'occurrence_id' ) ); ?>>
							<?php
							/*
							 * A full date stays on the list and says so. It
							 * still takes bookings — they join the waiting list
							 * — and leaving it out would show somebody a gap in
							 * the weeks with no way to ask for a place.
							 */
							echo $qevm_date['full']
								? esc_html(
									sprintf(
										/* translators: %s: A date, e.g. "3 June 2026 6:00 pm". */
										__( '%s — full, join the waiting list', 'quick-events-manager' ),
										$qevm_date['label']
									)
								)
								: esc_html( $qevm_date['label'] );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $qevm_error ) : ?>
					<span class="qevm-field__error" id="qevm-date-error"><?php echo esc_html( $qevm_error ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( array() !== $ticket_types ) : ?>
			<?php $qevm_error = FormHandler::error( $result, 'ticket_type_id' ); ?>
			<fieldset class="qevm-field qevm-ticket-choice"
				<?php
				if ( '' !== $qevm_error ) {
					echo 'aria-invalid="true" aria-describedby="qevm-ticket-error"';
				}
				?>
				>
				<legend><?php esc_html_e( 'Which kind of place', 'quick-events-manager' ); ?> <span class="qevm-required" aria-hidden="true">*</span></legend>

				<?php foreach ( $ticket_types as $qevm_index => $qevm_ticket ) : ?>
					<?php $qevm_id = 'qevm-ticket-' . (int) $qevm_ticket['id']; ?>
					<p class="qevm-ticket-option<?php echo '' !== $qevm_ticket['opens'] ? ' qevm-ticket-option--waiting' : ''; ?>">
						<input type="radio" id="<?php echo esc_attr( $qevm_id ); ?>" name="qevm_ticket_type_id" required
							value="<?php echo esc_attr( (string) $qevm_ticket['id'] ); ?>"
							<?php disabled( '' !== $qevm_ticket['opens'] ); ?>
							<?php checked( (string) $qevm_ticket['id'], (string) FormHandler::value( $result, 'ticket_type_id' ) ); ?> />
						<label for="<?php echo esc_attr( $qevm_id ); ?>">
							<span class="qevm-ticket-option__name"><?php echo esc_html( $qevm_ticket['name'] ); ?></span>

							<?php if ( isset( $qevm_ticket['price'] ) && '' !== $qevm_ticket['price'] ) : ?>
								<span class="qevm-ticket-option__price"><?php echo esc_html( $qevm_ticket['price'] ); ?></span>
							<?php endif; ?>

							<?php if ( '' !== $qevm_ticket['description'] ) : ?>
								<span class="qevm-ticket-option__description"><?php echo esc_html( $qevm_ticket['description'] ); ?></span>
							<?php endif; ?>

							<?php
							/*
							 * Said only when it can be said truthfully. On an
							 * event with several dates, how many places are left
							 * of a kind depends on which date — and no date has
							 * been chosen yet, so nothing is claimed.
							 */
							?>
							<?php if ( '' !== $qevm_ticket['opens'] ) : ?>
								<span class="qevm-ticket-option__state">
									<?php
									printf(
										/* translators: %s: Date and time the sale opens. */
										esc_html__( 'On sale from %s', 'quick-events-manager' ),
										esc_html( $qevm_ticket['opens'] )
									);
									?>
								</span>
							<?php elseif ( $qevm_ticket['full'] ) : ?>
								<span class="qevm-ticket-option__state">
									<?php esc_html_e( 'Full — join the waiting list', 'quick-events-manager' ); ?>
								</span>
							<?php elseif ( null !== $qevm_ticket['remaining'] ) : ?>
								<span class="qevm-ticket-option__state">
									<?php
									printf(
										/* translators: %s: Number of places left. */
										esc_html( _n( '%s place left', '%s places left', (int) $qevm_ticket['remaining'], 'quick-events-manager' ) ),
										esc_html( number_format_i18n( (int) $qevm_ticket['remaining'] ) )
									);
									?>
								</span>
							<?php endif; ?>
						</label>
					</p>
				<?php endforeach; ?>

				<?php if ( '' !== $qevm_error ) : ?>
					<span class="qevm-field__error" id="qevm-ticket-error"><?php echo esc_html( $qevm_error ); ?></span>
				<?php endif; ?>
			</fieldset>
		<?php endif; ?>

		<p class="qevm-field">
			<label for="qevm-quantity"><?php esc_html_e( 'Number of places', 'quick-events-manager' ); ?></label>
			<input type="number" id="qevm-quantity" name="qevm_quantity" min="1"
				value="<?php echo esc_attr( (string) FormHandler::value( $result, 'quantity', 1 ) ); ?>"
				max="<?php echo esc_attr( (string) $max_places ); ?>" data-qevm-quantity />
		</p>

		<?php
		/*
		 * A name per further place. Position 1 is the booker, who has already
		 * given their name above, so these start at 2 and match the position
		 * shown against each person on the attendees screen.
		 *
		 * Every row is in the markup and hidden, rather than built by script.
		 * It keeps each label and each `for` attribute in PHP where they are
		 * translated and escaped like everything else, leaves the whole form
		 * overridable by a theme, and reduces the script to toggling an
		 * attribute.
		 *
		 * Hidden rows are also disabled, so a browser does not submit twenty
		 * empty names for a booking of one. The service ignores anything past
		 * the quantity regardless — this only keeps the request honest.
		 */
		?>
		<?php
		/*
		 * The custom questions, asked once of the person booking. Their own
		 * template, so a theme can restyle the questions without taking on the
		 * whole form.
		 */
		if ( array() !== $fields ) {
			$qevm_questions = Templates::render(
				'registration-fields.php',
				array(
					'fields'   => $fields,
					'position' => \QuickEventsManager\Registration\RegistrationService::ANSWER_POSITION,
					'values'   => $field_values,
					'errors'   => $field_errors,
				)
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- registration-fields.php escapes every value it prints.
			echo $qevm_questions;
		}
		?>

		<fieldset class="qevm-guests" data-qevm-guests hidden>
			<legend class="qevm-guests__legend"><?php esc_html_e( 'Who else is coming?', 'quick-events-manager' ); ?></legend>

			<p class="qevm-guests__hint" id="qevm-guests-hint">
				<?php esc_html_e( 'Your own place is the first one. Names are optional — leave a place blank if you do not know yet who is taking it.', 'quick-events-manager' ); ?>
			</p>

			<?php for ( $qevm_position = 2; $qevm_position <= $max_places; $qevm_position++ ) : ?>
				<p class="qevm-field qevm-guests__row" data-qevm-guest hidden>
					<label for="qevm-guest-<?php echo esc_attr( (string) $qevm_position ); ?>">
						<?php
						printf(
							/* translators: %s: Position of the place within the booking, counting from 2. */
							esc_html__( 'Name of guest %s', 'quick-events-manager' ),
							esc_html( number_format_i18n( $qevm_position ) )
						);
						?>
					</label>
					<?php $qevm_guests = (array) FormHandler::value( $result, 'guests', array() ); ?>
					<input type="text" id="qevm-guest-<?php echo esc_attr( (string) $qevm_position ); ?>"
						name="qevm_guest_name[<?php echo esc_attr( (string) $qevm_position ); ?>]"
						value="<?php echo esc_attr( isset( $qevm_guests[ $qevm_position ] ) ? (string) $qevm_guests[ $qevm_position ] : '' ); ?>"
						aria-describedby="qevm-guests-hint" autocomplete="off" disabled />
				</p>
			<?php endfor; ?>
		</fieldset>

		<?php
		/*
		 * Outside the fieldset on purpose: the fieldset is hidden until the
		 * script reveals it, so a message inside it would be one nobody without
		 * a script could read.
		 */
		?>
		<noscript>
			<p class="qevm-guests__hint">
				<?php esc_html_e( 'Guest names need JavaScript. You can still book the places you need, and tell the organiser who is coming afterwards.', 'quick-events-manager' ); ?>
			</p>
		</noscript>

		<?php
		/*
		 * Honeypot. Hidden from people with CSS and from screen readers with
		 * aria-hidden, but visible to the naive bots that fill in every field
		 * they find.
		 */
		?>
		<p class="qevm-honeypot" aria-hidden="true">
			<label for="qevm-website"><?php esc_html_e( 'Leave this field empty', 'quick-events-manager' ); ?></label>
			<input type="text" id="qevm-website" name="qevm_website" tabindex="-1" autocomplete="off" />
		</p>

		<?php
		/*
		 * The consent notice is the site's own wording, so it is a described-by
		 * span rather than the label itself. A privacy policy link inside a
		 * label is a link that fights the checkbox for the click, and the
		 * wording is the part that has to be readable — putting it in the
		 * description means a screen reader announces the checkbox, that it is
		 * required, and then the whole notice.
		 */
		?>
		<?php if ( '' !== $consent_text ) : ?>
			<p class="qevm-field qevm-field--consent">
				<label for="qevm-consent">
					<input type="checkbox" id="qevm-consent" name="qevm_consent" value="1" required
						aria-describedby="qevm-consent-text" />
					<?php esc_html_e( 'I agree', 'quick-events-manager' ); ?>
					<span class="qevm-required" aria-hidden="true">*</span>
				</label>
				<span class="qevm-consent__text" id="qevm-consent-text">
					<?php echo wp_kses( $consent_text, \QuickEventsManager\Privacy\Consent::allowed_html() ); ?>
				</span>
			</p>
		<?php endif; ?>

		<p class="qevm-field qevm-field--submit">
			<button type="submit" class="qevm-button">
				<?php
				echo $is_full
					? esc_html__( 'Join the waiting list', 'quick-events-manager' )
					: esc_html__( 'Register', 'quick-events-manager' );
				?>
			</button>
		</p>
	</form>
</div>
