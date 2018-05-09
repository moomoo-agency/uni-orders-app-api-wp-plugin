<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Uni_Jwt_Auth Class
 */
final class Uni_Jwt_Auth {
    /**
     * The current version of the plugin
     */
    protected $version;

    /**
     * The namespace for the api calls.
     * Ref: https://developer.wordpress.org/rest-api/extending-the-rest-api/routes-and-endpoints/#namespaces
     *
     */
    private $namespace;

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
    public function add_routes() {
        register_rest_route( $this->namespace, 'token', [
            'methods'  => 'POST',
            'callback' => array( $this, 'create_token' ),
        ] );

        register_rest_route( $this->namespace, 'token/check', [
            'methods'  => 'POST',
            'callback' => array( $this, 'check_token' ),
        ] );
    }

    /**
     * Token creation logic
     *
     * @param WP_REST_Request $request Full request data
     * @return $token Token
     */
    public function create_token( $request ) {
        // the following is for testing purposes only
        // let's test our endpoint
        $username = $request->get_param('username');
        if ( ! empty( $username ) ) {
            $dummy_data = "You send me this username: {$username}";
        } else {
            $dummy_data = "No username has been sent yet";
        }

        return $dummy_data;
    }

    /**
     * Token validation logic
     */
    public function check_token() {
        //
    }
}
