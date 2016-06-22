<?php
/**
 * WooCommerce Subscriptions Webhook class
 *
 * This class introduces webhooks to, storing and retrieving webhook data from the associated
 * `shop_webhook` custom post type, as well as delivery logs from the `webhook_delivery`
 * comment type.
 *
 * Subscription Webhooks are enqueued to their associated actions, delivered, and logged.
 *
 * @author      Prospress
 * @category    Webhooks
 * @since       2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Webhooks {

	/**
	 * Setup webhook for subscriptions
	 *
	 * @since 2.0
	 */
	public static function init() {

		add_filter( 'woocommerce_webhook_topic_hooks', __CLASS__ . '::add_topics', 10, 2 );

		add_filter( 'woocommerce_webhook_payload', __CLASS__ . '::create_payload', 10, 4 );

		add_filter( 'woocommerce_valid_webhook_resources', __CLASS__ . '::add_resource', 10, 1 );

		add_action( 'woocommerce_checkout_subscription_created', __CLASS__ . '::add_subscription_created_callback', 10, 1 );

		add_action( 'woocommerce_subscription_date_updated', __CLASS__ . '::add_subscription_updated_callback', 10, 1 );

		add_filter( 'woocommerce_webhook_topics' , __CLASS__ . '::add_topics_admin_menu', 10, 1 );

	}

	/**
	 * Add Subscription webhook topics
	 *
	 * @param array $topic_hooks
	 * @since 2.0
	 */
	public static function add_topics( $topic_hooks, $webhook ) {

		if ( 'subscription' == $webhook->get_resource() ) {
			$topic_hooks = apply_filters( 'woocommerce_subscriptions_webhook_topics', array(
				'subscription.created' => array(
					'wcs_api_subscription_created',
					'wcs_webhook_subscription_created',
					'woocommerce_process_shop_subscription_meta',
				),
				'subscription.updated' => array(
					'wc_api_subscription_updated',
					'woocommerce_subscription_status_changed',
					'wcs_webhook_subscription_updated',
					'woocommerce_process_shop_subscription_meta',
				),
				'subscription.deleted' => array(
					'woocommerce_subscription_trashed',
					'woocommerce_subscription_deleted',
					'woocommerce_api_delete_subscription',
				),
			), $webhook );
		}

		return $topic_hooks;
	}

	/**
	 * Add Subscription topics to the Webhooks dropdown menu in when creating a new webhook.
	 *
	 * @since 2.0
	 */
	public static function add_topics_admin_menu( $topics ) {

		$front_end_topics = array(
			'subscription.created' => __( ' Subscription Created', 'woocommerce-subscriptions' ),
			'subscription.updated' => __( ' Subscription Updated', 'woocommerce-subscriptions' ),
			'subscription.deleted' => __( ' Subscription Deleted', 'woocommerce-subscriptions' ),
			'subscription.switched' => __( ' Subscription Switched', 'woocommerce-subscriptions' ),
		);

		return array_merge( $topics, $front_end_topics );
	}

	/**
	 * Setup payload for subscription webhook delivery.
	 *
	 * @since 2.0
	 */
	public static function create_payload( $payload, $resource, $resource_id, $id ) {

		if ( 'subscription' == $resource && empty( $payload ) && wcs_is_subscription( $resource_id ) ) {

			$webhook      = new WC_Webhook( $id );
			$event        = $webhook->get_event();
			$current_user = get_current_user_id();

			wp_set_current_user( $webhook->get_user_id() );

			WC()->api->WC_API_Subscriptions->register_routes( array() );

			$payload = WC()->api->WC_API_Subscriptions->get_subscription( $resource_id );

			wp_set_current_user( $current_user );
		}

		return $payload;
	}

	/**
	 * Add webhook resource for subscription.
	 *
	 * @param array $resources
	 * @since 2.0
	 */
	public static function add_resource( $resources ) {

		$resources[] = 'subscription';

		return $resources;
	}

	/**
	 * Call a "subscription created" action hook with the first parameter being a subscription id so that it can be used
	 * for webhooks.
	 *
	 * @since 2.0
	 */
	public static function add_subscription_created_callback( $subscription ) {
		do_action( 'wcs_webhook_subscription_created', $subscription->id );
	}

	/**
	 * Call a "subscription updated" action hook with a subscription id as the first parameter to be used for webhooks payloads.
	 *
	 * @since 2.0
	 */
	public static function add_subscription_updated_callback( $subscription ) {
		do_action( 'wcs_webhook_subscription_updated', $subscription->id );
	}

}
WCS_Webhooks::init();
