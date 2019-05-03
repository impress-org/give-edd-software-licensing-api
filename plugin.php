<?php
/**
 * Plugin Name: Give EDD Software Licensing API Extended
 * Plugin URI: https://givewp.com
 * Description: Add more api endpoints for Easy Digital Downloads - Software Licenses.
 * Author: GiveWP
 * Author URI: https://givewp.com
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

if ( ! defined( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_FILE' ) ) {
	define( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_DIR' ) ) {
	define( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_DIR', plugin_dir_path( GIVE_EDD_SL_API_EXTENDED_PLUGIN_FILE ) );
}

if ( ! defined( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_URL' ) ) {
	define( 'GIVE_EDD_SL_API_EXTENDED_PLUGIN_URL', plugin_dir_url( GIVE_EDD_SL_API_EXTENDED_PLUGIN_FILE ) );
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
	 */
	public function plugin_setup() {
		add_filter( 'edd_sl_license_response', array( $this, 'additional_license_response_checks' ), 10, 1 );
		add_filter( 'edd_remote_license_check_response', array( $this, 'additional_license_checks' ), 10, 3 );
		add_action( 'edd_check_subscription', array( $this, 'remote_subscription_check' ) );
		add_action( 'edd_check_licenses', array( $this, 'remote_licenses_check' ) );
		add_filter( 'edd_sl_get_addon_info', 'edd_sl_readme_modify_license_response', 10, 3 );
	}

	/**
	 * Given an array of arguments, sort them by length, and then md5 them to generate a checksum.
	 * Note: this function copied from EDD_Software_Licensing to descrease response time when do bulk licenses check
	 *
	 * @param array $args
	 *
	 * @return string
	 * @since 3.5
	 */
	private function get_request_checksum_copy( $args = array() ) {
		usort( $args, array( $this, 'sort_args_by_length' ) );
		$string_args = json_encode( $args );

		return md5( $string_args );
	}

	/**
	 * License check
	 * Note: this function copied from EDD_Software_Licensing to descrease response time when do bulk licenses check
	 *
	 * Modification list:
	 * 1. return array instead of json
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	function remote_license_check_copy( $data ) {

		$item_id   = ! empty( $data['item_id'] ) ? absint( $data['item_id'] ) : false;
		$item_name = ! empty( $data['item_name'] ) ? rawurldecode( $data['item_name'] ) : false;
		$license   = isset( $data['license'] ) ? urldecode( $data['license'] ) : false;
		$url       = isset( $data['url'] ) ? urldecode( $data['url'] ) : '';

		$args = array(
			'item_id'   => $item_id,
			'item_name' => $item_name,
			'key'       => $license,
			'url'       => $url,
		);

		$result   = $message = edd_software_licensing()->check_license( $args );
		$checksum = $this->get_request_checksum_copy( $args );

		if ( 'invalid' === $result ) {
			$result = false;
		}

		$response = array();
		if ( false !== $result ) {
			$license = edd_software_licensing()->get_license( $license, true );

			$response['expires']          = is_numeric( $license->expiration ) ? date( 'Y-m-d H:i:s', $license->expiration ) : $license->expiration;
			$response['payment_id']       = $license->payment_id;
			$response['customer_name']    = $license->customer->name;
			$response['customer_email']   = $license->customer->email;
			$response['license_limit']    = $license->activation_limit;
			$response['site_count']       = $license->activation_count;
			$response['activations_left'] = $license->activation_limit > 0 ? $license->activation_limit - $license->activation_count : 'unlimited';
			$response['price_id']         = $license->price_id;
		}

		if ( empty( $item_name ) ) {
			$item_name = get_the_title( $item_id );
		}

		$response = array_merge(
			array(
				'success'   => (bool) $result,
				'license'   => $message,
				'item_id'   => $item_id,
				'item_name' => $item_name,
				'checksum'  => $checksum,
			),
			$response
		);

		$license_id = ! empty( $license->ID ) ? $license->ID : false;

		return apply_filters( 'edd_remote_license_check_response', $response, $args, $license_id );
	}

	/**
	 * Version Check
	 * Note: this function copied from EDD_Software_Licensing to descrease response time when do bulk licenses check
	 *
	 * Modification list:
	 * 1. return array instead of json
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	function get_latest_version_remote_copy( $data ) {

		$url       = isset( $data['url'] ) ? sanitize_text_field( urldecode( $data['url'] ) ) : false;
		$license   = isset( $data['license'] ) ? sanitize_text_field( urldecode( $data['license'] ) ) : false;
		$slug      = isset( $data['slug'] ) ? sanitize_text_field( urldecode( $data['slug'] ) ) : false;
		$item_id   = isset( $data['item_id'] ) ? absint( $data['item_id'] ) : false;
		$item_name = isset( $data['item_name'] ) ? sanitize_text_field( rawurldecode( $data['item_name'] ) ) : false;
		$beta      = isset( $data['beta'] ) ? (bool) $data['beta'] : false;
		if ( empty( $item_name ) && empty( $item_id ) ) {
			$item_name = isset( $data['name'] ) ? sanitize_text_field( rawurldecode( $data['name'] ) ) : false;
		}

		$response = array(
			'new_version'    => '',
			'stable_version' => '',
			'sections'       => '',
			'license_check'  => '',
			'msg'            => '',
		);

		if ( empty( $item_id ) && empty( $item_name ) && ( ! defined( 'EDD_BYPASS_NAME_CHECK' ) || ! EDD_BYPASS_NAME_CHECK ) ) {
			$response['msg'] = __( 'No item provided', 'edd_sl' );

			return $response;
		}

		if ( empty( $item_id ) ) {

			if ( empty( $license ) && empty( $item_name ) ) {
				$response['msg'] = __( 'No item provided', 'edd_sl' );

				return $response;
			}

			$check_by_name_first = apply_filters( 'edd_sl_force_check_by_name', false );

			if ( empty( $license ) || $check_by_name_first ) {

				$item_id = edd_software_licensing()->get_download_id_by_name( $item_name );

			} else {

				$item_id = edd_software_licensing()->get_download_id_by_license( $license );

			}
		}

		$download = new EDD_SL_Download( $item_id );

		if ( ! $download ) {

			if ( empty( $license ) || $check_by_name_first ) {
				$response['msg'] = sprintf( __( 'Item name provided does not match a valid %s', 'edd_sl' ), edd_get_label_singular() );
			} else {
				$response['msg'] = sprintf( __( 'License key provided does not match a valid %s', 'edd_sl' ), edd_get_label_singular() );
			}

			return $response;
		}

		$is_valid_for_download = edd_software_licensing()->is_download_id_valid_for_license( $download->ID, $license );
		if ( ! empty( $license ) && ( ! defined( 'EDD_BYPASS_NAME_CHECK' ) || ! EDD_BYPASS_NAME_CHECK ) && ( ! $is_valid_for_download || ( ! empty( $item_name ) && ! edd_software_licensing()->check_item_name( $download->ID, $item_name, $license ) ) ) ) {

			$download_name   = ! empty( $item_name ) ? $item_name : $download->get_name();
			$response['msg'] = sprintf( __( 'License key is not valid for %s', 'edd_sl' ), $download_name );

			return $response;
		}

		$stable_version = $version = edd_software_licensing()->get_latest_version( $item_id );
		$slug           = ! empty( $slug ) ? $slug : $download->post_name;
		$description    = ! empty( $download->post_excerpt ) ? $download->post_excerpt : $download->post_content;
		$changelog      = $download->get_changelog();

		$download_beta = false;
		if ( $beta && $download->has_beta() ) {
			$version_beta = edd_software_licensing()->get_beta_download_version( $item_id );
			if ( version_compare( $version_beta, $stable_version, '>' ) ) {
				$changelog     = $download->get_beta_changelog();
				$version       = $version_beta;
				$download_beta = true;
			}
		}

		$response = array(
			'new_version'    => $version,
			'stable_version' => $stable_version,
			'name'           => $download->post_title,
			'slug'           => $slug,
			'url'            => esc_url( add_query_arg( 'changelog', '1', get_permalink( $item_id ) ) ),
			'last_updated'   => $download->post_modified,
			'homepage'       => get_permalink( $item_id ),
			'package'        => edd_software_licensing()->get_encoded_download_package_url( $item_id, $license, $url, $download_beta ),
			'download_link'  => edd_software_licensing()->get_encoded_download_package_url( $item_id, $license, $url, $download_beta ),
			'sections'       => serialize(
				array(
					'description' => wpautop( strip_tags( $description, '<p><li><ul><ol><strong><a><em><span><br>' ) ),
					'changelog'   => wpautop( strip_tags( stripslashes( $changelog ), '<p><li><ul><ol><strong><a><em><span><br>' ) ),
				)
			),
			'banners'        => serialize(
				array(
					'high' => get_post_meta( $item_id, '_edd_readme_plugin_banner_high', true ),
					'low'  => get_post_meta( $item_id, '_edd_readme_plugin_banner_low', true ),
				)
			),
			'icons'          => array(),
		);

		if ( has_post_thumbnail( $download->ID ) ) {
			$thumb_id  = get_post_thumbnail_id( $download->ID );
			$thumb_128 = get_the_post_thumbnail_url( $download->ID, 'sl-small' );
			if ( ! empty( $thumb_128 ) ) {
				$response['icons']['1x'] = $thumb_128;
			}

			$thumb_256 = get_the_post_thumbnail_url( $download->ID, 'sl-large' );
			if ( ! empty( $thumb_256 ) ) {
				$response['icons']['2x'] = $thumb_256;
			}
		}

		$response['icons'] = serialize( $response['icons'] );

		$response = apply_filters( 'edd_sl_license_response', $response, $download, $download_beta );

		/**
		 * Encode any emoji in the name and sections.
		 *
		 * @since 3.6.5
		 * @see   https://github.com/easydigitaldownloads/EDD-Software-Licensing/issues/1313
		 */
		if ( function_exists( 'wp_encode_emoji' ) ) {
			$response['name'] = wp_encode_emoji( $response['name'] );

			$sections             = maybe_unserialize( $response['sections'] );
			$response['sections'] = serialize( array_map( 'wp_encode_emoji', $sections ) );
		}

		return $response;
	}


	/**
	 * Check licenses in bulk
	 *
	 * @param $args
	 */
	public function remote_licenses_check( $args ) {
		$defaults = array(
			'licenses' => '',
			'url'      => '',
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
					'get_version'   => array(),
					'get_versions'  => array(),
				);

				$remote_response = $this->remote_license_check_copy(
					array(
						'edd_action' => 'check_license',
						'license'    => $license,
						'url'        => $args['url'],
					)
				);

				$response[ $license ]['check_license'] = $remote_response;

				if ( $response[ $license ]['check_license']['success'] ) {
					if ( $response[ $license ]['check_license']['is_all_access_pass'] ) {
						foreach ( $response[ $license ]['check_license']['download'] as $download ) {
							$remote_response = $this->get_latest_version_remote_copy(
								array(
									'edd_action' => 'get_version',
									'license'    => $license,
									'url'        => $args['url'],
									'item_name'  => $download['name'],
									'slug'       => $download['plugin_slug'],
								)
							);

							$response[ $license ]['get_versions'][] = $remote_response['new_version'] ? $remote_response : array();
						}
					} else {
						$remote_response = $this->get_latest_version_remote_copy(
							array(
								'edd_action' => 'get_version',
								'license'    => $license,
								'url'        => $args['url'],
								'item_name'  => $response[ $license ]['check_license']['item_name'],
								'slug'       => $response[ $license ]['check_license']['plugin_slug'],
							)
						);

						$response[ $license ]['get_version'] = $remote_response['new_version'] ? $remote_response : array();
					}
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
	 */
	public function additional_license_checks( $response, $args, $license_id ) {
		global $post;

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

				$included_downloads = new WP_Query(
					array(
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
					)
				);

				// print_r( $included_downloads );

				if ( $included_downloads->have_posts() ) {
					$tmp_post = $post;

					while ( $included_downloads->have_posts() ) {
						$included_downloads->the_post();

						$included_download = new EDD_Download( get_the_ID() );
						$file              = $this->get_latest_release( $included_download->files );

						// @todo by default this function set price id to default variable price if any . revalidate this if it will create any issue or not
						// Override exiting file url with protect download file url.
						$file['plugin_slug']     = basename( $file['file'], '.zip' );
						$file['file']            = edd_all_access_product_download_url( $included_download->ID, 0, $file['array_index'] );
						$file['name']            = get_post_field( 'post_title', get_the_ID(), 'raw' );
						$file['current_version'] = edd_software_licensing()->get_download_version( $included_download->ID );
						$file['readme']          = get_post_meta( get_the_ID(), '_edd_readme_location', true );
						$response['download'][]  = $file;
					}

					// Temporary hack: some global $post keep remain to last item in loop which set wrong item name to last license.
					$post = $tmp_post;
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

			$response['current_version'] = edd_software_licensing()->get_download_version( $download->ID );
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
	 * Get version query additional checks
	 *
	 * @param array $response
	 *
	 * @return array
	 */
	public function additional_license_response_checks( $response ) {
		$license = sanitize_text_field( $_REQUEST['license'] );
		$license = edd_software_licensing()->get_license( $license );
		$url     = isset( $_REQUEST['url'] ) ? urldecode( $_REQUEST['url'] ) : '';

		if ( empty( $url ) ) {

			// Attempt to grab the URL from the user agent if no URL is specified
			$domain = array_map( 'trim', explode( ';', $_SERVER['HTTP_USER_AGENT'] ) );
			$url    = trim( $domain[1] );

		}

		if ( $license && ! $license->is_site_active( $url ) ) {
			$response = array(
				'new_version'    => '',
				'stable_version' => '',
				'sections'       => '',
				'license_check'  => '',
				'msg'            => __( 'Site not active' ),
			);

			echo json_encode( $response );
			exit;
		}

		return $response;
	}

	/**
	 * Get subscription data.
	 *
	 * @param int $payment_id Payment ID.
	 *
	 * @return array|object|null Subscription data
	 * @since  0.1
	 * @access public
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