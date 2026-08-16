<?php
/**
 * CSV export of an event's attendees.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\CustomFields\AnswerRepository;
use QuickEventsManager\CustomFields\CustomFieldsModule;
use QuickEventsManager\CustomFields\Definitions;
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
	 * Request argument asking for the sensitive answers as well.
	 */
	const SENSITIVE_ARG = 'qevm_include_sensitive';

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

		/*
		 * Sensitive answers are left out unless this request asks for them.
		 *
		 * A per-export choice rather than a stored setting, and that is the
		 * whole design. A setting is ticked once, by somebody who needed the
		 * data that afternoon, and stays ticked for every export anybody makes
		 * afterwards. A file of dietary requirements and access needs is a file
		 * of health information about named people, and it gets emailed to a
		 * caterer, carried on a laptop and left in a downloads folder. Deciding
		 * each time is the only version of this that keeps meaning something.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() above.
		$include_sensitive = ! empty( $_GET[ self::SENSITIVE_ARG ] );

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

		self::write( $out, $event_id, $include_sensitive );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://output handle opened above.
		fclose( $out );

		exit;
	}

	/**
	 * Write the whole CSV to an open stream.
	 *
	 * Split from handle() so it can be tested. handle() reads $_GET, checks a
	 * capability and a nonce, sends headers and ends in exit() — none of which
	 * a test can call, and exit() in particular cannot be caught. Everything
	 * that decides what the file contains therefore lived somewhere no test
	 * could reach, which is how a column of health information gets into an
	 * export nobody meant it to be in.
	 *
	 * The capability and nonce stay in handle(), deliberately: they are facts
	 * about the request rather than about the file, and a method that took
	 * "is this allowed" as an argument is one somebody can call with `true`.
	 *
	 * @since 26.0
	 *
	 * @param resource $out               Open stream to write to.
	 * @param int      $event_id          Event id.
	 * @param bool     $include_sensitive Whether to include answers marked sensitive.
	 * @return void
	 */
	public static function write( $out, $event_id, $include_sensitive = false ) {

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

		$fields = self::exported_fields( $event_id, $include_sensitive );

		$headers = array(
			__( 'Name', 'quick-events-manager' ),
			__( 'Email', 'quick-events-manager' ),
			__( 'Phone', 'quick-events-manager' ),
			__( 'Places', 'quick-events-manager' ),
			__( 'Reference', 'quick-events-manager' ),
			__( 'Status', 'quick-events-manager' ),
			__( 'Registered (UTC)', 'quick-events-manager' ),
		);

		foreach ( $fields as $field ) {
			$headers[] = self::defuse( $field->label() );
		}

		fputcsv( $out, $headers );

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

			$answers = array() === $fields
				? array()
				: AnswerRepository::for_registrations(
					array_map( static fn ( $registration ) => $registration->id(), $rows )
				);

			foreach ( $rows as $registration ) {
				$line = array(
					self::defuse( $registration->booker_name() ),
					self::defuse( $registration->booker_email() ),
					self::defuse( $registration->booker_phone() ),
					$registration->quantity(),
					$registration->code(),
					$registration->status()->label(),
					$registration->created_at(),
				);

				$given = isset( $answers[ $registration->id() ] ) ? $answers[ $registration->id() ] : array();

				foreach ( $fields as $field ) {
					$value = isset( $given[ $field->key() ] ) ? $given[ $field->key() ] : '';

					$line[] = self::defuse( is_array( $value ) ? implode( '; ', $value ) : (string) $value );
				}

				fputcsv( $out, $line );
			}

			++$page;
		} while ( $fetched === $page_size );
	}

	/**
	 * The questions whose answers belong in this export.
	 *
	 * Sensitive ones are left out unless the request asked for them. The flag
	 * exists because dietary requirements and access needs are the two
	 * questions every event asks, and both say something about a named person's
	 * health — so the default for a file that leaves the building is to leave
	 * them behind.
	 *
	 * @since 26.0
	 *
	 * @param int  $event_id          Event id.
	 * @param bool $include_sensitive Whether the request asked for sensitive answers.
	 * @return \QuickEventsManager\CustomFields\Field[]
	 */
	private static function exported_fields( $event_id, $include_sensitive ) {
		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( CustomFieldsModule::ID ) ) {
			return array();
		}

		$fields = Definitions::for_event( $event_id );

		if ( $include_sensitive ) {
			return $fields;
		}

		return array_values(
			array_filter(
				$fields,
				static function ( $field ) {
					return ! $field->is_sensitive();
				}
			)
		);
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
