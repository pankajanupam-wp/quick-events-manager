<?php
/**
 * Schema, capabilities and version bookkeeping.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Creates what the plugin needs on disk and in the database.
 *
 * @since 26.0
 */
final class Installer {

	/**
	 * Roles that get event capabilities, and whether they get the "others" set.
	 *
	 * @var array<string, bool>
	 */
	const ROLES = array(
		'administrator' => true,
		'editor'        => true,
	);

	/**
	 * Run the full install.
	 *
	 * Deliberately does not record the schema version. That is the migration
	 * runner's job, and only once it has actually applied everything.
	 *
	 * Stamping here would be wrong in a case that is easy to miss: a site still
	 * running 1.0 that deactivates it and activates 26.0 fires this hook, and if
	 * activation declared the schema current, the legacy post-type migration
	 * would be skipped and those events would stay invisible. On a genuinely
	 * fresh install nothing is lost by leaving it to the runner — every
	 * migration finds no work and completes immediately.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function install() {
		self::add_capabilities();
		self::add_roles();
	}

	/**
	 * Create or update the tables belonging to enabled modules.
	 *
	 * Only tables belonging to modules that are switched on are touched, so a
	 * site that never enables registration never grows a registrations table.
	 *
	 * dbDelta is idempotent, so this is safe to call whenever the stored version
	 * is behind — including on each request of a migration that is taking more
	 * than one request to finish.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function upgrade_schema() {
		self::add_capabilities();
		self::add_roles();
		self::drop_retired_columns();

		$registry = \QuickEventsManager\Plugin::instance()->registry();

		foreach ( $registry->enabled_ids() as $id ) {
			$module = $registry->get( $id );

			if ( null !== $module ) {
				$module->activate();
			}
		}
	}

	/**
	 * Remove columns that no longer exist in any schema.
	 *
	 * **dbDelta never drops anything.** It adds columns and it widens them, and
	 * a column deleted from a `CREATE TABLE` statement simply stays on every
	 * site that already had it — which is the right default, since guessing that
	 * a missing column means "delete this data" would be catastrophic. Removing
	 * one is therefore an explicit act, and this is where those acts are
	 * recorded.
	 *
	 * `min_per_order` and `max_per_order` were written because
	 * docs/database.md specified them. Nothing ever read them: no interface set
	 * them, no booking checked them, and a column that looks like a feature and
	 * is not one is worse than an absent one. A per-order minimum and maximum is
	 * a real thing to want, and it comes back as one piece of work — a field on
	 * the ticket types box, a check on the booking form and a check in the order
	 * builder — rather than as two columns waiting to be noticed.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function drop_retired_columns() {
		self::drop_columns( 'ticket_types', array( 'min_per_order', 'max_per_order' ) );

		/*
		 * `occ_status` on the ticket types table served no query: every read
		 * filters on `event_id`, and `occurrence_id` is written and never read.
		 * An index nothing reads is not free — it is maintained on every insert
		 * and every update, for nothing — and stage 7 recorded it as worth
		 * revisiting in stage 10's performance pass rather than pretending it
		 * earned its place. This is that revisit.
		 *
		 * The column stays. It is the key a per-date ticket type will use, it
		 * is specified in docs/database.md, and unlike `min_per_order` it makes
		 * no promise to anybody that something is enforced when it is not.
		 */
		self::drop_indexes( 'ticket_types', array( 'occ_status' ) );
	}

	/**
	 * Drop indexes from one of the plugin's tables, if they are there.
	 *
	 * What dbDelta will not do is remove an index, any more than it removes a
	 * column: one taken out of a `CREATE TABLE` statement stays on every site
	 * that already had it. Idempotent, and quiet about anything already gone.
	 *
	 * @since 26.0
	 *
	 * @param string             $name    Unprefixed table name.
	 * @param array<int, string> $indexes Index names.
	 * @return void
	 */
	public static function drop_indexes( $name, array $indexes ) {
		global $wpdb;

		$table = self::table( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema question about our own table.
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( ! $exists ) {
			return;
		}

		foreach ( $indexes as $index ) {
			$index = preg_replace( '/[^a-z0-9_]/', '', (string) $index );

			if ( '' === $index ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema question about our own table.
			$present = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ) );

			if ( array() === (array) $present ) {
				continue;
			}

			/*
			 * Interpolated for the same reason the column drop is: MySQL takes
			 * neither a table nor an index name as a bound parameter in DDL,
			 * and both are ours.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A schema change is the purpose; see above.
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$index}`" );
		}
	}

	/**
	 * Drop columns from one of the plugin's tables, if they are there.
	 *
	 * Idempotent: what is already gone is not dropped again, and a table that
	 * does not exist is not touched.
	 *
	 * @since 26.0
	 *
	 * @param string             $name    Unprefixed table name, e.g. 'ticket_types'.
	 * @param array<int, string> $columns Column names.
	 * @return void
	 */
	public static function drop_columns( $name, array $columns ) {
		global $wpdb;

		$table = self::table( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema question about our own table.
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( ! $exists ) {
			return;
		}

		foreach ( $columns as $column ) {
			$column = preg_replace( '/[^a-z0-9_]/', '', (string) $column );

			if ( '' === $column ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema question about our own table.
			$present = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );

			if ( array() === (array) $present ) {
				continue;
			}

			/*
			 * Interpolated because MySQL takes neither a table nor a column
			 * name as a bound parameter in DDL. Both are ours: the table comes
			 * from this class, and the column has just been stripped to
			 * `[a-z0-9_]` and matched against `SHOW COLUMNS`.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A schema change is the entire purpose of this method; see above.
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
		}
	}

	/**
	 * Run a CREATE TABLE statement through dbDelta().
	 *
	 * The dbDelta() function lives in an admin include that is not loaded on
	 * front-end requests, so it has to be required explicitly. It is also
	 * famously picky: two spaces after PRIMARY KEY, no backticks around the
	 * table name, and one field per line.
	 *
	 * @since 26.0
	 *
	 * @param string $sql Full CREATE TABLE statement.
	 * @return void
	 */
	public static function run_schema( $sql ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );

		self::verify_indexes( (string) $sql );
	}

	/**
	 * Check that the indexes a schema declares are really there.
	 *
	 * **dbDelta reports what it decided to do, not what the database did.** It
	 * compares the statement against the table, prints "Added index" for
	 * anything missing, and does not look again afterwards — so an `ALTER` that
	 * MySQL refuses is reported as a success. The way that happens in practice
	 * is a unique index over data that already violates it, which is exactly
	 * how it was found in C8.1: the check-in table's uniqueness guarantee was
	 * reported as added and was not there.
	 *
	 * An index missing from a table is not a visible failure. It is a query
	 * that scans, or a guarantee that silently is not one, and nothing says so.
	 *
	 * Verified here rather than from a list of tables kept somewhere else,
	 * because every schema in the plugin passes through this method — there is
	 * nothing to keep in step.
	 *
	 * @since 26.0
	 *
	 * @param string $sql The CREATE TABLE statement just run.
	 * @return array<int, string> Index names that are still missing.
	 */
	public static function verify_indexes( $sql ) {
		global $wpdb;

		$table = self::table_in( $sql );

		if ( '' === $table ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A schema question about our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );

		$present = array();

		foreach ( (array) $rows as $row ) {
			$present[ (string) $row['Key_name'] ] = true;
		}

		$missing = array();

		foreach ( self::indexes_in( $sql ) as $name ) {
			if ( ! isset( $present[ $name ] ) ) {
				$missing[] = $name;
			}
		}

		if ( array() !== $missing ) {
			/**
			 * Fires when a table is missing an index its schema declares.
			 *
			 * Almost always means the `ALTER` was refused because the data
			 * violates it — duplicate rows under a unique key — which is a
			 * thing a person has to resolve rather than a thing to retry.
			 *
			 * @since 26.0
			 *
			 * @param string             $table   Table name, with prefix.
			 * @param array<int, string> $missing Index names.
			 */
			do_action( 'qevm_indexes_missing', $table, $missing );
		}

		return $missing;
	}

	/**
	 * The table a CREATE TABLE statement is for.
	 *
	 * @since 26.0
	 *
	 * @param string $sql The statement.
	 * @return string
	 */
	private static function table_in( $sql ) {
		return preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $found ) ? $found[1] : '';
	}

	/**
	 * The index names a CREATE TABLE statement declares.
	 *
	 * `PRIMARY KEY` is deliberately included, under the name MySQL gives it.
	 *
	 * @since 26.0
	 *
	 * @param string $sql The statement.
	 * @return array<int, string>
	 */
	private static function indexes_in( $sql ) {
		$names = array();

		if ( preg_match( '/PRIMARY\s+KEY/i', $sql ) ) {
			$names[] = 'PRIMARY';
		}

		if ( preg_match_all( '/(?:UNIQUE\s+)?KEY\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $sql, $found ) ) {
			foreach ( $found[1] as $name ) {
				if ( 0 !== strcasecmp( 'KEY', $name ) ) {
					$names[] = $name;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * The charset/collate clause for a CREATE TABLE statement.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function charset_collate() {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}

	/**
	 * Prefixed name of one of the plugin's tables.
	 *
	 * @since 26.0
	 *
	 * @param string $name Unprefixed table name, e.g. 'registrations'.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'qevm_' . $name;
	}

	/**
	 * Grant event capabilities to the roles that should manage events.
	 *
	 * The post type uses a custom capability type rather than mapping onto
	 * `post`, which is what later allows a check-in-only staff role to exist
	 * without also handing out the right to edit posts.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function add_capabilities() {
		foreach ( self::ROLES as $role_name => $manages_others ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::capabilities( $manages_others ) as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * The roles this plugin creates, and what each may do.
	 *
	 * Three jobs that exist at every event and are not "administrator":
	 *
	 * - **Event Manager** runs events end to end — creating, editing and
	 *   publishing them, managing attendees and working a door. Not site
	 *   settings: switching modules on and off changes how the whole site
	 *   behaves, and that stays with somebody who can already do it.
	 * - **Event Organizer** looks after their own events and the people on
	 *   them — including standing at the door of one — and cannot touch
	 *   anybody else's. What separates an organiser from a manager is whose
	 *   events they may edit, not what they may do at their own.
	 * - **Event Staff** works a door and nothing else. No editing of any post,
	 *   including events — this is the volunteer with a phone, and the point of
	 *   the role is that handing them the phone is safe.
	 *
	 * @var array<string, array{name: string, caps: string[]}>
	 */
	const OWN_ROLES = array(
		'qevm_event_manager'   => array(
			'name' => 'Event Manager',
			'caps' => array( 'read', 'upload_files', 'manage_qevm_registrations', 'manage_qevm_checkins' ),
		),
		'qevm_event_organizer' => array(
			'name' => 'Event Organizer',
			'caps' => array( 'read', 'upload_files', 'manage_qevm_registrations' ),
		),
		'qevm_event_staff'     => array(
			'name' => 'Event Staff',
			'caps' => array( 'read', 'manage_qevm_checkins' ),
		),
	);

	/**
	 * Create the plugin's own roles.
	 *
	 * `add_role()` does nothing when the role already exists, which is what
	 * makes this safe to call on every upgrade — and also means a site that has
	 * customised one of these keeps its changes rather than having them
	 * silently reset on the next release.
	 *
	 * The event capabilities are added separately, because the "others" set is
	 * what separates a manager from an organiser and both need the rest.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function add_roles() {
		foreach ( self::OWN_ROLES as $key => $definition ) {
			$existing = get_role( $key );

			if ( null === $existing ) {
				add_role( $key, $definition['name'], array_fill_keys( $definition['caps'], true ) );

				$existing = get_role( $key );
			}

			if ( null === $existing ) {
				continue;
			}

			foreach ( $definition['caps'] as $cap ) {
				$existing->add_cap( $cap );
			}
		}

		/*
		 * Event capabilities on top. A manager gets the "others" set and an
		 * organiser does not, which is the whole difference between them.
		 * Staff get none: a door needs to read a list, not edit an event.
		 */
		$manager = get_role( 'qevm_event_manager' );

		if ( null !== $manager ) {
			foreach ( self::capabilities( true ) as $cap ) {
				$manager->add_cap( $cap );
			}
		}

		$organizer = get_role( 'qevm_event_organizer' );

		if ( null !== $organizer ) {
			foreach ( self::capabilities( false ) as $cap ) {
				$organizer->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the plugin's own roles.
	 *
	 * Used by uninstall.php. Anybody holding one of these is left with no role
	 * at all, which is the same thing WordPress does when a role is removed and
	 * is why this is not part of deactivation.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function remove_roles() {
		foreach ( array_keys( self::OWN_ROLES ) as $key ) {
			if ( null !== get_role( $key ) ) {
				remove_role( $key );
			}
		}
	}

	/**
	 * Remove the plugin's capabilities from every role.
	 *
	 * Used by uninstall.php. Left out of deactivation on purpose: a site owner
	 * deactivating to debug something should not have to rebuild their roles.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function remove_capabilities() {
		foreach ( array_keys( self::ROLES ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::capabilities( true ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Every capability the plugin grants.
	 *
	 * @since 26.0
	 *
	 * @param bool $include_others Whether to include the manage-others caps.
	 * @return string[]
	 */
	public static function capabilities( $include_others = true ) {
		return array_merge(
			self::post_type_capabilities( $include_others ),
			self::venue_post_type_capabilities( $include_others ),
			self::organizer_post_type_capabilities( $include_others ),
			self::management_capabilities()
		);
	}

	/**
	 * The capability names WordPress maps onto the event post type.
	 *
	 * @since 26.0
	 *
	 * @param bool $include_others Whether to include the manage-others caps.
	 * @return string[]
	 */
	public static function post_type_capabilities( $include_others = true ) {
		$caps = array(
			'edit_qevm_event',
			'read_qevm_event',
			'delete_qevm_event',
			'edit_qevm_events',
			'publish_qevm_events',
			'delete_qevm_events',

			/*
			 * These two are **not** about other people's posts, which is where
			 * they used to live. WordPress maps `edit_post` on any published
			 * post — including your own — through `edit_published_*`, so an
			 * Event Organizer without it could create something, publish it,
			 * and never edit it again. Found by the stage 10 security pass,
			 * while checking a different question entirely.
			 */
			'edit_published_qevm_events',
			'delete_published_qevm_events',
		);

		if ( ! $include_others ) {
			return $caps;
		}

		return array_merge(
			$caps,
			array(
				'edit_others_qevm_events',
				'delete_others_qevm_events',
				'read_private_qevm_events',
			)
		);
	}

	/**
	 * The capability names WordPress maps onto the venue post type.
	 *
	 * Reusable venues are a module, and one that is off on a fresh install, so
	 * on most sites the post type these names belong to is never registered.
	 * They are granted anyway, and for the reason `manage_qevm_checkins` is: a
	 * capability that arrives with its feature has to be granted by a migration
	 * walking every role on every site, and that migration is only avoidable
	 * while the plugin is unreleased.
	 *
	 * Granting them as the module is switched on would look tidier and would
	 * slip the uninstall guard in the process. Deletion works from a list
	 * written out by hand, because uninstall.php runs with no autoloader and
	 * nothing to ask; the only thing keeping that list honest is a test against
	 * capabilities(). A name this method never returns is a name the list is
	 * never required to carry, and it would then outlive deletion on every role
	 * of every site that had enabled the module.
	 *
	 * @since 26.0
	 *
	 * @param bool $include_others Whether to include the manage-others caps.
	 * @return string[]
	 */
	public static function venue_post_type_capabilities( $include_others = true ) {
		$caps = array(
			'edit_qevm_venue',
			'read_qevm_venue',
			'delete_qevm_venue',
			'edit_qevm_venues',
			'publish_qevm_venues',
			'delete_qevm_venues',

			/*
			 * These two are **not** about other people's posts, which is where
			 * they used to live. WordPress maps `edit_post` on any published
			 * post — including your own — through `edit_published_*`, so an
			 * Event Organizer without it could create something, publish it,
			 * and never edit it again. Found by the stage 10 security pass,
			 * while checking a different question entirely.
			 */
			'edit_published_qevm_venues',
			'delete_published_qevm_venues',
		);

		if ( ! $include_others ) {
			return $caps;
		}

		return array_merge(
			$caps,
			array(
				'edit_others_qevm_venues',
				'delete_others_qevm_venues',
				'read_private_qevm_venues',
			)
		);
	}

	/**
	 * The capability names WordPress maps onto the organiser post type.
	 *
	 * Granted up front for the same reason as the venue set above, and kept in
	 * a method of its own rather than folded in with them because the two
	 * modules are independent: a site can run reusable venues without reusable
	 * organisers, and a later change to one list must not quietly move the
	 * other.
	 *
	 * @since 26.0
	 *
	 * @param bool $include_others Whether to include the manage-others caps.
	 * @return string[]
	 */
	public static function organizer_post_type_capabilities( $include_others = true ) {
		$caps = array(
			'edit_qevm_organizer',
			'read_qevm_organizer',
			'delete_qevm_organizer',
			'edit_qevm_organizers',
			'publish_qevm_organizers',
			'delete_qevm_organizers',

			/*
			 * These two are **not** about other people's posts, which is where
			 * they used to live. WordPress maps `edit_post` on any published
			 * post — including your own — through `edit_published_*`, so an
			 * Event Organizer without it could create something, publish it,
			 * and never edit it again. Found by the stage 10 security pass,
			 * while checking a different question entirely.
			 */
			'edit_published_qevm_organizers',
			'delete_published_qevm_organizers',
		);

		if ( ! $include_others ) {
			return $caps;
		}

		return array_merge(
			$caps,
			array(
				'edit_others_qevm_organizers',
				'delete_others_qevm_organizers',
				'read_private_qevm_organizers',
			)
		);
	}

	/**
	 * The capabilities that govern the plugin rather than the posts.
	 *
	 * Kept apart from the post type's own set, and not behind the "others"
	 * flag, because managing a guest list is not a statement about whose posts
	 * somebody may edit. `manage_qevm_registrations` had been sitting in that
	 * branch, which worked only because both roles that get capabilities happen
	 * to get the others set too.
	 *
	 * The separation is what stage 8's check-in staff role needs: somebody on a
	 * door should be able to mark people through it without being handed the
	 * right to edit other people's events, and that is a different list rather
	 * than a smaller one.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function management_capabilities() {
		return array(
			'manage_qevm_registrations',

			/*
			 * Nothing reads this yet — check-in is stage 8. It is granted now
			 * because a capability that arrives with the feature has to be
			 * granted by a migration that walks every role on every site, and
			 * that migration is only avoidable while the plugin is unreleased.
			 * It costs one row in an option today.
			 */
			'manage_qevm_checkins',
		);
	}
}
