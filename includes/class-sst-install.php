<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * SST Install.
 *
 * Handles plugin installation and upgrades.
 *
 * @author 	Simple Sales Tax
 * @package SST
 * @since 	5.0
 */
class SST_Install {

	/**
	 * @var array Callbacks that need to run for each update.
	 * @since 5.0
	 */
	private static $update_hooks = array(
		'2.6' => array(
			'sst_update_26_remove_shipping_taxable_option',
		),
		'3.8' => array(
			'sst_update_38_update_addresses',
		),
		'4.2' => array(
			'sst_update_42_migrate_settings',
			'sst_update_42_migrate_order_data',
		),
		'4.5' => array(
			'sst_update_45_remove_license_option'
		),
	);

	/**
	 * @var SST_Updater Background updater.
	 * @since 5.0
	 */
	private static $background_updater;

	/**
	 * Initialize installer.
	 *
	 * @since 4.4
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'init', array( __CLASS__, 'init_background_updater' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'trigger_update' ) );
		add_filter( 'plugin_action_links_' . SST_PLUGIN_BASENAME, array( __CLASS__, 'add_action_links' ) );
	}

	/**
	 * Initialize the background updater.
	 *
	 * @since 5.0
	 */
	public static function init_background_updater() {
		include_once 'class-sst-updater.php';
		self::$background_updater = new SST_Updater();
	}

	/**
	 * Compares the current version of the plugin against the version stored
	 * in the database and runs the installer if necessary.
	 *
	 * @since 4.4
	 */
	public static function check_version() {
		if ( get_option( 'wootax_version' ) !== SST()->version ) {
			self::install();
		}
	}

	/**
	 * Install Simple Sales Tax.
	 *
	 * @since 5.0
	 */
	public static function install() {
		// If any dependencies are missing, display a message and die.
		if ( ( $missing = SST_Compatibility::get_missing_dependencies() ) ) {
			deactivate_plugins( SST_PLUGIN_BASENAME );
			$missing_list = implode( ', ', $missing );
			$message = sprintf( __( 'Simple Sales Tax needs the following to run: %s. Please ensure that all requirements are met and try again.', 'simplesalestax' ), $missing_list );
			wp_die( $message );
		}

		// Include required classes
		if ( ! class_exists( 'WC_Admin_Notices' ) ) {
			require WC()->plugin_path() . '/admin/class-wc-admin-notices.php';
		}

		// Install
		self::add_roles();
		self::add_tax_rate();
		self::configure_woocommerce();
		self::schedule_events();

		// Remove existing notices, if any
		WC_Admin_Notices::remove_notice( 'sst_update' );

		// Queue updates if needed (if db version not set, use default value of 1.0)
		$db_version = get_option( 'wootax_version', '1.0' );

		if ( version_compare( $db_version, max( array_keys( self::$update_hooks ) ), '<' ) ) {
			$update_url    = esc_url( add_query_arg( 'do_sst_update', true ) );
			$update_notice = sprintf( __( 'A Simple Sales Tax data update is required. <a href="%s">Click here</a> to start the update.', 'simplesalestax' ), $update_url );
			WC_Admin_Notices::add_custom_notice( 'sst_update', $update_notice );
		} else {
			update_option( 'wootax_version', SST()->version );
		}
	}

	/**
	 * Start update when a user clicks the "Update" button in the dashboard.
	 *
	 * @since 5.0
	 */
	public static function trigger_update() {
		if ( ! empty( $_GET[ 'do_sst_update'] ) ) {
			self::update();

			// Remove "update required" notice
			WC_Admin_Notices::remove_notice( 'sst_update' );
			
			// Add "update in progress" notice
			$notice = __( 'Simple Sales Tax is updating. This notice will disappear when the update is complete.', 'simplesalestax' );
			WC_Admin_Notices::add_custom_notice( 'sst_updating', $notice );
		}
	}
	/**
	 * Queue all required updates to run in the background. Ripped from
	 * WooCommerce core.
	 *
	 * @since 5.0
	 */
	private static function update() {
		$current_db_version = get_option( 'wootax_version', '1.0' );
		$logger             = new WC_Logger();
		$update_queued      = false;

		foreach ( self::$db_updates as $version => $update_callbacks ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					$logger->add( 'sst_db_updates', sprintf( 'Queuing %s - %s', $version, $update_callback ) );
					self::$background_updater->push_to_queue( $update_callback );
					$update_queued = true;
				}
			}
		}

		if ( $update_queued ) {
			self::$background_updater->save()->dispatch();
		}
	}

	/**
	 * Add custom user roles.
	 *
	 * @since 5.0
	 */
	public static function add_roles() {
		add_role( 'exempt-customer', __( 'Exempt Customer', 'simplesalestax' ), array(
			'read' 			=> true,
			'edit_posts' 	=> false,
			'delete_posts' 	=> false,
		) );
	}

	/**
	 * Remove custom user roles.
	 *
	 * @since 5.0
	 */
	public static function remove_roles() {
		remove_role( 'exempt-customer' );
	}

	/**
	 * Add plugin action links.
	 *
	 * @since 4.2
	 *
	 * @param  array $links Existing action links for plugin.
	 * @return array
	 */
	public static function add_settings_link( $links ) { 
	 	$settings_link = '<a href="admin.php?page=wc-settings&tab=integration&section=wootax">Settings</a>'; 
	  	array_unshift( $links, $settings_link ); 
	  	return $links; 
	}

	/**
	 * Schedule cronjobs (clear them first).
	 *
	 * @since 4.4
	 */
	private static function schedule_events() {
		wp_clear_scheduled_hook( 'wootax_update_recurring_tax' );

		// Ripped from WooCommerce: allows us to schedule an event starting at 00:00 tomorrow local time
		$ve = get_option( 'gmt_offset' ) > 0 ? '+' : '-';

		wp_schedule_event( strtotime( '00:00 tomorrow ' . $ve . get_option( 'gmt_offset' ) . ' HOURS' ), 'twicedaily', 'wootax_update_recurring_tax' );
	}

	/**
	 * Set WooCommerce options for ideal plugin performance.
	 *
	 * @since 4.2
	 */
 	private static function configure_woocommerce() {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		update_option( 'woocommerce_default_customer_address', 'base' );
		update_option( 'woocommerce_shipping_tax_class', '' );
		update_option( 'woocommerce_tax_round_at_subtotal', false );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );
		update_option( 'woocommerce_tax_total_display', 'itemized' );
	}

	/**
	 * Add a tax rate so we can persist calculate tax totals after checkout.
	 *
	 * @since 5.0
	 */
	private static function add_tax_rate() {
		global $wpdb;

		$tax_rates_table = "{$wpdb->prefix}woocommerce_tax_rates";

		// Get existing rate, if any
		$rate_id  = get_option( 'wootax_rate_id', 0 );
		$existing = $wpdb->get_row( $wpdb->prepare( "
			SELECT * FROM {$tax_rates_table} WHERE tax_rate_id = %d;
		", $rate_id ) );

		// Add or update tax rate
		$_tax_rate = array(
			'tax_rate_country'  => 'WOOTAX',
			'tax_rate_state'    => 'RATE',
			'tax_rate'          => 0,
			'tax_rate_name'     => 'DO-NOT-REMOVE',
			'tax_rate_priority' => 0,
			'tax_rate_compound' => 1,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => 'standard',
		);

		if ( is_null( $existing ) ) {
			$wpdb->insert( $tax_rates_table, $_tax_rate );
			update_option( 'wootax_rate_id', $wpdb->insert_id );
		} else {
			$where = array( 'tax_rate_id' => $rate_id );
			$wpdb->update( $tax_rates_table, $_tax_rate, $where );
		}
	}

}