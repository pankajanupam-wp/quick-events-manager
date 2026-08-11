<?php
/**
 * GDPR export and erasure for attendee data.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Privacy;

use QuickEventsManager\Registration\Registration;
use QuickEventsManager\Registration\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs registrations into WordPress's own privacy tools.
 *
 * The plugin stores names, email addresses and phone numbers, so a site owner
 * handed a subject access request has to be able to answer it. Registering
 * here means the existing Tools > Export/Erase Personal Data screens cover
 * event registrations with no extra work and no separate interface.
 *
 * @since 26.0
 */
final class Privacy {

	/**
	 * Registrations handled per page of a request.
	 */
	const PER_PAGE = 100;

	/**
	 * Hook into the privacy tools.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @since 26.0
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ) {
		$exporters['quick-events-manager'] = array(
			'exporter_friendly_name' => __( 'Event registrations', 'quick-events-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @since 26.0
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ) {
		$erasers['quick-events-manager'] = array(
			'eraser_friendly_name' => __( 'Event registrations', 'quick-events-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export every registration made with an email address.
	 *
	 * @since 26.0
	 *
	 * @param string $email_address Address being exported.
	 * @param int    $page          One-based page number.
	 * @return array
	 */
	public function export( $email_address, $page = 1 ) {
		$registrations = Repository::find_by_email( $email_address );
		$page          = max( 1, (int) $page );
		$slice         = array_slice( $registrations, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$items         = array();

		foreach ( $slice as $registration ) {
			$items[] = array(
				'group_id'    => 'qevm_registrations',
				'group_label' => __( 'Event registrations', 'quick-events-manager' ),
				'item_id'     => 'qevm-registration-' . $registration->id(),
				'data'        => array(
					array(
						'name'  => __( 'Event', 'quick-events-manager' ),
						'value' => get_the_title( $registration->event_id() ),
					),
					array(
						'name'  => __( 'Name', 'quick-events-manager' ),
						'value' => $registration->name(),
					),
					array(
						'name'  => __( 'Email', 'quick-events-manager' ),
						'value' => $registration->email(),
					),
					array(
						'name'  => __( 'Phone', 'quick-events-manager' ),
						'value' => $registration->phone(),
					),
					array(
						'name'  => __( 'Places', 'quick-events-manager' ),
						'value' => $registration->quantity(),
					),
					array(
						'name'  => __( 'Reference', 'quick-events-manager' ),
						'value' => $registration->code(),
					),
					array(
						'name'  => __( 'Status', 'quick-events-manager' ),
						'value' => Registration::status_label( $registration->status() ),
					),
					array(
						'name'  => __( 'Registered', 'quick-events-manager' ),
						'value' => $registration->created_at(),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => ( $page * self::PER_PAGE ) >= count( $registrations ),
		);
	}

	/**
	 * Erase every registration made with an email address.
	 *
	 * Rows are deleted outright rather than anonymised. A registration with
	 * the personal details stripped is just a row nobody can act on, and
	 * keeping it would mean the erasure was not really an erasure.
	 *
	 * @since 26.0
	 *
	 * @param string $email_address Address being erased.
	 * @param int    $page          One-based page number.
	 * @return array
	 */
	public function erase( $email_address, $page = 1 ) {
		$registrations = Repository::find_by_email( $email_address );
		$removed       = 0;

		foreach ( array_slice( $registrations, 0, self::PER_PAGE ) as $registration ) {
			if ( Repository::delete( $registration->id() ) ) {
				++$removed;
			}
		}

		$messages = array();

		if ( $removed > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: Number of registrations removed. */
				_n(
					'Removed %d event registration.',
					'Removed %d event registrations.',
					$removed,
					'quick-events-manager'
				),
				$removed
			);
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => count( $registrations ) <= self::PER_PAGE,
		);
	}

	/**
	 * Suggest privacy policy wording.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'When you register for an event on this site, we store the name, email address, phone number and number of places you enter, so we know who is attending.', 'quick-events-manager' ) . '</p>'
			. '<p>' . __( 'We do not store your IP address, and we do not share this information with any external service.', 'quick-events-manager' ) . '</p>'
			. '<p>' . __( 'Registrations are kept until the site owner deletes them.', 'quick-events-manager' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Quick Events Manager', 'quick-events-manager' ), wp_kses_post( $content ) );
	}
}
