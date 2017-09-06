<?php

/**
 * The main plugin class
 *
 * @package Code_Snippets
 */
class Code_Snippets {

	/**
	 * The current plugin version
	 * @var string
	 */
	public $version;

	/**
	 * Filesystem path to the main plugin file
	 * @var string
	 */
	public $file;

	/**
	 * @var Code_Snippets_DB
	 */
	public $db;

	/**
	 * @var Code_Snippets_Admin
	 */
	public $admin;

	/**
	 * Class constructor
	 *
	 * @param string $version The current plugin version
	 * @param string $file    The main plugin file
	 */
	function __construct( $version, $file ) {
		$this->version = $version;
		$this->file = $file;

		add_action( 'grant_super_admin', array( $this, 'grant_network_cap' ) );
		add_action( 'remove_super_admin', array( $this, 'remove_network_cap' ) );

		add_action( 'init', array( $this, 'load_textdomain' ), 9 );

		add_filter( 'code_snippets/execute_snippets', array( $this, 'disable_snippet_execution' ), 5 );

		if ( isset( $_REQUEST['snippets-safe-mode'] ) ) {
			add_filter( 'home_url', array( $this, 'add_safe_mode_query_var' ) );
			add_filter( 'admin_url', array( $this, 'add_safe_mode_query_var' ) );
		}
	}

	function load_plugin() {
		$includes_path = dirname( __FILE__ );

		/* Database operation functions */
		$this->db = new Code_Snippets_DB();

		/* Snippet operation functions */
		require_once $includes_path . '/snippet-ops.php';

		/* Upgrade function */
		require_once $includes_path . '/upgrade.php';

		/* CodeMirror editor functions */
		require_once $includes_path . '/editor.php';

		/* Backwards compatability functions */
		require_once $includes_path . '/functions.php';

		/* General Administration functions */
		if ( is_admin() ) {
			$this->admin = new Code_Snippets_Admin();
		}

		/* Settings component */
		require_once $includes_path . '/settings/settings-fields.php';
		require_once $includes_path . '/settings/editor-preview.php';
		require_once $includes_path . '/settings/render-fields.php';
		require_once $includes_path . '/settings/settings.php';

		$this->shortcode = new Code_Snippets_Shortcode();
	}

	public function disable_snippet_execution( $execute_snippets ) {

		/* Bail early if safe mode is active */
		if ( defined( 'CODE_SNIPPETS_SAFE_MODE' ) && CODE_SNIPPETS_SAFE_MODE ) {
			return false;
		}

		if ( isset( $_GET['snippets-safe-mode'] ) && $_GET['snippets-safe-mode'] && $this->current_user_can() ) {
			return false;
		}

		return $execute_snippets;
	}

	/**
	 * Fetch the admin menu slug for a snippets menu
	 * @param  string $menu The menu to retrieve the slug for
	 * @return string       The menu's slug
	 */
	public function get_menu_slug( $menu = '' ) {
		$add = array( 'single', 'add', 'add-new', 'add-snippet', 'new-snippet', 'add-new-snippet' );
		$edit = array( 'edit', 'edit-snippet' );
		$import = array( 'import', 'import-snippets' );
		$settings = array( 'settings', 'snippets-settings' );

		if ( in_array( $menu, $edit ) ) {
			return 'edit-snippet';
		} elseif ( in_array( $menu, $add ) ) {
			return 'add-snippet';
		} elseif ( in_array( $menu, $import ) ) {
			return 'import-snippets';
		} elseif ( in_array( $menu, $settings ) ) {
			return 'snippets-settings';
		} else {
			return 'snippets';
		}
	}

	/**
	 * Fetch the URL to a snippets admin menu
	 * @param  string $menu    The menu to retrieve the URL to
	 * @param  string $context The URL scheme to use
	 * @return string          The menu's URL
	 */
	public function get_menu_url( $menu = '', $context = 'self' ) {
		$slug = $this->get_menu_slug( $menu );
		$url = 'admin.php?page=' . $slug;

		if ( 'network' === $context ) {
			return network_admin_url( $url );
		} elseif ( 'admin' === $context ) {
			return admin_url( $url );
		} else {
			return self_admin_url( $url );
		}
	}

	/**
	 * Fetch the admin menu hook for a snippets menu
	 * @param  string $menu The menu to retrieve the hook for
	 * @return string       The menu's hook
	 */
	public function get_menu_hook( $menu = '' ) {
		$slug = $this->get_menu_slug( $menu );
		return get_plugin_page_hookname( $slug, 'snippets' );
	}

	/**
	 * Fetch the admin menu slug for a snippets menu
	 * @param  int    $snippet_id The snippet
	 * @param  string $context    The URL scheme to use
	 * @return string             The URL to the edit snippet page for that snippet
	 */
	public function get_snippet_edit_url( $snippet_id, $context = 'self' ) {
		return add_query_arg(
			'id', absint( $snippet_id ),
			$this->get_menu_url( 'edit', $context )
		);
	}

	/**
	 * Determine whether the current user can perform actions on snippets.
	 *
	 * @since [NEXT_VERSION]
	 * @return boolean Whether the current user has the required capability
	 */
	public function current_user_can() {
		return current_user_can( $this->get_cap() );
	}

	/**
	 * Retrieve the name of the capability required to manage sub-site snippets
	 *
	 * @return string
	 */
	public function get_cap_name() {
		return apply_filters( 'code_snippets_cap', 'manage_snippets' );
	}

	/**
	 * Retrieve the name of the capability required to manage network snippets
	 *
	 * @return string
	 */
	public function get_network_cap_name() {
		return apply_filters( 'code_snippets_network_cap', 'manage_network_snippets' );
	}

	/**
	 * Get the required capability to perform a certain action on snippets.
	 * Does not check if the user has this capability or not.
	 *
	 * If multisite, checks if *Enable Administration Menus: Snippets* is active
	 * under the *Settings > Network Settings* network admin menu
	 *
	 * @since 2.0
	 * @return string The capability required to manage snippets
	 */
	public function get_cap() {

		if ( is_multisite() ) {
			$menu_perms = get_site_option( 'menu_items', array() );

			/* If multisite is enabled and the snippet menu is not activated,
			   restrict snippet operations to super admins only */
			if ( empty( $menu_perms['snippets'] ) ) {
				return $this->get_network_cap_name();
			}
		}

		return $this->get_cap_name();
	}

	/**
	 * Add the multisite capabilities to a user
	 *
	 * @since 2.0
	 * @param int $user_id The ID of the user to add the cap to
	 */
	function grant_network_cap( $user_id ) {

		/* Get the user from the ID */
		$user = new WP_User( $user_id );

		/* Add the capability */
		$user->add_cap( $this->get_network_cap_name() );
	}

	/**
	 * Remove the multisite capabilities from a user
	 *
	 * @since 2.0
	 * @param int $user_id The ID of the user to remove the cap from
	 */
	function remove_network_cap( $user_id ) {

		/* Get the user from the ID */
		$user = new WP_User( $user_id );

		/* Remove the capability */
		$user->remove_cap( $this->get_network_cap_name() );
	}

	/**
	 * Load up the localization file if we're using WordPress in a different language.
	 *
	 * If you wish to contribute a language file to be included in the Code Snippets package,
	 * please see create an issue on GitHub: https://github.com/sheabunge/code-snippets/issues
	 */
	function load_textdomain() {
		$domain = 'code-snippets';
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		// wp-content/languages/code-snippets/code-snippets-[locale].mo
		load_textdomain( $domain, trailingslashit( WP_LANG_DIR ) . "$domain/$domain-$locale.mo" );

		// wp-content/plugins/code-snippets/languages/code-snippets-[locale].mo
		load_plugin_textdomain( $domain, false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	/**
	 * Inject the safe mode query var into URLs
	 *
	 * @param string $url A URL
	 *
	 * @return string
	 */
	function add_safe_mode_query_var( $url ) {
		if ( isset( $_REQUEST['snippets-safe-mode'] ) ) {
			return add_query_arg( 'snippets-safe-mode', $_REQUEST['snippets-safe-mode'], $url );
		}
		return $url;
	}
}
