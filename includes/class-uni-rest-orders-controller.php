<?php

defined( 'ABSPATH' ) || exit;

class Uni_Rest_Orders_Controller extends Uni_Rest_CRUD_Controller {

	/**
	 * The current version of the plugin
	 */
	protected $version;

	/**
	 * The namespace for the api calls.
	 * Ref: https://developer.wordpress.org/rest-api/extending-the-rest-api/routes-and-endpoints/#namespaces
	 *
	 */
	protected $namespace;

	/**
	 * Post type.
	 */
	protected $post_type = 'shop_order';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'orders';

	/**
	 * If object is hierarchical.
	 *
	 * @var bool
	 */
	protected $hierarchical = true;

	/**
	 * Stores the request.
	 * @var array
	 */
	protected $request = array();

	/**
	 * Constructor
	 */
	public function __construct( $namespace, $version ) {
		$this->version   = $version;
		$this->namespace = $namespace . '/v' . intval( $version );
	}

	/**
	 * Add custom routes for JWT creation and validation
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, $this->rest_base, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params()
			),
			'schema' => array( $this, 'get_public_item_schema' )
		) );

		register_rest_route(
			$this->namespace, $this->rest_base . '/(?P<id>[\d]+)', array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'wc-orders-app' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'default'     => false,
							'type'        => 'boolean',
							'description' => __( 'Whether to bypass trash and force deletion.', 'wc-orders-app' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace, $this->rest_base . '/batch', array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'batch_items' ),
					'permission_callback' => array( $this, 'batch_items_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_batch_schema' ),
			)
		);
	}

	/**
	 * Get object.
	 *
	 * @param  int $id Object ID.
	 *
	 * @return WC_Data
	 */
	protected function get_object( $id ) {
		return wc_get_order( $id );
	}

	/**
	 * Get formatted product meta data
	 *
	 * @param  array Form data like array
	 *
	 * @return array An array of meta data with nice names
	 */
	protected function get_product_meta( $item_meta_data ) {

		$item_data          = array();
		$filtered_form_data = array();

		array_walk(
			$item_meta_data,
			function ( $v ) use ( &$filtered_form_data ) {
				$meta_data = $v->get_data();
				if ( false !== strpos( $meta_data['key'], UniCpo()->get_var_slug() ) && ! empty( $meta_data['value'] ) ) {
					$filtered_form_data[ ltrim( $meta_data['key'], '_' ) ] = $meta_data['value'];
				}
			}
		);

		//print_r( $filtered_form_data );

		if ( ! empty( $filtered_form_data ) ) {
			$posts = uni_cpo_get_posts_by_slugs( array_keys( $filtered_form_data ) );
			if ( ! empty( $posts ) ) {
				$posts_ids = wp_list_pluck( $posts, 'ID' );
				foreach ( $posts_ids as $post_id ) {
					$option = uni_cpo_get_option( $post_id );
					if ( is_object( $option ) ) {
						$post_name        = trim( $option->get_slug(), '{}' );
						$display_key      = uni_cpo_sanitize_label( $option->cpo_order_label() );
						$calculate_result = $option->calculate( $filtered_form_data );
						if ( is_array( $calculate_result ) ) {
							foreach ( $calculate_result as $k => $v ) {
								if ( $post_name === $k ) { // excluding special vars
									if ( is_array( $v['cart_meta'] ) ) {
										$value = implode( ', ', $v['cart_meta'] );
									} else {
										$value = $v['cart_meta'];
									}
									if ( is_array( $v['order_meta'] ) ) {
										$display_value = implode( ', ', $v['order_meta'] );
									} else {
										$display_value = $v['order_meta'];
									}
									$item_data[] = array(
										'name'    => $option->get_slug(),
										'key'     => $display_key,
										'value'   => $value,
										'display' => $display_value
									);
									break;
								}
							}
						}
					}
				}
			}
		}

		return $item_data;

	}

	/**
	 * Expands an order item to get its data.
	 *
	 * @param WC_Order_item $item
	 *
	 * @return array
	 */
	protected function get_order_item_data( $item ) {
		$data              = $item->get_data();
		$item_meta_data    = $item->get_meta_data();
		$data['meta_data'] = $this->get_product_meta( $item_meta_data );

		$format_decimal = array( 'subtotal', 'subtotal_tax', 'total', 'total_tax', 'tax_total', 'shipping_tax_total' );

		// Format decimal values.
		foreach ( $format_decimal as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = wc_format_decimal( $data[ $key ], $this->request['dp'] );
			}
		}

		// Add SKU and PRICE to products.
		if ( is_callable( array( $item, 'get_product' ) ) ) {
			$data['sku']   = $item->get_product() ? $item->get_product()->get_sku() : null;
			$data['price'] = $item->get_quantity() ? $item->get_total() / $item->get_quantity() : 0;
		}

		// Format taxes.
		if ( ! empty( $data['taxes']['total'] ) ) {
			$taxes = array();

			foreach ( $data['taxes']['total'] as $tax_rate_id => $tax ) {
				$taxes[] = array(
					'id'       => $tax_rate_id,
					'total'    => $tax,
					'subtotal' => isset( $data['taxes']['subtotal'][ $tax_rate_id ] ) ? $data['taxes']['subtotal'][ $tax_rate_id ] : '',
				);
			}
			$data['taxes'] = $taxes;
		} elseif ( isset( $data['taxes'] ) ) {
			$data['taxes'] = array();
		}

		// Remove names for coupons, taxes and shipping.
		if ( isset( $data['code'] ) || isset( $data['rate_code'] ) || isset( $data['method_title'] ) ) {
			unset( $data['name'] );
		}

		// Remove props we don't want to expose.
		unset( $data['order_id'] );
		unset( $data['type'] );

		return $data;
	}

	/**
	 * Get formatted item data.
	 *
	 * @since  3.0.0
	 *
	 * @param  WC_Data $object WC_Data instance.
	 *
	 * @return array
	 */
	protected function get_formatted_item_data( $object ) {
		$data              = $object->get_data();
		$format_decimal    = array(
			'discount_total',
			'discount_tax',
			'shipping_total',
			'shipping_tax',
			'shipping_total',
			'shipping_tax',
			'cart_tax',
			'total',
			'total_tax'
		);
		$format_date       = array( 'date_created', 'date_modified', 'date_completed', 'date_paid' );
		$format_line_items = array( 'line_items', 'tax_lines', 'shipping_lines', 'fee_lines', 'coupon_lines' );

		// Format decimal values.
		foreach ( $format_decimal as $key ) {
			$data[ $key ] = wc_format_decimal( $data[ $key ], $this->request['dp'] );
		}

		// Format date values.
		foreach ( $format_date as $key ) {
			$datetime              = $data[ $key ];
			$data[ $key ]          = wc_rest_prepare_date_response( $datetime, false );
			$data[ $key . '_gmt' ] = wc_rest_prepare_date_response( $datetime );
		}

		// Format the order status.
		$data['status'] = 'wc-' === substr( $data['status'], 0, 3 ) ? substr( $data['status'], 3 ) : $data['status'];

		// Format line items.
		foreach ( $format_line_items as $key ) {
			$data[ $key ] = array_values( array_map( array( $this, 'get_order_item_data' ), $data[ $key ] ) );
		}

		// express fee date
		$ee_date             = get_post_meta( $object->get_id(), '_fee_shipping_date', true );
		$data['ship_by']     = wc_rest_prepare_date_response( $ee_date, false );
		$data['ship_by_gmt'] = wc_rest_prepare_date_response( $ee_date );

		// customer data
		$customer         = new WC_Customer( $data['customer_id'] );
		$customer_data    = $customer->get_data();
		$format_user_data = array( 'first_name', 'last_name', 'email' );

		foreach ( $format_user_data as $key ) {
			$data['customer'][ $key ] = $customer_data[ $key ];
		}
		$data['customer']['id']      = $data['customer_id'];
		$data['customer']['company'] = $customer->get_billing_company();

		return array(
			'id'                 => $object->get_id(),
			'number'             => $data['number'],
			'order_key'          => $data['order_key'],
			'created_via'        => $data['created_via'],
			'status'             => $data['status'],
			'currency'           => $data['currency'],
			'date_created'       => $data['date_created'],
			'date_created_gmt'   => $data['date_created_gmt'],
			'date_modified'      => $data['date_modified'],
			'date_modified_gmt'  => $data['date_modified_gmt'],
			'transaction_id'     => $data['transaction_id'],
			'date_paid'          => $data['date_paid'],
			'date_paid_gmt'      => $data['date_paid_gmt'],
			'date_completed'     => $data['date_completed'],
			'date_completed_gmt' => $data['date_completed_gmt'],
			'total'              => $data['total'],
			'ship_by'            => $data['ship_by'],
			'ship_by_gmt'        => $data['ship_by_gmt'],
			'customer'           => $data['customer'],
			'line_items'         => $data['line_items'],
			'discount_total'     => $data['discount_total'],
			'discount_tax'       => $data['discount_tax'],
			'shipping_total'     => $data['shipping_total'],
			'shipping_tax'       => $data['shipping_tax'],
			'cart_tax'           => $data['cart_tax'],
			'total_tax'          => $data['total_tax'],
			'customer_note'      => $data['customer_note'],
			'billing'            => $data['billing'],
			'shipping'           => $data['shipping'],
		);
	}

	/**
	 * Prepare a single order output for response.
	 *
	 * @param  WC_Data $object Object data.
	 * @param  WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_object_for_response( $object, $request ) {
		$this->request       = $request;
		$this->request['dp'] = is_null( $this->request['dp'] ) ? wc_get_price_decimals() : absint( $this->request['dp'] );
		$data                = $this->get_formatted_item_data( $object );
		$context             = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data                = $this->add_additional_fields_to_object( $data, $request );
		$data                = $this->filter_response_by_context( $data, $context );
		$response            = rest_ensure_response( $data );
		$response->add_links( $this->prepare_links( $object, $request ) );

		/**
		 * Filter the data for a response.
		 *
		 * The dynamic portion of the hook name, $this->post_type,
		 * refers to object type being prepared for the response.
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param WC_Data $object Object data.
		 * @param WP_REST_Request $request Request object.
		 */
		return apply_filters( "uni_wc_orders_app_rest_prepare_{$this->post_type}_object", $response, $object, $request );
	}

	/**
	 * Prepare objects query.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 *
	 * @return array
	 */
	protected function prepare_objects_query( $request ) {
		global $wpdb;

		$args = parent::prepare_objects_query( $request );

		// Set post_status.
		if ( 'any' !== $request['status'] ) {
			$args['post_status'] = 'wc-' . $request['status'];
		} else {
			$args['post_status'] = 'any';
		}

		if ( isset( $request['customer'] ) ) {
			if ( ! empty( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}

			$args['meta_query'][] = array(
				'key'   => '_customer_user',
				'value' => $request['customer'],
				'type'  => 'NUMERIC',
			);
		}

		// Search by product.
		if ( ! empty( $request['product'] ) ) {
			$order_ids = $wpdb->get_col( $wpdb->prepare( "
				SELECT order_id
				FROM {$wpdb->prefix}woocommerce_order_items
				WHERE order_item_id IN ( SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_product_id' AND meta_value = %d )
				AND order_item_type = 'line_item'
			 ", $request['product'] ) );

			// Force WP_Query return empty if don't found any order.
			$order_ids = ! empty( $order_ids ) ? $order_ids : array( 0 );

			$args['post__in'] = $order_ids;
		}

		// Search.
		if ( ! empty( $args['s'] ) ) {
			$order_ids = wc_order_search( $args['s'] );

			if ( ! empty( $order_ids ) ) {
				unset( $args['s'] );
				$args['post__in'] = array_merge( $order_ids, array( 0 ) );
			}
		}

		return $args;
	}

	/**
	 * Only return writable props from schema.
	 *
	 * @param  array $schema Schema.
	 *
	 * @return bool
	 */
	protected function filter_writable_props( $schema ) {
		return empty( $schema['readonly'] );
	}

	/**
	 * Prepare a single order for create or update.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @param  bool $creating If is creating a new object.
	 *
	 * @return WP_Error|WC_Data
	 */
	protected function prepare_object_for_database( $request, $creating = false ) {
		$id        = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$order     = new WC_Order( $id );
		$schema    = $this->get_item_schema();
		$data_keys = array_keys( array_filter( $schema['properties'], array( $this, 'filter_writable_props' ) ) );

		// Handle all writable props.
		foreach ( $data_keys as $key ) {
			$value = $request[ $key ];

			if ( ! is_null( $value ) ) {
				switch ( $key ) {
					case 'status':
						// Status change should be done later so transitions have new data.
						break;
					case 'billing':
					case 'shipping':
						$this->update_address( $order, $value, $key );
						break;
					case 'line_items':
					case 'shipping_lines':
					case 'fee_lines':
					case 'coupon_lines':
						if ( is_array( $value ) ) {
							foreach ( $value as $item ) {
								if ( is_array( $item ) ) {
									if ( $this->item_is_null( $item ) || ( isset( $item['quantity'] ) && 0 === $item['quantity'] ) ) {
										$order->remove_item( $item['id'] );
									} else {
										$this->set_item( $order, $key, $item );
									}
								}
							}
						}
						break;
					case 'meta_data':
						if ( is_array( $value ) ) {
							foreach ( $value as $meta ) {
								$order->update_meta_data( $meta['key'], $meta['value'], isset( $meta['id'] ) ? $meta['id'] : '' );
							}
						}
						break;
					default:
						if ( is_callable( array( $order, "set_{$key}" ) ) ) {
							$order->{"set_{$key}"}( $value );
						}
						break;
				}
			}
		}

		/**
		 * Filters an object before it is inserted via the REST API.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`,
		 * refers to the object type slug.
		 *
		 * @param WC_Data $order Object object.
		 * @param WP_REST_Request $request Request object.
		 * @param bool $creating If is creating a new object.
		 */
		return apply_filters( "uni_wc_orders_app_rest_pre_insert_{$this->post_type}_object", $order, $request, $creating );
	}

	/**
	 * Save an object data.
	 *
	 * @since  3.0.0
	 * @throws WC_REST_Exception But all errors are validated before returning any data.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @param  bool $creating If is creating a new object.
	 *
	 * @return WC_Data|WP_Error
	 */
	protected function save_object( $request, $creating = false ) {
		try {
			$object = $this->prepare_object_for_database( $request, $creating );

			if ( is_wp_error( $object ) ) {
				return $object;
			}

			// Make sure gateways are loaded so hooks from gateways fire on save/create.
			WC()->payment_gateways();

			if ( ! is_null( $request['customer_id'] ) && 0 !== $request['customer_id'] ) {
				// Make sure customer exists.
				if ( false === get_user_by( 'id', $request['customer_id'] ) ) {
					throw new WC_REST_Exception( 'woocommerce_rest_invalid_customer_id', __( 'Customer ID is invalid.', 'wc-orders-app' ), 400 );
				}

				// Make sure customer is part of blog.
				if ( is_multisite() && ! is_user_member_of_blog( $request['customer_id'] ) ) {
					throw new WC_REST_Exception( 'woocommerce_rest_invalid_customer_id_network', __( 'Customer ID does not belong to this site.', 'wc-orders-app' ), 400 );
				}
			}

			if ( $creating ) {
				$object->set_created_via( 'rest-api' );
				$object->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
				$object->calculate_totals();
			} else {
				// If items have changed, recalculate order totals.
				if ( isset( $request['billing'] ) || isset( $request['shipping'] ) || isset( $request['line_items'] ) || isset( $request['shipping_lines'] ) || isset( $request['fee_lines'] ) || isset( $request['coupon_lines'] ) ) {
					$object->calculate_totals( true );
				}
			}

			// Set status.
			if ( ! empty( $request['status'] ) ) {
				$object->set_status( $request['status'] );
			}

			$object->save();

			// Actions for after the order is saved.
			if ( true === $request['set_paid'] ) {
				if ( $creating || $object->needs_payment() ) {
					$object->payment_complete( $request['transaction_id'] );
				}
			}

			return $this->get_object( $object->get_id() );
		} catch ( WC_REST_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Maybe set an item prop if the value was posted.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @param string $prop Order property.
	 * @param array $posted Request data.
	 */
	protected function maybe_set_item_prop( $item, $prop, $posted ) {
		if ( isset( $posted[ $prop ] ) ) {
			$item->{"set_$prop"}( $posted[ $prop ] );
		}
	}

	/**
	 * Maybe set item props if the values were posted.
	 *
	 * @param WC_Order_Item $item Order item data.
	 * @param string[] $props Properties.
	 * @param array $posted Request data.
	 */
	protected function maybe_set_item_props( $item, $props, $posted ) {
		foreach ( $props as $prop ) {
			$this->maybe_set_item_prop( $item, $prop, $posted );
		}
	}

	/**
	 * Wrapper method to create/update order items.
	 * When updating, the item ID provided is checked to ensure it is associated
	 * with the order.
	 *
	 * @param WC_Order $order order object.
	 * @param string $item_type The item type.
	 * @param array $posted item provided in the request body.
	 *
	 * @throws WC_REST_Exception If item ID is not associated with order.
	 */
	protected function set_item( $order, $item_type, $posted ) {
		global $wpdb;

		if ( ! empty( $posted['id'] ) ) {
			$action = 'update';
		} else {
			$action = 'create';
		}

		$method = 'prepare_' . $item_type;
		$item   = null;

		// Verify provided line item ID is associated with order.
		if ( 'update' === $action ) {
			$item = $order->get_item( absint( $posted['id'] ), false );

			if ( ! $item ) {
				throw new WC_REST_Exception( 'uni_wc_orders_app_rest_invalid_item_id', __( 'Order item ID provided is not associated with order.', 'wc-orders-app' ), 400 );
			}
		}

		// Prepare item data.
		$item = $this->$method( $posted, $action, $item );

		do_action( 'uni_wc_orders_app_rest_set_order_item', $item, $posted );

		// If creating the order, add the item to it.
		if ( 'create' === $action ) {
			$order->add_item( $item );
		} else {
			$item->save();
		}
	}

	/**
	 * Helper method to check if the resource ID associated with the provided item is null.
	 * Items can be deleted by setting the resource ID to null.
	 *
	 * @param array $item Item provided in the request body.
	 *
	 * @return bool True if the item resource ID is null, false otherwise.
	 */
	protected function item_is_null( $item ) {
		$keys = array( 'product_id', 'method_id', 'method_title', 'name', 'code' );

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $item ) && is_null( $item[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get order statuses without prefixes.
	 * @return array
	 */
	protected function get_order_statuses() {
		$order_statuses = array();

		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			$order_statuses[] = str_replace( 'wc-', '', $status );
		}

		return $order_statuses;
	}

	/**
	 * Check batch limit.
	 *
	 * @param array $items Request items.
	 * @return bool|WP_Error
	 */
	protected function check_batch_limit( $items ) {
		$limit = apply_filters( 'uni_wc_orders_app_rest_batch_items_limit', 100, $this->get_normalized_rest_base() );
		$total = 0;

		if ( ! empty( $items['create'] ) ) {
			$total += count( $items['create'] );
		}

		if ( ! empty( $items['update'] ) ) {
			$total += count( $items['update'] );
		}

		if ( ! empty( $items['delete'] ) ) {
			$total += count( $items['delete'] );
		}

		if ( $total > $limit ) {
			/* translators: %s: items limit */
			return new WP_Error( 'uni_wc_orders_app_rest_request_entity_too_large', sprintf( __( 'Unable to accept more than %s items for this request.', 'wc-orders-app' ), $limit ), array( 'status' => 413 ) );
		}

		return true;
	}

	/**
	 * Bulk create, update and delete items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array Of WP_Error or WP_REST_Response.
	 */
	public function batch_items( $request ) {
		/**
		 * REST Server
		 *
		 * @var WP_REST_Server $wp_rest_server
		 */
		global $wp_rest_server;

		// Get the request params.
		$items    = array_filter( $request->get_params() );
		$response = array();

		// Check batch limit.
		$limit = $this->check_batch_limit( $items );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		if ( ! empty( $items['create'] ) ) {
			foreach ( $items['create'] as $item ) {
				$_item = new WP_REST_Request( 'POST' );

				// Default parameters.
				$defaults = array();
				$schema   = $this->get_public_item_schema();
				foreach ( $schema['properties'] as $arg => $options ) {
					if ( isset( $options['default'] ) ) {
						$defaults[ $arg ] = $options['default'];
					}
				}
				$_item->set_default_params( $defaults );

				// Set request parameters.
				$_item->set_body_params( $item );
				$_response = $this->create_item( $_item );

				if ( is_wp_error( $_response ) ) {
					$response['create'][] = array(
						'id'    => 0,
						'error' => array(
							'code'    => $_response->get_error_code(),
							'message' => $_response->get_error_message(),
							'data'    => $_response->get_error_data(),
						),
					);
				} else {
					$response['create'][] = $wp_rest_server->response_to_data( $_response, '' );
				}
			}
		}

		if ( ! empty( $items['update'] ) ) {
			foreach ( $items['update'] as $item ) {
				$_item = new WP_REST_Request( 'PUT' );
				$_item->set_body_params( $item );
				$_response = $this->update_item( $_item );

				if ( is_wp_error( $_response ) ) {
					$response['update'][] = array(
						'id'    => $item['id'],
						'error' => array(
							'code'    => $_response->get_error_code(),
							'message' => $_response->get_error_message(),
							'data'    => $_response->get_error_data(),
						),
					);
				} else {
					$response['update'][] = $wp_rest_server->response_to_data( $_response, '' );
				}
			}
		}

		if ( ! empty( $items['delete'] ) ) {
			foreach ( $items['delete'] as $id ) {
				$id = (int) $id;

				if ( 0 === $id ) {
					continue;
				}

				$_item = new WP_REST_Request( 'DELETE' );
				$_item->set_query_params( array(
					'id'    => $id,
					'force' => true,
				) );
				$_response = $this->delete_item( $_item );

				if ( is_wp_error( $_response ) ) {
					$response['delete'][] = array(
						'id'    => $id,
						'error' => array(
							'code'    => $_response->get_error_code(),
							'message' => $_response->get_error_message(),
							'data'    => $_response->get_error_data(),
						),
					);
				} else {
					$response['delete'][] = $wp_rest_server->response_to_data( $_response, '' );
				}
			}
		}

		return $response;
	}

	/**
	 * Get the Order's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->post_type,
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => __( 'Unique identifier for the resource.', 'wc-orders-app' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'number'             => array(
					'description' => __( 'Order number.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'order_key'          => array(
					'description' => __( 'Order key.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'created_via'        => array(
					'description' => __( 'Shows where the order was created.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'status'             => array(
					'description' => __( 'Order status.', 'wc-orders-app' ),
					'type'        => 'string',
					'default'     => 'pending',
					'enum'        => $this->get_order_statuses(),
					'context'     => array( 'view', 'edit' ),
				),
				'currency'           => array(
					'description' => __( 'Currency the order was created with, in ISO format.', 'wc-orders-app' ),
					'type'        => 'string',
					'default'     => get_woocommerce_currency(),
					'enum'        => array_keys( get_woocommerce_currencies() ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created'       => array(
					'description' => __( "The date the order was created, in the site's timezone.", 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created_gmt'   => array(
					'description' => __( "The date the order was created, as GMT.", 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified'      => array(
					'description' => __( "The date the order was last modified, in the site's timezone.", 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified_gmt'  => array(
					'description' => __( "The date the order was last modified, as GMT.", 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'transaction_id'     => array(
					'description' => __( 'Unique transaction ID.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_paid'          => array(
					'description' => __( "The date the order was paid, in the site's timezone.", 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_paid_gmt'      => array(
					'description' => __( 'The date the order was paid, as GMT.', 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_completed'     => array(
					'description' => __( "The date the order was completed, in the site's timezone.", 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_completed_gmt' => array(
					'description' => __( 'The date the order was completed, as GMT.', 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'total'              => array(
					'description' => __( 'Grand total.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'ship_by'            => array(
					'description' => __( "The date the order must be shipped.", 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'ship_by_gmt'        => array(
					'description' => __( 'The date the order must be shipped, as GMT.', 'wc-orders-app' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'customer'           => array(
					'description' => __( 'Sum of all taxes.', 'wc-orders-app' ),
					'type'        => 'object',
					'properties'  => array(
						'id'         => array(
							'description' => __( 'Unique identifier for the resource.', 'wc-orders-app' ),
							'type'        => 'integer',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'email'      => array(
							'description' => __( 'The email address for the customer.', 'wc-orders-app' ),
							'type'        => 'string',
							'format'      => 'email',
							'context'     => array( 'view', 'edit' ),
						),
						'first_name' => array(
							'description' => __( 'Customer first name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'arg_options' => array(
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
						'last_name'  => array(
							'description' => __( 'Customer last name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'arg_options' => array(
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
						'company'    => array(
							'description' => __( 'Customer\'s company name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'arg_options' => array(
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'line_items'         => array(
					'description' => __( 'Line items data.', 'wc-orders-app' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array(
								'description' => __( 'Item ID.', 'wc-orders-app' ),
								'type'        => 'integer',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'name'         => array(
								'description' => __( 'Product name.', 'wc-orders-app' ),
								'type'        => 'mixed',
								'context'     => array( 'view', 'edit' ),
							),
							'product_id'   => array(
								'description' => __( 'Product ID.', 'wc-orders-app' ),
								'type'        => 'mixed',
								'context'     => array( 'view', 'edit' ),
							),
							'variation_id' => array(
								'description' => __( 'Variation ID, if applicable.', 'wc-orders-app' ),
								'type'        => 'integer',
								'context'     => array( 'view', 'edit' ),
							),
							'quantity'     => array(
								'description' => __( 'Quantity ordered.', 'wc-orders-app' ),
								'type'        => 'integer',
								'context'     => array( 'view', 'edit' ),
							),
							'tax_class'    => array(
								'description' => __( 'Tax class of product.', 'wc-orders-app' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'subtotal'     => array(
								'description' => __( 'Line subtotal (before discounts).', 'wc-orders-app' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'subtotal_tax' => array(
								'description' => __( 'Line subtotal tax (before discounts).', 'wc-orders-app' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'total'        => array(
								'description' => __( 'Line total (after discounts).', 'wc-orders-app' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'total_tax'    => array(
								'description' => __( 'Line total tax (after discounts).', 'wc-orders-app' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'taxes'        => array(
								'description' => __( 'Line taxes.', 'wc-orders-app' ),
								'type'        => 'array',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'id'       => array(
											'description' => __( 'Tax rate ID.', 'wc-orders-app' ),
											'type'        => 'integer',
											'context'     => array( 'view', 'edit' ),
										),
										'total'    => array(
											'description' => __( 'Tax total.', 'wc-orders-app' ),
											'type'        => 'string',
											'context'     => array( 'view', 'edit' ),
										),
										'subtotal' => array(
											'description' => __( 'Tax subtotal.', 'wc-orders-app' ),
											'type'        => 'string',
											'context'     => array( 'view', 'edit' ),
										),
									),
								),
							),
							'meta_data'    => array(
								'description' => __( 'Meta data.', 'wc-orders-app' ),
								'type'        => 'array',
								'context'     => array( 'view', 'edit' ),
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'name'    => array(
											'description' => __( 'Uni CPO option slug', 'wc-orders-app' ),
											'type'        => 'string',
											'context'     => array( 'view', 'edit' ),
											'readonly'    => true,
										),
										'key'     => array(
											'description' => __( 'Uni CPO option title', 'wc-orders-app' ),
											'type'        => 'string',
											'context'     => array( 'view', 'edit' ),
											'readonly'    => true,
										),
										'value'   => array(
											'description' => __( 'Uni CPO option\'s value', 'wc-orders-app' ),
											'type'        => 'mixed',
											'context'     => array( 'view', 'edit' ),
											'readonly'    => true,
										),
										'display' => array(
											'description' => __( 'Uni CPO option\'s value to be displayed', 'wc-orders-app' ),
											'type'        => 'mixed',
											'context'     => array( 'view', 'edit' ),
											'readonly'    => true,
										),
									),
								),
							),
							'sku'          => array(
								'description' => __( 'Product SKU.', 'wc-orders-app' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'price'        => array(
								'description' => __( 'Product price.', 'wc-orders-app' ),
								'type'        => 'number',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
						),
					),
				),
				'discount_total'     => array(
					'description' => __( 'Total discount amount for the order.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'discount_tax'       => array(
					'description' => __( 'Total discount tax amount for the order.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'shipping_total'     => array(
					'description' => __( 'Total shipping amount for the order.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'shipping_tax'       => array(
					'description' => __( 'Total shipping tax amount for the order.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'cart_tax'           => array(
					'description' => __( 'Sum of line item taxes only.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'total_tax'          => array(
					'description' => __( 'Sum of all taxes.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'customer_note'      => array(
					'description' => __( 'Note left by customer during checkout.', 'wc-orders-app' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'billing'            => array(
					'description' => __( 'Billing address.', 'wc-orders-app' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'first_name' => array(
							'description' => __( 'First name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'last_name'  => array(
							'description' => __( 'Last name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'company'    => array(
							'description' => __( 'Company name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'address_1'  => array(
							'description' => __( 'Address line 1', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'address_2'  => array(
							'description' => __( 'Address line 2', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'city'       => array(
							'description' => __( 'City name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'state'      => array(
							'description' => __( 'ISO code or name of the state, province or district.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'postcode'   => array(
							'description' => __( 'Postal code.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'country'    => array(
							'description' => __( 'Country code in ISO 3166-1 alpha-2 format.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'email'      => array(
							'description' => __( 'Email address.', 'wc-orders-app' ),
							'type'        => 'string',
							'format'      => 'email',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'phone'      => array(
							'description' => __( 'Phone number.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					),
				),
				'shipping'           => array(
					'description' => __( 'Shipping address.', 'wc-orders-app' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'first_name' => array(
							'description' => __( 'First name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'last_name'  => array(
							'description' => __( 'Last name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'company'    => array(
							'description' => __( 'Company name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'address_1'  => array(
							'description' => __( 'Address line 1', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'address_2'  => array(
							'description' => __( 'Address line 2', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'city'       => array(
							'description' => __( 'City name.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'state'      => array(
							'description' => __( 'ISO code or name of the state, province or district.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'postcode'   => array(
							'description' => __( 'Postal code.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'country'    => array(
							'description' => __( 'Country code in ISO 3166-1 alpha-2 format.', 'wc-orders-app' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
					),
					'readonly'    => true,
				)
			)
		);

		return $this->add_additional_fields_schema( $schema );

	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['status'] = array(
			'default'           => 'any',
			'description'       => __( 'Limit result set to orders assigned a specific status.', 'wc-orders-app' ),
			'type'              => 'string',
			'enum'              => array_merge( array( 'any' ), $this->get_order_statuses() ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Get the batch schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_public_batch_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'batch',
			'type'       => 'object',
			'properties' => array(
				'create' => array(
					'description' => __( 'List of created resources.', 'wc-orders-app' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type'    => 'object',
					),
				),
				'update' => array(
					'description' => __( 'List of updated resources.', 'wc-orders-app' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type'    => 'object',
					),
				),
				'delete' => array(
					'description' => __( 'List of delete resources.', 'wc-orders-app' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type'    => 'integer',
					),
				),
			),
		);

		return $schema;
	}
}