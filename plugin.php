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
	private static $instance;

	/**
	 * Give_EDD_Software_Licensing_API_Extended constructor.
	 */
	private function __construct() {
	}


	/**
	 * Get instance.
	 *
	 * @return  mixed
	 * @since   0.1
	 * @access  public
	 * @wp-hook plugins_loaded
	 *
	 */
	public static function get_instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Add hooks.
	 *
	 * @return  void
	 * @since   0.1
	 * @access  public
	 * @wp-hook plugins_loaded
	 *
	 */
	public function plugin_setup() {
		add_filter( 'edd_remote_license_check_response', array( $this, 'remote_license_check' ), 10, 3 );
		add_action( 'edd_check_subscription', array( $this, 'remote_subscription_check' ) );
		add_action( 'edd_check_licenses', array( $this, 'remote_licenses_check' ) );
	}


	/**
	 * Check licenses in bulk
	 *
	 * @param $args
	 */
	public function remote_licenses_check( $args ) {
		$defaults = array(
			'licenses'  => '',
			'url'       => '',
		);

		$args             = array_map( 'sanitize_text_field', $args );
		$args             = wp_parse_args( $args, $defaults );
		$args['licenses'] = array_map( 'trim', explode( ',', $args['licenses'] ) );

		if ( ! $args['url'] ) {

			// Attempt to grab the URL from the user agent if no URL is specified
			$domain      = array_map( 'trim', explode( ';', $_SERVER['HTTP_USER_AGENT'] ) );
			$args['url'] = trim( $domain[1] );

		}

		$response = array();

		if ( $args['licenses'] ) {
			foreach ( $args['licenses'] as $license ) {
				$response[ $license ] = array(
					'check_license' => '',
					'get_version'   => '',
				);

				$remote_response = wp_remote_post(
				// 'https://givewp.com/checkout/',
					'http://staging.givewp.com/chekout/', // For testing purpose
					array(
						'timeout'   => 15,
						'sslverify' => false,
						'body'      => array(
							'edd_action' => 'check_license',
							'license'    => $license,
							'url'        => $args['url'],
						),
					)
				);

				$response[ $license ]['check_license'] = ! is_wp_error( $remote_response )
					? json_decode( wp_remote_retrieve_body( $remote_response ), true )
					: $remote_response;

				if ( $response[ $license ]['check_license']['success'] ) {
					$remote_response = wp_remote_post(
					// 'https://givewp.com/checkout/',
						'http://staging.givewp.com/chekout/', // For testing purpose
						array(
							'timeout'   => 15,
							'sslverify' => false,
							'body'      => array(
								'edd_action' => 'get_version',
								'license'    => $license,
								'url'        => $args['url'],
								'item_name'  => $response[ $license ]['check_license']['item_name'],
							),
						)
					);

					$response[ $license ]['get_version'] = ! is_wp_error( $remote_response )
						? json_decode( wp_remote_retrieve_body( $remote_response ), true )
						: $remote_response;
				}
			}
		}


		header( 'Content-Type: application/json' );
		echo wp_json_encode( $response );

		exit;
	}


	/**
	 * Add custom data to api response
	 *
	 * @param $response
	 * @param $args
	 * @param $license_id
	 *
	 * @return mixed
	 * @since 0.3
	 *
	 * @todo  : return result only if source set to give or something else because we do not want to sent extra information on every license check
	 *
	 */
	public function remote_license_check( $response, $args, $license_id ) {
		// @todo: decide whether all access pass can be varibale priced if yes then how it will impect code..
		// @todo: decide whether send this addition license data to only add-ons page of Give core.
		// @todo discuss with devin that do we agree to deactivate license if it this request will come from non registered site
		/* @var EDD_SL_License $license */
		$license = EDD_Software_Licensing::instance()->get_license( $license_id );

		// Bailout if license does not found.
		if ( ! $license || ! $response['success'] ) {
			return $response;
		}

		/* @var EDD_Download $license */
		$download = $license->download;

		$response['license_key']        = $args['key'];
		$response['license_id']         = $license->ID;
		$response['payment_id']         = $license->payment_id;
		$response['download']           = '';
		$response['is_all_access_pass'] = false;

		// @todo: review till when we have to show download links for all access pass or single license.
		// @todo: check if need to verify download limit
		if (
			function_exists( 'edd_all_access_download_is_all_access' )
			&& edd_all_access_download_is_all_access( $download->id )
		) {
			$response['is_all_access_pass'] = true;

			/* @var  EDD_All_Access_Pass $all_access_pass */
			$all_access_pass = new EDD_All_Access_Pass( $license->payment_id, $download->ID, $license->price_id );

			// Get downloads attached to all access pass.
			if ( ! empty( $all_access_pass->all_access_meta['all_access_categories'] ) ) {
				$response['download'] = array();

				$included_downloads = new WP_Query( array(
					'post_type'      => 'download',
					'post_status'    => 'publish',
					'tax_query'      => array(
						array(
							'taxonomy' => 'download_category',
							'field'    => 'term_id',
							'terms'    => $all_access_pass->all_access_meta['all_access_categories'],
						),
					),
					'posts_per_page' => - 1,
				) );

				// print_r( $included_downloads );

				if ( $included_downloads->have_posts() ) {
					while ( $included_downloads->have_posts() ) {
						$included_downloads->the_post();

						$included_download = new EDD_Download( get_the_ID() );
						$file              = $this->get_latest_release( $included_download->files );

						// @todo by default this function set price id to default variable price if any . revalidate this if it will create any issue or not
						// Override exiting file url with protect download file url.
						$file['plugin_slug']     = basename( $file['file'], '.zip' );
						$file['file']            = edd_all_access_product_download_url( $included_download->ID, 0, $file['array_index'] );
						//$file['current_version'] = edd_software_licensing()->get_download_version( $included_download->ID );
						$file['name']            = str_replace( ' ', '-', strtolower( get_post_field( 'post_title', get_the_ID(), 'raw' ) ) );
						$file['readme']          = get_post_meta( get_the_ID(), '_edd_readme_location', true );
						$response['download'][]  = $file;
					}

					wp_reset_postdata();
				}
			}
		} else {
			$download_file_info = $this->get_latest_release( $download->get_files( $license->price_id ) );

			// Bailout: verify if we are looking at give addon or others.
			if ( ! $download_file_info ) {
				return $response;
			}

			$response['download'] = edd_get_download_file_url(
				edd_get_payment_key( $license->payment_id ), // @todo: which payment id we need to pass if multiple payment happen.
				$response['customer_email'],
				$download_file_info['array_index'],
				$download->ID,
				$license->price_id
			);

			//$response['current_version'] = edd_software_licensing()->get_download_version( $download->ID );
			$response['readme']          = get_post_meta( $download->ID, '_edd_readme_location', true );
			$response['plugin_slug']     = basename( $download_file_info['file'], '.zip' );

			// Backward compatibility for subscription bundles.
			$subscription             = $this->subscription_check( array( 'license' => $args['key'] ) );
			$response['subscription'] = array();

			if ( $subscription['status'] ) {
				$response['subscription'] = $subscription;
			}
		}

		// Set plugin slug if missing.
		if ( ! $response['item_name'] ) {
			$args['item_name']   = $response['item_name'] = edd_software_licensing()->get_download_name( $license_id );
			$response['license'] = edd_software_licensing()->check_license( $args );
		}

		return $response;
	}


	/**
	 * Get latest release url
	 *
	 * @param array $download_files
	 *
	 * @return array
	 * @since 0.3
	 *
	 */
	private function get_latest_release( $download_files ) {
		if ( empty( $download_files ) ) {
			return array();
		}

		/**
		 * Things we are assuming here
		 * 1. plugin file name must start with give
		 * 2. Only latest version file path will be without plugin version
		 * 3. download file always contain only one url to latest release.
		 */
		foreach ( $download_files as $index => $download_file ) {
			// @todo: currently we are only check if url contain does not contain any version number if not then it will be latest release url. revalidate it.
			$zip_filename = basename( $download_file['file'] );
			preg_match( '/-d/', $zip_filename, $version_number_part );

			// Must be a give addon.
			if (
				! empty( $version_number_part ) // if muber detected in url then download file belong to older version.
				|| false === strpos( $zip_filename, 'give' )
			) {
				continue;
			}

			// We need this for edd_get_download_file_url.
			$download_file['array_index'] = $index;

			return $download_file;
		}
	}

	/**
	 * Get subscription data.
	 *
	 * @param int $payment_id Payment ID.
	 *
	 * @return array|object|null Subscription data
	 * @since  0.1
	 * @access public
	 *
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
	 * Get subscription information
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	private function subscription_check( $data ) {
		$license_key = urldecode( $data['license'] );

		/* @var EDD_License $license */
		$license = edd_software_licensing()->get_license( $license_key, true );

		$subscriptions = array();
		foreach ( $license->payment_ids as $payment_id ) {
			$subscription = $this->get_subscription( $payment_id );
			if ( 'active' === $subscription['status'] ) {
				$subscriptions[] = $subscription;
			}
		}

		/* @var EDD_License $subscription_license_obj */
		$subscription_license_obj = edd_software_licensing()->get_license_by_purchase( $payment_id );

		return array(
			'success'          => ! empty( $subscriptions ),
			'id'               => ! empty( $subscription['id'] ) ? absint( $subscription['id'] ) : '',
			'license_key'      => $license_key,
			'subscription_key' => $subscription_license_obj->license_key,
			'status'           => ! empty( $subscription['status'] ) ? $subscription['status'] : '',
			'expires'          => ! empty( $subscription['expiration'] )
				? (
				is_numeric( $subscription['expiration'] )
					? date( 'Y-m-d H:i:s', $subscription['expiration'] )
					: $subscription['expiration']
				)
				: '',
			'payment_id'       => $payment_id,
			'invoice_url'      => urlencode( add_query_arg( 'payment_key', edd_get_payment_key( $payment_id ), edd_get_success_page_uri() ) ),
		);
	}


	/**
	 * Check subscription for addon
	 *
	 * @param array $data Api request data.
	 *
	 * @return void
	 * @since  0.1
	 * @access public
	 *
	 */
	function remote_subscription_check( $data ) {
		$license = edd_software_licensing()->get_license( urldecode( $data['license'] ), true );

		header( 'Content-Type: application/json' );
		echo wp_json_encode(
			apply_filters(
				'give_edd_remote_subscription_check_response',
				$this->subscription_check( $data ),
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
