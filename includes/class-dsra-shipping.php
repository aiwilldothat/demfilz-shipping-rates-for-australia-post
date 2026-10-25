<?php
/**
 * Australia Post shipping method.
 *
 * Integrates with WooCommerce shipping zones and produces live rates from either
 * the PAC API or the Shipping & Tracking (account) API, depending on the rate
 * source chosen on the settings page. Global configuration lives on the
 * dedicated settings page; this method only carries the per-zone title and tax
 * status.
 *
 * @package DSRA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DSRA_Shipping
 */
class DSRA_Shipping extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping zone instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'auspost';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Australia Post', 'demfilz-shipping-rates-for-australia-post' );
		$this->method_description = __( 'Live Australia Post shipping rates. Configure your API credentials and options under WooCommerce &raquo; Settings &raquo; Australia Post.', 'demfilz-shipping-rates-for-australia-post' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}

	/**
	 * Initialise settings.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title      = $this->get_option( 'title', __( 'Australia Post', 'demfilz-shipping-rates-for-australia-post' ) );
		$this->tax_status = $this->get_option( 'tax_status', 'none' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Per-instance (per shipping zone) settings.
	 */
	public function init_form_fields() {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=auspost' );

		$this->instance_form_fields = array(
			'title'       => array(
				'title'       => __( 'Method title', 'demfilz-shipping-rates-for-australia-post' ),
				'type'        => 'text',
				'description' => __( 'Heading shown to customers in this zone.', 'demfilz-shipping-rates-for-australia-post' ),
				'default'     => __( 'Australia Post', 'demfilz-shipping-rates-for-australia-post' ),
				'desc_tip'    => true,
			),
			'tax_status'  => array(
				'title'       => __( 'Tax status', 'demfilz-shipping-rates-for-australia-post' ),
				'type'        => 'select',
				'description' => __( 'Australia Post domestic prices already include GST, so "None" is recommended to avoid double-charging tax.', 'demfilz-shipping-rates-for-australia-post' ),
				'default'     => 'none',
				'options'     => array(
					'taxable' => __( 'Taxable', 'demfilz-shipping-rates-for-australia-post' ),
					'none'    => __( 'None', 'demfilz-shipping-rates-for-australia-post' ),
				),
				'desc_tip'    => true,
			),
			'global_note' => array(
				'title'       => __( 'Credentials &amp; options', 'demfilz-shipping-rates-for-australia-post' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: settings page URL */
					__( 'The rate source, API credentials, packaging, fees and other options are configured globally on the <a href="%s">Australia Post settings page</a>.', 'demfilz-shipping-rates-for-australia-post' ),
					esc_url( $settings_url )
				),
			),
		);
	}

	/**
	 * Calculate and register shipping rates for a package.
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		// Test mode: only expose live rates to administrators.
		if ( 'yes' === DSRA_Settings::get( 'test_mode', 'no' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! $this->has_credentials() ) {
			$this->maybe_fallback( $package );
			return;
		}

		$destination = isset( $package['destination'] ) ? $package['destination'] : array();
		$country     = isset( $destination['country'] ) ? strtoupper( $destination['country'] ) : '';
		$to_postcode = isset( $destination['postcode'] ) ? preg_replace( '/\s+/', '', $destination['postcode'] ) : '';

		if ( '' === $country ) {
			return;
		}

		$from_postcode = DSRA_Settings::get( 'origin_postcode' );
		if ( empty( $from_postcode ) ) {
			$from_postcode = get_option( 'woocommerce_store_postcode' );
		}

		list( $weight, $length, $width, $height ) = $this->get_package_dimensions( $package );

		$api = $this->get_api( $from_postcode );

		if ( 'AU' === $country ) {
			if ( empty( $to_postcode ) || empty( $from_postcode ) ) {
				$this->maybe_fallback( $package );
				return;
			}
			$services = $api->domestic_services( $from_postcode, $to_postcode, $weight, $length, $width, $height );
		} else {
			$services = $api->international_services( $country, $weight );
		}

		if ( is_wp_error( $services ) || empty( $services ) ) {
			$this->maybe_fallback( $package );
			return;
		}

		$services = $this->filter_services( $services );
		$show_eta = 'yes' === DSRA_Settings::get( 'delivery_time', 'no' );
		$added    = false;

		foreach ( $services as $service ) {
			$cost  = $this->adjust_cost( (float) $service['price'] );
			$label = $this->rate_label( $service['name'] );

			if ( $show_eta ) {
				$eta = $this->delivery_estimate( $service['code'] );
				if ( '' !== $eta ) {
					$label .= ' (' . $eta . ')';
				}
			}

			$this->add_rate(
				array(
					'id'        => $this->get_rate_id( sanitize_title( $service['code'] ) ),
					'label'     => $label,
					'cost'      => $cost,
					'package'   => $package,
					'meta_data' => array( 'auspost_service_code' => $service['code'] ),
				)
			);
			$added = true;
		}

		if ( $added ) {
			$this->maybe_free( $package );
		} else {
			$this->maybe_fallback( $package );
		}
	}

	/**
	 * Build the API client for the configured rate source.
	 *
	 * @param string $from_postcode Origin postcode (used by the account client).
	 * @return DSRA_API|DSRA_Account_API
	 */
	protected function get_api( $from_postcode = '' ) {
		$cache_ttl = (int) DSRA_Settings::get( 'cache_minutes', 720 ) * MINUTE_IN_SECONDS;
		$debug     = 'yes' === DSRA_Settings::get( 'debug', 'no' );

		if ( 'account' === DSRA_Settings::get( 'api_mode', 'pac' ) ) {
			return new DSRA_Account_API(
				DSRA_Settings::get( 'account_number' ),
				DSRA_Settings::get( 'account_api_key' ),
				DSRA_Settings::get( 'account_api_password' ),
				$from_postcode,
				$cache_ttl,
				$debug
			);
		}

		return new DSRA_API( DSRA_Settings::get( 'api_key' ), $cache_ttl, $debug );
	}

	/**
	 * Whether the credentials for the selected rate source are present.
	 *
	 * @return bool
	 */
	protected function has_credentials() {
		if ( 'account' === DSRA_Settings::get( 'api_mode', 'pac' ) ) {
			return '' !== (string) DSRA_Settings::get( 'account_number' )
				&& '' !== (string) DSRA_Settings::get( 'account_api_key' )
				&& '' !== (string) DSRA_Settings::get( 'account_api_password' );
		}

		return '' !== (string) DSRA_Settings::get( 'api_key' );
	}

	/**
	 * Determine total weight (kg) and parcel dimensions (cm) for a package.
	 *
	 * @param array $package Shipping package.
	 * @return array [ weight, length, width, height ]
	 */
	protected function get_package_dimensions( $package ) {
		$use_product_dims = 'yes' === DSRA_Settings::get( 'use_product_dimensions', 'yes' );

		$total_weight = 0.0;
		$max_length   = 0.0;
		$max_width    = 0.0;
		$sum_height   = 0.0;
		$has_dims     = true;

		if ( ! empty( $package['contents'] ) ) {
			foreach ( $package['contents'] as $item ) {
				if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
					continue;
				}
				$product = $item['data'];
				$qty     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

				if ( ! $product->needs_shipping() ) {
					continue;
				}

				$total_weight += (float) $product->get_weight() * $qty;

				if ( $use_product_dims ) {
					$l = (float) $product->get_length();
					$w = (float) $product->get_width();
					$h = (float) $product->get_height();

					if ( $l <= 0 || $w <= 0 || $h <= 0 ) {
						$has_dims = false;
					}

					$max_length  = max( $max_length, $l );
					$max_width   = max( $max_width, $w );
					$sum_height += $h * $qty;
				}
			}
		}

		// Convert from the store's units to AusPost units (kg / cm).
		$total_weight = wc_get_weight( $total_weight, 'kg' );

		$default_weight = (float) DSRA_Settings::get( 'default_weight', 0.5 );
		if ( $total_weight <= 0 ) {
			$total_weight = $default_weight > 0 ? $default_weight : 0.2;
		}

		if ( $use_product_dims && $has_dims && $max_length > 0 ) {
			$length = wc_get_dimension( $max_length, 'cm' );
			$width  = wc_get_dimension( $max_width, 'cm' );
			$height = wc_get_dimension( $sum_height, 'cm' );
		} else {
			$length = (float) DSRA_Settings::get( 'default_length', 20 );
			$width  = (float) DSRA_Settings::get( 'default_width', 15 );
			$height = (float) DSRA_Settings::get( 'default_height', 10 );
		}

		// Australia Post requires positive dimensions.
		$length = max( $length, 1 );
		$width  = max( $width, 1 );
		$height = max( $height, 1 );

		return array(
			round( $total_weight, 3 ),
			round( $length, 1 ),
			round( $width, 1 ),
			round( $height, 1 ),
		);
	}

	/**
	 * Restrict the returned services to the admin's allow-list, if any.
	 *
	 * @param array $services Services from the API.
	 * @return array
	 */
	protected function filter_services( $services ) {
		$enabled = (array) DSRA_Settings::get( 'enabled_services', array() );
		$enabled = array_filter( array_map( 'strval', $enabled ) );

		if ( empty( $enabled ) ) {
			return $services;
		}

		$out = array();
		foreach ( $services as $service ) {
			if ( in_array( $service['code'], $enabled, true ) ) {
				$out[] = $service;
			}
		}

		return $out;
	}

	/**
	 * Apply GST removal, markup and handling fee to a base price.
	 *
	 * @param float $base Base price from the API.
	 * @return float
	 */
	protected function adjust_cost( $base ) {
		if ( 'yes' === DSRA_Settings::get( 'remove_gst', 'no' ) ) {
			$base = $base / 1.1;
		}

		$markup   = (float) DSRA_Settings::get( 'markup_percent', 0 );
		$handling = (float) DSRA_Settings::get( 'handling_fee', 0 );

		$cost = ( $base * ( 1 + $markup / 100 ) ) + $handling;

		return round( max( $cost, 0 ), 2 );
	}

	/**
	 * Build the customer-facing rate label.
	 *
	 * @param string $name Service name.
	 * @return string
	 */
	protected function rate_label( $name ) {
		$prefix = (string) DSRA_Settings::get( 'label_prefix', '' );
		return trim( $prefix . $name );
	}

	/**
	 * A rough estimated delivery time based on the service code.
	 *
	 * Australia Post does not return a guaranteed delivery window in the rate
	 * response, so this is an indicative estimate by service type.
	 *
	 * @param string $code Service code.
	 * @return string
	 */
	protected function delivery_estimate( $code ) {
		$code             = strtoupper( $code );
		$is_international = ( 0 === strpos( $code, 'INT_' ) );

		if ( false !== strpos( $code, 'COURIER' ) || false !== strpos( $code, '_COR_' ) ) {
			return $is_international
				? __( 'Est. 2–4 business days', 'demfilz-shipping-rates-for-australia-post' )
				: __( 'Est. same/next business day (metro)', 'demfilz-shipping-rates-for-australia-post' );
		}

		if ( false !== strpos( $code, 'EXPRESS' ) || false !== strpos( $code, '_EXP_' ) ) {
			return $is_international
				? __( 'Est. 2–4 business days', 'demfilz-shipping-rates-for-australia-post' )
				: __( 'Est. 1–2 business days', 'demfilz-shipping-rates-for-australia-post' );
		}

		if ( false !== strpos( $code, '_SEA_' ) ) {
			return __( 'Est. 30+ business days', 'demfilz-shipping-rates-for-australia-post' );
		}

		if ( $is_international ) {
			return __( 'Est. 6+ business days', 'demfilz-shipping-rates-for-australia-post' );
		}

		return __( 'Est. 2–6 business days', 'demfilz-shipping-rates-for-australia-post' );
	}

	/**
	 * Add a free shipping rate when the cart qualifies.
	 *
	 * @param array $package Shipping package.
	 */
	protected function maybe_free( $package ) {
		if ( 'yes' !== DSRA_Settings::get( 'free_enabled', 'no' ) ) {
			return;
		}

		$min      = (float) DSRA_Settings::get( 'free_min', 0 );
		$subtotal = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;

		if ( $min > 0 && $subtotal < $min ) {
			return;
		}

		$this->add_rate(
			array(
				'id'      => $this->get_rate_id( 'free' ),
				'label'   => DSRA_Settings::get( 'free_label', __( 'Free Shipping', 'demfilz-shipping-rates-for-australia-post' ) ),
				'cost'    => 0,
				'package' => $package,
			)
		);
	}

	/**
	 * Add the configured fallback rate, if enabled.
	 *
	 * @param array $package Shipping package.
	 */
	protected function maybe_fallback( $package ) {
		if ( 'yes' !== DSRA_Settings::get( 'fallback_enabled', 'no' ) ) {
			return;
		}

		$cost = (float) DSRA_Settings::get( 'fallback_cost', 0 );

		$this->add_rate(
			array(
				'id'      => $this->get_rate_id( 'fallback' ),
				'label'   => DSRA_Settings::get( 'fallback_label', __( 'Shipping', 'demfilz-shipping-rates-for-australia-post' ) ),
				'cost'    => round( $cost, 2 ),
				'package' => $package,
			)
		);
	}
}
