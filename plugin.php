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
	 * List of licenses for which webhook triggered
	 * @var array
	 */
	private static $license_webhook_triggered = array();

	/**
	 * List of add-ons for which webhook triggered
	 * @var array
	 */
	private static $addon_webhook_triggered = array();

	/**
	 * List of subscription for which webhook triggered
	 * @var array
	 */
	private static $subscription_webhook_triggered = array();

	/**
	 * Lumen token params
	 * @var string
	 */
	private static $lumen_token = '';
	private static $lumen_token_expire = '';

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
		add_filter( 'edd_sl_license_response', array( $this, 'additional_license_response_checks' ), 10, 2 );
		add_filter( 'edd_remote_license_check_response', array( $this, 'additional_license_checks' ), 10, 3 );
		add_action( 'edd_check_subscription', array( $this, 'remote_subscription_check' ) );
		add_action( 'edd_check_licenses', array( $this, 'remote_licenses_check' ) );

		/**
		 * Update data on lumen server handler
		 */

		// Check class-sl-license.php for this hooks.
		$sl_license_filters = array(
			'edd_sl_post_set_status',
			'edd_sl_post_set_expiration',
			'edd_sl_post_license_renewal',
			'edd_sl_post_set_activation_limit',
			'edd_sl_post_set_lifetime'
		);

		foreach ( $sl_license_filters as $sl_license_filter ){
			add_action( $sl_license_filter, array( $this, 'setup_lumen_license_webhook_job' ), 10, 1 );
		}

		add_action( 'edd_deactivate_site', array( $this, 'setup_lumen_license_webhook_job_when_deactivate_site' ), 5 );
		add_action( 'edd_insert_site', array( $this, 'setup_lumen_license_webhook_job_when_add_site' ), 5 );
		add_action( 'wp_ajax_edd_sl_regenerate_license', array( $this, 'setup_lumen_license_webhook_job_when_regenerate_key' ), 5 );
		add_action( 'admin_init', array( $this, 'setup_lumen_license_webhook_job_when_process_license' ), 0 );

		add_action( 'save_post_download', array( $this, 'setup_lumen_addon_webhook_job' ), 10, 1 );

		add_action( 'edd_recurring_update_subscription', array( $this, 'setup_lumen_subscription_webhook_job' ), 10, 1 );
		add_action( 'admin_init', array( $this, 'setup_lumen_subscription_webhook_job_when_subs_deleted' ), 2 );

		add_action( 'edd_post_edit_customer', array( $this, 'setup_lumen_license_webhook_job_when_update_customer' ), 10, 2 );
	}

	/**
	 * Used by get_request_checksum to sort the array by size.
	 * Note: this function copied from EDD_Software_Licensing to decrease response time when do bulk licenses check
	 *
	 * @param string $a The first item to compare for length.
	 * @param string $b The second item to compare for length.
	 *
	 * @return int The difference in length.
	 * @since 3.5
	 */
	private function sort_args_by_length( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	}

	/**
	 * Given an array of arguments, sort them by length, and then md5 them to generate a checksum.
	 * Note: this function copied from EDD_Software_Licensing to decrease response time when do bulk licenses check
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

		// @todo: Maybe need to search by title.
		if ( ! $download->ID ) {
			$the_query = new WP_Query(
				array(
					's'              => $item_name,
					'posts_per_page' => 1,
					'posy_type'      => 'download',
					'post_status'    => 'publish',
				)
			);

			// The Loop
			if ( $the_query->have_posts() ) {

				while ( $the_query->have_posts() ) {
					$the_query->the_post();

					$download = new EDD_SL_Download( get_the_ID() );
				}

				wp_reset_postdata();
			}
		}

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
		$slug           = ! empty( $slug ) ? $slug : basename( dirname( get_post_meta( $download->ID, '_edd_readme_location', true ) ) );
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

		// Pull add-on thumbs from custom ACF Field.
		$addon_thumb = get_field( 'download_icon', $download->ID );
		if ( is_array( $addon_thumb ) ) {
			$response['icons']['1x'] = isset( $addon_thumb['sizes']['sl-small'] ) ? $addon_thumb['sizes']['sl-small'] : $addon_thumb['url'];
			$response['icons']['2x'] = isset( $addon_thumb['sizes']['sl-large'] ) ? $addon_thumb['sizes']['sl-large'] : $addon_thumb['url'];
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
			'licenses'   => '',
			'unlicensed' => '',
			'url'        => '',
		);

		$args                = array_map( 'sanitize_text_field', $args );
		$args                = wp_parse_args( $args, $defaults );
		$args['licenses']    = ! empty( $args['licenses'] ) ? array_map( 'trim', explode( ',', $args['licenses'] ) ) : '';
		$args['unlicensed']  = ! empty( $args['unlicensed'] ) ? array_map( 'trim', explode( ',', $args['unlicensed'] ) ) : '';
		$skip_licensed_addon = array();

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

							if ( ! $remote_response['new_version'] ) {
								continue;
							}

							$response[ $license ]['get_versions'][] = $remote_response;
							$skip_licensed_addon[]                  = $remote_response['slug'];
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

						if ( ! $remote_response['new_version'] ) {
							continue;
						}

						$response[ $license ]['get_version'] = $remote_response;
						$skip_licensed_addon[]               = $remote_response['slug'];
					}
				}
			}
		}

		if ( $args['unlicensed'] ) {
			$skip_licensed_addon = array_filter( $skip_licensed_addon );

			foreach ( $args['unlicensed'] as $addon ) {
				$remote_response = $this->get_latest_version_remote_copy(
					array(
						'edd_action' => 'get_version',
						'url'        => $args['url'],
						'item_name'  => $addon,
						// 'slug'       => $response[ $license ]['check_license']['plugin_slug'],
					)
				);

				if (
					! $remote_response['new_version']
					|| in_array( $remote_response['slug'], $skip_licensed_addon )
				) {
					continue;
				}

				$response[ sanitize_title( $addon ) ] = $remote_response;
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
						$file['readme']          = get_post_meta( get_the_ID(), '_edd_readme_location', true );
						$file['plugin_slug']     = basename( dirname( $file['readme'] ) );
						$file['file']            = edd_all_access_product_download_url( $included_download->ID, 0, $file['array_index'] );
						$file['name']            = get_post_field( 'post_title', get_the_ID(), 'raw' );
						$file['current_version'] = edd_software_licensing()->get_download_version( $included_download->ID );
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
				// It prevent activation of deprecated add-on but valid license key.
				$response['success'] = false;

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
			$response['plugin_slug']     = basename( dirname( $response['readme'] ) );

			// Backward compatibility for subscription bundles.
			// @todo: as per devin, we should only check for license date instead of subscription date.
			// $subscription             = $this->subscription_check( array( 'license' => $args['key'] ) );
			// $response['subscription'] = array();
			// if ( $subscription['status'] ) {
			// $response['subscription'] = $subscription;
			// }
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
			// We are assuming that url will be like http://xyz.com/downloads/plugins/give-manual-donations-1.3.2.zip, in this case we will get "-1." as selection.
			$zip_filename = basename( $download_file['file'] );
			preg_match( '/-\d[.]/', $zip_filename, $version_number_part );

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
	 * @param EDD_Download $download
	 *
	 * @return array
	 */
	public function additional_license_response_checks( $response, $download ) {
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

	/**
	 * Setup license lumen webhook job
	 *
	 * @param int $license_id
	 *
	 * @return bool
	 */
	function setup_lumen_license_webhook_job( $license_id ) {
		/* @var EDD_License $license */
		$license     = edd_software_licensing()->get_license( $license_id );
		$license_key = $license->key;


		// Exit.
		if ( ! $license ) {
			return false;
		}

		// Setup a background job.
		$this->trigger_lumen_license_webhook( $license_key );

		return true;
	}

	/**
	 * Setup license lumen webhook job when deactivate site
	 *
	 * @return bool
	 */
	public function setup_lumen_license_webhook_job_when_deactivate_site() {
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'edd_deactivate_site_nonce' ) ) {
			return false;
		}

		$license_id = absint( $_GET['license'] );
		$license    = edd_software_licensing()->get_license( $license_id );

		if ( $license_id !== $license->ID ) {
			return false;
		}

		if (
			( is_admin() && ! current_user_can( 'manage_licenses' ) )
			|| ( ! is_admin() && $license->user_id != get_current_user_id() )
		) {
			return false;
		}

		$site_url = ! empty( $_GET['site_url'] ) ? urldecode( $_GET['site_url'] ) : false;
		$site_id  = ! empty( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : false;

		if ( empty( $site_url ) && empty( $site_id ) ) {
			return false;
		}

		$this->trigger_lumen_license_webhook( $license->key );

		return true;
	}

	/**
	 * Setup license lumen webhook job when add site
	 *
	 * @return bool
	 */
	public function setup_lumen_license_webhook_job_when_add_site() {
		if ( ! wp_verify_nonce( $_POST['edd_add_site_nonce'], 'edd_add_site_nonce' ) ) {
			return false;
		}

		if ( ! empty( $_POST['license_id'] ) && empty( $_POST['license'] ) ) {
			// In 3.5, we switched from checking for license_id to just license. Fallback check for backwards compatibility
			$_POST['license'] = $_POST['license_id'];
		}

		$license_id = absint( $_POST['license'] );
		$license    = edd_software_licensing()->get_license( $license_id );
		if ( $license_id !== $license->ID ) {
			return false;
		}

		if (
			( is_admin() && ! current_user_can( 'manage_licenses' ) )
			|| ( ! is_admin() && $license->user_id != get_current_user_id() )
		) {
			return false;
		}

		if ( $license->is_at_limit() && ! current_user_can( 'manage_licenses' ) ) {
			return false;
		}

		$this->trigger_lumen_license_webhook( $license->key );

		return true;
	}

	/**
	 * Setup license lumen webhook job when regenerate key
	 *
	 * @return bool
	 */
	public function setup_lumen_license_webhook_job_when_regenerate_key() {
		if ( ! current_user_can( 'manage_licenses' ) ) {
			return false;
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'edd-sl-regenerate-license' ) ) {
			return false;
		}

		if ( ! isset( $_POST['license_id'] ) || ! is_numeric( $_POST['license_id'] ) ) {
			return false;
		}

		$license_id = absint( $_POST['license_id'] );
		$license    = edd_software_licensing()->get_license( $license_id );

		if ( ! $license ) {
			return false;
		}

		$this->trigger_lumen_license_webhook( $license->key );

		return true;
	}

	/**
	 * Setup license lumen webhook job when process license
	 *
	 * @return bool
	 */
	public function setup_lumen_license_webhook_job_when_process_license() {
		if ( ! current_user_can( 'manage_licenses' ) ) {
			return false;
		}

		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( $_GET['_wpnonce'], 'edd_sl_license_nonce' )
		) {
			return false;
		}

		if ( isset( $_GET['license'] ) ) {
			if ( is_numeric( $_GET['license'] ) ) {
				$new_license_id = edd_software_licensing()->license_meta_db->get_license_id( '_edd_sl_legacy_id', absint( $_GET['license'] ), true );
			}

			if ( empty( $new_license_id ) ) {
				return false;
			}
		}

		if ( (
			     ! isset( $_GET['license_id'] )
			     || ! is_numeric( $_GET['license_id'] ) )
		     && empty( $new_license_id )
		) {
			return false;
		}

		$license_id = isset( $_GET['license_id'] ) ? absint( $_GET['license_id'] ) : $new_license_id;

		$action = sanitize_text_field( $_GET['action'] );

		$license = edd_software_licensing()->get_license( $license_id );

		if ( ! $license ) {
			return false;
		}

		$allowed_actiones = array( 'deactivate', 'activate', 'enable', 'disable', 'renew', 'delete', 'set-lifetime' );

		if ( ! in_array( $action, $allowed_actiones ) ) {
			return false;
		}

		$this->trigger_lumen_license_webhook( $license->key );

		return true;
	}

	/**
	 * Setup add-on lumen webhook job when update customer
	 *
	 * @param $customer_id
	 * @param $customer_data
	 */
	public function setup_lumen_license_webhook_job_when_update_customer( $customer_id, $customer_data ) {
		$licenses = edd_software_licensing()->licenses_db->get_licenses( array(
			'number'      => - 1,
			'customer_id' => $customer_id,
			'orderby'     => 'id',
			'order'       => 'ASC',
		) );


		if ( $licenses ) {
			foreach ( $licenses as $license ) {
				$this->trigger_lumen_license_webhook( $license->key );
			}
		}
	}

	/**
	 * Setup add-on lumen webhook job
	 *
	 * @param int $download_id
	 */
	function setup_lumen_addon_webhook_job( $download_id ) {
		$this->trigger_lumen_addon_webhook( $download_id );
	}

	/**
	 * Setup add-on lumen webhook job
	 *
	 * @param int $subscription_id
	 */
	function setup_lumen_subscription_webhook_job( $subscription_id ) {
		$this->trigger_lumen_subscription_webhook( $subscription_id );
	}


	/**
	 * Setup subscription lumen webhook job when delete subscription
	 *
	 * @return bool
	 */
	function setup_lumen_subscription_webhook_job_when_subs_deleted() {
		if ( empty( $_POST['sub_id'] ) ) {
			return false;
		}

		if ( empty( $_POST['edd_delete_subscription'] ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_shop_payments' ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( $_POST['edd-recurring-update-nonce'], 'edd-recurring-update' ) ) {
			return false;
		}

		$subscription = new EDD_Subscription( absint( $_POST['sub_id'] ) );

		if ( ! $subscription ) {
			return false;
		}

		$this->setup_lumen_subscription_webhook_job( $subscription->id );
	}

	/**
	 * Trigger license lumen webhook job
	 *
	 * @param string $license_key
	 *
	 * @return bool
	 */
	public function trigger_lumen_license_webhook( $license_key ) {
		// check if webhook already triggered for license.
		if ( in_array( $license_key, self::$license_webhook_triggered ) ) {
			return false;
		}

		self::$license_webhook_triggered[] = $license_key;

		$token = $this->get_lumen_token();

		// Exit.
		if ( empty( $token ) ) {
			return false;
		}

		$license = edd_software_licensing()->get_license( $license_key, true );

		// Check if we are processing bundled license.
		if ( $licenses = $license->get_child_licenses() ) {
			$temp = array();

			/* @var EDD_License $item */
			foreach ( $licenses as $item ) {
				$temp[]                            = $item->key;
				self::$license_webhook_triggered[] = $item->key;
			}

			$license_key = implode( ',', $temp );
		}

		wp_remote_post(
			$this->get_lumen_api_uri( 'update-license' ),
			array(
				'timeout' => 15,
				'body'    => array(
					'license' => $license_key,
					'token'   => $token
				)
			)
		);
	}

	/**
	 * Trigger add-on lumen webhook job
	 *
	 * @param int $download_id
	 *
	 * @return bool
	 */
	public function trigger_lumen_addon_webhook( $download_id ) {
		// check if webhook already triggered for license.
		if ( in_array( $download_id, self::$addon_webhook_triggered ) ) {
			return false;
		}

		$token = $this->get_lumen_token();

		// Exit.
		if ( empty( $token ) ) {
			return false;
		}

		self::$subscription_webhook_triggered[] = $download_id;

		wp_remote_post(
			$this->get_lumen_api_uri( 'update-addon' ),
			array(
				'timeout' => 15,
				'body'    => array(
					'addon' => get_the_title( $download_id ),
					'token' => $token
				)
			)
		);
	}

	/**
	 * Trigger subscription lumen webhook job
	 *
	 * @param string $subscription_id
	 *
	 * @return bool
	 */
	public function trigger_lumen_subscription_webhook( $subscription_id ) {
		// check if webhook already triggered for license.
		if ( in_array( $subscription_id, self::$subscription_webhook_triggered ) ) {
			return false;
		}

		$token = $this->get_lumen_token();

		// Exit.
		if ( empty( $token ) ) {
			return false;
		}

		$subscription = new EDD_Subscription( $subscription_id );

		if ( ! $subscription ) {
			return false;
		}

		$license = edd_software_licensing()->get_license_by_purchase( $subscription->parent_payment_id, $subscription->product_id );

		if ( ! $license ) {
			return false;
		}

		$license_key = edd_software_licensing()->get_license_key( $license->ID );

		self::$subscription_webhook_triggered[] = $subscription_id;

		wp_remote_post(
			$this->get_lumen_api_uri( 'update-subscription' ),
			array(
				'timeout' => 15,
				'body'    => array(
					'license'      => $license_key,
					'subscription' => $subscription_id,
					'token'        => $token
				)
			)
		);
	}


	/**
	 * Get token from lumen to make safe requests
	 *
	 * @return string
	 */
	private function get_lumen_token() {
		// Use cached token.
		if ( self::$lumen_token_expire > current_time( 'timestamp' ) ) {
			return self::$lumen_token;
		}

		$token    = '';
		$response = wp_remote_post(
			$this->get_lumen_api_uri( 'auth' ),
			array(
				'body'    => array(
					'email'    => LUMEN_USER_EMAIL,
					'password' => LUMEN_USER_PASSWORD,
				),
				'timeout' => 15
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$response = json_decode( wp_remote_retrieve_body( $response ), true );
			$token    = ! empty( $response['token'] ) ? $response['token'] : '';
		}

		self::$lumen_token        = $token;
		self::$lumen_token_expire = $token ? strtotime( '+ 5 second', current_time( 'timestamp' ) ) : '';

		return $token;
	}

	/**
	 * Get lumen api uri
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	private function get_lumen_api_uri( $type = '' ) {
		$url = untrailingslashit( LUMEN_SERVER_URI );

		switch ( $type ) {
			case 'auth':
				$url = "{$url}/auth/login";
				break;

			case 'update-license':
				$url = "{$url}/update/license";
				break;

			case 'update-addon':
				$url = "{$url}/update/addon";
				break;

			case 'update-subscription':
				$url = "{$url}/update/subscription";
				break;
		}

		return $url;
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
