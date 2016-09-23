<?php
/**
 * A timeout resistant, single-serve upgrader for WC Subscriptions.
 *
 * This class is used to make all reasonable attempts to neatly upgrade data between versions of Subscriptions.
 *
 * For example, the way subscription data is stored changed significantly between v1.n and v2.0. It was imperative
 * the data be upgraded to the new schema without hassle. A hassle could easily occur if 100,000 orders were being
 * modified - memory exhaustion, script time out etc.
 *
 * @author		Prospress
 * @category	Admin
 * @package		WooCommerce Subscriptions/Admin/Upgrades
 * @version		2.0.0
 * @since		1.2
 */
class WC_Subscriptions_Upgrader {

	private static $active_version;

	private static $upgrade_limit_hooks;

	private static $upgrade_limit_subscriptions;

	private static $about_page_url;

	private static $old_subscription_count = null;

	public static $is_wc_version_2 = false;

	public static $updated_to_wc_2_0;

	/**
	 * Hooks upgrade function to init.
	 *
	 * @since 1.2
	 */
	public static function init() {

		self::$active_version = get_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '0' );

		self::$is_wc_version_2 = version_compare( get_option( 'woocommerce_db_version' ), '2.0', '>=' );

		self::$about_page_url = admin_url( 'index.php?page=wcs-about&wcs-updated=true' );

		$version_out_of_date = version_compare( self::$active_version, WC_Subscriptions::$version, '<' );

		// Set the cron lock on every request with an out of date version, regardless of authentication level, as we can only lock cron for up to 10 minutes at a time, but we need to keep it locked until the upgrade is complete, regardless of who is browing the site
		if ( $version_out_of_date ) {
			self::set_cron_lock();
		}

		if ( isset( $_POST['action'] ) && 'wcs_upgrade' == $_POST['action'] ) { // We're checking for CSRF in ajax_upgrade

			add_action( 'wp_ajax_wcs_upgrade', __CLASS__ . '::ajax_upgrade', 10 );

		} elseif ( @current_user_can( 'activate_plugins' ) ) {

			if ( isset( $_GET['wcs_upgrade_step'] ) || $version_out_of_date ) {

				$is_upgrading = get_option( 'wc_subscriptions_is_upgrading', false );

				// Check if we've exceeded the 2 minute upgrade window we use for blocking upgrades (we could seemingly use transients here to get the check for free if transients were guaranteed to exist: http://journal.rmccue.io/296/youre-using-transients-wrong/)
				if ( false !== $is_upgrading && $is_upgrading < gmdate( 'U' ) ) {
					$is_upgrading = false;
					delete_option( 'wc_subscriptions_is_upgrading' );
				}

				if ( false !== $is_upgrading ) {

					add_action( 'init', __CLASS__ . '::upgrade_in_progress_notice', 11 );

				} else {

					// Run upgrades as soon as admin hits site
					add_action( 'wp_loaded', __CLASS__ . '::upgrade', 11 );

				}
			} elseif ( is_admin() && isset( $_GET['page'] ) && 'wcs-about' == $_GET['page'] ) {

				add_action( 'admin_menu', __CLASS__ . '::updated_welcome_page' );

			}
		}

		// While the upgrade is in progress, we need to block PayPal IPN messages to avoid renewals failing to process
		add_action( 'woocommerce_api_wc_gateway_paypal', __CLASS__ . '::maybe_block_paypal_ipn', 0 );

		// Sometimes redirect to the Welcome/About page after an upgrade
		add_action( 'woocommerce_subscriptions_upgraded', __CLASS__ . '::maybe_redirect_after_upgrade_complete', 100, 2 );
	}

	/**
	 * Set limits on the number of items to upgrade at any one time based on the size of the site.
	 *
	 * The size of subscription at the time the upgrade is started is used to determine the batch size.
	 *
	 * @since 2.0
	 */
	protected static function set_upgrade_limits() {

		$total_initial_subscription_count = self::get_total_subscription_count( true );

		if ( $total_initial_subscription_count > 5000 ) {
			$base_upgrade_limit = 20;
		} elseif ( $total_initial_subscription_count > 1500 ) {
			$base_upgrade_limit = 30;
		} else {
			$base_upgrade_limit = 50;
		}

		self::$upgrade_limit_hooks         = apply_filters( 'woocommerce_subscriptions_hooks_to_upgrade', $base_upgrade_limit * 5 );
		self::$upgrade_limit_subscriptions = apply_filters( 'woocommerce_subscriptions_to_upgrade', $base_upgrade_limit );
	}

	/**
	 * Try to block WP-Cron until upgrading finishes. spawn_cron() will only let us steal the lock for 10 minutes into the future, so
	 * we can actually only block it for 9 minutes confidently. But as long as the upgrade process continues, the lock will remain.
	 *
	 * @since 2.0
	 */
	protected static function set_cron_lock() {
		delete_transient( 'doing_cron' );
		set_transient( 'doing_cron', sprintf( '%.22F', 9 * MINUTE_IN_SECONDS + microtime( true ) ), 0 );
	}

	/**
	 * Checks which upgrades need to run and calls the necessary functions for that upgrade.
	 *
	 * @since 1.2
	 */
	public static function upgrade() {
		global $wpdb;

		self::set_upgrade_limits();

		update_option( WC_Subscriptions_Admin::$option_prefix . '_previous_version', self::$active_version );

		// Update the hold stock notification to be one week (if it's still at the default 60 minutes) to prevent cancelling subscriptions using manual renewals and payment methods that can take more than 1 hour (i.e. PayPal eCheck)
		if ( '0' == self::$active_version || version_compare( self::$active_version, '1.4', '<' ) ) {

			$hold_stock_duration = get_option( 'woocommerce_hold_stock_minutes' );

			if ( 60 == $hold_stock_duration ) {
				update_option( 'woocommerce_hold_stock_minutes', 60 * 24 * 7 );
			}

			// Allow products & subscriptions to be purchased in the same transaction
			update_option( 'woocommerce_subscriptions_multiple_purchase', 'yes' );

		}

		// Keep track of site url to prevent duplicate payments from staging sites, first added in 1.3.8 & updated with 1.4.2 to work with WP Engine staging sites
		if ( '0' == self::$active_version || version_compare( self::$active_version, '1.4.2', '<' ) ) {
			WC_Subscriptions::set_duplicate_site_url_lock();
		}

		// Migrate products, WP-Cron hooks and subscriptions to the latest architecture, via Ajax
		if ( '0' != self::$active_version && version_compare( self::$active_version, '2.0', '<' ) ) {
			// Delete old cron locks
			$deleted_rows = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE `option_name` LIKE 'wcs\_blocker\_%'" );

			WCS_Upgrade_Logger::add( sprintf( 'Deleted %d rows of "wcs_blocker_"', $deleted_rows ) );

			self::ajax_upgrade_handler();
		}

		// Repair incorrect dates set when upgrading with 2.0.0
		if ( version_compare( self::$active_version, '2.0.0', '>=' ) && version_compare( self::$active_version, '2.0.2', '<' ) && self::migrated_subscription_count() > 0 ) {
			self::ajax_upgrade_handler();
		}

		if ( '0' != self::$active_version && version_compare( self::$active_version, '2.1.0', '<' ) ) {

			// Delete cached subscription length ranges to force an update with 2.1
			WC_Subscriptions::$cache->delete_cached( tlc_transient( 'wcs-sub-ranges-' . get_locale() )->key );

			WCS_Upgrade_Logger::add( 'v2.1: Deleted cached subscription ranges.' );

			include_once( 'class-wcs-upgrade-2-1.php' );
			WCS_Upgrade_2_1::set_cancelled_dates();
		}

		self::upgrade_complete();
	}

	/**
	 * When an upgrade is complete, set the active version, delete the transient locking upgrade and fire a hook.
	 *
	 * @since 1.2
	 */
	public static function upgrade_complete() {

		update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', WC_Subscriptions::$version );

		delete_transient( 'doing_cron' );

		delete_option( 'wc_subscriptions_is_upgrading' );

		do_action( 'woocommerce_subscriptions_upgraded', WC_Subscriptions::$version, self::$active_version );
	}

	/**
	 * Redirect to the Subscriptions major version Welcome/About page for major version updates
	 *
	 * @since 2.1
	 */
	public static function maybe_redirect_after_upgrade_complete( $current_version, $previously_active_version ) {
		if ( version_compare( $previously_active_version, '2.1.0', '<' ) && version_compare( $current_version, '2.1.0', '>=' ) ) {
			wp_safe_redirect( self::$about_page_url );
			exit();
		}
	}

	/**
	 * Add support for quantities for subscriptions.
	 * Update all current subscription wp_cron tasks to the new action-scheduler system.
	 *
	 * @since 2.0
	 */
	private static function ajax_upgrade_handler() {

		$_GET['wcs_upgrade_step'] = ( ! isset( $_GET['wcs_upgrade_step'] ) ) ? 0 : $_GET['wcs_upgrade_step'];

		switch ( (int) $_GET['wcs_upgrade_step'] ) {
			case 1:
				self::display_database_upgrade_helper();
				break;
			case 3: // keep a way to circumvent the upgrade routine just in case
				self::upgrade_complete();
				wp_safe_redirect( self::$about_page_url );
				break;
			case 0:
			default:
				wp_safe_redirect( admin_url( 'admin.php?wcs_upgrade_step=1' ) );
				break;
		}

		exit();
	}

	/**
	 * Move scheduled subscription hooks out of wp-cron and into the new Action Scheduler.
	 *
	 * Also set all existing subscriptions to "sold individually" to maintain previous behavior
	 * for existing subscription products before the subscription quantities feature was enabled..
	 *
	 * @since 1.5
	 */
	public static function ajax_upgrade() {
		global $wpdb;

		check_admin_referer( 'wcs_upgrade_process', 'nonce' );

		self::set_upgrade_limits();

		WCS_Upgrade_Logger::add( sprintf( 'Starting upgrade step: %s', $_POST['upgrade_step'] ) );

		if ( ini_get( 'max_execution_time' ) < 600 ) {
			@set_time_limit( 600 );
		}

		@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );

		update_option( 'wc_subscriptions_is_upgrading', gmdate( 'U' ) + 60 * 2 );

		switch ( $_POST['upgrade_step'] ) {

			case 'really_old_version':
				$upgraded_versions = self::upgrade_really_old_versions();
				$results = array(
					// translators: placeholder is a list of version numbers (e.g. "1.3 & 1.4 & 1.5")
					'message' => sprintf( __( 'Database updated to version %s', 'woocommerce-subscriptions' ), $upgraded_versions ),
				);
				break;

			case 'products':

				require_once( 'class-wcs-upgrade-1-5.php' );

				$upgraded_product_count = WCS_Upgrade_1_5::upgrade_products();
				$results = array(
					// translators: placeholder is number of upgraded subscriptions
					'message' => sprintf( _x( 'Marked %s subscription products as "sold individually".', 'used in the subscriptions upgrader', 'woocommerce-subscriptions' ), $upgraded_product_count ),
				);
				break;

			case 'hooks':

				require_once( 'class-wcs-upgrade-1-5.php' );

				$upgraded_hook_count = WCS_Upgrade_1_5::upgrade_hooks( self::$upgrade_limit_hooks );
				$results = array(
					'upgraded_count' => $upgraded_hook_count,
					// translators: 1$: number of action scheduler hooks upgraded, 2$: "{execution_time}", will be replaced on front end with actual time
					'message'        => sprintf( __( 'Migrated %1$s subscription related hooks to the new scheduler (in %2$s seconds).', 'woocommerce-subscriptions' ), $upgraded_hook_count, '{execution_time}' ),
				);
				break;

			case 'subscriptions':

				require_once( 'class-wcs-repair-2-0.php' );
				require_once( 'class-wcs-upgrade-2-0.php' );

				try {

					$upgraded_subscriptions = WCS_Upgrade_2_0::upgrade_subscriptions( self::$upgrade_limit_subscriptions );

					$results = array(
						'upgraded_count' => $upgraded_subscriptions,
						// translators: 1$: number of subscriptions upgraded, 2$: "{execution_time}", will be replaced on front end with actual time it took
						'message'        => sprintf( __( 'Migrated %1$s subscriptions to the new structure (in %2$s seconds).', 'woocommerce-subscriptions' ), $upgraded_subscriptions, '{execution_time}' ),
						'status'         => 'success',
						// translators: placeholder is "{time_left}", will be replaced on front end with actual time
						'time_message'   => sprintf( _x( 'Estimated time left (minutes:seconds): %s', 'Message that gets sent to front end.', 'woocommerce-subscriptions' ), '{time_left}' ),
					);

				} catch ( Exception $e ) {

					WCS_Upgrade_Logger::add( sprintf( 'Error on upgrade step: %s. Error: %s', $_POST['upgrade_step'], $e->getMessage() ) );

					$results = array(
						'upgraded_count' => 0,
						// translators: 1$: error message, 2$: opening link tag, 3$: closing link tag
						'message'        => sprintf( __( 'Unable to upgrade subscriptions.<br/>Error: %1$s<br/>Please refresh the page and try again. If problem persists, %2$scontact support%3$s.', 'woocommerce-subscriptions' ), '<code>' . $e->getMessage(). '</code>', '<a href="' . esc_url( 'https://woocommerce.com/my-account/create-a-ticket/' ) . '">', '</a>' ),
						'status'         => 'error',
					);
				}

				break;

			case 'subscription_dates_repair':

				require_once( 'class-wcs-upgrade-2-0.php' );
				require_once( 'class-wcs-repair-2-0-2.php' );

				$subscription_ids_to_repair = WCS_Repair_2_0_2::get_subscriptions_to_repair( self::$upgrade_limit_subscriptions );

				try {

					$subscription_counts = WCS_Repair_2_0_2::maybe_repair_subscriptions( $subscription_ids_to_repair );

					// translators: placeholder is the number of subscriptions repaired
					$repair_incorrect = sprintf( _x( 'Repaired %d subscriptions with incorrect dates, line tax data or missing customer notes.', 'Repair message that gets sent to front end.', 'woocommerce-subscriptions' ), $subscription_counts['repaired_count'] );

					$repair_not_needed = '';

					if ( $subscription_counts['unrepaired_count'] > 0 ) {
						// translators: placeholder is number of subscriptions that were checked and did not need repairs. There's a space at the beginning!
						$repair_not_needed = sprintf( _nx( ' %d other subscription was checked and did not need any repairs.', '%d other subscriptions were checked and did not need any repairs.', $subscription_counts['unrepaired_count'], 'Repair message that gets sent to front end.', 'woocommerce-subscriptions' ), $subscription_counts['unrepaired_count'] );
					}

					// translators: placeholder is "{execution_time}", which will be replaced on front end with actual time
					$repair_time = sprintf( _x( '(in %s seconds)', 'Repair message that gets sent to front end.', 'woocommerce-subscriptions' ), '{execution_time}' );

					// translators: $1: "Repaired x subs with incorrect dates...", $2: "X others were checked and no repair needed", $3: "(in X seconds)". Ordering for RTL languages.
					$repair_message = sprintf( _x( '%1$s%2$s %3$s', 'The assembled repair message that gets sent to front end.', 'woocommerce-subscriptions' ), $repair_incorrect, $repair_not_needed, $repair_time );

					$results = array(
						'repaired_count'   => $subscription_counts['repaired_count'],
						'unrepaired_count' => $subscription_counts['unrepaired_count'],
						'message'          => $repair_message,
						'status'           => 'success',
						// translators: placeholder is "{time_left}", will be replaced on front end with actual time
						'time_message'     => sprintf( _x( 'Estimated time left (minutes:seconds): %s', 'Message that gets sent to front end.', 'woocommerce-subscriptions' ), '{time_left}' ),
					);

				} catch ( Exception $e ) {

					WCS_Upgrade_Logger::add( sprintf( 'Error on upgrade step: %s. Error: %s', $_POST['upgrade_step'], $e->getMessage() ) );

					$results = array(
						'repaired_count'   => 0,
						'unrepaired_count' => 0,
						// translators: 1$: error message, 2$: opening link tag, 3$: closing link tag
						'message'          => sprintf( _x( 'Unable to repair subscriptions.<br/>Error: %1$s<br/>Please refresh the page and try again. If problem persists, %2$scontact support%3$s.', 'Error message that gets sent to front end when upgrading Subscriptions', 'woocommerce-subscriptions' ), '<code>' . $e->getMessage(). '</code>', '<a href="' . esc_url( 'https://woocommerce.com/my-account/create-a-ticket/' ) . '">', '</a>' ),
						'status'           => 'error',
					);
				}

				break;
		}

		if ( 'subscriptions' == $_POST['upgrade_step'] && 0 === self::get_total_subscription_count_query() ) {

			self::upgrade_complete();

		} elseif ( 'subscription_dates_repair' == $_POST['upgrade_step'] ) {

			$subscriptions_to_repair = WCS_Repair_2_0_2::get_subscriptions_to_repair( self::$upgrade_limit_subscriptions );

			if ( empty( $subscriptions_to_repair ) ) {
				self::upgrade_complete();
			}
		}

		WCS_Upgrade_Logger::add( sprintf( 'Completed upgrade step: %s', $_POST['upgrade_step'] ) );

		header( 'Content-Type: application/json; charset=utf-8' );
		echo wcs_json_encode( $results );
		exit();
	}

	/**
	 * Handle upgrades for really old versions.
	 *
	 * @since 2.0
	 */
	private static function upgrade_really_old_versions() {

		if ( '0' != self::$active_version && version_compare( self::$active_version, '1.2', '<' ) ) {
			include_once( 'class-wcs-upgrade-1-2.php' );
			self::generate_renewal_orders();
			update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '1.2' );
			$upgraded_versions = '1.2, ';
		}

		// Add Variable Subscription product type term
		if ( '0' != self::$active_version && version_compare( self::$active_version, '1.3', '<' ) ) {
			include_once( 'class-wcs-upgrade-1-3.php' );
			update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '1.3' );
			$upgraded_versions .= '1.3 & ';
		}

		// Moving subscription meta out of user meta and into item meta
		if ( '0' != self::$active_version && version_compare( self::$active_version, '1.4', '<' ) ) {
			include_once( 'class-wcs-upgrade-1-4.php' );
			update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '1.4' );
			$upgraded_versions .= '1.4.';
		}

		return $upgraded_versions;
	}

	/**
	 * Version 1.2 introduced child renewal orders to keep a record of each completed subscription
	 * payment. Before 1.2, these orders did not exist, so this function creates them.
	 *
	 * @since 1.2
	 */
	private static function generate_renewal_orders() {
		global $wpdb;
		$woocommerce = WC();

		$subscriptions_grouped_by_user = WC_Subscriptions_Manager::get_all_users_subscriptions();

		// Don't send any order emails
		$email_actions = array( 'woocommerce_low_stock', 'woocommerce_no_stock', 'woocommerce_product_on_backorder', 'woocommerce_order_status_pending_to_processing', 'woocommerce_order_status_pending_to_completed', 'woocommerce_order_status_pending_to_on-hold', 'woocommerce_order_status_failed_to_processing', 'woocommerce_order_status_failed_to_completed', 'woocommerce_order_status_pending_to_processing', 'woocommerce_order_status_pending_to_on-hold', 'woocommerce_order_status_completed', 'woocommerce_new_customer_note' );
		foreach ( $email_actions as $action ) {
			remove_action( $action, array( &$woocommerce, 'send_transactional_email' ) );
		}

		remove_action( 'woocommerce_payment_complete', 'WC_Subscriptions_Renewal_Order::maybe_record_renewal_order_payment', 10, 1 );

		foreach ( $subscriptions_grouped_by_user as $user_id => $users_subscriptions ) {
			foreach ( $users_subscriptions as $subscription_key => $subscription ) {
				$order_post = get_post( $subscription['order_id'] );

				if ( isset( $subscription['completed_payments'] ) && count( $subscription['completed_payments'] ) > 0 && null != $order_post ) {
					foreach ( $subscription['completed_payments'] as $payment_date ) {

						$existing_renewal_order = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_date_gmt = %s AND post_parent = %d AND post_type = 'shop_order'", $payment_date, $subscription['order_id'] ) );

						// If a renewal order exists on this date, don't generate another one
						if ( null !== $existing_renewal_order ) {
							continue;
						}

						$renewal_order_id = WC_Subscriptions_Renewal_Order::generate_renewal_order( $subscription['order_id'], $subscription['product_id'], array( 'new_order_role' => 'child' ) );

						if ( $renewal_order_id ) {

							// Mark the order as paid
							$renewal_order = new WC_Order( $renewal_order_id );

							$renewal_order->payment_complete();

							// Avoid creating 100s "processing" orders
							$renewal_order->update_status( 'completed' );

							// Set correct dates on the order
							$renewal_order = array(
								'ID'            => $renewal_order_id,
								'post_date'     => $payment_date,
								'post_date_gmt' => $payment_date,
							);
							wp_update_post( $renewal_order );

							update_post_meta( $renewal_order_id, '_paid_date', $payment_date );
							update_post_meta( $renewal_order_id, '_completed_date', $payment_date );

						}
					}
				}
			}
		}
	}

	/**
	 * Let the site administrator know we are upgrading the database and provide a confirmation is complete.
	 *
	 * This is important to avoid the possibility of a database not upgrading correctly, but the site continuing
	 * to function without any remedy.
	 *
	 * @since 1.2
	 */
	public static function display_database_upgrade_helper() {

		wp_register_style( 'wcs-upgrade', plugins_url( '/assets/css/wcs-upgrade.css', WC_Subscriptions::$plugin_file ) );
		wp_register_script( 'wcs-upgrade', plugins_url( '/assets/js/wcs-upgrade.js', WC_Subscriptions::$plugin_file ), 'jquery' );

		if ( version_compare( self::$active_version, '2.0.0', '<' ) ) {
			// We're running the 2.0 upgrade routine
			$subscription_count = self::get_total_subscription_count();
		} elseif ( version_compare( self::$active_version, '2.0.0', '>=' ) && version_compare( self::$active_version, '2.0.2', '<' ) ) {
			// We're running the 2.0.2 repair routine
			$subscription_counts = wp_count_posts( 'shop_subscription' );
			$subscription_count  = array_sum( (array) $subscription_counts ) - $subscription_counts->trash - $subscription_counts->{'auto-draft'};
		} else {
			// How did we get here?
			$subscription_count = 0;
		}

		$script_data = array(
			'really_old_version'        => ( version_compare( self::$active_version, '1.4', '<' ) ) ? 'true' : 'false',
			'upgrade_to_1_5'            => ( version_compare( self::$active_version, '1.5', '<' ) ) ? 'true' : 'false',
			'upgrade_to_2_0'            => ( version_compare( self::$active_version, '2.0.0', '<' ) ) ? 'true' : 'false',
			'repair_2_0'                => ( version_compare( self::$active_version, '2.0.0', '>=' ) && version_compare( self::$active_version, '2.0.2', '<' ) ) ? 'true' : 'false',
			'hooks_per_request'         => self::$upgrade_limit_hooks,
			'ajax_url'                  => admin_url( 'admin-ajax.php' ),
			'upgrade_nonce'             => wp_create_nonce( 'wcs_upgrade_process' ),
			'subscription_count'        => $subscription_count,
		);

		wp_localize_script( 'wcs-upgrade', 'wcs_update_script_data', $script_data );

		// Can't get subscription count with database structure < 1.4
		if ( 'false' == $script_data['really_old_version'] ) {

			// The base duration is 50 subscriptions per minute (i.e. approximately 60 seconds per batch of 50)
			$estimated_duration = ceil( $subscription_count / 50 );

			// Large sites take about 2-3x as long (i.e. approximately 80 seconds per batch of 35)
			if ( $subscription_count > 5000 ) {
				$estimated_duration *= 3;
			}

			// And really large sites take around 5-6x as long (i.e. approximately 100 seconds per batch of 25)
			if ( $subscription_count > 10000 ) {
				$estimated_duration *= 2;
			}
		}

		$about_page_url = self::$about_page_url;

		@header( 'Content-Type: ' . get_option( 'html_type' ) . '; charset=' . get_option( 'blog_charset' ) );
		include_once( 'templates/wcs-upgrade.php' );
		WCS_Upgrade_Logger::add( 'Loaded database upgrade helper' );
	}

	/**
	 * Let the site administrator know we are upgrading the database already to prevent duplicate processes running the
	 * upgrade. Also provides some useful diagnostic information, like how long before the site admin can restart the
	 * upgrade process, and how many subscriptions per request can typically be updated given the amount of memory
	 * allocated to PHP.
	 *
	 * @since 1.4
	 */
	public static function upgrade_in_progress_notice() {
		include_once( 'templates/wcs-upgrade-in-progress.php' );
		WCS_Upgrade_Logger::add( 'Loaded database upgrade in progress notice...' );
	}

	/**
	 * Display the Subscriptions welcome/about page after successfully upgrading to the latest version.
	 *
	 * @since 1.4
	 */
	public static function updated_welcome_page() {
		$about_page = add_dashboard_page( __( 'Welcome to WooCommerce Subscriptions 2.1', 'woocommerce-subscriptions' ), __( 'About WooCommerce Subscriptions', 'woocommerce-subscriptions' ), 'manage_options', 'wcs-about', __CLASS__ . '::about_screen' );
		add_action( 'admin_print_styles-'. $about_page, __CLASS__ . '::admin_css' );
		add_action( 'admin_head',  __CLASS__ . '::admin_head' );
	}

	/**
	 * admin_css function.
	 *
	 * @access public
	 * @return void
	 */
	public static function admin_css() {
		wp_enqueue_style( 'woocommerce-subscriptions-about', plugins_url( '/assets/css/about.css', WC_Subscriptions::$plugin_file ), array(), self::$active_version );
	}

	/**
	 * Add styles just for this page, and remove dashboard page links.
	 *
	 * @access public
	 * @return void
	 */
	public static function admin_head() {
		remove_submenu_page( 'index.php', 'wcs-about' );
	}

	/**
	 * Output the about screen.
	 */
	public static function about_screen() {

		$active_version = self::$active_version;
		$settings_page  = admin_url( 'admin.php?page=wc-settings&tab=subscriptions' );

		include_once( 'templates/wcs-about.php' );
	}

	/**
	 * In v2.0 and newer, it's possible to simply use wp_count_posts( 'shop_subscription' ) to count subscriptions,
	 * but not in v1.5, because a subscription data is still stored in order item meta. This function queries the
	 * v1.5 database structure.
	 *
	 * @since 2.0
	 */
	private static function get_total_subscription_count( $initial = false ) {

		if ( $initial ) {

			$subscription_count = get_option( 'wcs_upgrade_initial_total_subscription_count', false );

			if ( false === $subscription_count ) {
				$subscription_count = self::get_total_subscription_count();
				update_option( 'wcs_upgrade_initial_total_subscription_count', $subscription_count );
			}
		} else {

			if ( null === self::$old_subscription_count ) {
				self::$old_subscription_count = self::get_total_subscription_count_query();
			}

			$subscription_count = self::$old_subscription_count;
		}

		return $subscription_count;
	}

	/**
	 * Returns the number of subscriptions left in the 1.5 structure
	 * @return integer number of 1.5 subscriptions left
	 */
	private static function get_total_subscription_count_query() {
		global $wpdb;

		$query = self::get_subscription_query();

		$wpdb->get_results( $query );

		return $wpdb->num_rows;
	}

	/**
	 * Single source of truth for the query
	 * @param  integer $limit the number of subscriptions to get
	 * @return string        SQL query of what we need
	 */
	public static function get_subscription_query( $batch_size = null ) {
		global $wpdb;

		if ( null === $batch_size ) {
			$select = 'SELECT DISTINCT items.order_item_id';
			$limit = '';
		} else {
			$select = 'SELECT meta.*, items.*';
			$limit = sprintf( ' LIMIT 0, %d', $batch_size );
		}

		$query = sprintf( "%s FROM `{$wpdb->prefix}woocommerce_order_itemmeta` AS meta
			LEFT JOIN `{$wpdb->prefix}woocommerce_order_items` AS items USING (order_item_id)
			LEFT JOIN (
				SELECT a.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta` AS a
				LEFT JOIN (
					SELECT `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta`
					WHERE `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_key = '_subscription_status'
				) AS s
				USING (order_item_id)
				WHERE 1=1
				AND a.order_item_id = s.order_item_id
				AND a.meta_key = '_subscription_start_date'
				ORDER BY CASE WHEN CAST(a.meta_value AS DATETIME) IS NULL THEN 1 ELSE 0 END, CAST(a.meta_value AS DATETIME) ASC
				%s
			) AS a3 USING (order_item_id)
			WHERE meta.meta_key REGEXP '_subscription_(.*)|_product_id|_variation_id'
			AND meta.order_item_id = a3.order_item_id
			AND items.order_item_id IS NOT NULL", $select, $limit );

		return $query;
	}

	/**
	 * Check if the database has some data that was migrated from 1.5 to 2.0
	 *
	 * @return bool True if it detects some v1.5 migrated data, otherwise false
	 */
	protected static function migrated_subscription_count() {
		global $wpdb;

		$migrated_subscription_count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT `post_id`) FROM $wpdb->postmeta
			 WHERE `meta_key` LIKE '%wcs\_migrated%'"
		);

		return $migrated_subscription_count;
	}

	/**
	 * While the upgrade is in progress, we need to block IPN messages to avoid renewals failing to process correctly.
	 *
	 * PayPal will retry the IPNs for up to a day or two until it has a successful request, so the store will continue to receive
	 * IPN messages during the upgrade process, then once it is completed, the IPN will be successfully processed.
	 *
	 * The method returns a 409 Conflict HTTP response code to indicate that the IPN is conflicting with the upgrader.
	 *
	 * @since 2.0
	 */
	public static function maybe_block_paypal_ipn() {
		if ( false !== get_option( 'wc_subscriptions_is_upgrading', false ) ) {
			WCS_Upgrade_Logger::add( '*** PayPal IPN Request blocked: ' . print_r( wp_unslash( $_POST ), true ) ); // No CSRF needed as it's from outside
			wp_die( 'PayPal IPN Request Failure', 'PayPal IPN', array( 'response' => 409 ) );
		}
	}

	/**
	 * Used to check if a user ID is greater than the last user upgraded to version 1.4.
	 *
	 * Needs to be a separate function so that it can use a static variable (and therefore avoid calling get_option() thousands
	 * of times when iterating over thousands of users).
	 *
	 * @since 1.4
	 */
	public static function is_user_upgraded_to_1_4( $user_id ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Upgrade_1_4::is_user_upgraded( $user_id )' );
		return WCS_Upgrade_1_4::is_user_upgraded( $user_id );
	}
}
add_action( 'after_setup_theme', 'WC_Subscriptions_Upgrader::init', 11 );
