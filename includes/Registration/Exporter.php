<?php
/**
 * CSV export of an event's attendees.
 *
 * @package QuickEventsManager
 */

namespace QEM\Registration;

use QEM\Events\Event;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the attendee list as a CSV download.
 *
 * @since 26.0
 */
final class Exporter {

	/**
	 * Nonce action.
	 */
	const NONCE = 'qem_export_registrations';

	/**
	 * Hook into admin-post.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_qem_export_registrations', array( $this, 'handle' ) );
	}

	/**
	 * Send the file.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_qem_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to export attendees.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::NONCE );

		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$event    = new Event( $event_id );

		if ( ! $event->is_valid() ) {
			wp_die( esc_html__( 'That event could not be found.', 'quick-events-manager' ) );
		}

		$filename = sanitize_file_name(
			sprintf( '%s-attendees-%s.csv', get_post_field( 'post_name', $event_id ), gmdate( 'Y-m-d' ) )
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		/*
		 * A UTF-8 byte order mark. Excel on Windows assumes the system code
		 * page without it and mangles every non-ASCII name in the file, which
		 * is exactly the case an event organiser hits first.
		 */
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv(
			$out,
			array(
				__( 'Name', 'quick-events-manager' ),
				__( 'Email', 'quick-events-manager' ),
				__( 'Phone', 'quick-events-manager' ),
				__( 'Places', 'quick-events-manager' ),
				__( 'Reference', 'quick-events-manager' ),
				__( 'Status', 'quick-events-manager' ),
				__( 'Registered (UTC)', 'quick-events-manager' ),
			)
		);

		$page = 1;

		do {
			$rows = Repository::for_event(
				$event_id,
				array(
					'per_page' => 500,
					'page'     => $page,
					'orderby'  => 'created_at',
					'order'    => 'ASC',
				)
			);

			foreach ( $rows as $registration ) {
				fputcsv(
					$out,
					array(
						self::defuse( $registration->name() ),
						self::defuse( $registration->email() ),
						self::defuse( $registration->phone() ),
						$registration->quantity(),
						$registration->code(),
						Registration::status_label( $registration->status() ),
						$registration->created_at(),
					)
				);
			}

			++$page;
		} while ( count( $rows ) === 500 );

		fclose( $out );

		exit;
	}

	/**
	 * Stop a spreadsheet treating a field as a formula.
	 *
	 * A value beginning =, +, - or @ is executed as a formula when the CSV is
	 * opened, which turns an attendee's name field into code running on the
	 * organiser's machine. Prefixing a tab makes the cell inert while leaving
	 * the text readable.
	 *
	 * @since 26.0
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function defuse( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		return in_array( $value[0], array( '=', '+', '-', '@' ), true ) ? "\t" . $value : $value;
	}
}
