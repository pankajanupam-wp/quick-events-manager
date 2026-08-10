<?php
/**
 * Template loading with theme overrides.
 *
 * @package QuickEventsManager
 */

namespace QEM\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a template, preferring the theme's copy over the plugin's.
 *
 * Deliberately the whole of the "theming system": drop a file into
 * `your-theme/quick-events-manager/` and it wins. No template hierarchy of our
 * own, no settings screen for choosing layouts, no page builder.
 *
 * @since 26.0
 */
final class Templates {

	/**
	 * Directory name themes put their overrides in.
	 */
	const THEME_DIR = 'quick-events-manager';

	/**
	 * Locate a template file.
	 *
	 * @since 26.0
	 *
	 * @param string $template File name, e.g. 'event-card.php'.
	 * @return string Absolute path, or '' when the file does not exist.
	 */
	public static function locate( $template ) {
		$template = ltrim( $template, '/' );

		/*
		 * locate_template() checks the child theme then the parent, which is
		 * the behaviour anyone overriding a template expects.
		 */
		$found = locate_template( array( self::THEME_DIR . '/' . $template ) );

		if ( '' !== $found ) {
			return $found;
		}

		$plugin_path = QEM_PATH . 'templates/' . $template;

		/**
		 * Filter the resolved path of a template.
		 *
		 * @since 26.0
		 *
		 * @param string $plugin_path Absolute path to the plugin's copy.
		 * @param string $template    Requested file name.
		 */
		$plugin_path = apply_filters( 'qem_template_path', $plugin_path, $template );

		return is_readable( $plugin_path ) ? $plugin_path : '';
	}

	/**
	 * Render a template and return its output.
	 *
	 * Variables are extracted into the template's scope, which is how every
	 * WordPress template part works and what a theme author will expect.
	 *
	 * @since 26.0
	 *
	 * @param string $template File name.
	 * @param array  $vars     Variables to expose.
	 * @return string Rendered markup.
	 */
	public static function render( $template, array $vars = array() ) {
		$path = self::locate( $template );

		if ( '' === $path ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template scope, matching core's own template parts.
		extract( $vars, EXTR_SKIP );

		ob_start();

		include $path;

		return (string) ob_get_clean();
	}
}
