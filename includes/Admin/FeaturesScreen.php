<?php
/**
 * The Features screen: one toggle per module, grouped by level.
 *
 * @package QuickEventsManager
 */

namespace QEM\Admin;

use QEM\Modules\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the site owner switch capability on a level at a time.
 *
 * The whole point of the plugin's structure shows up here: someone who wants
 * a list of events on a page never has to visit this screen, and someone who
 * wants attendee management finds it in one place with an explanation of what
 * it adds.
 *
 * @since 26.0
 */
final class FeaturesScreen {

	/**
	 * Menu slug.
	 */
	const SLUG = 'qem-features';

	/**
	 * Nonce action.
	 */
	const NONCE = 'qem_save_features';

	/**
	 * Module registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @since 26.0
	 *
	 * @param Registry $registry Module registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Hook into the admin.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_qem_save_features', array( $this, 'handle_save' ) );
		add_filter( 'plugin_action_links_' . QEM_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . QEM_POST_TYPE,
			__( 'Features', 'quick-events-manager' ),
			__( 'Features', 'quick-events-manager' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Add a Features link to the plugins list.
	 *
	 * @since 26.0
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'edit.php?post_type=' . QEM_POST_TYPE . '&page=' . self::SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Features', 'quick-events-manager' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Render the screen.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage features.', 'quick-events-manager' ) );
		}

		$modules = $this->registry->all();
		$enabled = $this->registry->enabled_ids();
		$levels  = array();

		foreach ( $modules as $module ) {
			$levels[ $module->level() ][] = $module;
		}

		ksort( $levels );
		?>
		<div class="wrap qem-features">
			<h1><?php esc_html_e( 'Features', 'quick-events-manager' ); ?></h1>

			<p class="qem-intro">
				<?php esc_html_e( 'Quick Events Manager starts simple. Switch on only what you need — anything you leave off adds nothing to your site, loads no code and creates no database tables.', 'quick-events-manager' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Features updated.', 'quick-events-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="qem_save_features" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<?php foreach ( $levels as $level => $level_modules ) : ?>
					<h2 class="qem-level-heading"><?php echo esc_html( self::level_title( $level ) ); ?></h2>
					<p class="qem-level-description"><?php echo esc_html( self::level_description( $level ) ); ?></p>

					<ul class="qem-module-list">
						<?php foreach ( $level_modules as $module ) : ?>
							<?php
							$id         = $module->id();
							$is_on      = in_array( $id, $enabled, true );
							$is_locked  = $module->is_required();
							$element_id = 'qem-module-' . sanitize_html_class( $id );
							?>
							<li class="qem-module<?php echo $is_on ? ' is-enabled' : ''; ?>">
								<label for="<?php echo esc_attr( $element_id ); ?>">
									<input type="checkbox" id="<?php echo esc_attr( $element_id ); ?>"
										name="qem_modules[]" value="<?php echo esc_attr( $id ); ?>"
										<?php checked( $is_on ); ?>
										<?php disabled( $is_locked ); ?> />
									<span class="qem-module-title"><?php echo esc_html( $module->title() ); ?></span>
								</label>
								<p class="qem-module-description"><?php echo esc_html( $module->description() ); ?></p>
								<?php if ( $is_locked ) : ?>
									<p class="qem-module-note">
										<?php esc_html_e( 'Always on — this is what the plugin does.', 'quick-events-manager' ); ?>
									</p>
									<input type="hidden" name="qem_modules[]" value="<?php echo esc_attr( $id ); ?>" />
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save features', 'quick-events-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save the submitted toggles.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage features.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::NONCE );

		$submitted = isset( $_POST['qem_modules'] ) ? (array) wp_unslash( $_POST['qem_modules'] ) : array();
		$submitted = array_map( 'sanitize_key', $submitted );

		foreach ( $this->registry->all() as $id => $module ) {
			if ( in_array( $id, $submitted, true ) ) {
				$this->registry->enable( $id );
			} else {
				$this->registry->disable( $id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => QEM_POST_TYPE,
					'page'      => self::SLUG,
					'updated'   => '1',
				),
				admin_url( 'edit.php' )
			)
		);

		exit;
	}

	/**
	 * Heading for a disclosure level.
	 *
	 * @since 26.0
	 *
	 * @param int $level Level number.
	 * @return string
	 */
	private static function level_title( $level ) {
		switch ( (int) $level ) {
			case 0:
				return __( 'The basics', 'quick-events-manager' );
			case 1:
				return __( 'Taking registrations', 'quick-events-manager' );
			case 2:
				return __( 'Going further', 'quick-events-manager' );
			default:
				return __( 'Advanced', 'quick-events-manager' );
		}
	}

	/**
	 * Explanatory line under a level heading.
	 *
	 * @since 26.0
	 *
	 * @param int $level Level number.
	 * @return string
	 */
	private static function level_description( $level ) {
		switch ( (int) $level ) {
			case 0:
				return __( 'Everything you need to publish events. This is always on.', 'quick-events-manager' );
			case 1:
				return __( 'Let people sign up, and keep track of who is coming.', 'quick-events-manager' );
			case 2:
				return __( 'More control over how registration works and how your events look.', 'quick-events-manager' );
			default:
				return __( 'For larger or more complex events.', 'quick-events-manager' );
		}
	}
}
