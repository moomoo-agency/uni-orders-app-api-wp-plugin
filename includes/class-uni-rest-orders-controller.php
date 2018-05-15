<?php

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
     * Expands an order item to get its data.
     *
     * @param WC_Order_item $item
     *
     * @return array
     */
    protected function get_order_item_data( $item ) {
        $data           = $item->get_data();
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

        return array(
            'id'                => $object->get_id(),
            'number'            => $data['number'],
            'order_key'         => $data['order_key'],
            'created_via'       => $data['created_via'],
            'status'            => $data['status'],
            'currency'          => $data['currency'],
            'date_created'      => $data['date_created'],
            'date_created_gmt'  => $data['date_created_gmt'],
            'date_modified'     => $data['date_modified'],
            'date_modified_gmt' => $data['date_modified_gmt'],
            'total'             => $data['total']
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
        return apply_filters( "woocommerce_rest_prepare_{$this->post_type}_object", $response, $object, $request );
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
     * Prepare links for the request.
     *
     * @param WC_Data         $object  Object data.
     * @param WP_REST_Request $request Request object.
     * @return array                   Links for the given post.
     */
    protected function prepare_links( $object, $request ) {
        $links = array(
            'self' => array(
                'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $object->get_id() ) ),
            ),
            'collection' => array(
                'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
            ),
        );

        return $links;
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
                'id'                => array(
                    'description' => __( 'Unique identifier for the resource.', 'wc-orders-app' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'number'            => array(
                    'description' => __( 'Order number.', 'wc-orders-app' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'order_key'         => array(
                    'description' => __( 'Order key.', 'wc-orders-app' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'created_via'       => array(
                    'description' => __( 'Shows where the order was created.', 'wc-orders-app' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'status'            => array(
                    'description' => __( 'Order status.', 'wc-orders-app' ),
                    'type'        => 'string',
                    'default'     => 'pending',
                    'enum'        => $this->get_order_statuses(),
                    'context'     => array( 'view', 'edit' ),
                ),
                'currency'          => array(
                    'description' => __( 'Currency the order was created with, in ISO format.', 'wc-orders-app' ),
                    'type'        => 'string',
                    'default'     => get_woocommerce_currency(),
                    'enum'        => array_keys( get_woocommerce_currencies() ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'date_created'      => array(
                    'description' => __( "The date the order was created, in the site's timezone.", 'wc-orders-app' ),
                    'type'        => 'date-time',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'date_created_gmt'  => array(
                    'description' => __( "The date the order was created, as GMT.", 'wc-orders-app' ),
                    'type'        => 'date-time',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'date_modified'     => array(
                    'description' => __( "The date the order was last modified, in the site's timezone.", 'wc-orders-app' ),
                    'type'        => 'date-time',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'date_modified_gmt' => array(
                    'description' => __( "The date the order was last modified, as GMT.", 'wc-orders-app' ),
                    'type'        => 'date-time',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'total'             => array(
                    'description' => __( 'Grand total.', 'wc-orders-app' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
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

        $params['status']   = array(
            'default'           => 'any',
            'description'       => __( 'Limit result set to orders assigned a specific status.', 'wc-orders-app' ),
            'type'              => 'string',
            'enum'              => array_merge( array( 'any' ), $this->get_order_statuses() ),
            'sanitize_callback' => 'sanitize_key',
            'validate_callback' => 'rest_validate_request_arg',
        );
        $params['customer'] = array(
            'description'       => __( 'Limit result set to orders assigned a specific customer.', 'wc-orders-app' ),
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'validate_callback' => 'rest_validate_request_arg',
        );
        $params['product']  = array(
            'description'       => __( 'Limit result set to orders assigned a specific product.', 'wc-orders-app' ),
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'validate_callback' => 'rest_validate_request_arg',
        );
        $params['dp']       = array(
            'default'           => wc_get_price_decimals(),
            'description'       => __( 'Number of decimal points to use in each resource.', 'wc-orders-app' ),
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'validate_callback' => 'rest_validate_request_arg',
        );

        return $params;
    }
}