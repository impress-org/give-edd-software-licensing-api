<?php
/**
 * Plugin Name: Give EDD Software Licensing API Extended
 * Plugin URI: https://givewp.com
 * Description:
 * Author: WordImpress
 * Author URI: https://wordimpress.com
 * Version: 0.1
 *
 * Give EDD Software Licensing API Extended is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Give EDD Software Licensing API Extended is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Give. If not, see <http://www.gnu.org/licenses/>.
 *
 * @package Give-EDD-Software-Licensing-API-Extended
 */

if ( ! defined( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_DIR' ) ) {
	define( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_URL' ) ) {
	define( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_FILE' ) ) {
	define( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'EDD_SL_VERSION' ) ) {
	define( 'EDD_SL_VERSION', '0.1' );
}

// Do nothing if EDD Software Licensing plugin is not activated.
$active_plugins = array_map( 'strtolower', get_option( 'active_plugins', array() ) );
if ( ! in_array( 'edd-software-licensing/edd-software-licenses.php', $active_plugins, true ) ) {
	return;
}


/**
 * Class Give_EDD_Software_Licensing_API_Extended
 */
class Give_EDD_Software_Licensing_API_Extended extends EDD_Software_Licensing{

	/**
	 * Instance.
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @var object $instance
	 */
	static private $instance;

	/**
	 * Give_EDD_Software_Licensing_API_Extended constructor.
	 */
	private function __construct(){}


	/**
	 * Get instance.
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @return mixed
	 */
	static public function get_instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Add hooks.
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'edd_check_subscription', array( $this, 'remote_subscription_check' ) );
	}


	/**
	 * Check if payment is for subscription or not.
	 *
	 * @since  0.1
	 * @access public
	 * @param  int $payment_id Payment ID.
	 *
	 * @return bool|string
	 */
	public function is_subscription( $payment_id ) {
		return get_post_meta( $payment_id, '_edd_subscription_payment', true );
	}


	/**
	 * Get subscription data.
	 *
	 * @since  0.1
	 * @access public
	 * @param  int $payment_id Payment ID.
	 *
	 * @return array|object|null Subscription data
	 */
	function get_subscription( $payment_id ) {
		global $wpdb;

		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}edd_subscriptions WHERE parent_payment_id=%d",
				$payment_id
			),
			ARRAY_A
		);

		return ( ! empty( $result ) ? current( $result ) : array() );
	}

	/**
	 * Get licenses key.
	 *
	 * @since  0.1
	 * @access public
	 * @param  int $payment_id Payment ID.
	 *
	 * @return array|object|null Subscription data
	 */
	function get_licenses( $payment_id ) {
		global $wpdb;

		// Get license ids.
		$result = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_edd_sl_payment_id' AND meta_value=%d",
				$payment_id
			)
		);

		if ( ! empty( $result ) ) {
			$license_ids = implode( ',', $result );

			// Get license keys.
			$result = $wpdb->get_col(
				$wpdb->prepare(
					"
                    SELECT meta_value FROM $wpdb->postmeta
                    WHERE meta_key='%s'
                    AND post_id
                    IN (%s)
                    ",
					'_edd_sl_key',
					$license_ids
				)
			);
		}

		return $result;
	}


	/**
	 * Check subscription for addon
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @param  array $data Api request data.
	 *
	 * @return void
	 */
	function remote_subscription_check( $data ) {

		$item_id     = ! empty( $data['item_id'] )   ? absint( $data['item_id'] ) : false;
		$item_name   = ! empty( $data['item_name'] ) ? rawurldecode( $data['item_name'] ) : false;
		$license     = urldecode( $data['license'] );
		$url         = isset( $data['url'] ) ? urldecode( $data['url'] ) : '';

		$license_id  = $this->get_license_by_key( $license );
		$payment_id  = get_post_meta( $license_id, '_edd_sl_payment_id', true );

		$subscription = $this->get_subscription( $payment_id );
		$license      = ( $license = $this->get_licenses( $payment_id ) ) ? current( $license ) : '';

		header( 'Content-Type: application/json' );
		echo wp_json_encode(
		    apply_filters(
		        'give_edd_remote_license_check_response',
				array(
					'success'          => (bool) $subscription,
					'id'               => ! empty( $subscription['id'] ) ? absint( $subscription['id'] ) : '',
					'license_key'      => $license,
					'status'           => ! empty( $subscription['status'] ) ? $subscription['status'] : '',
					'expires'          => ! empty( $subscription['expiration'] ) ? ( is_numeric( $subscription['expiration'] ) ? date( 'Y-m-d H:i:s', $subscription['expiration'] ) : $subscription['expiration'] ) : '',
					'payment_id'       => $payment_id,
					'invoice_url'      => urlencode( add_query_arg( 'payment_key', edd_get_payment_key( $payment_id ), edd_get_success_page_uri() ) ),
				),
				$data,
				$license_id
			)
		);

		exit;
	}
}

// Initialize plugin.
Give_EDD_Software_Licensing_API_Extended::get_instance()->hooks();

