<?php

abstract class Uni_Rest_CRUD_Controller extends Uni_REST_Posts_Controller {

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
     * If object is hierarchical.
     *
     * @var bool
     */
    protected $hierarchical = false;

    /**
     * Get object.
     *
     * @param  int $id Object ID.
     * @return object WC_Data object or WP_Error object.
     */
    protected function get_object( $id ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'wc-orders-app' ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Get object permalink.
     *
     * @param  object $object
     * @return string
     */
    protected function get_permalink( $object ) {
        return '';
    }

    /**
     * Prepares the object for the REST response.
     *
     * @param  WC_Data         $object  Object data.
     * @param  WP_REST_Request $request Request object.
     * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
     */
    protected function prepare_object_for_response( $object, $request ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'wc-orders-app' ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Prepares one object for create or update operation.
     *
     * @param  WP_REST_Request $request Request object.
     * @param  bool            $creating If is creating a new object.
     * @return WP_Error|WC_Data The prepared item, or WP_Error object on failure.
     */
    protected function prepare_object_for_database( $request, $creating = false ) {
        return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'wc-orders-app' ), __METHOD__ ), array( 'status' => 405 ) );
    }

    /**
     * Get a single item.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_item( $request ) {
        $object = $this->get_object( (int) $request['id'] );

        if ( ! $object || 0 === $object->get_id() ) {
            return new WP_Error( "uni_wc_orders_app_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'wc-orders-app' ), array( 'status' => 404 ) );
        }

        $data     = $this->prepare_object_for_response( $object, $request );
        $response = rest_ensure_response( $data );

        if ( $this->public ) {
            $response->link_header( 'alternate', $this->get_permalink( $object ), array( 'type' => 'text/html' ) );
        }

        return $response;
    }

    /**
     * Prepare objects query.
     *
     * @param  WP_REST_Request $request Full details about the request.
     * @return array
     */
    protected function prepare_objects_query( $request ) {
        $args                        = array();
        $args['offset']              = $request['offset'];
        $args['order']               = $request['order'];
        $args['orderby']             = $request['orderby'];
        $args['paged']               = $request['page'];
        $args['post__in']            = $request['include'];
        $args['post__not_in']        = $request['exclude'];
        $args['posts_per_page']      = $request['per_page'];
        $args['name']                = $request['slug'];
        $args['post_parent__in']     = $request['parent'];
        $args['post_parent__not_in'] = $request['parent_exclude'];
        $args['s']                   = $request['search'];

        if ( 'date' === $args['orderby'] ) {
            $args['orderby'] = 'date ID';
        }

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

        /**
         * Filter the query arguments for a request.
         *
         * Enables adding extra arguments or setting defaults for a post
         * collection request.
         *
         * @param array           $args    Key value array of query var to query value.
         * @param WP_REST_Request $request The request used.
         */
        $args = apply_filters( "uni_wc_orders_app_rest_{$this->post_type}_object_query", $args, $request );

        return $this->prepare_items_query( $args, $request );
    }

    /**
     * Get objects.
     *
     * @param  array $query_args Query args.
     * @return array
     */
    protected function get_objects( $query_args ) {
        $query  = new WP_Query();
        $result = $query->query( $query_args );

        $total_posts = $query->found_posts;
        if ( $total_posts < 1 ) {
            // Out-of-bounds, run the query again without LIMIT for total count.
            unset( $query_args['paged'] );
            $count_query = new WP_Query();
            $count_query->query( $query_args );
            $total_posts = $count_query->found_posts;
        }

        return array(
            'objects' => array_map( array( $this, 'get_object' ), $result ),
            'total'   => (int) $total_posts,
            'pages'   => (int) ceil( $total_posts / (int) $query->query_vars['posts_per_page'] ),
        );
    }

    /**
     * Get a collection of posts.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_items( $request ) {
        $query_args    = $this->prepare_objects_query( $request );
        $query_results = $this->get_objects( $query_args );

        $objects = array();
        foreach ( $query_results['objects'] as $object ) {
            if ( ! wc_rest_check_post_permissions( $this->post_type, 'read', $object->get_id() ) ) {
                continue;
            }

            $data = $this->prepare_object_for_response( $object, $request );
            $objects[] = $this->prepare_response_for_collection( $data );
        }

        $page      = (int) $query_args['paged'];
        $max_pages = $query_results['pages'];

        $response = rest_ensure_response( $objects );
        $response->header( 'X-WP-Total', $query_results['total'] );
        $response->header( 'X-WP-TotalPages', (int) $max_pages );

        $base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );

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
}