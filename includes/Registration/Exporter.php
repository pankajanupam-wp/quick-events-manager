<?php
/**
 * CSV export of an event's attendees.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Events\Event;

use QuickEventsManager\Domain\RegistrationStatus;

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
	const NONCE = 'qevm_export_registrations';

	/**
	 * Hook into admin-post.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_qevm_export_registrations', array( $this, 'handle' ) );
	}

	/**
	 * Send the file.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
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
		 *
		 * WP_Filesystem is not an alternative here, and the sniff suggesting it
		 * is reading the call rather than the stream. This writes to
		 * php://output — the response body being streamed to the browser, not a
		 * file on disk. WP_Filesystem abstracts over FTP and SSH transports for
		 * writing files into the WordPress install; it has no concept of the
		 * current response, and routing a download through it would mean
		 * buffering the whole export in memory first, which is the one thing
		 * paging through 500 rows at a time exists to avoid.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming a download to php://output; see above.
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

		$page      = 1;
		$page_size = 500;

		do {
			$rows = Repository::for_event(
				$event_id,
				array(
					'per_page' => $page_size,
					'page'     => $page,
					'orderby'  => 'created_at',
					'order'    => 'ASC',
				)
			);

			$fetched = count( $rows );

			foreach ( $rows as $registration ) {
				fputcsv(
					$out,
					array(
						self::defuse( $registration->name() ),
						self::defuse( $registration->email() ),
						self::defuse( $registration->phone() ),
						$registration->quantity(),
						$registration->code(),
						$registration->status()->label(),
						$registration->created_at(),
					)
				);
			}

			++$page;
		} while ( $fetched === $page_size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://output handle opened above.
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
