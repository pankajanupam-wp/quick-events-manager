<?php
/**
 * The email queue, and every query against it.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

use QuickEventsManager\Domain\EmailStatus;
use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * One row per message per recipient, waiting to be sent.
 *
 * `wp_mail()` is synchronous. Sending to five hundred people inside the request
 * that pressed the button means five hundred SMTP conversations before the page
 * responds, and the request dies somewhere in the middle — having sent an
 * unknowable number of them, with no record of which.
 *
 * A row per recipient rather than a row per message with a list of addresses,
 * because the question that gets asked afterwards is always "did *this person*
 * get it", and because one bad address should not take the other four hundred
 * and ninety-nine down with it.
 *
 * **The body is rendered when the message is queued, not when it is sent.** An
 * email says what it said when it was triggered, even if somebody edits the
 * template ten minutes later while the queue is still draining.
 *
 * @since 26.0
 */
final class Queue {

	/**
	 * How many attempts a message gets before it is given up on.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Minutes to wait before retrying, indexed by attempts already made.
	 *
	 * Backing off rather than retrying immediately. The usual reason a send
	 * fails is that the mail host is rate-limiting or briefly down, and hammering
	 * it is how a site's address ends up blocked.
	 *
	 * @var int[]
	 */
	const BACKOFF_MINUTES = array( 5, 30, 120 );

	/**
	 * Minutes after which a claimed message is considered abandoned.
	 *
	 * A worker that dies mid-send leaves its row in `sending` for ever
	 * otherwise. Long enough that a slow SMTP conversation is not mistaken for a
	 * crash, short enough that a confirmation is not stuck for an afternoon.
	 */
	const CLAIM_TIMEOUT_MINUTES = 15;

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'email_queue' );
	}

	/**
	 * Whether the table has been created.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Establishing whether a table exists cannot be answered from cache.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Add one message for one recipient.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $message Message parts.
	 * @return int New id, or 0 if it could not be queued.
	 */
	public static function add( array $message ) {
		global $wpdb;

		$recipient = isset( $message['recipient'] ) ? sanitize_email( (string) $message['recipient'] ) : '';

		if ( ! self::table_exists() || '' === $recipient || ! is_email( $recipient ) ) {
			return 0;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; there is no core API for it.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'template'      => isset( $message['template'] ) ? (string) $message['template'] : '',
				'recipient'     => $recipient,
				'subject'       => isset( $message['subject'] ) ? (string) $message['subject'] : '',
				'body'          => isset( $message['body'] ) ? (string) $message['body'] : '',
				'headers'       => isset( $message['headers'] ) ? (string) wp_json_encode( (array) $message['headers'] ) : null,
				'context_type'  => isset( $message['context_type'] ) ? (string) $message['context_type'] : '',
				'context_id'    => isset( $message['context_id'] ) ? (int) $message['context_id'] : 0,
				'status'        => EmailStatus::Pending->value,
				'attempts'      => 0,
				'scheduled_for' => isset( $message['scheduled_for'] ) ? (string) $message['scheduled_for'] : $now,
				'created_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		$id = $inserted ? (int) $wpdb->insert_id : 0;

		if ( $id > 0 ) {
			/**
			 * Fires after a message has been added to the queue.
			 *
			 * @since 26.0
			 *
			 * @param int                  $id      Queue id.
			 * @param array<string, mixed> $message Message as it was queued.
			 */
			do_action( 'qevm_email_queued', $id, $message );
		}

		return $id;
	}

	/**
	 * Claim up to a number of messages for sending.
	 *
	 * The claim is the whole reason this method exists rather than a plain
	 * SELECT. Two workers can run at once — a cron tick and a second one fired
	 * by a page load — and both would otherwise read the same pending rows and
	 * send every message twice. A duplicate confirmation is not a cosmetic
	 * problem: it is the same booking reference arriving twice, and the
	 * recipient has no way to tell which is real.
	 *
	 * So the claim is an UPDATE with the status in its own WHERE clause. MySQL
	 * applies it to each row once, so exactly one worker moves a given row out
	 * of `pending`, whoever else is looking at it. The claimed ids are read back
	 * by the marker written into `last_error`, which is the only column free to
	 * carry one.
	 *
	 * `scheduled_for` is pushed forward as part of the claim, which is what
	 * makes an abandoned row recoverable: a worker that dies leaves the row in
	 * `sending`, and after the timeout it becomes eligible again.
	 *
	 * @since 26.0
	 *
	 * @param int $limit How many to take.
	 * @return array<int, array<string, mixed>> Claimed rows.
	 */
	public static function claim( $limit ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$limit = max( 1, (int) $limit );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$token = 'claim:' . wp_generate_password( 20, false, false );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; a cached answer to "what is unsent" is the wrong answer.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				 SET status = %s,
				     attempts = attempts + 1,
				     last_error = %s,
				     scheduled_for = %s
				 WHERE status IN ( %s, %s )
				   AND scheduled_for <= %s
				 ORDER BY scheduled_for ASC, id ASC
				 LIMIT %d',
				$table,
				EmailStatus::Sending->value,
				$token,
				gmdate( 'Y-m-d H:i:s', time() + ( self::CLAIM_TIMEOUT_MINUTES * MINUTE_IN_SECONDS ) ),
				EmailStatus::Pending->value,
				EmailStatus::Sending->value,
				$now,
				$limit
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE last_error = %s ORDER BY id ASC', $table, $token ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return (array) $rows;
	}

	/**
	 * Record that a message went out.
	 *
	 * @since 26.0
	 *
	 * @param int $id Queue id.
	 * @return void
	 */
	public static function mark_sent( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			self::table(),
			array(
				'status'     => EmailStatus::Sent->value,
				'sent_at'    => gmdate( 'Y-m-d H:i:s' ),
				'last_error' => null,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record that a send failed, and decide whether to try again.
	 *
	 * @since 26.0
	 *
	 * @param int    $id       Queue id.
	 * @param int    $attempts Attempts made so far, including this one.
	 * @param string $error    What went wrong.
	 * @return void
	 */
	public static function mark_failed( $id, $attempts, $error ) {
		global $wpdb;

		$attempts = (int) $attempts;
		$give_up  = $attempts >= self::MAX_ATTEMPTS;

		$backoff = self::BACKOFF_MINUTES;
		$wait    = isset( $backoff[ $attempts - 1 ] ) ? $backoff[ $attempts - 1 ] : (int) end( $backoff );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			self::table(),
			array(
				'status'        => $give_up ? EmailStatus::Failed->value : EmailStatus::Pending->value,
				'last_error'    => mb_substr( (string) $error, 0, 500 ),
				'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() + ( (int) $wait * MINUTE_IN_SECONDS ) ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * How many messages are in each state.
	 *
	 * @since 26.0
	 *
	 * @return array<string, int>
	 */
	public static function counts() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; a count of what is outstanding must not come from cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', self::table() ),
			ARRAY_A
		);

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Everything queued against one thing, newest first.
	 *
	 * @since 26.0
	 *
	 * @param string $type Context type, e.g. `registration`.
	 * @param int    $id   Context id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_context( $type, $id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE context_type = %s AND context_id = %d ORDER BY id DESC',
				self::table(),
				(string) $type,
				(int) $id
			),
			ARRAY_A
		);
	}

	/**
	 * Withdraw everything still queued against one thing.
	 *
	 * @since 26.0
	 *
	 * @param string $type Context type.
	 * @param int    $id   Context id.
	 * @return int Rows cancelled.
	 */
	public static function cancel_for_context( $type, $id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s WHERE context_type = %s AND context_id = %d AND status IN ( %s, %s )',
				self::table(),
				EmailStatus::Cancelled->value,
				(string) $type,
				(int) $id,
				EmailStatus::Pending->value,
				EmailStatus::Sending->value
			)
		);
	}

	/**
	 * The schema, for dbDelta().
	 *
	 * @since 26.0
	 *
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	public static function schema() {
		$table   = self::table();
		$collate = Installer::charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template varchar(64) NOT NULL DEFAULT '',
			recipient varchar(190) NOT NULL,
			subject text NOT NULL,
			body longtext NOT NULL,
			headers text DEFAULT NULL,
			context_type varchar(40) NOT NULL DEFAULT '',
			context_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_error text DEFAULT NULL,
			scheduled_for datetime NOT NULL,
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_scheduled (status, scheduled_for),
			KEY context (context_type, context_id)
		) {$collate};";
	}
}
