<?php
/**
 * Australia Post Shipping & Tracking API client (account rates).
 *
 * Returns the negotiated prices on a merchant's Australia Post charge account
 * (eParcel / MyPost Business / Enterprise) via the Prices endpoint.
 *
 * Docs: https://developers.auspost.com.au/apis/shipping-and-tracking/reference
 * Auth: HTTP Basic (API key + password) plus an Account-Number header.
 *
 * Exposes the same public interface as {@see DSRA_API} so the shipping
 * method can use either client interchangeably.
 *
 * @package DSRA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DSRA_Account_API
 */
class DSRA_Account_API {

	/**
	 * Base URL for the Shipping & Tracking API.
	 */
	const BASE_URL = 'https://digitalapi.auspost.com.au/shipping/v1';

	/**
	 * Charge account number.
	 *
	 * @var string
	 */
	protected $account;

	/**
	 * API key (Basic auth username).
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * API password (Basic auth password).
	 *
	 * @var string
	 */
	protected $password;

	/**
	 * Origin postcode (needed for international quotes where it is not passed in).
	 *
	 * @var string
	 */
	protected $from_postcode;

	/**
	 * Cache lifetime in seconds (0 disables caching).
	 *
	 * @var int
	 */
	protected $cache_ttl;

	/**
	 * Whether to write debug entries to the WooCommerce logger.
	 *
	 * @var bool
	 */
	protected $debug;

	/**
	 * Constructor.
	 *
	 * @param string $account       Account number.
	 * @param string $api_key       API key.
	 * @param string $password      API password.
	 * @param string $from_postcode Default origin postcode.
	 * @param int    $cache_ttl     Cache lifetime in seconds.
	 * @param bool   $debug         Enable debug logging.
	 */
	public function __construct( $account, $api_key, $password, $from_postcode = '', $cache_ttl = 0, $debug = false ) {
		$this->account       = trim( (string) $account );
		$this->api_key       = trim( (string) $api_key );
		$this->password      = trim( (string) $password );
		$this->from_postcode = trim( (string) $from_postcode );
		$this->cache_ttl     = max( 0, (int) $cache_ttl );
		$this->debug         = (bool) $debug;
	}

	/**
	 * Retrieve domestic services with prices.
	 *
	 * @param string $from_postcode Origin postcode.
	 * @param string $to_postcode   Destination postcode.
	 * @param float  $weight        Weight in kilograms.
	 * @param float  $length        Length in centimetres.
	 * @param float  $width         Width in centimetres.
	 * @param float  $height        Height in centimetres.
	 * @return array|WP_Error
	 */
	public function domestic_services( $from_postcode, $to_postcode, $weight, $length, $width, $height ) {
		return $this->prices(
			$from_postcode,
			array( 'postcode' => (string) $to_postcode ),
			$weight,
			$length,
			$width,
			$height
		);
	}

	/**
	 * Retrieve international services with prices.
	 *
	 * @param string $country_code Two-letter ISO destination country code.
	 * @param float  $weight       Weight in kilograms.
	 * @return array|WP_Error
	 */
	public function international_services( $country_code, $weight ) {
		return $this->prices(
			$this->from_postcode,
			array( 'country_code' => strtoupper( $country_code ) ),
			$weight,
			null,
			null,
			null
		);
	}

	/**
	 * Call the Prices endpoint and return normalised services.
	 *
	 * @param string $from_postcode Origin postcode.
	 * @param array  $to            Destination descriptor (postcode or country_code).
	 * @param float  $weight        Weight in kilograms.
	 * @param float  $length        Length in centimetres (nullable).
	 * @param float  $width         Width in centimetres (nullable).
	 * @param float  $height        Height in centimetres (nullable).
	 * @return array|WP_Error
	 */
	protected function prices( $from_postcode, $to, $weight, $length, $width, $height ) {
		if ( '' === $this->account || '' === $this->api_key || '' === $this->password ) {
			return new WP_Error( 'dsra_no_account', __( 'Australia Post account credentials are incomplete.', 'demfilz-shipping-rates-for-australia-post' ) );
		}

		$item = array( 'weight' => (string) $weight );
		if ( $length ) {
			$item['length'] = (string) $length;
		}
		if ( $width ) {
			$item['width'] = (string) $width;
		}
		if ( $height ) {
			$item['height'] = (string) $height;
		}

		$body = array(
			'from'  => array( 'postcode' => (string) $from_postcode ),
			'to'    => $to,
			'items' => array( $item ),
		);

		$cache_key = 'dsra_acc_' . md5( wp_json_encode( $body ) );

		if ( $this->cache_ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$this->log( 'Cache hit (account)' );
				return $cached;
			}
		}

		$response = $this->request( '/prices/items', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$services = $this->parse_services( $response );

		if ( $this->cache_ttl > 0 && ! empty( $services ) ) {
			set_transient( $cache_key, $services, $this->cache_ttl );
		}

		return $services;
	}

	/**
	 * Execute the HTTP POST request.
	 *
	 * @param string $endpoint Endpoint path.
	 * @param array  $body     Request body.
	 * @return array|WP_Error
	 */
	protected function request( $endpoint, $body ) {
		$url = self::BASE_URL . $endpoint;

		$this->log( 'Request (account): ' . $url . ' ' . wp_json_encode( $body ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization'  => 'Basic ' . base64_encode( $this->api_key . ':' . $this->password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic auth.
					'Account-Number' => $this->account,
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP error: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		$this->log( 'Response (' . $code . '): ' . $raw );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'dsra_bad_json', __( 'Australia Post returned an invalid response.', 'demfilz-shipping-rates-for-australia-post' ) );
		}

		if ( 200 !== $code || ! empty( $data['errors'] ) ) {
			$message = $this->extract_error( $data );
			return new WP_Error( 'dsra_api_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * Pull a human-readable error message out of an error payload.
	 *
	 * @param array $data Decoded response.
	 * @return string
	 */
	protected function extract_error( $data ) {
		if ( ! empty( $data['errors'][0]['message'] ) ) {
			return sanitize_text_field( $data['errors'][0]['message'] );
		}
		if ( ! empty( $data['errors'][0]['error_message'] ) ) {
			return sanitize_text_field( $data['errors'][0]['error_message'] );
		}
		return __( 'Australia Post API request failed.', 'demfilz-shipping-rates-for-australia-post' );
	}

	/**
	 * Normalise the prices payload into a flat list of code/name/price arrays.
	 *
	 * The Prices endpoint returns one entry per item, each with a "prices" map
	 * keyed by service code.
	 *
	 * @param array $data Decoded response body.
	 * @return array
	 */
	protected function parse_services( $data ) {
		if ( empty( $data['items'][0]['prices'] ) || ! is_array( $data['items'][0]['prices'] ) ) {
			return array();
		}

		$out = array();

		foreach ( $data['items'][0]['prices'] as $code => $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}

			$price = null;
			foreach ( array( 'calculated_price', 'price', 'calculated_price_inc_gst', 'price_inc_gst' ) as $key ) {
				if ( isset( $service[ $key ] ) && is_numeric( $service[ $key ] ) ) {
					$price = (float) $service[ $key ];
					break;
				}
			}

			if ( null === $price ) {
				continue;
			}

			$name = isset( $service['product_type'] ) ? $service['product_type'] : $code;

			$out[] = array(
				'code'  => sanitize_text_field( $code ),
				'name'  => sanitize_text_field( $name ),
				'price' => $price,
			);
		}

		return $out;
	}

	/**
	 * Lightweight connectivity test used by the settings page.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$result = $this->domestic_services( '2000', '3000', 1, 10, 10, 10 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'dsra_empty', __( 'Connected, but no services were returned for the test parcel. Check your account is enabled for these products.', 'demfilz-shipping-rates-for-australia-post' ) );
		}

		return true;
	}

	/**
	 * Write a debug message to the WooCommerce logger.
	 *
	 * @param string $message Message to log.
	 */
	protected function log( $message ) {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->debug( $message, array( 'source' => 'demfilz-shipping-rates-for-australia-post' ) );
	}
}
