<?php
/**
 * The cron worker that drains the email queue.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Sends what is queued, a batch at a time, within a time budget.
 *
 * The same shape as the migration runner and the retention sweep: claim a
 * batch, work until the budget is gone, leave the rest for the next tick. The
 * budget is short because WP-Cron runs inside somebody's page load, and a
 * worker that spends ninety seconds sending mail is ninety seconds a visitor
 * spends looking at a blank tab.
 *
 * @since 26.0
 */
final class Worker {

	/**
	 * Cron hook.
	 */
	const HOOK = 'qevm_process_email_queue';

	/**
	 * Messages claimed per batch.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Seconds of sending to attempt in one run.
	 *
	 * Deliberately short, for the reason in the class docblock. Five hundred
	 * messages take several ticks, which is exactly what a queue is for.
	 */
	const TIME_BUDGET = 15;

	/**
	 * Seconds from now that a nudge asks for.
	 */
	const NUDGE_DELAY = 10;

	/**
	 * Hook the worker up.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_on_cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );

		/*
		 * Nudge the queue as soon as something is added, rather than waiting up
		 * to five minutes for the next tick. A registration confirmation that
		 * arrives quarter of an hour after somebody signed up reads as broken,
		 * however correct the queue is being.
		 */
		add_action( 'qevm_email_queued', array( __CLASS__, 'schedule_soon' ) );
	}

	/**
	 * Keep the recurring tick on the schedule.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'qevm_five_minutes', self::HOOK );
		}
	}

	/**
	 * Ask for a run in the near future.
	 *
	 * Fired once per message queued, which for a single registration is twice
	 * and for a broadcast to five hundred attendees is five hundred times. So
	 * the guard matters: with a run already imminent there is nothing to ask
	 * for, and asking anyway means five hundred round trips through the cron
	 * option for one scheduled event.
	 *
	 * The guard used to test `wp_next_scheduled( self::HOOK . '_soon' )` — a
	 * hook name nothing ever schedules, so it was always false and never
	 * guarded anything. Core's own duplicate check inside
	 * `wp_schedule_single_event()` kept that from producing five hundred cron
	 * entries, which is why it went unnoticed: the bug was invisible until
	 * something queued in bulk.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function schedule_soon() {
		$next = wp_next_scheduled( self::HOOK );

		if ( $next && $next <= time() + MINUTE_IN_SECONDS ) {
			return;
		}

		wp_schedule_single_event( time() + self::NUDGE_DELAY, self::HOOK );
	}

	/**
	 * Take the worker off the schedule.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function unschedule() {
		$scheduled = wp_next_scheduled( self::HOOK );

		while ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::HOOK );

			$scheduled = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Add the five-minute interval WP-Cron does not ship with.
	 *
	 * `$schedules` is typed loosely on purpose. Core hands this filter an array,
	 * and by the time it reaches here any other plugin may have filtered it
	 * first — several well-known ones return something else entirely. Declaring
	 * the type core promises would let static analysis call the check below
	 * redundant and invite somebody to delete it, at which point one badly
	 * behaved plugin fatals every request that touches cron.
	 *
	 * @since 26.0
	 *
	 * @param mixed $schedules Known schedules, whatever earlier filters left.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_interval( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules['qevm_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'quick-events-manager' ),
		);

		return $schedules;
	}

	/**
	 * Run from cron, discarding the count.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function run_on_cron() {
		self::run();
	}

	/**
	 * Send what is due, until the batch or the budget runs out.
	 *
	 * @since 26.0
	 *
	 * @param int $budget Seconds to spend. Defaults to TIME_BUDGET.
	 * @return array{sent: int, failed: int}
	 */
	public static function run( $budget = 0 ) {
		$deadline = microtime( true ) + ( $budget > 0 ? (int) $budget : self::TIME_BUDGET );
		$sent     = 0;
		$failed   = 0;

		while ( microtime( true ) < $deadline ) {
			$batch = Queue::claim( self::BATCH_SIZE );

			if ( array() === $batch ) {
				break;
			}

			foreach ( $batch as $row ) {
				if ( self::deliver( $row ) ) {
					++$sent;
				} else {
					++$failed;
				}

				/*
				 * Checked inside the loop as well as outside it. A batch of
				 * twenty slow sends would otherwise run well past the budget,
				 * because the outer check only happens once a batch is done.
				 */
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
		}

		return array(
			'sent'   => $sent,
			'failed' => $failed,
		);
	}

	/**
	 * Hand one message to `wp_mail()` and record what happened.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Queue row.
	 * @return bool Whether it went.
	 */
	private static function deliver( array $row ) {
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'];
		$headers  = array();

		if ( ! empty( $row['headers'] ) ) {
			$decoded = json_decode( (string) $row['headers'], true );
			$headers = is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Filters the files attached to one queued message.
		 *
		 * `wp_mail()` takes attachments as **file paths**, and everything this
		 * plugin attaches exists only as a string. Writing one to a temporary
		 * file to hand back a path means finding a writable directory on a host
		 * that may not have one, guessing at a unique name, and deleting it
		 * afterwards on a code path that can exit early — three ways to leave
		 * rubbish in the uploads folder for the sake of a 400-byte text file. So
		 * it goes on through PHPMailer directly, with the listener added
		 * immediately before the send and removed immediately after in a
		 * `finally`: `phpmailer_init` fires for **every** message the site
		 * sends, and left attached it would staple an event's calendar file to
		 * password resets and every other plugin's mail.
		 *
		 * Attachments are produced here rather than stored on the row, because
		 * everything this plugin attaches is derived from something the row
		 * already points at — the confirmation's calendar file is a view of the
		 * event. Storing a copy at queue time would mean a message delayed by an
		 * hour carried a calendar entry for a time the event had since moved
		 * away from, which is worse than a stale line of body text: it goes into
		 * somebody's diary.
		 *
		 * Return an array of `array( 'name' => …, 'content' => …, 'type' => … )`.
		 *
		 * @since 26.0
		 *
		 * @param array<int, array<string, string>> $files Files to attach.
		 * @param array<string, mixed>              $row   Queue row.
		 */
		$files = (array) apply_filters( 'qevm_email_attachments', array(), $row );

		$attach = static function ( $phpmailer ) use ( $files ) {
			foreach ( $files as $file ) {
				if ( empty( $file['name'] ) || ! isset( $file['content'] ) ) {
					continue;
				}

				$phpmailer->addStringAttachment(
					(string) $file['content'],
					(string) $file['name'],
					'base64',
					isset( $file['type'] ) ? (string) $file['type'] : 'application/octet-stream'
				);
			}
		};

		if ( array() !== $files ) {
			add_action( 'phpmailer_init', $attach );
		}

		/*
		 * wp_mail() can throw as well as return false. PHPMailer raises an
		 * exception for a malformed address, and an uncaught one here would
		 * take down the cron run and leave every message behind this one
		 * claimed and unsent.
		 */
		try {
			$went = wp_mail(
				(string) $row['recipient'],
				(string) $row['subject'],
				(string) $row['body'],
				$headers
			);

			$error = $went ? '' : __( 'wp_mail() returned false.', 'quick-events-manager' );
		} catch ( \Throwable $thrown ) {
			$went  = false;
			$error = $thrown->getMessage();
		} finally {
			remove_action( 'phpmailer_init', $attach );
		}

		if ( $went ) {
			Queue::mark_sent( $id );
		} else {
			Queue::mark_failed( $id, $attempts, $error );
		}

		/**
		 * Fires after one queued message has been attempted.
		 *
		 * @since 26.0
		 *
		 * @param int    $id        Queue id.
		 * @param bool   $went      Whether wp_mail() accepted it.
		 * @param string $recipient Address it was for.
		 */
		do_action( 'qevm_email_attempted', $id, (bool) $went, (string) $row['recipient'] );

		return (bool) $went;
	}
}
