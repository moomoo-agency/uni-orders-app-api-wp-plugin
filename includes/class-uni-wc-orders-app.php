<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Uni_Wc_Orders_App Class
 */
final class Uni_Wc_Orders_App {

    /**
     * The current version of the plugin
     */
    protected $version;

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * The namespace for API endpoints. The same for all the sub classes which add endpoints.
     */
    protected $namespace;

    /**
     * Throw error on object clone
     *
     * The whole idea of the singleton design pattern is that there is a single
     * object therefore, we don't want the object to be cloned.
     *
     * @since 1.0.0
     * @return void
     */
    public function __clone() {
        _doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'wc-orders-app' ), '1.0.0' );
    }

    /**
     * Disable unserializing of the class
     *
     * @since 1.0.0
     * @return void
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'wc-orders-app' ), '1.0.0' );
    }

    /**
     * Main Instance
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->version   = '1.0.0';
        $this->namespace = 'uni-app';

        $this->load_dependencies();
        $this->load_plugin_textdomain();
        $this->define_constants();
        $this->init_hooks();
    }

    /**
     * Load dependencies
     */
    private function load_dependencies() {
        include_once( $this->plugin_path() . '/class-uni-jwt-auth.php' );
        include_once( $this->plugin_path() . '/vendor/php-jwt/JWT.php' );
        include_once( $this->plugin_path() . '/vendor/php-jwt/BeforeValidException.php' );
        include_once( $this->plugin_path() . '/vendor/php-jwt/ExpiredException.php' );
        include_once( $this->plugin_path() . '/vendor/php-jwt/SignatureInvalidException.php' );
    }

    /**
     * load_plugin_textdomain()
     */
    private function load_plugin_textdomain() {
        load_textdomain( 'wc-orders-app', WP_LANG_DIR . '/uni-wc-orders-app/wc-orders-app-' . get_locale() . '.mo' );
        load_plugin_textdomain( 'wc-orders-app', false, plugin_basename( dirname( __FILE__ ) ) . "/languages" );
    }

    /**
     * Define Constants. Mainly a secrete key. Grab yourself one from
     * here: https://api.wordpress.org/secret-key/1.1/salt/
     */
    private function define_constants() {
        // the key used here is just an example, it is not a real one!
        $this->define( 'WC_ORDERS_APP_SECRET_KEY', '~8wnp!4{.Pv|(B$tSo0$fS|5uj>|2I$EGf3+:F7D[ K3b@-:gwZ-V>H[?I~Pv:9)' );
    }

    /**
     * Init hooks
     */
    private function init_hooks() {
        $jwt_auth = new Uni_Jwt_Auth( $this->namespace, $this->version );
        add_action( 'rest_api_init', array( $jwt_auth, 'add_routes' ) );
    }

    /**
     * Define constant if not already set.
     *
     */
    private function define( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    /**
     * get_version()
     */
    public function get_version() {
        return $this->version;
    }

    /**
     * plugin_url()
     */
    public function plugin_url() {
        return untrailingslashit( plugins_url( '/', __FILE__ ) );
    }

    /**
     * plugin_path()
     */
    public function plugin_path() {
        return untrailingslashit( plugin_dir_path( __FILE__ ) );
    }
}
