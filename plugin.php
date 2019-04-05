<?php
/**
 * Plugin Name: Give EDD Software Licensing API Extended
 * Plugin URI: https://givewp.com
 * Description: Add more api endpoints for Easy Digital Downloads - Software Licenses.
 * Author: WordImpress
 * Author URI: https://wordimpress.com
 * Version: 0.2
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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
class Give_EDD_Software_Licensing_API_Extended {

	/**
	 * Instance.
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @var    object $instance
	 */
	static private $instance;

	/**
	 * Give_EDD_Software_Licensing_API_Extended constructor.
	 */
	private function __construct() {
	}


	/**
	 * Get instance.
	 *
	 * @since   0.1
	 * @access  public
	 * @wp-hook plugins_loaded
	 *
	 * @return  mixed
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
	 * @since   0.1
	 * @access  public
	 * @wp-hook plugins_loaded
	 *
	 * @return  void
	 */
	public function plugin_setup() {
		add_filter( 'edd_remote_license_check_response', array( $this, 'remote_license_check' ), 10, 3 );
		add_action( 'edd_check_subscription', array( $this, 'remote_subscription_check' ) );
	}


	/**
	 * Add custom data to api response
	 *
	 * @since 0.3
	 *
	 * @todo: return result only if source set to give
	 *
	 * @param $response
	 * @param $args
	 * @param $license_id
	 *
	 * @return mixed
	 */
	public function remote_license_check( $response, $args, $license_id ){
		/* @var EDD_SL_License $license */
		$license = EDD_Software_Licensing::instance()->get_license( $license_id );

		/* @var EDD_Download $license */
		$download = $license->download;

		$download_file_info = $this->get_latest_release_url($download->get_files(  $license->price_id ) );

		// Bailout: verify if we are looking at give addon or others.
		if( ! $download_file_info ) {
			return $response;
		}

		$response['download_file'] = edd_get_download_file_url(
			$license->key,
			$response['customer_email'],
			$download_file_info['index'],
			$download->ID,
			$license->price_id
		);
		$response['current_version'] = get_post_meta( $download->ID, '_edd_sl_version', true );

		// Set plugin slug if missing.
		if( ! $response['item_name'] && $response['download_file'] ) {
			$args['item_name'] = $response['item_name'] = str_replace(  ' ', '-', strtolower( edd_software_licensing()->get_download_name( $license_id ) ));
			$response['license'] = edd_software_licensing()->get_download_version( $download->ID );
		}

		return $response;
	}


	/**
	 * Get latest release url
	 *
	 * @since 0.3
	 *
	 * @param array $download_files
	 *
	 * @return array
	 */
	private function get_latest_release_url( $download_files ){
		if( empty($download_files ) ) {
			return array();
		}

		/**
		 * Things we are assuming here
		 * 1. plugin file name must start with give
		 * 2. Only latest version file path will be without plugin version
		 * 3. download file always contain only one url to latest release.
		 */
		foreach ( $download_files as $download_file ) {
			$zip_filename = basename( $download_file['file'] );
			 preg_match( '/-d/', $zip_filename, $version_number_part );

			// Must be a give addon.
			if(
				! empty( $version_number_part ) // if muber detected in url then download file belong to older version.
				|| false === strpos( $zip_filename, 'give' )
			){
				continue;
			}

			return $download_file;
		}
	}

	/**
	 * Get subscription data.
	 *
	 * @since  0.1
	 * @access public
	 *
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

		$item_id     = ! empty( $data['item_id'] ) ? absint( $data['item_id'] ) : false;
		$item_name   = ! empty( $data['item_name'] ) ? rawurldecode( $data['item_name'] ) : false;
		$license_key = urldecode( $data['license'] );
		$url         = isset( $data['url'] ) ? urldecode( $data['url'] ) : '';
		$license = edd_software_licensing()->get_license( $license_key, true );

		$subscriptions = array();
		foreach ( $license->payment_ids as $payment_id ) {
			$subscription = $this->get_subscription( $payment_id );
			if('active' === $subscription['status']) {
				$subscriptions[] = $subscription;
			}
		}

		header( 'Content-Type: application/json' );
		echo wp_json_encode(
			apply_filters(
				'give_edd_remote_license_check_response',
				array(
					'success'     => ! empty( $subscriptions ),
					'id'          => ! empty( $subscription['id'] ) ? absint( $subscription['id'] ) : '',
					'license_key' => $license_key,
					'status'      => ! empty( $subscription['status'] ) ? $subscription['status'] : '',
					'expires'     => ! empty( $subscription['expiration'] ) ? ( is_numeric( $subscription['expiration'] ) ? date( 'Y-m-d H:i:s', $subscription['expiration'] ) : $subscription['expiration'] ) : '',
					'payment_id'  => $payment_id,
					'invoice_url' => urlencode( add_query_arg( 'payment_key', edd_get_payment_key( $payment_id ), edd_get_success_page_uri() ) ),
				),
				$data,
				$license
			)
		);

		exit;
	}
}


// Initialize plugin.
add_action(
	'plugins_loaded',
	array(
		Give_EDD_Software_Licensing_API_Extended::get_instance(),
		'plugin_setup',
	)
);
