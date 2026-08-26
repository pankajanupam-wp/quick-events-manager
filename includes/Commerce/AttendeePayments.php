<?php
/**
 * What was paid, on the screen where the organiser is already looking.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * A payment column on the attendee screen, and the refund button in it.
 *
 * **Not a separate orders screen**, at least not yet. The question "did this
 * person pay, and can I give it back" is asked while looking at the attendee
 * list, and a second screen listing the same bookings by their money would mean
 * two places to look and two places to keep in step. Reconciliation across
 * events is a different question, and the report that answers it belongs to
 * whoever asks for it rather than to a guess made now.
 *
 * The column appears only when the event actually has orders against it, on the
 * same principle as the date and ticket columns beside it: a column that says
 * the same nothing on every row costs the ones that say something.
 *
 * @since 26.0
 */
final class AttendeePayments {

	/**
	 * The admin-post action that issues a refund.
	 */
	const REFUND_ACTION = 'qevm_refund';

	/**
	 * Nonce for it.
	 */
	const REFUND_NONCE = 'qevm_refund';

	/**
	 * Add the hooks.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'qevm_attendee_columns', array( __CLASS__, 'add_column' ), 10, 2 );
		add_filter( 'qevm_attendee_column', array( __CLASS__, 'render_cell' ), 10, 3 );

		add_action( 'admin_post_' . self::REFUND_ACTION, array( __CLASS__, 'handle_refund' ) );
	}

	/**
	 * Offer the column, when there is anything to put in it.
	 *
	 * @since 26.0
	 *
	 * @param mixed $columns  Columns so far.
	 * @param mixed $event_id The event.
	 * @return array<string, string>
	 */
	public static function add_column( $columns, $event_id ) {
		$columns = is_array( $columns ) ? $columns : array();

		if ( array() === OrderRepository::for_event( (int) $event_id, 1 ) ) {
			return $columns;
		}

		$columns['payment'] = __( 'Payment', 'quick-events-manager' );

		return $columns;
	}

	/**
	 * One cell.
	 *
	 * @since 26.0
	 *
	 * @param mixed $cell         Markup so far.
	 * @param mixed $column       Column id.
	 * @param mixed $registration The booking.
	 * @return string
	 */
	public static function render_cell( $cell, $column, $registration ) {
		if ( 'payment' !== $column || ! is_object( $registration ) ) {
			return (string) $cell;
		}

		$order = OrderRepository::find( (int) $registration->get( 'order_id', 0 ) );

		if ( null === $order ) {
			return '<span class="qevm-payment qevm-payment--none">' . esc_html__( 'Free', 'quick-events-manager' ) . '</span>';
		}

		$total    = Money::from_minor( $order->total_minor(), $order->currency() );
		$refunded = Money::from_minor( $order->refunded_minor(), $order->currency() );

		$markup  = '<span class="qevm-payment__amount">' . esc_html( $total->format() ) . '</span> ';
		$markup .= '<span class="qevm-payment__status">' . esc_html( $order->status()->label() ) . '</span>';

		if ( ! $refunded->is_zero() ) {
			$markup .= '<br /><span class="qevm-payment__refunded">' . esc_html(
				sprintf(
					/* translators: %s: Amount refunded. */
					__( '%s refunded', 'quick-events-manager' ),
					$refunded->format()
				)
			) . '</span>';
		}

		$markup .= self::refund_form( $order );

		return $markup;
	}

	/**
	 * The refund control, when there is something left to refund.
	 *
	 * An amount field rather than a plain button, because a partial refund is
	 * an ordinary thing — a late arrival charged the full price, a session
	 * missed — and a screen that only does all-or-nothing sends somebody to the
	 * Stripe dashboard, where the ledger here will never hear about it.
	 *
	 * @since 26.0
	 *
	 * @param Order $order The order.
	 * @return string
	 */
	private static function refund_form( Order $order ): string {
		$remaining = $order->total_minor() - $order->refunded_minor();

		if ( ! $order->status()->is_settled() || $remaining <= 0 || ! current_user_can( 'manage_qevm_registrations' ) ) {
			return '';
		}

		$left = Money::from_minor( $remaining, $order->currency() );

		$markup  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="qevm-refund-form">';
		$markup .= '<input type="hidden" name="action" value="' . esc_attr( self::REFUND_ACTION ) . '" />';
		$markup .= '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->id() ) . '" />';
		$markup .= wp_nonce_field( self::REFUND_NONCE, '_wpnonce', true, false );

		$markup .= '<label class="screen-reader-text" for="qevm-refund-' . esc_attr( (string) $order->id() ) . '">'
			. esc_html__( 'Amount to refund', 'quick-events-manager' )
			. '</label>';

		$markup .= '<input type="text" inputmode="decimal" size="6" name="amount" '
			. 'id="qevm-refund-' . esc_attr( (string) $order->id() ) . '" '
			. 'placeholder="' . esc_attr( $left->format( false ) ) . '" />';

		$markup .= ' <button type="submit" class="button button-small">' . esc_html__( 'Refund', 'quick-events-manager' ) . '</button>';
		$markup .= '</form>';

		return $markup;
	}

	/**
	 * Issue a refund.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function handle_refund() {
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to refund a booking.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::REFUND_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$typed = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';

		$order = OrderRepository::find( $order_id );

		if ( null === $order ) {
			self::go_back( 0, 'error', __( 'That order could not be found.', 'quick-events-manager' ) );
		}

		/*
		 * An empty box means "all of it". Somebody who wants the whole thing
		 * back should not have to retype a figure that is already on the
		 * screen, and retyping it is how a digit gets dropped.
		 */
		$amount = '' === trim( $typed )
			? 0
			: Money::from_major( str_replace( ',', '', $typed ), $order->currency() )->minor();

		$done = Refunds::issue( $order->id(), $amount, __( 'Refunded by the organiser', 'quick-events-manager' ) );

		$event_id = self::event_for( $order );

		if ( is_wp_error( $done ) ) {
			self::go_back( $event_id, 'error', $done->get_error_message() );
		}

		self::go_back( $event_id, 'success', __( 'Refunded.', 'quick-events-manager' ) );
	}

	/**
	 * Which event's attendee screen to go back to.
	 *
	 * @since 26.0
	 *
	 * @param Order $order The order.
	 * @return int
	 */
	private static function event_for( Order $order ): int {
		$lines = OrderItemRepository::for_order( $order->id() );

		return array() !== $lines ? $lines[0]->event_id() : 0;
	}

	/**
	 * Back to the attendee screen with something to say.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $status   'success' or 'error'.
	 * @param string $message  What happened.
	 * @return void
	 */
	private static function go_back( $event_id, $status, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'qevm-attendees',
					'event_id'     => (int) $event_id,
					'qevm_result'  => $status,
					'qevm_message' => rawurlencode( $message ),
				),
				admin_url( 'edit.php?post_type=' . QEVM_POST_TYPE )
			)
		);

		exit;
	}
}
