<?php

abstract class Uni_REST_Posts_Controller extends WP_REST_Controller {

    /**
     * The current version of the plugin
     */
    protected $version;

    /**
     * The namespace for the api calls.
     *
     */
    protected $namespace;

    /**
     * Post type.
     */
    protected $post_type = '';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = '';

    /**
     * Check if a given request has access to get items
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|bool
     */
    public function get_items_permissions_check( $request ) {
        return true;
        /*return ( current_user_can( 'manage_woocommerce' ) )
            ? true
            : new WP_Error(
        'uni_wc_orders_app_rest_cannot_view',
        __( 'Sorry, you cannot list resources.',
        'wc-orders-app' ),
        array( 'status' => rest_authorization_required_code() ) );*/
    }

    /**
     * Check if a given request has access to get a specific item
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|bool
     */
    public function get_item_permissions_check( $request ) {
        return $this->get_items_permissions_check( $request );
    }

    /**
     * Check if a given request has access to create items
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|bool
     */
    public function create_item_permissions_check( $request ) {
        return ( current_user_can( 'manage_woocommerce' ) )
            ? true
            : new WP_Error( 'uni_wc_orders_app_rest_cannot_modify', __( 'Sorry, you are not allowed to modify resources.', 'wc-orders-app' ), array( 'status' => rest_authorization_required_code() ) );
    }

    /**
     * Check if a given request has access to update a specific item
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|bool
     */
    public function update_item_permissions_check( $request ) {
        return $this->create_item_permissions_check( $request );
    }

    /**
     * Check if a given request has access to delete a specific item
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|bool
     */
    public function delete_item_permissions_check( $request ) {
        return $this->create_item_permissions_check( $request );
    }

    /**
     * Get a collection of items
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_items( $request ) {
        $args                         = array();
        $args['offset']               = $request['offset'];
        $args['order']                = $request['order'];
        $args['orderby']              = $request['orderby'];
        $args['paged']                = $request['page'];
        $args['post__in']             = $request['include'];
        $args['post__not_in']         = $request['exclude'];
        $args['posts_per_page']       = $request['per_page'];
        $args['name']                 = $request['slug'];
        $args['s']                    = $request['search'];

        $args['date_query'] = array();
        // Set before into date query. Date query must be specified as an array of an array.
        if ( isset( $request['before'] ) ) {
            $args['date_query'][0]['before'] = $request['before'];
        }

        // Set after into date query. Date query must be specified as an array of an array.
        if ( isset( $request['after'] ) ) {
            $args['date_query'][0]['after'] = $request['after'];
        }

        // Force the post_type argument, since it's not a user input variable.
        $args['post_type'] = $this->post_type;

        $query_args = $this->prepare_items_query( $args, $request );

        $posts_query = new WP_Query();
        $query_result = $posts_query->query( $query_args );

        $posts = array();
        foreach ( $query_result as $post ) {
            if ( ! wc_rest_check_post_permissions( $this->post_type, 'read', $post->ID ) ) {
                continue;
            }

            $data = $this->prepare_item_for_response( $post, $request );
            $posts[] = $this->prepare_response_for_collection( $data );
        }

        $page = (int) $query_args['paged'];
        $total_posts = $posts_query->found_posts;

        if ( $total_posts < 1 ) {
            // Out-of-bounds, run the query again without LIMIT for total count
            unset( $query_args['paged'] );
            $count_query = new WP_Query();
            $count_query->query( $query_args );
            $total_posts = $count_query->found_posts;
        }

        $max_pages = ceil( $total_posts / (int) $query_args['posts_per_page'] );

        $response = rest_ensure_response( $posts );
        $response->header( 'X-WP-Total', (int) $total_posts );
        $response->header( 'X-WP-TotalPages', (int) $max_pages );

        $request_params = $request->get_query_params();
        $base = add_query_arg( $request_params, rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );

        if ( $page > 1 ) {
            $prev_page = $page - 1;
            if ( $prev_page > $max_pages ) {
                $prev_page = $max_pages;
            }
            $prev_link = add_query_arg( 'page', $prev_page, $base );
            $response->link_header( 'prev', $prev_link );
        }
        if ( $max_pages > $page ) {
            $next_page = $page + 1;
            $next_link = add_query_arg( 'page', $next_page, $base );
            $response->link_header( 'next', $next_link );
        }

        return $response;
    }

    /**
     * Get one item from the collection
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_item( $request ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass." ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Create one item from the collection
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Request
     */
    public function create_item( $request ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass." ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Update one item from the collection
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Request
     */
    public function update_item( $request ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass." ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Delete one item from the collection
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Request
     */
    public function delete_item( $request ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass." ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Determine the allowed query_vars for a get_items() response and
     * prepare for WP_Query.
     *
     * @param array           $prepared_args
     * @param WP_REST_Request $request
     * @return array          $query_args
     */
    protected function prepare_items_query( $prepared_args = array(), $request = null ) {

        $valid_vars = array_flip( $this->get_allowed_query_vars() );
        $query_args = array();
        foreach ( $valid_vars as $var => $index ) {
            if ( isset( $prepared_args[ $var ] ) ) {
                /**
                 * Filter the query_vars used in `get_items` for the constructed query.
                 *
                 * The dynamic portion of the hook name, $var, refers to the query_var key.
                 *
                 * @param mixed $prepared_args[ $var ] The query_var value.
                 *
                 */
                $query_args[ $var ] = apply_filters( "uni_wc_orders_app_rest_query_var-{$var}", $prepared_args[ $var ] );
            }
        }

        $query_args['ignore_sticky_posts'] = true;

        if ( 'include' === $query_args['orderby'] ) {
            $query_args['orderby'] = 'post__in';
        } elseif ( 'id' === $query_args['orderby'] ) {
            $query_args['orderby'] = 'ID'; // ID must be capitalized
        }

        return $query_args;
    }

    /**
     * Get all the WP Query vars that are allowed for the API request.
     *
     * @return array
     */
    protected function get_allowed_query_vars() {
        global $wp;

        /**
         * Filter the publicly allowed query vars.
         *
         * Allows adjusting of the default query vars that are made public.
         *
         * @param array  Array of allowed WP_Query query vars.
         */
        $valid_vars = apply_filters( 'query_vars', $wp->public_query_vars );

        $post_type_obj = get_post_type_object( $this->post_type );
        if ( current_user_can( $post_type_obj->cap->edit_posts ) ) {
            /**
             * Filter the allowed 'private' query vars for authorized users.
             *
             * If the user has the `edit_posts` capability, we also allow use of
             * private query parameters, which are only undesirable on the
             * frontend, but are safe for use in query strings.
             *
             * To disable anyway, use
             * `add_filter( 'uni_wc_orders_app_rest_private_query_vars', '__return_empty_array' );`
             *
             * @param array $private_query_vars Array of allowed query vars for authorized users.
             * }
             */
            $private = apply_filters( 'uni_wc_orders_app_rest_private_query_vars', $wp->private_query_vars );
            $valid_vars = array_merge( $valid_vars, $private );
        }
        // Define our own in addition to WP's normal vars.
        $rest_valid = array(
            'date_query',
            'ignore_sticky_posts',
            'offset',
            'post__in',
            'post__not_in',
            'post_parent',
            'post_parent__in',
            'post_parent__not_in',
            'posts_per_page',
            'meta_query',
            'tax_query',
            'meta_key',
            'meta_value',
            'meta_compare',
            'meta_value_num',
        );
        $valid_vars = array_merge( $valid_vars, $rest_valid );

        /**
         * Filter allowed query vars for the REST API.
         *
         * This filter allows you to add or remove query vars from the final allowed
         * list for all requests, including unauthenticated ones. To alter the
         * vars for editors only.
         *
         * @param array {
         *    Array of allowed WP_Query query vars.
         *
         *    @param string $allowed_query_var The query var to allow.
         * }
         */
        $valid_vars = apply_filters( 'uni_wc_orders_app_rest_query_vars', $valid_vars );

        return $valid_vars;
    }

    /**
     * Prepare the item for create or update operation
     *
     * @param WP_REST_Request $request Request object
     * @return WP_Error|object $prepared_item
     */
    protected function prepare_item_for_database( $request ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass." ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Prepare the item for the REST response
     *
     * @param mixed $item WordPress representation of the item.
     * @param WP_REST_Request $request Request object.
     * @return mixed
     */
    public function prepare_item_for_response( $item, $request ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass." ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Get the query params for collections
     *
     * @return array
     */
    public function get_collection_params() {
        $params = parent::get_collection_params();

        $params['context']['default'] = 'view';

        $params['after'] = array(
            'description'        => __( 'Limit response to resources published after a given ISO8601 compliant date.', 'wc-orders-app' ),
            'type'               => 'string',
            'format'             => 'date-time',
            'validate_callback'  => 'rest_validate_request_arg',
        );
        $params['before'] = array(
            'description'        => __( 'Limit response to resources published before a given ISO8601 compliant date.', 'wc-orders-app' ),
            'type'               => 'string',
            'format'             => 'date-time',
            'validate_callback'  => 'rest_validate_request_arg',
        );
        $params['exclude'] = array(
            'description'       => __( 'Ensure result set excludes specific IDs.', 'wc-orders-app' ),
            'type'              => 'array',
            'items'             => array(
                'type'          => 'integer',
            ),
            'default'           => array(),
            'sanitize_callback' => 'wp_parse_id_list',
        );
        $params['include'] = array(
            'description'       => __( 'Limit result set to specific ids.', 'wc-orders-app' ),
            'type'              => 'array',
            'items'             => array(
                'type'          => 'integer',
            ),
            'default'           => array(),
            'sanitize_callback' => 'wp_parse_id_list',
        );
        $params['offset'] = array(
            'description'        => __( 'Offset the result set by a specific number of items.', 'wc-orders-app' ),
            'type'               => 'integer',
            'sanitize_callback'  => 'absint',
            'validate_callback'  => 'rest_validate_request_arg',
        );
        $params['order'] = array(
            'description'        => __( 'Order sort attribute ascending or descending.', 'wc-orders-app' ),
            'type'               => 'string',
            'default'            => 'desc',
            'enum'               => array( 'asc', 'desc' ),
            'validate_callback'  => 'rest_validate_request_arg',
        );
        $params['orderby'] = array(
            'description'        => __( 'Sort collection by object attribute.', 'wc-orders-app' ),
            'type'               => 'string',
            'default'            => 'date',
            'enum'               => array(
                'date',
                'id',
                'include',
                'title',
                'slug',
            ),
            'validate_callback'  => 'rest_validate_request_arg',
        );

        return $params;
    }
}