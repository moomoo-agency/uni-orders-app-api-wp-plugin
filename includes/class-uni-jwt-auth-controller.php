<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use \Firebase\JWT\JWT;

/**
 * Uni_Jwt_Auth_Controller Class
 */
final class Uni_Jwt_Auth_Controller extends WP_REST_Controller {
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
     * The base of this controller's route.
     *
     * @var string
     */
    protected $rest_base = 'orders';

    /**
     * Authentication error.
     *
     * @var WP_Error
     */
    protected $error = null;

    /**
     * Logged in user data.
     *
     * @var stdClass
     */
    protected $user = null;

    /**
     * Constructor
     */
    public function __construct( $namespace, $version ) {
        $this->version   = $version;
        $this->namespace = $namespace . '/v' . intval( $version );
        $this->rest_base = 'token';
    }

    /**
     * Add custom routes for JWT creation and validation
     */
    public function register_routes() {
        register_rest_route( $this->namespace, $this->rest_base, [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'create_token' ),
            'args'     => $this->get_collection_params()
        ] );
    }

    /**
     * Token creation logic
     *
     * @param WP_REST_Request $request Full request data
     *
     * @return $token Token
     */
    public function create_token( $request ) {
        $secret_key = defined( 'WC_ORDERS_APP_SECRET_KEY' ) ? WC_ORDERS_APP_SECRET_KEY : false;
        $username   = $request->get_param( 'username' );
        $password   = $request->get_param( 'password' );

        if ( empty( $secret_key ) ) {
            return new WP_Error(
                'uni_wc_orders_app_misconfiguration',
                __( 'Required params are missing.', 'wc-orders-app' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $user = wp_authenticate( $username, $password );

        if ( is_wp_error( $user ) ) {
            $error_code = $user->get_error_code();

            return new WP_Error(
                "uni_wc_orders_app_{$error_code}",
                $user->get_error_message( $error_code ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $iss = get_bloginfo( 'url' );
        $iat = time();
        $exp = $iat + ( DAY_IN_SECONDS * 7 );

        $data = array(
            'iss'  => $iss,
            'exp'  => $exp,
            'nbf'  => $iat,
            'iat'  => $iat,
            'data' => array(
                'user_id' => $user->get( 'ID' )
            )
        );

        $token = $this->issue_token( $data, $secret_key );

        return $token;
    }

    /**
     * Issuing token
     *
     * @param $data array
     *
     * @return $token Token
     */
    private function issue_token( $data, $secret_key ) {
        $token = JWT::encode( $data, $secret_key );

        return $token;
    }

    /**
     * Token validation logic
     */
    public function check_token() {
        // check if this is token generation request
        $check_uri = ( strpos( $_SERVER['REQUEST_URI'], 'uni-app' ) && strpos( $_SERVER['REQUEST_URI'], 'token' ) );

        if ( $check_uri > 0 ) {
            // return true if this is token generation request
            $this->user = true;
            return $this->user;
        }

        $header               = $this->get_authorization_header();
        $header_missing_error = __( 'Authorization header is missing', 'wc-orders-app' );

        if ( ! $header ) {
            $this->set_error( $header_missing_error );

            return false;
        }

        $header_bearer = sscanf( $header, 'Bearer %s' );

        if ( null === $header_bearer ) {
            $this->set_error( $header_missing_error );

            return false;
        }

        $secret_key = defined( 'WC_ORDERS_APP_SECRET_KEY' ) ? WC_ORDERS_APP_SECRET_KEY : false;

        if ( ! $secret_key ) {
            $this->set_error( __( 'Required params are missing.', 'wc-orders-app' ) );

            return false;
        }

        $token = $header_bearer[0];

        try {
            $decoded    = JWT::decode( $token, $secret_key, array( 'HS256' ) );
            $this->user = $decoded->data->user_id;

            return $this->user;
        } catch ( Exception $e ) {
            $this->set_error( $e->getMessage() );

            return false;
        }
    }

    /**
     * Get the query params for token creation
     *
     * @return array
     */
    public function get_collection_params() {
        $params['username'] = array(
            'required'          => true,
            'description'       => __( 'Please, provide your username', 'wc-orders-app' ),
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'validate_callback' => 'rest_validate_request_arg',
        );

        $params['password'] = array(
            'required'          => true,
            'description'       => __( 'Please, provide your password', 'wc-orders-app' ),
            'type'              => 'string',
            'validate_callback' => 'rest_validate_request_arg',
        );

        return $params;
    }

    /**
     * Get the authorization header.
     *
     *
     * @return string Authorization header if set.
     */
    public function get_authorization_header() {
        if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            return wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
        }

        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            // Check for the authoization header case-insensitively.
            foreach ( $headers as $key => $value ) {
                if ( 'authorization' === strtolower( $key ) ) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Set authentication error.
     *
     * @param WP_Error $error Authentication error data.
     */
    protected function set_error( $error ) {
        // Reset user.
        $this->user = null;

        $this->error = $error;
    }

    /**
     * Get authentication error.
     *
     * @return WP_Error|null.
     */
    protected function get_error() {
        return $this->error;
    }

    /**
     * Check if is request to our REST API.
     *
     * @return bool
     */
    protected function is_request_to_rest_api() {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }

        $rest_prefix = trailingslashit( rest_get_url_prefix() );

        // Check if our endpoint.
        $is_our = ( false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix . 'uni-app/' ) );

        return apply_filters( 'uni_wc_orders_app_rest_is_request_to_rest_api', $is_our );
    }

    /**
     * Authenticate user.
     *
     * @param int|false $user_id User ID if one has been determined, false otherwise.
     *
     * @return int|false
     */
    public function authenticate( $user_id ) {
        if ( ! empty( $user_id ) || ! $this->is_request_to_rest_api() ) {
            return $user_id;
        }

        if ( is_ssl() ) {
            return $this->check_token();
        } else {
            $this->set_error( __( 'Use SSL connection only', 'wc-orders-app' ) );

            return false;
        }
    }

    /**
     * Check for user permissions
     *
     * @param mixed $result Response to replace the requested version with.
     *
     * @return mixed
     */
    public function check_user_permissions( $result ) {
        if ( $this->user ) {
            return $result;
        } else {
            return new WP_Error(
                'uni_wc_orders_app_auth_error',
                $this->get_error(),
                array( 'status' => rest_authorization_required_code() )
            );
        }
    }
}
