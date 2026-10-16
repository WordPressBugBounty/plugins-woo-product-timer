<?php
/*
Plugin Name: WPC Product Timer for WooCommerce
Plugin URI: https://wpclever.net/
Description: WPC Product Timer helps you add many actions for the product based on the conditionals of the time.
Author: WPClever
Author URI: https://wpclever.net
Text Domain: woo-product-timer
Domain Path: /languages/
Requires Plugins: woocommerce
Version: 6.0.1
Requires at least: 5.9
WC requires at least: 3.0
WC tested up to: 11.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

defined( 'ABSPATH' ) || exit;

! defined( 'WOOPT_VERSION' ) && define( 'WOOPT_VERSION', '6.0.1' );
! defined( 'WOOPT_LITE' ) && define( 'WOOPT_LITE', __FILE__ );
! defined( 'WOOPT_FILE' ) && define( 'WOOPT_FILE', __FILE__ );
! defined( 'WOOPT_URI' ) && define( 'WOOPT_URI', plugin_dir_url( __FILE__ ) );
! defined( 'WOOPT_DIR' ) && define( 'WOOPT_DIR', plugin_dir_path( __FILE__ ) );
! defined( 'WOOPT_DOCS' ) && define( 'WOOPT_DOCS', 'https://doc.wpclever.net/woopt/' );
! defined( 'WOOPT_SUPPORT' ) && define( 'WOOPT_SUPPORT', 'https://wpclever.net/support/?utm_source=support&utm_medium=woopt&utm_campaign=wporg' );
! defined( 'WOOPT_REVIEWS' ) && define( 'WOOPT_REVIEWS', 'https://wordpress.org/support/plugin/woo-product-timer/reviews/' );
! defined( 'WOOPT_CHANGELOG' ) && define( 'WOOPT_CHANGELOG', 'https://wordpress.org/plugins/woo-product-timer/#developers' );
! defined( 'WOOPT_DISCUSSION' ) && define( 'WOOPT_DISCUSSION', 'https://wordpress.org/support/plugin/woo-product-timer' );

// WPC Core
require_once __DIR__ . '/includes/wpc-core/wpc-core.php';
wpc_core_register( [
	'file'    => __FILE__,
	'version' => WOOPT_VERSION,
	'prefix'  => 'woopt',
] );

if ( ! function_exists( 'woopt_init' ) ) {
    add_action( 'plugins_loaded', 'woopt_init', 11 );

    function woopt_init() {
        if ( ! function_exists( 'WC' ) || ! version_compare( WC()->version, '3.0', '>=' ) ) {
            add_action( 'admin_notices', 'woopt_notice_wc' );

            return null;
        }

        if ( ! class_exists( 'WPCleverWoopt' ) && class_exists( 'WC_Product' ) ) {
            class WPCleverWoopt {
                public static $global_actions = [];
                public static $features = [];
                protected static $capabilities = [];
                protected static $instance = null;
                protected static $product_actions_cache = [];
                protected static $parsed_global_actions = null;
                protected static $now = null;

                public static function instance() {
                    if ( is_null( self::$instance ) ) {
                        self::$instance = new self();
                    }

                    return self::$instance;
                }

                function __construct() {
                    self::$global_actions = (array) get_option( 'woopt_actions', [] );
                    self::$features       = (array) get_option( 'woopt_features', [] );

                    // Init
                    add_action( 'init', [ $this, 'init' ] );

                    // Settings
                    add_action( 'admin_init', [ $this, 'register_settings' ] );
                    add_filter( 'pre_update_option', [ $this, 'last_saved' ], 10, 2 );
                    add_action( 'admin_menu', [ $this, 'admin_menu' ] );

                    // Enqueue backend scripts
                    add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

                    // Product data tabs
                    add_filter( 'woocommerce_product_data_tabs', [ $this, 'product_data_tabs' ] );

                    // Product data panels
                    add_action( 'woocommerce_product_data_panels', [ $this, 'product_data_panels' ] );
                    add_action( 'woocommerce_process_product_meta', [ $this, 'process_product_meta' ] );

                    // Add settings link
                    add_filter( 'plugin_action_links', [ $this, 'action_links' ], 10, 2 );
                    add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );

                    // AJAX
                    add_action( 'wp_ajax_woopt_save_actions', [ $this, 'ajax_save_actions' ] );
                    add_action( 'wp_ajax_woopt_add_time', [ $this, 'ajax_add_time' ] );
                    add_action( 'wp_ajax_woopt_add_apply_combination', [ $this, 'ajax_add_apply_combination' ] );
                    add_action( 'wp_ajax_woopt_search_term', [ $this, 'ajax_search_term' ] );
                    add_action( 'wp_ajax_woopt_simulate', [ $this, 'ajax_simulate' ] );

                    // Product class
                    add_filter( 'woocommerce_post_class', [ $this, 'woopt_post_class' ], 99, 2 );

                    // Features
                    if ( empty( self::$features ) || in_array( 'stock', self::$features ) ) {
                        add_filter( 'woocommerce_product_is_in_stock', [ $this, 'woopt_is_in_stock' ], 99, 2 );
                    }

                    if ( empty( self::$features ) || in_array( 'visibility', self::$features ) ) {
                        add_filter( 'woocommerce_product_is_visible', [ $this, 'woopt_is_visible' ], 99, 2 );
                        add_filter( 'woocommerce_variation_is_visible', [ $this, 'woopt_is_visible' ], 99, 2 );
                        add_filter( 'woocommerce_variation_is_active', [ $this, 'woopt_is_visible' ], 99, 2 );
                        add_filter( 'woocommerce_product_get_catalog_visibility', [
                                $this,
                                'woopt_get_catalog_visibility'
                        ], 99, 2 );
                    }

                    if ( empty( self::$features ) || in_array( 'featured', self::$features ) ) {
                        add_filter( 'woocommerce_product_get_featured', [ $this, 'woopt_is_featured' ], 99, 2 );
                    }

                    if ( empty( self::$features ) || in_array( 'purchasable', self::$features ) ) {
                        add_filter( 'woocommerce_is_purchasable', [ $this, 'woopt_is_purchasable' ], 99, 2 );
                    }

                    if ( empty( self::$features ) || in_array( 'onsale', self::$features ) ) {
                        add_filter( 'woocommerce_product_is_on_sale', [ $this, 'woopt_is_on_sale' ], 99, 2 );
                        add_filter( 'woocommerce_product_get_price', [ $this, 'woopt_get_on_sale_price' ], 99, 2 );
                        add_filter( 'woocommerce_product_variation_get_price', [
                                $this,
                                'woopt_get_on_sale_price'
                        ], 99, 2 );
                        add_filter( 'woocommerce_variation_prices_price', [ $this, 'woopt_get_on_sale_price' ], 99, 2 );
                        add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'variation_prices_hash' ], 99 );
                    }

                    if ( empty( self::$features ) || in_array( 'individually', self::$features ) ) {
                        add_filter( 'woocommerce_is_sold_individually', [ $this, 'woopt_sold_individually' ], 99, 2 );
                    }

                    if ( empty( self::$features ) || in_array( 'price', self::$features ) ) {
                        add_filter( 'woocommerce_product_get_regular_price', [
                                $this,
                                'woopt_get_regular_price'
                        ], 98, 2 );
                        add_filter( 'woocommerce_product_get_sale_price', [ $this, 'woopt_get_sale_price' ], 98, 2 );
                        add_filter( 'woocommerce_product_get_price', [ $this, 'woopt_get_price' ], 98, 2 );

                        // Variation
                        add_filter( 'woocommerce_product_variation_get_regular_price', [
                                $this,
                                'woopt_get_regular_price'
                        ], 98, 2 );
                        add_filter( 'woocommerce_product_variation_get_sale_price', [
                                $this,
                                'woopt_get_sale_price'
                        ], 98, 2 );
                        add_filter( 'woocommerce_product_variation_get_price', [ $this, 'woopt_get_price' ], 98, 2 );

                        // Variations
                        add_filter( 'woocommerce_variation_prices_regular_price', [
                                $this,
                                'woopt_get_regular_price'
                        ], 98, 2 );
                        add_filter( 'woocommerce_variation_prices_sale_price', [
                                $this,
                                'woopt_get_sale_price'
                        ], 98, 2 );
                        add_filter( 'woocommerce_variation_prices_price', [ $this, 'woopt_get_price' ], 98, 2 );
                        add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'variation_prices_hash' ], 98 );
                    }

                    // Product columns
                    add_filter( 'manage_edit-product_columns', [ $this, 'product_columns' ], 10 );
                    add_action( 'manage_product_posts_custom_column', [ $this, 'custom_column' ], 10, 2 );

                    // Ajax edit
                    add_action( 'wp_ajax_woopt_edit', [ $this, 'ajax_edit_timer' ] );
                    add_action( 'wp_ajax_woopt_edit_save', [ $this, 'ajax_save_timer' ] );

                    // Export
                    add_filter( 'woocommerce_product_export_column_names', [ $this, 'export_columns' ] );
                    add_filter( 'woocommerce_product_export_product_default_columns', [ $this, 'export_columns' ] );
                    add_filter( 'woocommerce_product_export_product_column_woopt_actions', [
                            $this,
                            'export_data'
                    ], 10, 2 );

                    // Import
                    add_filter( 'woocommerce_csv_product_import_mapping_options', [ $this, 'import_options' ] );
                    add_filter( 'woocommerce_csv_product_import_mapping_default_columns', [ $this, 'import_columns' ] );
                    add_filter( 'woocommerce_product_import_pre_insert_product_object', [
                            $this,
                            'import_process'
                    ], 10, 2 );

                    // WPML
                    if ( function_exists( 'wpml_loaded' ) ) {
                        add_filter( 'woopt_apply_terms', [ $this, 'wpml_apply_terms' ], 99, 2 );
                    }
                }

                public static function woopt_action_data( $action ) {
                    $action_data = [];
                    $action_df   = [
                            'name'       => '',
                            'type'       => '',
                            'apply'      => '',
                            'apply_val'  => [],
                            'action'     => '',
                            'action_val' => [],
                            'timer'      => [],
                            'roles'      => [],
                    ];

                    if ( ! empty( $action ) ) {
                        if ( is_array( $action ) ) {
                            // new version 5.0
                            $action_data = $action;
                        } elseif ( is_string( $action ) ) {
                            $action_arr = explode( '|', $action );

                            if ( ! str_contains( $action, 'apply_' ) ) {
                                // product
                                $action_data = [
                                        'type'       => 'product',
                                        'action'     => $action_arr[0] ?? '',
                                        'action_val' => isset( $action_arr[1] ) ? [
                                                'value' => (float) $action_arr[1],
                                                'base'  => str_contains( $action_arr[1], '%' ) ? 'ps' : 'fa',
                                        ] : [ 'value' => '', 'base' => 'fa' ],
                                        'timer'      => isset( $action_arr[2] ) ? self::woopt_timer_data( $action_arr[2] ) : [],
                                        'roles'      => isset( $action_arr[3] ) ? explode( ',', (string) $action_arr[3] ) : [],
                                ];
                            } else {
                                // global
                                $apply     = ! empty( $action_arr[0] ) ? $action_arr[0] : 'apply_all';
                                $apply_val = [];

                                if ( $apply === 'apply_combination' ) {
                                    // combined
                                    $apply_val['combined'] = self::woopt_apply_combination_data( $action_arr[1] ?? [] );
                                } elseif ( $apply === 'apply_product' ) {
                                    // products
                                    $apply_val['products'] = explode( ',', ( $action_arr[1] ?? '' ) );
                                } else {
                                    // terms
                                    $apply_val['terms'] = explode( ',', ( $action_arr[1] ?? '' ) );
                                }

                                $action_data = [
                                        'type'       => 'global',
                                        'apply'      => $apply,
                                        'apply_val'  => $apply_val,
                                        'action'     => $action_arr[2] ?? '',
                                        'action_val' => isset( $action_arr[3] ) ? [
                                                'value' => (float) $action_arr[3],
                                                'base'  => str_contains( $action_arr[3], '%' ) ? 'ps' : 'fa',
                                        ] : [ 'value' => '', 'base' => 'fa' ],
                                        'timer'      => isset( $action_arr[4] ) ? self::woopt_timer_data( $action_arr[4] ) : [],
                                        'roles'      => isset( $action_arr[5] ) ? explode( ',', (string) $action_arr[5] ) : [],
                                ];
                            }
                        }
                    }

                    return array_merge( $action_df, $action_data );
                }

                public static function woopt_apply_combination_data( $combination ) {
                    $combination_data    = [];
                    $combination_item_df = [
                            'type' => '',
                            'val'  => []
                    ];

                    if ( ! empty( $combination ) ) {
                        if ( is_array( $combination ) ) {
                            // new version 5.0
                            $combination_data = $combination;
                        } elseif ( is_string( $combination ) ) {
                            $combination_arr = explode( '&', $combination );

                            if ( is_array( $combination_arr ) && ( count( $combination_arr ) > 0 ) ) {
                                foreach ( $combination_arr as $combination_key => $combination_item ) {
                                    if ( empty( $combination_key ) || is_numeric( $combination_key ) ) {
                                        $combination_key = self::generate_key();
                                    }

                                    $combination_item_arr  = explode( '>', $combination_item );
                                    $combination_item_type = isset( $combination_item_arr[0] ) ? trim( $combination_item_arr[0] ) : '';
                                    $combination_item_val  = isset( $combination_item_arr[1] ) ? trim( $combination_item_arr[1] ) : '';
                                    $combination_item_data = [
                                            'type' => $combination_item_type,
                                            'val'  => array_map( 'trim', explode( ',', $combination_item_val ) ),
                                    ];

                                    $combination_data[ $combination_key ] = array_merge( $combination_item_df, $combination_item_data );
                                }
                            }
                        } else {
                            $combination_new_key                      = self::generate_key();
                            $combination_data[ $combination_new_key ] = $combination_item_df;
                        }
                    } else {
                        $combination_new_key                      = self::generate_key();
                        $combination_data[ $combination_new_key ] = $combination_item_df;
                    }

                    return $combination_data;
                }

                public static function woopt_timer_data( $timer ) {
                    $timer_data = [];
                    $time_df    = [
                            'type' => '',
                            'val'  => ''
                    ];

                    if ( ! empty( $timer ) ) {
                        if ( is_array( $timer ) ) {
                            // new version 5.0
                            $timer_data = $timer;
                        } elseif ( is_string( $timer ) ) {
                            $timer_arr = explode( '&', $timer );

                            if ( is_array( $timer_arr ) && ( count( $timer_arr ) > 0 ) ) {
                                foreach ( $timer_arr as $key => $time ) {
                                    if ( empty( $key ) || is_numeric( $key ) ) {
                                        $key = self::generate_key();
                                    }

                                    $time_arr  = explode( '>', $time );
                                    $time_type = trim( $time_arr[0] ?? '' );
                                    $time_val  = trim( $time_arr[1] ?? '' );

                                    $time_data = [
                                            'type' => $time_type,
                                            'val'  => $time_val
                                    ];

                                    $timer_data[ $key ] = array_merge( $time_df, $time_data );
                                }
                            }
                        }
                    } else {
                        $key                = self::generate_key();
                        $timer_data[ $key ] = $time_df;
                    }

                    return $timer_data;
                }

                /**
                 * Get pre-parsed global actions (lazy-loaded, cached per request).
                 */
                protected static function get_parsed_global_actions() {
                    if ( self::$parsed_global_actions === null ) {
                        self::$parsed_global_actions = array_map( [
                                __CLASS__,
                                'woopt_action_data'
                        ], self::$global_actions );
                    }

                    return self::$parsed_global_actions;
                }

                /**
                 * Get product actions meta with runtime cache.
                 */
                protected static function get_product_actions_meta( $product_id ) {
                    if ( ! isset( self::$product_actions_cache[ $product_id ] ) ) {
                        $actions                                    = get_post_meta( $product_id, 'woopt_actions', true );
                        self::$product_actions_cache[ $product_id ] = is_array( $actions ) ? $actions : [];
                    }

                    return self::$product_actions_cache[ $product_id ];
                }

                /**
                 * Get pre-computed current time values (cached per request).
                 */
                protected static function get_now() {
                    if ( self::$now === null ) {
                        self::$now = [
                                'date'      => current_time( 'm/d/Y' ),
                                'datetime'  => current_time( 'm/d/Y h:i a' ),
                                'day'       => (int) current_time( 'd' ),
                                'weekday'   => strtolower( current_time( 'D' ) ),
                                'week'      => (int) current_time( 'W' ),
                                'monthday'  => strtolower( current_time( 'j' ) ),
                                'month'     => (int) current_time( 'm' ),
                                'timestamp' => current_time( 'U' ),
                        ];
                    }

                    return self::$now;
                }

                public static function woopt_check_apply( $product, $apply, $apply_val, $is_variation = false ) {
                    $product_id = 0;

                    if ( is_numeric( $product ) ) {
                        $product_id = $product;
                    } elseif ( is_a( $product, 'WC_Product' ) ) {
                        $product_id = $product->get_id();
                    }

                    if ( ! $product_id ) {
                        return false;
                    }

                    switch ( $apply ) {
                        case 'apply_all':
                            return true;
                        case 'apply_variation':
                            if ( $is_variation ) {
                                return true;
                            }

                            return false;
                        case 'apply_not_variation':
                            if ( ! $is_variation ) {
                                return true;
                            }

                            return false;
                        case 'apply_product':
                            if ( ! empty( $apply_val['products'] ) ) {
                                if ( in_array( $product_id, $apply_val['products'] ) ) {
                                    return true;
                                }
                            }

                            return false;
                        case 'apply_combination':
                            if ( ! empty( $apply_val['combined'] ) ) {
                                $match_all = true;

                                foreach ( $apply_val['combined'] as $conditional_item ) {
                                    $match                 = false;
                                    $conditional_item_type = $conditional_item['type'] ?? '';
                                    $conditional_item_val  = isset( $conditional_item['val'] ) ? (array) $conditional_item['val'] : [];

                                    if ( $conditional_item_type === 'variation' ) {
                                        if ( $is_variation ) {
                                            $match = true;
                                        }
                                    } elseif ( $conditional_item_type === 'not_variation' ) {
                                        if ( ! $is_variation ) {
                                            $match = true;
                                        }
                                    } else {
                                        if ( $is_variation ) {
                                            $variation = wc_get_product( $product_id );
                                            $attrs     = $variation->get_attributes();

                                            if ( ! empty( $attrs[ $conditional_item_type ] ) ) {
                                                if ( in_array( $attrs[ $conditional_item_type ], $conditional_item_val ) ) {
                                                    $match = true;
                                                }
                                            }
                                        } else {
                                            $conditional_item_terms = apply_filters( 'woopt_apply_terms', $conditional_item_val, $conditional_item_type );

                                            if ( has_term( $conditional_item_terms, $conditional_item_type, $product_id ) ) {
                                                $match = true;
                                            }
                                        }
                                    }

                                    $match_all &= $match;
                                }

                                return $match_all;
                            }

                            return false;
                        default:
                            if ( ! empty( $apply_val['terms'] ) ) {
                                $taxonomy = substr( $apply, 6 ); // trim from $apply

                                if ( $is_variation ) {
                                    $variation = wc_get_product( $product_id );
                                    $attrs     = $variation->get_attributes();

                                    if ( ! empty( $attrs[ $taxonomy ] ) ) {
                                        if ( in_array( $attrs[ $taxonomy ], $apply_val['terms'] ) ) {
                                            return true;
                                        }
                                    }
                                } else {
                                    $apply_terms = apply_filters( 'woopt_apply_terms', $apply_val['terms'], $taxonomy );

                                    if ( has_term( $apply_terms, $taxonomy, $product_id ) ) {
                                        return true;
                                    }
                                }
                            }

                            return false;
                    }
                }

                function wpml_apply_terms( $terms, $taxonomy ) {
                    $apply_terms = [];

                    if ( is_string( $terms ) ) {
                        $terms = explode( ',', $terms );
                    }

                    if ( is_array( $terms ) && ! empty( $terms ) ) {
                        foreach ( $terms as $term ) {
                            $apply_term    = get_term_by( 'slug', $term, $taxonomy );
                            $apply_terms[] = apply_filters( 'wpml_object_id', $apply_term->term_id, $taxonomy );
                        }
                    }

                    return $apply_terms;
                }

                public static function woopt_check_conditional( $timer, $product_id = null ) {
                    // old version before 5.0
                    return self::woopt_check_timer( $timer, $product_id );
                }

                public static function woopt_check_timer( $timer, $product_id = null, $now = null ) {
                    $check = true;

                    if ( ! empty( $timer ) ) {
                        if ( $now === null ) {
                            $now = self::get_now();
                        }

                        foreach ( $timer as $time ) {
                            $check_item = false;
                            $time_type  = isset( $time['type'] ) ? trim( $time['type'] ) : '';
                            $time_value = isset( $time['val'] ) ? trim( $time['val'] ) : '';

                            switch ( $time_type ) {
                                case 'date_range':
                                    $date_range = array_map( 'trim', explode( '-', $time_value ) );

                                    if ( count( $date_range ) === 2 ) {
                                        $date_range_start = trim( $date_range[0] );
                                        $date_range_end   = trim( $date_range[1] );
                                        $current_date     = strtotime( $now['date'] );

                                        if ( $current_date >= strtotime( $date_range_start ) && $current_date <= strtotime( $date_range_end ) ) {
                                            $check_item = true;
                                        }
                                    } elseif ( count( $date_range ) === 1 ) {
                                        $date_range_start = trim( $date_range[0] );

                                        if ( strtotime( $now['date'] ) === strtotime( $date_range_start ) ) {
                                            $check_item = true;
                                        }
                                    }

                                    break;
                                case 'date_multi':
                                    $multiple_dates_arr = array_map( 'trim', explode( ', ', $time_value ) );

                                    if ( in_array( $now['date'], $multiple_dates_arr ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'date_even':
                                    if ( $now['day'] % 2 === 0 ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'date_odd':
                                    if ( $now['day'] % 2 !== 0 ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'date_on':
                                    if ( strtotime( $now['date'] ) === strtotime( $time_value ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'date_before':
                                    if ( strtotime( $now['date'] ) < strtotime( $time_value ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'date_after':
                                    if ( strtotime( $now['date'] ) > strtotime( $time_value ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'date_time_before':
                                    $current_time = $now['datetime'];

                                    if ( strtotime( $current_time ) < strtotime( $time_value ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'date_time_after':
                                    $current_time = $now['datetime'];

                                    if ( strtotime( $current_time ) > strtotime( $time_value ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'time_range':
                                    $time_range = array_map( 'trim', explode( '-', $time_value ) );

                                    if ( count( $time_range ) === 2 ) {
                                        $current_time     = strtotime( $now['datetime'] );
                                        $current_date     = $now['date'];
                                        $time_range_start = $current_date . ' ' . $time_range[0];
                                        $time_range_end   = $current_date . ' ' . $time_range[1];

                                        if ( $current_time >= strtotime( $time_range_start ) && $current_time <= strtotime( $time_range_end ) ) {
                                            $check_item = true;
                                        }
                                    }

                                    break;
                                case 'time_before':
                                    $current_time = $now['datetime'];
                                    $current_date = $now['date'];

                                    if ( strtotime( $current_time ) < strtotime( $current_date . ' ' . $time_value ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'time_after':
                                    $current_time = $now['datetime'];
                                    $current_date = $now['date'];

                                    if ( strtotime( $current_time ) > strtotime( $current_date . ' ' . $time_value ) ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'weekly_every':
                                    if ( $now['weekday'] === $time_value ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'week_even':
                                    if ( $now['week'] % 2 === 0 ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'week_odd':
                                    if ( $now['week'] % 2 !== 0 ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'week_no':
                                    if ( $now['week'] === (int) $time_value ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'monthly_every':
                                    if ( $now['monthday'] === $time_value ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'month_no':
                                    if ( $now['month'] === (int) $time_value ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'days_less_published':
                                    $published = get_the_time( 'U', $product_id );

                                    if ( ( $now['timestamp'] - $published ) < 60 * 60 * 24 * (int) $time_value ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'days_greater_published':
                                    $published = get_the_time( 'U', $product_id );

                                    if ( ( $now['timestamp'] - $published ) > 60 * 60 * 24 * (int) $time_value ) {
                                        $check_item = true;
                                    }

                                    break;
                                case 'every_day':
                                    $check_item = true;

                                    break;
                            }

                            // Early return: if any condition fails, skip remaining checks
                            if ( ! $check_item ) {
                                return apply_filters( 'woopt_check_timer', false, $timer, $product_id );
                            }
                        }
                    }

                    return apply_filters( 'woopt_check_timer', $check, $timer, $product_id );
                }

                public static function woopt_check_roles( $roles, $sim_role = null ) {
                    $check = false;

                    if ( is_string( $roles ) ) {
                        $roles = explode( ',', $roles );
                    }

                    if ( empty( $roles ) || in_array( 'woopt_all', (array) $roles ) || in_array( 'all', (array) $roles ) ) {
                        $check = true;
                    }

                    if ( $sim_role !== null ) {
                        if ( $sim_role === 'woopt_guest' || $sim_role === 'guest' ) {
                            if ( in_array( 'woopt_guest', (array) $roles ) || in_array( 'guest', (array) $roles ) ) {
                                $check = true;
                            }
                        } else {
                            if ( in_array( 'woopt_user', (array) $roles ) || in_array( $sim_role, (array) $roles ) ) {
                                $check = true;
                            }
                        }

                        return apply_filters( 'woopt_check_roles', $check, $roles );
                    }

                    if ( is_user_logged_in() ) {
                        if ( in_array( 'woopt_user', (array) $roles ) ) {
                            $check = true;
                        }

                        $current_user = wp_get_current_user();

                        foreach ( $current_user->roles as $role ) {
                            if ( in_array( $role, (array) $roles ) ) {
                                $check = true;
                                break;
                            }
                        }
                    } else {
                        if ( in_array( 'woopt_guest', (array) $roles ) || in_array( 'guest', (array) $roles ) ) {
                            $check = true;
                        }
                    }

                    return apply_filters( 'woopt_check_roles', $check, $roles );
                }

                public static function woopt_get_action_result( $result, $product, $action_true = '', $action_false = '' ) {
                    $variation_id = 0;
                    $product_id   = $product->get_id();

                    if ( $product->is_type( 'variation' ) ) {
                        $variation_id = $product_id;
                        $product_id   = $product->get_parent_id();
                    }

                    // global actions
                    if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( $action_key !== $action_true && $action_key !== $action_false ) {
                                continue;
                            }

                            if ( self::woopt_check_apply( $product_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                if ( $action_key === $action_true ) {
                                    $result = true;
                                } else {
                                    $result = false;
                                }

                                break;
                            }
                        }
                    }

                    // product actions
                    $actions = self::get_product_actions_meta( $product_id );

                    if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                        foreach ( array_reverse( $actions ) as $action ) {
                            $action_data  = self::woopt_action_data( $action );
                            $action_key   = $action_data['action'];
                            $action_timer = $action_data['timer'];
                            $action_roles = $action_data['roles'];

                            if ( $action_key !== $action_true && $action_key !== $action_false ) {
                                continue;
                            }

                            if ( self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                if ( $action_key === $action_true ) {
                                    $result = true;
                                } else {
                                    $result = false;
                                }

                                break;
                            }
                        }
                    }

                    // global actions for variation
                    if ( $variation_id && is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_apply !== 'apply_product' ) && ( $action_apply !== 'apply_combination' ) ) {
                                continue;
                            }

                            if ( $action_key !== $action_true && $action_key !== $action_false ) {
                                continue;
                            }

                            if ( self::woopt_check_apply( $variation_id, $action_apply, $action_apply_val, true ) && self::woopt_check_timer( $action_timer, $variation_id ) && self::woopt_check_roles( $action_roles ) ) {
                                if ( $action_key === $action_true ) {
                                    $result = true;
                                } else {
                                    $result = false;
                                }

                                break;
                            }
                        }
                    }

                    return apply_filters( 'woopt_get_action_result', $result, $product, $action_true, $action_false );
                }

                public static function woopt_get_product_actions( $product, $context = 'active' ) {
                    $product_actions = [];

                    if ( apply_filters( 'woopt_ignore', false, $product, $context ) ) {
                        return $product_actions;
                    }

                    $variation_id = 0;
                    $product_id   = $product->get_id();

                    if ( $product->is_type( 'variation' ) ) {
                        $variation_id = $product_id;
                        $product_id   = $product->get_parent_id();
                    }

                    // global actions
                    if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( self::$global_actions as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( self::woopt_check_apply( $product_id, $action_apply, $action_apply_val ) ) {
                                if ( ( $context === 'all' ) || ( self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) ) {
                                    $product_actions[] = $action_data;
                                }
                            }
                        }
                    }

                    // product actions
                    $actions = self::get_product_actions_meta( $product_id );

                    if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                        foreach ( $actions as $action ) {
                            $action_data  = self::woopt_action_data( $action );
                            $action_timer = $action_data['timer'];
                            $action_roles = $action_data['roles'];

                            if ( ( $context === 'all' ) || ( self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) ) {
                                $product_actions[] = $action_data;
                            }
                        }
                    }

                    // global actions for variation
                    if ( $variation_id && is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( self::$global_actions as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_apply !== 'apply_product' ) && ( $action_apply !== 'apply_combination' ) ) {
                                continue;
                            }

                            if ( self::woopt_check_apply( $variation_id, $action_apply, $action_apply_val, true ) ) {
                                if ( ( $context === 'all' ) || ( self::woopt_check_timer( $action_timer, $variation_id ) && self::woopt_check_roles( $action_roles ) ) ) {
                                    $product_actions[] = $action_data;
                                }
                            }
                        }
                    }

                    return apply_filters( 'woopt_get_product_actions', $product_actions, $product, $context );
                }

                public static function woopt_post_class( $classes, $product ) {
                    if ( apply_filters( 'woopt_ignore', false, $product, 'post_class' ) ) {
                        return $classes;
                    }

                    if ( ( $product_actions = self::woopt_get_product_actions( $product ) ) && count( $product_actions ) ) {
                        foreach ( $product_actions as $product_action ) {
                            $classes[] = 'woopt_' . $product_action['action'];
                        }
                    }

                    return $classes;
                }

                public static function woopt_get_regular_price( $regular_price, $product ) {
                    $variation_id = 0;
                    $product_id   = $product->get_id();

                    if ( apply_filters( 'woopt_ignore', false, $product, 'regular_price' ) ) {
                        return $regular_price;
                    }

                    if ( $product->is_type( 'variation' ) ) {
                        $variation_id = $product_id;
                        $product_id   = $product->get_parent_id();
                    }

                    // global actions
                    if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_base      = ! empty( $action_data['action_val']['base'] ) ? $action_data['action_val']['base'] : 'fa';
                            $action_price     = isset( $action_data['action_val']['value'] ) ? (float) $action_data['action_val']['value'] : '';
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_price !== '' ) && ( $action_key === 'set_regularprice' ) && self::woopt_check_apply( $product_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                if ( $action_base === 'pr' || $action_base === 'ps' ) {
                                    // percentage
                                    $action_price = (float) $regular_price * $action_price / 100;
                                }

                                $regular_price = $action_price;
                                break;
                            }
                        }
                    }

                    // product actions
                    $actions = self::get_product_actions_meta( $product_id );

                    if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                        foreach ( array_reverse( $actions ) as $action ) {
                            $action_data  = self::woopt_action_data( $action );
                            $action_key   = $action_data['action'];
                            $action_base  = ! empty( $action_data['action_val']['base'] ) ? $action_data['action_val']['base'] : 'fa';
                            $action_price = isset( $action_data['action_val']['value'] ) ? (float) $action_data['action_val']['value'] : '';
                            $action_timer = $action_data['timer'];
                            $action_roles = $action_data['roles'];

                            if ( ( $action_price !== '' ) && ( $action_key === 'set_regularprice' ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                if ( $action_base === 'pr' || $action_base === 'ps' ) {
                                    // percentage
                                    $action_price = (float) $regular_price * $action_price / 100;
                                }

                                $regular_price = $action_price;
                                break;
                            }
                        }
                    }

                    // global actions for variation
                    if ( $variation_id && is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_base      = ! empty( $action_data['action_val']['base'] ) ? $action_data['action_val']['base'] : 'fa';
                            $action_price     = isset( $action_data['action_val']['value'] ) ? (float) $action_data['action_val']['value'] : '';
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_apply !== 'apply_product' ) && ( $action_apply !== 'apply_combination' ) ) {
                                continue;
                            }

                            if ( ( $action_price !== '' ) && ( $action_key === 'set_regularprice' ) && self::woopt_check_apply( $variation_id, $action_apply, $action_apply_val, true ) && self::woopt_check_timer( $action_timer, $variation_id ) && self::woopt_check_roles( $action_roles ) ) {
                                if ( $action_base === 'pr' || $action_base === 'ps' ) {
                                    // percentage
                                    $action_price = (float) $regular_price * $action_price / 100;
                                }

                                $regular_price = $action_price;
                                break;
                            }
                        }
                    }

                    return apply_filters( 'woopt_get_regular_price', $regular_price, $product );
                }

                public static function woopt_get_sale_price( $sale_price, $product ) {
                    $variation_id = 0;
                    $product_id   = $product->get_id();

                    if ( apply_filters( 'woopt_ignore', false, $product, 'sale_price' ) ) {
                        return $sale_price;
                    }

                    if ( $product->is_type( 'variation' ) ) {
                        $variation_id = $product_id;
                        $product_id   = $product->get_parent_id();
                    }

                    $regular_price = apply_filters( 'woopt_product_regular_price', $product->get_regular_price( 'edit' ), $product );

                    // global actions
                    if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_base      = ! empty( $action_data['action_val']['base'] ) ? $action_data['action_val']['base'] : 'fa';
                            $action_price     = isset( $action_data['action_val']['value'] ) ? (float) $action_data['action_val']['value'] : '';
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_price !== '' ) && ( $action_key === 'set_saleprice' ) && self::woopt_check_apply( $product_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                switch ( $action_base ) {
                                    case 'ps':
                                        // percentage of sale price
                                        $action_price = (float) $sale_price * $action_price / 100;
                                        break;
                                    case 'pr':
                                        // percentage of regular price
                                        $action_price = (float) $regular_price * $action_price / 100;
                                        break;
                                }

                                $sale_price = $action_price;
                                break;
                            }
                        }
                    }

                    // product actions
                    $actions = self::get_product_actions_meta( $product_id );

                    if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                        foreach ( array_reverse( $actions ) as $action ) {
                            $action_data  = self::woopt_action_data( $action );
                            $action_key   = $action_data['action'];
                            $action_base  = ! empty( $action_data['action_val']['base'] ) ? $action_data['action_val']['base'] : 'fa';
                            $action_price = isset( $action_data['action_val']['value'] ) ? (float) $action_data['action_val']['value'] : '';
                            $action_timer = $action_data['timer'];
                            $action_roles = $action_data['roles'];

                            if ( ( $action_price !== '' ) && ( $action_key === 'set_saleprice' ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                switch ( $action_base ) {
                                    case 'ps':
                                        // percentage of sale price
                                        $action_price = (float) $sale_price * $action_price / 100;
                                        break;
                                    case 'pr':
                                        // percentage of regular price
                                        $action_price = (float) $regular_price * $action_price / 100;
                                        break;
                                }

                                $sale_price = $action_price;
                                break;
                            }
                        }
                    }

                    // global actions for variation
                    if ( $variation_id && is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_base      = ! empty( $action_data['action_val']['base'] ) ? $action_data['action_val']['base'] : 'fa';
                            $action_price     = isset( $action_data['action_val']['value'] ) ? (float) $action_data['action_val']['value'] : '';
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_apply !== 'apply_product' ) && ( $action_apply !== 'apply_combination' ) ) {
                                continue;
                            }

                            if ( ( $action_price !== '' ) && ( $action_key === 'set_saleprice' ) && self::woopt_check_apply( $variation_id, $action_apply, $action_apply_val, true ) && self::woopt_check_timer( $action_timer, $variation_id ) && self::woopt_check_roles( $action_roles ) ) {
                                switch ( $action_base ) {
                                    case 'ps':
                                        // percentage of sale price
                                        $action_price = (float) $sale_price * $action_price / 100;
                                        break;
                                    case 'pr':
                                        // percentage of regular price
                                        $action_price = (float) $regular_price * $action_price / 100;
                                        break;
                                }

                                $sale_price = $action_price;
                                break;
                            }
                        }
                    }

                    return apply_filters( 'woopt_get_sale_price', $sale_price, $product );
                }

                public static function woopt_get_price( $price, $product ) {
                    if ( apply_filters( 'woopt_ignore', false, $product, 'price' ) ) {
                        return $price;
                    }

                    if ( $product->is_on_sale() ) {
                        return self::woopt_get_sale_price( $price, $product );
                    }

                    return self::woopt_get_regular_price( $price, $product );
                }

                function variation_prices_hash( $hash ) {
                    $hash[] = get_current_user_id();

                    return $hash;
                }

                public static function woopt_is_in_stock( $in_stock, $product ) {
                    if ( apply_filters( 'woopt_ignore', false, $product, 'in_stock' ) ) {
                        return $in_stock;
                    }

                    $in_stock = self::woopt_get_action_result( $in_stock, $product, 'set_instock', 'set_outofstock' );

                    return apply_filters( 'woopt_is_in_stock', $in_stock, $product );
                }

                public static function woopt_is_visible( $visible, $product ) {
                    if ( is_numeric( $product ) ) {
                        $product = wc_get_product( $product );
                    }

                    if ( ! is_a( $product, 'WC_Product' ) ) {
                        return $visible;
                    }

                    if ( apply_filters( 'woopt_ignore', false, $product, 'visible' ) ) {
                        return $visible;
                    }

                    $visible = self::woopt_get_action_result( $visible, $product, 'set_visible', 'set_hidden' );

                    return apply_filters( 'woopt_is_visible', $visible, $product );
                }

                public static function woopt_get_catalog_visibility( $visibility, $product ) {
                    if ( ! is_a( $product, 'WC_Product' ) ) {
                        return $visibility;
                    }

                    if ( apply_filters( 'woopt_ignore', false, $product, 'catalog_visibility' ) ) {
                        return $visibility;
                    }

                    if ( $product->is_type( 'variation' ) ) {
                        $product_id = $product->get_parent_id();
                    } else {
                        $product_id = $product->get_id();
                    }

                    // global actions
                    if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data       = self::woopt_action_data( $global_action );
                            $action_apply      = $action_data['apply'];
                            $action_apply_val  = $action_data['apply_val'];
                            $action_key        = $action_data['action'];
                            $action_visibility = ! empty( $action_data['action_val']['visibility'] ) ? $action_data['action_val']['visibility'] : 'visible';
                            $action_timer      = $action_data['timer'];
                            $action_roles      = $action_data['roles'];

                            if ( ( $action_key === 'set_catalog_visibility' ) && self::woopt_check_apply( $product_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $visibility = $action_visibility;
                                break;
                            }
                        }
                    }

                    // product actions
                    $actions = self::get_product_actions_meta( $product_id );

                    if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                        foreach ( array_reverse( $actions ) as $action ) {
                            $action_data       = self::woopt_action_data( $action );
                            $action_key        = $action_data['action'];
                            $action_visibility = ! empty( $action_data['action_val']['visibility'] ) ? $action_data['action_val']['visibility'] : 'visible';
                            $action_timer      = $action_data['timer'];
                            $action_roles      = $action_data['roles'];

                            if ( ( $action_key === 'set_catalog_visibility' ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $visibility = $action_visibility;
                                break;
                            }
                        }
                    }

                    return apply_filters( 'woopt_get_catalog_visibility', $visibility, $product );
                }

                public static function woopt_is_featured( $featured, $product ) {
                    if ( is_numeric( $product ) ) {
                        $product = wc_get_product( $product );
                    }

                    if ( apply_filters( 'woopt_ignore', false, $product, 'featured' ) ) {
                        return $featured;
                    }

                    $featured = self::woopt_get_action_result( $featured, $product, 'set_featured', 'set_unfeatured' );

                    return apply_filters( 'woopt_is_featured', $featured, $product );
                }

                public static function woopt_is_purchasable( $purchasable, $product ) {
                    if ( apply_filters( 'woopt_ignore', false, $product, 'purchasable' ) ) {
                        return $purchasable;
                    }

                    $purchasable = self::woopt_get_action_result( $purchasable, $product, 'set_purchasable', 'set_unpurchasable' );

                    return apply_filters( 'woopt_is_purchasable', $purchasable, $product );
                }

                public static function woopt_is_on_sale( $on_sale, $product ) {
                    if ( apply_filters( 'woopt_ignore', false, $product, 'on_sale' ) ) {
                        return $on_sale;
                    }

                    if ( $product->get_sale_price() ) {
                        $on_sale = self::woopt_get_action_result( $on_sale, $product, 'enable_onsale', 'disable_onsale' );
                    }

                    return apply_filters( 'woopt_is_on_sale', $on_sale, $product );
                }

                public static function woopt_get_on_sale_price( $price, $product ) {
                    $variation_id = 0;
                    $product_id   = $product->get_id();

                    if ( ! $product->get_sale_price() ) {
                        return $price;
                    }

                    if ( apply_filters( 'woopt_ignore', false, $product, 'on_sale_price' ) ) {
                        return $price;
                    }

                    if ( $product->is_type( 'variation' ) ) {
                        $variation_id = $product_id;
                        $product_id   = $product->get_parent_id();
                    }

                    // global actions
                    if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_key === 'disable_onsale' ) && self::woopt_check_apply( $product_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $price = $product->get_regular_price();
                                break;
                            }

                            if ( ( $action_key === 'enable_onsale' ) && self::woopt_check_apply( $product_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $price = $product->get_sale_price();
                                break;
                            }
                        }
                    }

                    // product actions
                    $actions = self::get_product_actions_meta( $product_id );

                    if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                        foreach ( array_reverse( $actions ) as $action ) {
                            $action_data  = self::woopt_action_data( $action );
                            $action_key   = $action_data['action'];
                            $action_timer = $action_data['timer'];
                            $action_roles = $action_data['roles'];

                            if ( ( $action_key === 'disable_onsale' ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $price = $product->get_regular_price();
                                break;
                            }

                            if ( ( $action_key === 'enable_onsale' ) && self::woopt_check_timer( $action_timer, $product_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $price = $product->get_sale_price();
                                break;
                            }
                        }
                    }

                    // global actions for variation
                    if ( $variation_id && is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                        foreach ( array_reverse( self::$global_actions ) as $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_key       = $action_data['action'];
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];

                            if ( ( $action_apply !== 'apply_product' ) && ( $action_apply !== 'apply_combination' ) ) {
                                continue;
                            }

                            if ( ( $action_key === 'disable_onsale' ) && self::woopt_check_apply( $variation_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $variation_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $price = $product->get_regular_price();
                                break;
                            }

                            if ( ( $action_key === 'enable_onsale' ) && self::woopt_check_apply( $variation_id, $action_apply, $action_apply_val ) && self::woopt_check_timer( $action_timer, $variation_id ) && self::woopt_check_roles( $action_roles ) ) {
                                $price = $product->get_sale_price();
                                break;
                            }
                        }
                    }

                    return apply_filters( 'woopt_get_on_sale_price', $price, $product );
                }

                public static function woopt_sold_individually( $sold_individually, $product ) {
                    if ( apply_filters( 'woopt_ignore', false, $product, 'sold_individually' ) ) {
                        return $sold_individually;
                    }

                    $sold_individually = self::woopt_get_action_result( $sold_individually, $product, 'enable_sold_individually', 'disable_sold_individually' );

                    return apply_filters( 'woopt_sold_individually', $sold_individually, $product );
                }

                function init() {

                    self::$capabilities = apply_filters( 'woopt_global_timer_capabilities', self::$capabilities );
                }

                function register_settings() {
                    // settings
                    register_setting( 'woopt_settings', 'woopt_settings', [
                            'type'              => 'array',
                            'sanitize_callback' => [ $this, 'sanitize_array' ],
                    ] );
                    register_setting( 'woopt_settings', 'woopt_features', [
                            'type'              => 'array',
                            'sanitize_callback' => [ $this, 'sanitize_array' ],
                    ] );
                    register_setting( 'woopt_settings', 'woopt_actions', [
                            'type'              => 'array',
                            'sanitize_callback' => [ $this, 'sanitize_array' ],
                    ] );
                }

                function last_saved( $value, $option ) {
                    if ( $option == 'woopt_settings' ) {
                        $value['_last_saved']    = time();
                        $value['_last_saved_by'] = get_current_user_id();
                    }

                    return $value;
                }

                function admin_menu() {
                    add_submenu_page( 'wpclever', esc_html__( 'WPC Product Timer', 'woo-product-timer' ), esc_html__( 'Product Timer', 'woo-product-timer' ), 'manage_options', 'wpclever-woopt', [
                            $this,
                            'admin_menu_content'
                    ] );
                    
                    if ( ! empty( self::$capabilities ) ) {
                        foreach ( self::$capabilities as $capability ) {
                            if ( current_user_can( $capability ) ) {
                                add_menu_page( esc_html__( 'WPC Global Timer', 'woo-product-timer' ), esc_html__( 'WPC Global Timer', 'woo-product-timer' ), $capability, 'wpclever-woopt-global', [
                                        $this,
                                        'admin_menu_content_global'
                                ], 'dashicons-clock',
                                        27 );
                                break;
                            }
                        }
                    }
                }

                function admin_menu_content() {
                    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) : 'global'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    ?>
                    <div class="wrap woopt-settings-wrap">
                        <div class="woopt-settings-header">
                            <div class="woopt-settings-header-inner">
                                <div class="woopt-header-left">
                                    <div class="woopt-logo">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                    </div>
                                    <div>
                                        <h1>
                                            <?php echo esc_html__( 'WPC Product Timer', 'woo-product-timer' ) . ' ' . esc_html( WOOPT_VERSION ); ?>
                                            <?php if ( defined( 'WOOPT_PREMIUM' ) ) : ?>
                                                <span class="premium"><?php esc_html_e( 'Premium', 'woo-product-timer' ); ?></span>
                                            <?php endif; ?>
                                        </h1>
                                        <p class="woopt-tagline">
                                            <?php esc_html_e( 'Schedule products, set date & time based pricing and visibility.', 'woo-product-timer' ); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ( isset( $_GET['settings-updated'] ) && sanitize_text_field( wp_unslash( $_GET['settings-updated'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                            <div class="notice notice-success is-dismissible">
                                <p><?php esc_html_e( 'Settings updated.', 'woo-product-timer' ); ?></p>
                            </div>
                        <?php } ?>

                        <div class="woopt-admin-nav">
                            <div class="woopt-nav-container">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=how' ) ); ?>"
                                   class="woopt-nav-item <?php echo esc_attr( $active_tab === 'how' ? 'active' : '' ); ?>">
                                    <?php esc_html_e( 'How to use?', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=global' ) ); ?>"
                                   class="woopt-nav-item <?php echo esc_attr( $active_tab === 'global' ? 'active' : '' ); ?>">
                                    <?php esc_html_e( 'Global Timer', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=simulator' ) ); ?>"
                                   class="woopt-nav-item <?php echo $active_tab === 'simulator' ? 'active' : ''; ?>">
                                    <?php esc_html_e( 'Simulator', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( WOOPT_DOCS ); ?>" class="woopt-nav-item" target="_blank">
                                    <?php esc_html_e( 'Docs', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=premium' ) ); ?>"
                                   class="woopt-nav-item wpc-premium <?php echo esc_attr( $active_tab === 'premium' ? 'active' : '' ); ?>">
                                    <?php esc_html_e( 'Premium Version', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-kit' ) ); ?>"
                                   class="woopt-nav-item">
                                    <?php esc_html_e( 'Essential Kit', 'woo-product-timer' ); ?>
                                </a>
                            </div>
                        </div>

                        <div class="woopt-tab-content active">
                            <?php if ( $active_tab === 'how' ) { ?>
                                <div class="woopt-card">
                                    <div class="woopt-card-header">
                                        <div>
                                            <h2 class="woopt-card-title"><?php esc_html_e( 'How to use?', 'woo-product-timer' ); ?></h2>
                                            <p class="woopt-card-desc"><?php esc_html_e( 'Quick guide on how to configure WPC Product Timer.', 'woo-product-timer' ); ?></p>
                                        </div>
                                    </div>
                                    <div class="woopt-settings-page-content-text">
                                        <p>
                                            <?php esc_html_e( '1. Global timer: Switch to Global Timer tab to set the timer for all products, categories or tags.', 'woo-product-timer' ); ?>
                                        </p>
                                        <p>
                                            <?php esc_html_e( '2. Product basis timer: When adding/editing the product you can choose the Timer tab then add action & time conditional.', 'woo-product-timer' ); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php } elseif ( $active_tab === 'global' ) { ?>
                                <form method="post" action="options.php">
                                    <div class="woopt-card">
                                        <div class="woopt-card-header">
                                            <div>
                                                <h2 class="woopt-card-title"><?php esc_html_e( 'General Settings', 'woo-product-timer' ); ?></h2>
                                                <p class="woopt-card-desc"><?php esc_html_e( 'Configure general settings for the plugin.', 'woo-product-timer' ); ?></p>
                                            </div>
                                        </div>

                                        <div class="woopt-settings-row">
                                            <div class="woopt-settings-label">
                                                <strong><?php esc_html_e( 'Performance', 'woo-product-timer' ); ?></strong>
                                            </div>
                                            <div class="woopt-settings-field">
                                                <ul class="woopt-checkbox-list">
                                                    <li>
                                                        <label>
                                                            <input type="checkbox" name="woopt_features[]"
                                                                   value="stock" <?php echo esc_attr( empty( self::$features ) || in_array( 'stock', self::$features, true ) ? 'checked' : '' ); ?>/>
                                                            <?php esc_html_e( 'Stock (in stock, out of stock)', 'woo-product-timer' ); ?>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label>
                                                            <input type="checkbox" name="woopt_features[]"
                                                                   value="visibility" <?php echo esc_attr( empty( self::$features ) || in_array( 'visibility', self::$features, true ) ? 'checked' : '' ); ?>/>
                                                            <?php esc_html_e( 'Visibility (visible, hidden, catalog visibility)', 'woo-product-timer' ); ?>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label>
                                                            <input type="checkbox" name="woopt_features[]"
                                                                   value="featured" <?php echo esc_attr( empty( self::$features ) || in_array( 'featured', self::$features, true ) ? 'checked' : '' ); ?>/>
                                                            <?php esc_html_e( 'Featured (featured, unfeatured)', 'woo-product-timer' ); ?>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label>
                                                            <input type="checkbox" name="woopt_features[]"
                                                                   value="purchasable" <?php echo esc_attr( empty( self::$features ) || in_array( 'purchasable', self::$features, true ) ? 'checked' : '' ); ?>/>
                                                            <?php esc_html_e( 'Purchasable (purchasable, unpurchasable)', 'woo-product-timer' ); ?>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label>
                                                            <input type="checkbox" name="woopt_features[]"
                                                                   value="price" <?php echo esc_attr( empty( self::$features ) || in_array( 'price', self::$features, true ) ? 'checked' : '' ); ?>/>
                                                            <?php esc_html_e( 'Price (regular price, sale price)', 'woo-product-timer' ); ?>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label>
                                                            <input type="checkbox" name="woopt_features[]"
                                                                   value="onsale" <?php echo esc_attr( empty( self::$features ) || in_array( 'onsale', self::$features, true ) ? 'checked' : '' ); ?>/>
                                                            <?php esc_html_e( 'On Sale (enable, disable)', 'woo-product-timer' ); ?>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label>
                                                            <input type="checkbox" name="woopt_features[]"
                                                                   value="individually" <?php echo esc_attr( empty( self::$features ) || in_array( 'individually', self::$features, true ) ? 'checked' : '' ); ?>/>
                                                            <?php esc_html_e( 'Sold Individually (enable, disable)', 'woo-product-timer' ); ?>
                                                        </label>
                                                    </li>
                                                </ul>
                                                <span class="description"><?php esc_html_e( 'Uncheck the feature(s) you don\'t use in all timers for better performance.', 'woo-product-timer' ); ?></span>
                                            </div>
                                        </div>

                                        <div class="woopt-settings-row">
                                            <div class="woopt-settings-label">
                                                <strong><?php esc_html_e( 'Current time', 'woo-product-timer' ); ?></strong>
                                            </div>
                                            <div class="woopt-settings-field">
                                                <div class="woopt-current-time-badges">
                                                    <code><?php echo esc_html( current_time( 'l' ) ); ?></code>
                                                    <code><?php echo esc_html( current_time( 'm/d/Y' ) ); ?></code>
                                                    <code><?php echo esc_html( current_time( 'h:i a' ) ); ?></code>
                                                    <code><?php echo esc_html__( 'Week No.', 'woo-product-timer' ) . ' ' . esc_html( current_time( 'W' ) ); ?></code>
                                                    <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"
                                                       target="_blank" class="woopt-link-btn"><?php esc_html_e( 'Date/time settings', 'woo-product-timer' ); ?></a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="woopt-card">
                                        <div class="woopt-card-header">
                                            <div>
                                                <h2 class="woopt-card-title"><?php esc_html_e( 'Global Timer Actions', 'woo-product-timer' ); ?></h2>
                                                <p class="woopt-card-desc">
                                                    <?php esc_html_e( 'Actions are checked from top to bottom. The last one that matches the current time will be executed.', 'woo-product-timer' ); ?>
                                                </p>
                                            </div>
                                            <div class="woopt-card-header-actions">
                                                <a href="#" class="woopt-action-tool-btn woopt_expand_all">
                                                    <span class="dashicons dashicons-arrow-down-alt2"></span> <?php esc_html_e( 'Expand All', 'woo-product-timer' ); ?>
                                                </a>
                                                <a href="#" class="woopt-action-tool-btn woopt_collapse_all">
                                                    <span class="dashicons dashicons-arrow-up-alt2"></span> <?php esc_html_e( 'Collapse All', 'woo-product-timer' ); ?>
                                                </a>
                                                <a href="https://wpclever.net/downloads/product-timer/?utm_source=pro&utm_medium=woopt&utm_campaign=wporg"
                                                   target="_blank" class="woopt-action-tool-btn"
                                                   onclick="return confirm('This feature only available in Premium Version!\nBuy it now? Just $29')">
                                                    <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Import / Export', 'woo-product-timer' ); ?>
                                                </a>
                                            </div>
                                        </div>

                                        <div class="woopt_actions woopt-rules-container">
                                            <?php
                                            if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                                                foreach ( self::$global_actions as $key => $action ) {
                                                    self::action( $key, $action, true );
                                                }
                                            } else {
                                                self::action( 0, null, true, true );
                                            }
                                            ?>
                                        </div>

                                        <a href="https://wpclever.net/downloads/product-timer/?utm_source=pro&utm_medium=woopt&utm_campaign=wporg"
                                           target="_blank" class="woopt-add-rule"
                                           onclick="return confirm('This feature only available in Premium Version!\nBuy it now? Just $29')">
                                            <span>+</span> <?php esc_html_e( 'Add action', 'woo-product-timer' ); ?>
                                        </a>
                                    </div>

                                    <div class="woopt-submit-row">
                                        <?php
                                        echo '<input type="hidden" name="woopt_settings[version]" value="' . esc_attr( WOOPT_VERSION ) . '"/>';
                                        settings_fields( 'woopt_settings' );
                                        submit_button( '', 'primary', 'submit', false );

                                        if ( function_exists( 'wpc_last_saved' ) ) {
                                            wpc_last_saved( get_option( 'woopt_settings', [] ) );
                                        }
                                        ?>
                                    </div>
                                </form>
                            <?php } elseif ( $active_tab === 'premium' ) { ?>
                                <div class="woopt-card">
                                    <div class="woopt-card-header">
                                        <div>
                                            <h2 class="woopt-card-title"><?php esc_html_e( 'Premium Version', 'woo-product-timer' ); ?></h2>
                                            <p class="woopt-card-desc"><?php esc_html_e( 'Unlock powerful features with WPC Product Timer Premium.', 'woo-product-timer' ); ?></p>
                                        </div>
                                    </div>
                                    <div class="woopt-settings-page-content-text">
                                        <p>
                                            <?php esc_html_e( 'Get the Premium Version just $29!', 'woo-product-timer' ); ?>
                                            <a href="https://wpclever.net/downloads/product-timer/?utm_source=pro&utm_medium=woopt&utm_campaign=wporg"
                                               target="_blank">https://wpclever.net/downloads/product-timer/</a>
                                        </p>
                                        <p><strong><?php esc_html_e( 'Extra features for Premium Version:', 'woo-product-timer' ); ?></strong></p>
                                        <ul>
                                            <li>- <?php esc_html_e( 'Add multiple actions.', 'woo-product-timer' ); ?></li>
                                            <li>- <?php esc_html_e( 'Get the lifetime update & premium support.', 'woo-product-timer' ); ?></li>
                                        </ul>
                                    </div>
                                </div>
                            <?php } elseif ( $active_tab === 'simulator' ) {
                                self::simulator();
                            } ?>
                        </div>
                    </div>
                    <?php
                }

                function admin_menu_content_global() {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    if ( isset( $_POST['woopt_actions'] ) && check_admin_referer( 'woopt_global_timer' ) ) {
                        update_option( 'woopt_actions', self::sanitize_array( map_deep( wp_unslash( $_POST['woopt_actions'] ?? [] ), 'sanitize_text_field' ) ) );
                        self::$global_actions        = (array) get_option( 'woopt_actions', [] );
                        self::$parsed_global_actions = null;

                        // Refresh variable prices after global timer changes
                        wc_delete_product_transients();
                    }
                    ?>
                    <div class="wrap woopt-settings-wrap">
                        <div class="woopt-settings-header">
                            <div class="woopt-settings-header-inner">
                                <div class="woopt-header-left">
                                    <div class="woopt-logo">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                    </div>
                                    <div>
                                        <h1>
                                            <?php echo esc_html__( 'WPC Product Timer', 'woo-product-timer' ) . ' ' . esc_html( WOOPT_VERSION ); ?>
                                            <?php if ( defined( 'WOOPT_PREMIUM' ) ) : ?>
                                                <span class="premium"><?php esc_html_e( 'Premium', 'woo-product-timer' ); ?></span>
                                            <?php endif; ?>
                                        </h1>
                                        <p class="woopt-tagline">
                                            <?php esc_html_e( 'Schedule products, set date & time based pricing and visibility.', 'woo-product-timer' ); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="woopt-admin-nav">
                            <div class="woopt-nav-container">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woopt-global' ) ); ?>"
                                   class="woopt-nav-item active">
                                    <?php esc_html_e( 'Global Timer', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=simulator' ) ); ?>"
                                   class="woopt-nav-item">
                                    <?php esc_html_e( 'Simulator', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( WOOPT_DOCS ); ?>" class="woopt-nav-item" target="_blank">
                                    <?php esc_html_e( 'Docs', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=premium' ) ); ?>"
                                   class="woopt-nav-item wpc-premium">
                                    <?php esc_html_e( 'Premium Version', 'woo-product-timer' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-kit' ) ); ?>"
                                   class="woopt-nav-item">
                                    <?php esc_html_e( 'Essential Kit', 'woo-product-timer' ); ?>
                                </a>
                            </div>
                        </div>

                        <div class="woopt-tab-content active">
                            <form method="post" action="">
                                <?php wp_nonce_field( 'woopt_global_timer' ); ?>

                                <div class="woopt-card">
                                    <div class="woopt-card-header">
                                        <div>
                                            <h2 class="woopt-card-title"><?php esc_html_e( 'Current time', 'woo-product-timer' ); ?></h2>
                                            <p class="woopt-card-desc"><?php esc_html_e( 'Server date and time settings.', 'woo-product-timer' ); ?></p>
                                        </div>
                                    </div>
                                    <div class="woopt-settings-row">
                                        <div class="woopt-settings-label">
                                            <strong><?php esc_html_e( 'Current time', 'woo-product-timer' ); ?></strong>
                                        </div>
                                        <div class="woopt-settings-field">
                                            <div class="woopt-current-time-badges">
                                                <code><?php echo esc_html( current_time( 'l' ) ); ?></code>
                                                <code><?php echo esc_html( current_time( 'm/d/Y' ) ); ?></code>
                                                <code><?php echo esc_html( current_time( 'h:i a' ) ); ?></code>
                                                <code><?php echo esc_html__( 'Week No.', 'woo-product-timer' ) . ' ' . esc_html( current_time( 'W' ) ); ?></code>
                                                <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"
                                                   target="_blank" class="woopt-link-btn">
                                                    <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Date/time settings', 'woo-product-timer' ); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="woopt-card">
                                    <div class="woopt-card-header">
                                        <div>
                                            <h2 class="woopt-card-title"><?php esc_html_e( 'Global Timer Actions', 'woo-product-timer' ); ?></h2>
                                            <p class="woopt-card-desc">
                                                <?php esc_html_e( 'Actions are checked from top to bottom. The last one that matches the current time will be executed.', 'woo-product-timer' ); ?>
                                            </p>
                                        </div>
                                        <div class="woopt-card-header-actions">
                                            <a href="#" class="woopt-action-tool-btn woopt_expand_all">
                                                <span class="dashicons dashicons-arrow-down-alt2"></span> <?php esc_html_e( 'Expand All', 'woo-product-timer' ); ?>
                                            </a>
                                            <a href="#" class="woopt-action-tool-btn woopt_collapse_all">
                                                <span class="dashicons dashicons-arrow-up-alt2"></span> <?php esc_html_e( 'Collapse All', 'woo-product-timer' ); ?>
                                            </a>
                                            <a href="https://wpclever.net/downloads/product-timer/?utm_source=pro&utm_medium=woopt&utm_campaign=wporg"
                                               target="_blank" class="woopt-action-tool-btn"
                                               onclick="return confirm('This feature only available in Premium Version!\nBuy it now? Just $29')">
                                                <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Import / Export', 'woo-product-timer' ); ?>
                                            </a>
                                        </div>
                                    </div>

                                    <div class="woopt_actions woopt-rules-container">
                                        <?php
                                        if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                                            foreach ( self::$global_actions as $key => $action ) {
                                                self::action( $key, $action, true );
                                            }
                                        } else {
                                            self::action( 0, null, true, true );
                                        }
                                        ?>
                                    </div>

                                    <a href="https://wpclever.net/downloads/product-timer/?utm_source=pro&utm_medium=woopt&utm_campaign=wporg"
                                       target="_blank" class="woopt-add-rule"
                                       onclick="return confirm('This feature only available in Premium Version!\nBuy it now? Just $29')">
                                        <span>+</span> <?php esc_html_e( 'Add action', 'woo-product-timer' ); ?>
                                    </a>
                                </div>

                                <div class="woopt-submit-row">
                                    <?php submit_button( esc_html__( 'Save changes', 'woo-product-timer' ), 'primary', 'submit', false ); ?>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php
                }

                function admin_enqueue_scripts( $hook ) {
                    if ( apply_filters( 'woopt_ignore_backend_scripts', false, $hook ) ) {
                        return null;
                    }

                    // Only load on product pages and plugin settings
                    if ( in_array( $hook, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) {
                        $screen = get_current_screen();

                        if ( ! $screen || $screen->post_type !== 'product' ) {
                            return null;
                        }
                    } elseif ( ! in_array( $hook, [
                            'toplevel_page_wpclever-woopt-global',
                            'wpclever_page_wpclever-woopt',
                    ], true ) ) {
                        return null;
                    }

                    // wpcdpk
                    wp_enqueue_style( 'wpcdpk', WOOPT_URI . 'assets/libs/wpcdpk/css/datepicker.css', [], WOOPT_VERSION );
                    wp_enqueue_script( 'wpcdpk', WOOPT_URI . 'assets/libs/wpcdpk/js/datepicker.js', [ 'jquery' ], WOOPT_VERSION, true );

                    // hint.css
                    wp_enqueue_style( 'hint-css', WOOPT_URI . 'assets/libs/hint/hint.min.css', [], '2.7.0' );

                    // backend
                    wp_enqueue_style( 'woopt-backend', WOOPT_URI . 'assets/css/backend.css', [
                        'woocommerce_admin_styles',
                        'hint-css',
                    ], WOOPT_VERSION );
                    wp_enqueue_script( 'woopt-backend', WOOPT_URI . 'assets/js/backend.js', [
                            'jquery',
                            'jquery-ui-sortable',
                            'jquery-ui-dialog',
                            'wc-enhanced-select',
                            'selectWoo',
                    ], WOOPT_VERSION, true );
                    wp_localize_script( 'woopt-backend', 'woopt_vars', [
                            'nonce' => wp_create_nonce( 'woopt-security' )
                    ] );
                }

                function action_links( $links, $file ) {
                    static $plugin;

                    if ( ! isset( $plugin ) ) {
                        $plugin = plugin_basename( __FILE__ );
                    }

                    if ( $plugin === $file ) {
                        $how                  = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=how' ) ) . '">' . esc_html__( 'How to use?', 'woo-product-timer' ) . '</a>';
                        $global               = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=global' ) ) . '">' . esc_html__( 'Global Timer', 'woo-product-timer' ) . '</a>';
                        $links['wpc-premium'] = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woopt&tab=premium' ) ) . '">' . esc_html__( 'Premium Version', 'woo-product-timer' ) . '</a>';
                        array_unshift( $links, $how, $global );
                    }

                    return (array) $links;
                }

                function row_meta( $links, $file ) {
                    static $plugin;

                    if ( ! isset( $plugin ) ) {
                        $plugin = plugin_basename( __FILE__ );
                    }

                    if ( $plugin === $file ) {
                        $row_meta = [
                                'docs'    => '<a href="' . esc_url( WOOPT_DOCS ) . '" target="_blank">' . esc_html__( 'Docs', 'woo-product-timer' ) . '</a>',
                                'support' => '<a href="' . esc_url( WOOPT_DISCUSSION ) . '" target="_blank">' . esc_html__( 'Community support', 'woo-product-timer' ) . '</a>',
                        ];

                        return array_merge( $links, $row_meta );
                    }

                    return (array) $links;
                }

                function product_data_tabs( $tabs ) {
                    $tabs['woopt'] = [
                            'label'  => esc_html__( 'Timer', 'woo-product-timer' ),
                            'target' => 'woopt_settings',
                    ];

                    return $tabs;
                }

                function product_data_panels() {
                    global $post, $thepostid, $product_object;

                    if ( $product_object instanceof WC_Product ) {
                        $product_id = $product_object->get_id();
                    } elseif ( is_numeric( $thepostid ) ) {
                        $product_id = $thepostid;
                    } elseif ( $post instanceof WP_Post ) {
                        $product_id = $post->ID;
                    } else {
                        $product_id = 0;
                    }

                    if ( ! $product_id ) {
                        ?>
                        <div id='woopt_settings' class='panel woocommerce_options_panel woopt_settings'>
                            <p class="woopt-color-notice"><?php esc_html_e( 'Product wasn\'t returned.', 'woo-product-timer' ); ?></p>
                        </div>
                        <?php
                        return;
                    }

                    $actions = get_post_meta( $product_id, 'woopt_actions', true );

                    $global_timer = '';

                    if ( current_user_can( 'manage_options' ) ) {
                        $global_timer = admin_url( 'admin.php?page=wpclever-woopt&tab=global' );
                    } else {
                        if ( ! empty( self::$capabilities ) ) {
                            foreach ( self::$capabilities as $capability ) {
                                if ( current_user_can( $capability ) ) {
                                    $global_timer = admin_url( 'admin.php?page=wpclever-woopt-global' );
                                    break;
                                }
                            }
                        }
                    }
                    ?>
                    <div id='woopt_settings' class='panel woocommerce_options_panel woopt_settings'>
                        <?php if ( ! empty( $global_timer ) ) { ?>
                            <div class="woopt_global_timer"><span class="dashicons dashicons-admin-site"></span>
                                <a href="<?php echo esc_url( $global_timer ); ?>"
                                   target="_blank"><?php esc_html_e( 'Click here to configure the Global Timer', 'woo-product-timer' ); ?></a>
                            </div>
                        <?php } ?>
                        <div class="woopt_current_time">
                            <?php esc_html_e( 'Current time', 'woo-product-timer' ); ?>
                            <code><?php echo esc_html( current_time( 'l' ) ); ?></code>
                            <code><?php echo esc_html( current_time( 'm/d/Y' ) ); ?></code>
                            <code><?php echo esc_html( current_time( 'h:i a' ) ); ?></code>
                            <code><?php echo esc_html__( 'Week No.', 'woo-product-timer' ) . ' ' . esc_html( current_time( 'W' ) ); ?></code>
                            <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"
                               target="_blank"><?php esc_html_e( 'Date/time settings', 'woo-product-timer' ); ?></a>
                        </div>
                        <div class="woopt_actions">
                            <?php
                            if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                                foreach ( $actions as $key => $action ) {
                                    self::action( $key, $action );
                                }
                            } else {
                                self::action( 0, null, false, true );
                            }
                            ?>
                        </div>
                        <div class="woopt_add_action">
                            <div>
                                <a href="https://wpclever.net/downloads/product-timer/?utm_source=pro&utm_medium=woopt&utm_campaign=wporg"
                                   target="_blank" class="button"
                                   onclick="return confirm('This feature only available in Premium Version!\nBuy it now? Just $29')">
                                    <?php esc_html_e( '+ Add action', 'woo-product-timer' ); ?>
                                </a>
                                <a href="#" class="woopt_expand_all">
                                    <?php esc_html_e( 'Expand All', 'woo-product-timer' ); ?>
                                </a> <a href="#" class="woopt_collapse_all">
                                    <?php esc_html_e( 'Collapse All', 'woo-product-timer' ); ?>
                                </a>
                            </div>
                            <div>
                                <a href="#" class="woopt_save_actions button button-primary">
                                    <?php esc_html_e( 'Save actions', 'woo-product-timer' ); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php
                }

                function action( $key = 0, $action_val = null, $global = false, $active = false ) {
                    if ( empty( $key ) || is_numeric( $key ) ) {
                        $key = self::generate_key();
                    }

                    $action_data = self::woopt_action_data( $action_val );
                    $name        = $action_data['name'] ?? '';
                    $apply       = $action_data['apply'] ?? 'apply_all';
                    $apply_val   = $action_data['apply_val'] ?? [];
                    $action      = $action_data['action'];
                    $action_val  = $action_data['action_val'] ?? [];
                    $base        = ! empty( $action_val['base'] ) ? $action_val['base'] : 'fa';
                    $visibility  = ! empty( $action_val['visibility'] ) ? $action_val['visibility'] : 'visible';
                    $price       = isset( $action_val['value'] ) ? (float) $action_val['value'] : '';
                    $conditional = $action_data['timer'];
                    $roles       = $action_data['roles'];
                    ?>
                    <div class="woopt_action <?php echo esc_attr( $active ? 'active' : '' ); ?>"
                         data-key="<?php echo esc_attr( $key ); ?>">
                        <div class="woopt_action_heading">
                            <span class="woopt_action_move"></span>
                            <span class="woopt_action_label"><span
                                        class="woopt_action_label_name"><?php echo esc_html( '#' . $key ); ?></span><span
                                        class="woopt_action_label_action"></span><span
                                        class="woopt_action_label_apply"></span></span>
                            <a href="#"
                               class="woopt_action_summary woopt_summary_btn hint--top"
                               aria-label="<?php esc_attr_e( 'Summary', 'woo-product-timer' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                            </a>
                            <a href="https://wpclever.net/downloads/product-timer/?utm_source=pro&utm_medium=woopt&utm_campaign=wporg"
                               target="_blank" class="woopt_duplicate_btn hint--top"
                               onclick="return confirm('This feature only available in Premium Version!\nBuy it now? Just $29')"
                               aria-label="<?php esc_attr_e( 'Duplicate', 'woo-product-timer' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                            </a>
                            <a href="#"
                               class="woopt_action_remove woopt_remove_btn hint--top"
                               aria-label="<?php esc_attr_e( 'Remove', 'woo-product-timer' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                            </a>
                        </div>
                        <div class="woopt_action_content">
                            <div class="woopt_tr">
                                <div class="woopt_th"><?php esc_html_e( 'Name', 'woo-product-timer' ); ?></div>
                                <div class="woopt_td woopt_action_td">
                                    <p class="description"><?php esc_html_e( 'For management use only.', 'woo-product-timer' ); ?></p>
                                    <input type="text" class="text large-text woopt_action_name_input"
                                           name="woopt_actions[<?php echo esc_attr( $key ); ?>][name]"
                                           data-name="<?php echo esc_attr( '#' . $key ); ?>"
                                           value="<?php echo esc_attr( $name ); ?>"/>
                                </div>
                            </div>
                            <?php if ( $global ) { ?>
                                <input type="hidden" name="woopt_actions[<?php echo esc_attr( $key ); ?>][type]"
                                       value="global"/>
                                <div class="woopt_tr">
                                    <div class="woopt_th"><?php esc_html_e( 'Apply for', 'woo-product-timer' ); ?></div>
                                    <div class="woopt_td woopt_action_td">
                                        <select class="woopt_apply_selector"
                                                name="woopt_actions[<?php echo esc_attr( $key ); ?>][apply]">
                                            <option value="apply_all" <?php selected( $apply, 'apply_all' ); ?>><?php esc_html_e( 'All products', 'woo-product-timer' ); ?></option>
                                            <option value="apply_variation" <?php selected( $apply, 'apply_variation' ); ?>><?php esc_html_e( 'Variations only', 'woo-product-timer' ); ?></option>
                                            <option value="apply_not_variation" <?php selected( $apply, 'apply_not_variation' ); ?>><?php esc_html_e( 'Non-variation products', 'woo-product-timer' ); ?></option>
                                            <option value="apply_product" <?php selected( $apply, 'apply_product' ); ?>><?php esc_html_e( 'Selected products', 'woo-product-timer' ); ?></option>
                                            <option value="apply_combination" <?php selected( $apply, 'apply_combination' ); ?>><?php esc_html_e( 'Combined sources', 'woo-product-timer' ); ?></option>
                                            <?php
                                            
                                            $taxonomies = get_object_taxonomies( 'product', 'objects' );

                                            foreach ( $taxonomies as $taxonomy ) {
                                                echo '<option value="apply_' . esc_attr( $taxonomy->name ) . '" ' . ( $apply === 'apply_' . $taxonomy->name ? 'selected' : '' ) . '>' . esc_html( $taxonomy->label ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="woopt_tr hide_apply show_if_apply_product">
                                    <div class="woopt_th"><?php esc_html_e( 'Products', 'woo-product-timer' ); ?></div>
                                    <div class="woopt_td woopt_action_td">
                                        <select class="wc-product-search woopt-product-search" multiple="multiple"
                                                name="woopt_actions[<?php echo esc_attr( $key ); ?>][apply_val][products][]"
                                                data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'woo-product-timer' ); ?>"
                                                data-action="woocommerce_json_search_products_and_variations"
                                                data-val="<?php echo esc_attr( $apply === 'apply_product' ? ( is_array( $apply_val ) ? implode( ',', ( $apply_val['products'] ?? [] ) ) : $apply_val ) : '' ); ?>">
                                            <?php
                                            if ( is_string( $apply_val ) ) {
                                                $product_ids = explode( ',', $apply_val );
                                            } elseif ( is_array( $apply_val ) ) {
                                                $product_ids = ! empty( $apply_val['products'] ) ? $apply_val['products'] : [];
                                            } else {
                                                $product_ids = [];
                                            }

                                            foreach ( $product_ids as $product_id ) {
                                                if ( $product = wc_get_product( $product_id ) ) {
                                                    echo '<option value="' . esc_attr( $product_id ) . '" selected>' . wp_kses_post( $product->get_formatted_name() ) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="woopt_tr hide_apply show_if_apply_combination">
                                    <div class="woopt_th"><?php esc_html_e( 'Applied conditions', 'woo-product-timer' ); ?></div>
                                    <div class="woopt_td woopt_action_td">
                                        <div class="woopt_apply_combinations">
                                            <p class="description"><?php esc_html_e( '* Configure to find products that match all listed conditions.', 'woo-product-timer' ); ?></p>
                                            <?php
                                            $combined = ! empty( $apply_val['combined'] ) ? (array) $apply_val['combined'] : [];

                                            if ( ! empty( $combined ) ) {
                                                foreach ( $combined as $combination_key => $combination_item ) {
                                                    self::apply_combination( $key, $combination_key, $combination_item );
                                                }
                                            }
                                            ?>
                                        </div>
                                        <div class="woopt_add_apply_combination">
                                            <a class="woopt_new_apply_combination"
                                               href="#"><?php esc_attr_e( '+ Add condition', 'woo-product-timer' ); ?></a>
                                        </div>
                                    </div>
                                </div>
                                <div class="woopt_tr show_apply hide_if_apply_all hide_if_apply_variation hide_if_apply_not_variation hide_if_apply_product hide_if_apply_category hide_if_apply_combination">
                                    <div class="woopt_th woopt_apply_text"><?php esc_html_e( 'Terms', 'woo-product-timer' ); ?></div>
                                    <div class="woopt_td woopt_action_td">
                                        <?php
                                        if ( is_string( $apply_val ) ) {
                                            $term_slugs = array_map( 'trim', explode( ',', $apply_val ) );
                                        } elseif ( is_array( $apply_val ) ) {
                                            $term_slugs = ! empty( $apply_val['terms'] ) ? $apply_val['terms'] : [];
                                        } else {
                                            $term_slugs = [];
                                        }
                                        ?>
                                        <select class="woopt_terms" multiple="multiple"
                                                name="woopt_actions[<?php echo esc_attr( $key ); ?>][apply_val][terms][]"
                                                data-<?php echo esc_attr( $apply ); ?>="<?php echo esc_attr( implode( ',', $term_slugs ) ); ?>">
                                            <?php
                                            if ( ! empty( $term_slugs ) ) {
                                                $taxonomy = substr( $apply, 6 );

                                                foreach ( $term_slugs as $ts ) {
                                                    if ( $term = get_term_by( 'slug', $ts, $taxonomy ) ) {
                                                        echo '<option value="' . esc_attr( $ts ) . '" selected>' . esc_html( $term->name ) . '</option>';
                                                    }
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            <?php } else { ?>
                                <input type="hidden" name="woopt_actions[<?php echo esc_attr( $key ); ?>][type]"
                                       value="product"/>
                            <?php } ?>
                            <div class="woopt_tr woopt_action_wrap">
                                <div class="woopt_th"><?php esc_html_e( 'Action', 'woo-product-timer' ); ?></div>
                                <div class="woopt_td woopt_action_td">
									<span>
                                        <select class="woopt_action_selector"
                                                name="woopt_actions[<?php echo esc_attr( $key ); ?>][action]">
                                            <option value=""><?php esc_html_e( 'Choose action', 'woo-product-timer' ); ?></option>
                                            <option value="set_instock" <?php selected( $action, 'set_instock' ); ?>><?php esc_html_e( 'Set in stock', 'woo-product-timer' ); ?></option>
                                            <option value="set_outofstock" <?php selected( $action, 'set_outofstock' ); ?>><?php esc_html_e( 'Set out of stock', 'woo-product-timer' ); ?></option>
                                            <option value="set_visible" <?php selected( $action, 'set_visible' ); ?>><?php esc_html_e( 'Set visible', 'woo-product-timer' ); ?></option>
                                            <option value="set_hidden" <?php selected( $action, 'set_hidden' ); ?>><?php esc_html_e( 'Set hidden', 'woo-product-timer' ); ?></option>
                                            <option value="set_catalog_visibility" <?php selected( $action, 'set_catalog_visibility' ); ?>><?php esc_html_e( 'Set catalog visibility', 'woo-product-timer' ); ?></option>
                                            <option value="set_featured" <?php selected( $action, 'set_featured' ); ?>><?php esc_html_e( 'Set featured', 'woo-product-timer' ); ?></option>
                                            <option value="set_unfeatured" <?php selected( $action, 'set_unfeatured' ); ?>><?php esc_html_e( 'Set unfeatured', 'woo-product-timer' ); ?></option>
                                            <option value="set_purchasable" <?php selected( $action, 'set_purchasable' ); ?>><?php esc_html_e( 'Set purchasable', 'woo-product-timer' ); ?></option>
                                            <option value="set_unpurchasable" <?php selected( $action, 'set_unpurchasable' ); ?>><?php esc_html_e( 'Set unpurchasable', 'woo-product-timer' ); ?></option>
                                            <option value="set_regularprice" <?php selected( $action, 'set_regularprice' ); ?>><?php esc_html_e( 'Set regular price', 'woo-product-timer' ); ?></option>
                                            <option value="set_saleprice" <?php selected( $action, 'set_saleprice' ); ?>><?php esc_html_e( 'Set sale price', 'woo-product-timer' ); ?></option>
                                            <option value="enable_onsale" <?php selected( $action, 'enable_onsale' ); ?>><?php esc_html_e( 'Enable on-sale', 'woo-product-timer' ); ?></option>
                                            <option value="disable_onsale" <?php selected( $action, 'disable_onsale' ); ?>><?php esc_html_e( 'Disable on-sale', 'woo-product-timer' ); ?></option>
                                            <option value="enable_sold_individually" <?php selected( $action, 'enable_sold_individually' ); ?>><?php esc_html_e( 'Enable sold individually', 'woo-product-timer' ); ?></option>
                                            <option value="disable_sold_individually" <?php selected( $action, 'disable_sold_individually' ); ?>><?php esc_html_e( 'Disable sold individually', 'woo-product-timer' ); ?></option>
                                        </select>
                                    </span>
                                    <span class="woopt_hide woopt_show_if_set_regularprice woopt_show_if_set_saleprice">
                                        <input class="woopt_price"
                                               name="woopt_actions[<?php echo esc_attr( $key ); ?>][action_val][value]"
                                               value="<?php echo esc_attr( $price ); ?>" type="number" step="any"
                                        />
                                    </span>
                                    <span class="woopt_hide woopt_show_if_set_regularprice woopt_show_if_set_saleprice">
										<select name="woopt_actions[<?php echo esc_attr( $key ); ?>][action_val][base]"
                                                class="woopt_action_price_base">
											<option value="ps" <?php selected( 'ps', $base ); ?>><?php esc_html_e( '% of sale price', 'woo-product-timer' ); ?></option>
											<option value="pr" <?php selected( 'pr', $base ); ?> data-set_saleprice="<?php esc_attr_e( '% of regular price', 'woo-product-timer' ); ?>"
                                                    data-set_regularprice="<?php esc_attr_e( '%', 'woo-product-timer' ); ?>"><?php esc_html_e( '% of regular price', 'woo-product-timer' ); ?></option>
											<option value="fa" <?php selected( 'fa', $base ); ?>><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></option>
										</select>
									</span> <span class="woopt_hide woopt_show_if_set_catalog_visibility">
										<select name="woopt_actions[<?php echo esc_attr( $key ); ?>][action_val][visibility]">
											<option value="visible" <?php selected( 'visible', $visibility ); ?>><?php esc_html_e( 'Shop and search results', 'woo-product-timer' ); ?></option>
											<option value="catalog" <?php selected( 'catalog', $visibility ); ?>><?php esc_html_e( 'Shop only', 'woo-product-timer' ); ?></option>
											<option value="search" <?php selected( 'search', $visibility ); ?>><?php esc_html_e( 'Search results only', 'woo-product-timer' ); ?></option>
											<option value="hidden" <?php selected( 'hidden', $visibility ); ?>><?php esc_html_e( 'Hidden', 'woo-product-timer' ); ?></option>
										</select>
									</span>
                                </div>
                            </div>
                            <div class="woopt_tr">
                                <div class="woopt_th"><?php esc_html_e( 'Time conditions', 'woo-product-timer' ); ?></div>
                                <div class="woopt_td">
                                    <div class="woopt_timer">
                                        <p class="description"><?php esc_html_e( '* Configure date and time of the action that must match all listed conditions.', 'woo-product-timer' ); ?></p>
                                        <?php
                                        if ( is_array( $conditional ) && ( count( $conditional ) > 0 ) ) {
                                            foreach ( $conditional as $conditional_key => $conditional_item ) {
                                                self::time( $key, $conditional_key, $conditional_item );
                                            }
                                        } else {
                                            self::time( $key );
                                        }
                                        ?>
                                    </div>
                                    <div class="woopt_add_time">
                                        <a href="#"
                                           class="woopt_new_time"><?php esc_html_e( '+ Add time', 'woo-product-timer' ); ?></a>
                                    </div>
                                </div>
                            </div>
                            <div class="woopt_tr">
                                <div class="woopt_th"><?php esc_html_e( 'User roles', 'woo-product-timer' ); ?></div>
                                <div class="woopt_td">
                                    <div class="woopt_user_roles">
                                        <p class="description"><?php esc_html_e( '* Configure user role(s) that apply the action.', 'woo-product-timer' ); ?></p>
                                        <?php
                                        global $wp_roles;

                                        if ( is_array( $roles ) ) {
                                            $roles_arr = $roles;
                                        } elseif ( is_string( $roles ) ) {
                                            $roles_arr = explode( ',', $roles );
                                        } else {
                                            $roles_arr = [];
                                        }

                                        if ( empty( $roles ) || in_array( 'woopt_all', $roles_arr ) || in_array( 'all', $roles_arr ) ) {
                                            $roles_arr = [ 'woopt_all' ];
                                        }

                                        echo '<select class="woopt_user_roles_select" multiple="multiple" name="woopt_actions[' . esc_attr( $key ) . '][roles][]">';
                                        echo '<option value="woopt_all" ' . ( in_array( 'woopt_all', $roles_arr ) || in_array( 'all', $roles_arr ) ? 'selected' : '' ) . '>' . esc_html__( 'All', 'woo-product-timer' ) . '</option>';
                                        echo '<option value="woopt_user" ' . ( in_array( 'woopt_user', $roles_arr ) ? 'selected' : '' ) . '>' . esc_html__( 'User (logged in)', 'woo-product-timer' ) . '</option>';
                                        echo '<option value="woopt_guest" ' . ( in_array( 'woopt_guest', $roles_arr ) || in_array( 'guest', $roles_arr ) ? 'selected' : '' ) . '>' . esc_html__( 'Guest (not logged in)', 'woo-product-timer' ) . '</option>';

                                        if ( ! empty( $wp_roles->roles ) ) {
                                            foreach ( $wp_roles->roles as $role => $details ) {
                                                echo '<option value="' . esc_attr( $role ) . '" ' . ( in_array( $role, $roles_arr ) ? 'selected' : '' ) . '>' . esc_html( $details['name'] ) . '</option>';
                                            }
                                        }

                                        echo '</select>';
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }

                function apply_combination( $key = 0, $combination_key = 0, $combination_item = null ) {
                    if ( empty( $key ) || is_numeric( $key ) ) {
                        $key = self::generate_key();
                    }

                    if ( empty( $combination_key ) || is_numeric( $combination_key ) ) {
                        $combination_key = self::generate_key();
                    }

                    $combination_item_type = ! empty( $combination_item['type'] ) ? $combination_item['type'] : '';
                    $combination_item_val  = ! empty( $combination_item['val'] ) ? (array) $combination_item['val'] : [];
                    ?>
                    <div class="woopt_apply_combination">
                        <span class="woopt_apply_combination_remove">&times;</span> <span>
                                    <select class="woopt_apply_combination_select"
                                            name="woopt_actions[<?php echo esc_attr( $key ); ?>][apply_val][combined][<?php echo esc_attr( $combination_key ); ?>][type]">
	                                    <option value="variation" <?php selected( $combination_item_type, 'variation' ); ?>><?php esc_html_e( 'Variations only', 'woo-product-timer' ); ?></option>
	                                    <option value="not_variation" <?php selected( $combination_item_type, 'not_variation' ); ?>><?php esc_html_e( 'Non-variation products', 'woo-product-timer' ); ?></option>
                                        <?php
                                        $taxonomies = get_object_taxonomies( 'product', 'objects' ); 

                                        foreach ( $taxonomies as $taxonomy ) {
                                            echo '<option value="' . esc_attr( $taxonomy->name ) . '" ' . ( $combination_item_type === $taxonomy->name ? 'selected' : '' ) . '>' . esc_html( $taxonomy->label ) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </span> <span>
                                    <select class="woopt_apply_terms woopt_apply_combination_val" multiple="multiple"
                                            name="woopt_actions[<?php echo esc_attr( $key ); ?>][apply_val][combined][<?php echo esc_attr( $combination_key ); ?>][val][]">
                                        <?php
                                        if ( ! empty( $combination_item_val ) ) {
                                            foreach ( $combination_item_val as $t ) {
                                                if ( $term = get_term_by( 'slug', $t, $combination_item_type ) ) {
                                                    echo '<option value="' . esc_attr( $t ) . '" selected>' . esc_html( $term->name ) . '</option>';
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </span>
                    </div>
                    <?php
                }

                function time( $key = 0, $time_key = 0, $time_data = [] ) {
                    if ( empty( $key ) || is_numeric( $key ) ) {
                        $key = self::generate_key();
                    }

                    if ( empty( $time_key ) || is_numeric( $time_key ) ) {
                        $time_key = self::generate_key();
                    }

                    $type = ! empty( $time_data['type'] ) ? $time_data['type'] : 'every_day';
                    $val  = ! empty( $time_data['val'] ) ? $time_data['val'] : '';
                    $date = $date_time = $date_multi = $date_range = $from = $to = $time = $weekday = $monthday = $weekno = $monthno = $number = '';

                    switch ( $type ) {
                        case 'date_on':
                        case 'date_before':
                        case 'date_after':
                            $date = $val;
                            break;
                        case 'date_time_before':
                        case 'date_time_after':
                            $date_time = $val;
                            break;
                        case 'date_multi':
                            $date_multi = $val;
                            break;
                        case 'date_range':
                            $date_range = $val;
                            break;
                        case 'time_range':
                            $time_range = array_map( 'trim', explode( '-', (string) $val ) );
                            $from       = ! empty( $time_range[0] ) ? $time_range[0] : '';
                            $to         = ! empty( $time_range[1] ) ? $time_range[1] : '';
                            break;
                        case 'time_before':
                        case 'time_after':
                            $time = $val;
                            break;
                        case 'weekly_every':
                            $weekday = $val;
                            break;
                        case 'week_no':
                            $weekno = $val;
                            break;
                        case 'monthly_every':
                            $monthday = $val;
                            break;
                        case 'month_no':
                            $monthno = $val;
                            break;
                        case 'days_less_published':
                        case 'days_greater_published':
                            $number = $val;
                            break;
                        default:
                            $val = '';
                    }
                    ?>
                    <div class="woopt_time">
                        <input type="hidden" class="woopt_time_val"
                               name="woopt_actions[<?php echo esc_attr( $key ); ?>][timer][<?php echo esc_attr( $time_key ); ?>][val]"
                               value="<?php echo esc_attr( $val ); ?>"/>
                        <span class="woopt_time_remove">&times;</span> <span>
							<select class="woopt_time_type"
                                    name="woopt_actions[<?php echo esc_attr( $key ); ?>][timer][<?php echo esc_attr( $time_key ); ?>][type]">
								<option value=""><?php esc_html_e( 'Choose the time', 'woo-product-timer' ); ?></option>
								<option value="date_on"
                                        data-show="date" <?php selected( $type, 'date_on' ); ?>><?php esc_html_e( 'On the date', 'woo-product-timer' ); ?></option>
                                <option value="date_time_before"
                                        data-show="date_time" <?php selected( $type, 'date_time_before' ); ?>><?php esc_html_e( 'Before date & time', 'woo-product-timer' ); ?></option>
								<option value="date_time_after"
                                        data-show="date_time" <?php selected( $type, 'date_time_after' ); ?>><?php esc_html_e( 'After date & time', 'woo-product-timer' ); ?></option>
								<option value="date_before"
                                        data-show="date" <?php selected( $type, 'date_before' ); ?>><?php esc_html_e( 'Before date', 'woo-product-timer' ); ?></option>
								<option value="date_after"
                                        data-show="date" <?php selected( $type, 'date_after' ); ?>><?php esc_html_e( 'After date', 'woo-product-timer' ); ?></option>
								<option value="date_multi"
                                        data-show="date_multi" <?php selected( $type, 'date_multi' ); ?>><?php esc_html_e( 'Multiple dates', 'woo-product-timer' ); ?></option>
								<option value="date_range"
                                        data-show="date_range" <?php selected( $type, 'date_range' ); ?>><?php esc_html_e( 'Date range', 'woo-product-timer' ); ?></option>
								<option value="date_even"
                                        data-show="none" <?php selected( $type, 'date_even' ); ?>><?php esc_html_e( 'All even dates', 'woo-product-timer' ); ?></option>
								<option value="date_odd"
                                        data-show="none" <?php selected( $type, 'date_odd' ); ?>><?php esc_html_e( 'All odd dates', 'woo-product-timer' ); ?></option>
								<option value="time_range"
                                        data-show="time_range" <?php selected( $type, 'time_range' ); ?>><?php esc_html_e( 'Daily time range', 'woo-product-timer' ); ?></option>
                                <option value="time_before"
                                        data-show="time" <?php selected( $type, 'time_before' ); ?>><?php esc_html_e( 'Daily before time', 'woo-product-timer' ); ?></option>
								<option value="time_after"
                                        data-show="time" <?php selected( $type, 'time_after' ); ?>><?php esc_html_e( 'Daily after time', 'woo-product-timer' ); ?></option>
                                <option value="weekly_every"
                                        data-show="weekday" <?php selected( $type, 'weekly_every' ); ?>><?php esc_html_e( 'Weekly on every', 'woo-product-timer' ); ?></option>
                                <option value="week_even"
                                        data-show="none" <?php selected( $type, 'week_even' ); ?>><?php esc_html_e( 'All even weeks', 'woo-product-timer' ); ?></option>
								<option value="week_odd"
                                        data-show="none" <?php selected( $type, 'week_odd' ); ?>><?php esc_html_e( 'All odd weeks', 'woo-product-timer' ); ?></option>
                                <option value="week_no"
                                        data-show="weekno" <?php selected( $type, 'week_no' ); ?>><?php esc_html_e( 'On week No.', 'woo-product-timer' ); ?></option>
                                <option value="monthly_every"
                                        data-show="monthday" <?php selected( $type, 'monthly_every' ); ?>><?php esc_html_e( 'Monthly on the', 'woo-product-timer' ); ?></option>
                                <option value="month_no"
                                        data-show="monthno" <?php selected( $type, 'month_no' ); ?>><?php esc_html_e( 'On month No.', 'woo-product-timer' ); ?></option>
                                <option value="days_less_published"
                                        data-show="number" <?php selected( $type, 'days_less_published' ); ?>><?php esc_html_e( 'Days of being published are smaller than', 'woo-product-timer' ); ?></option>
                                <option value="days_greater_published"
                                        data-show="number" <?php selected( $type, 'days_greater_published' ); ?>><?php esc_html_e( 'Days of being published are bigger than', 'woo-product-timer' ); ?></option>
                                <option value="every_day"
                                        data-show="none" <?php selected( $type, 'every_day' ); ?>><?php esc_html_e( 'Everyday', 'woo-product-timer' ); ?></option>
							</select>
						</span> <span class="woopt_hide woopt_show_if_date_time">
							<input value="<?php echo esc_attr( $date_time ); ?>"
                                   class="woopt_dpk_date_time woopt_date_time_input woopt_input_300" type="text" readonly="readonly"/>
						</span> <span class="woopt_hide woopt_show_if_date">
							<input value="<?php echo esc_attr( $date ); ?>" class="woopt_dpk_date woopt_date_input woopt_input_300"
                                   type="text" readonly="readonly"/>
						</span> <span class="woopt_hide woopt_show_if_date_range">
							<input value="<?php echo esc_attr( $date_range ); ?>"
                                   class="woopt_dpk_date_range woopt_date_input woopt_input_300" type="text" readonly="readonly"/>
						</span> <span class="woopt_hide woopt_show_if_date_multi">
							<input value="<?php echo esc_attr( $date_multi ); ?>"
                                   class="woopt_dpk_date_multi woopt_date_input woopt_input_300" type="text" readonly="readonly"/>
						</span> <span class="woopt_hide woopt_show_if_time_range">
							<input value="<?php echo esc_attr( $from ); ?>"
                                   class="woopt_dpk_time woopt_time_from woopt_time_input woopt_input_300" type="text"
                                   readonly="readonly" placeholder="from"/>
							<input value="<?php echo esc_attr( $to ); ?>"
                                   class="woopt_dpk_time woopt_time_to woopt_time_input woopt_input_300" type="text" readonly="readonly"
                                   placeholder="to"/>
						</span> <span class="woopt_hide woopt_show_if_time">
							<input value="<?php echo esc_attr( $time ); ?>"
                                   class="woopt_dpk_time woopt_time_on woopt_time_input woopt_input_300" type="text" readonly="readonly"/>
						</span> <span class="woopt_hide woopt_show_if_weekday">
							<select class="woopt_weekday">
                                <option value="mon" <?php selected( $weekday, 'mon' ); ?>><?php esc_html_e( 'Monday', 'woo-product-timer' ); ?></option>
                                <option value="tue" <?php selected( $weekday, 'tue' ); ?>><?php esc_html_e( 'Tuesday', 'woo-product-timer' ); ?></option>
                                <option value="wed" <?php selected( $weekday, 'wed' ); ?>><?php esc_html_e( 'Wednesday', 'woo-product-timer' ); ?></option>
                                <option value="thu" <?php selected( $weekday, 'thu' ); ?>><?php esc_html_e( 'Thursday', 'woo-product-timer' ); ?></option>
                                <option value="fri" <?php selected( $weekday, 'fri' ); ?>><?php esc_html_e( 'Friday', 'woo-product-timer' ); ?></option>
                                <option value="sat" <?php selected( $weekday, 'sat' ); ?>><?php esc_html_e( 'Saturday', 'woo-product-timer' ); ?></option>
                                <option value="sun" <?php selected( $weekday, 'sun' ); ?>><?php esc_html_e( 'Sunday', 'woo-product-timer' ); ?></option>
                            </select>
						</span> <span class="woopt_hide woopt_show_if_monthday">
							<select class="woopt_monthday">
                                <?php for ( $i = 1; $i < 32; $i ++ ) {
                                    echo '<option value="' . esc_attr( $i ) . '" ' . ( (int) $monthday === $i ? 'selected' : '' ) . '>' . absint( $i ) . '</option>';
                                } ?>
                            </select>
						</span> <span class="woopt_hide woopt_show_if_weekno">
							<select class="woopt_weekno">
                                <?php
                                for ( $i = 1; $i < 54; $i ++ ) {
                                    echo '<option value="' . esc_attr( $i ) . '" ' . ( (int) $weekno === $i ? 'selected' : '' ) . '>' . absint( $i ) . '</option>';
                                }
                                ?>
                            </select>
						</span> <span class="woopt_hide woopt_show_if_monthno">
							<select class="woopt_monthno">
                                <?php
                                for ( $i = 1; $i < 13; $i ++ ) {
                                    echo '<option value="' . esc_attr( $i ) . '" ' . ( (int) $monthno === $i ? 'selected' : '' ) . '>' . absint( $i ) . '</option>';
                                }
                                ?>
                            </select>
						</span> <span class="woopt_hide woopt_show_if_number">
							<input type="number" step="1" min="0" class="woopt_number"
                                   value="<?php echo esc_attr( (int) $number ); ?>"/>
						</span>
                    </div>
                    <?php
                }

                function ajax_save_actions() {
                    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'woopt-security' ) ) {
                        die( 'Permissions check failed!' );
                    }

                    $pid       = isset( $_POST['pid'] ) ? absint( wp_unslash( $_POST['pid'] ?? '' ) ) : 0;
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $form_data = isset( $_POST['form_data'] ) ? wp_unslash( $_POST['form_data'] ?? '' ) : '';

                    if ( $pid && $form_data ) {
                        $actions = [];
                        parse_str( $form_data, $actions );

                        if ( isset( $actions['woopt_actions'] ) ) {
                            update_post_meta( $pid, 'woopt_actions', self::sanitize_array( map_deep( $actions['woopt_actions'], 'sanitize_text_field' ) ) );
                        }
                    }

                    wp_die();
                }

                function ajax_add_time() {
                    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'woopt-security' ) ) {
                        die( 'Permissions check failed!' );
                    }

                    $key = ! empty( $_POST['key'] ) && ! is_numeric( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ?? '' ) ) : self::generate_key();

                    self::time( $key );
                    wp_die();
                }

                function ajax_add_apply_combination() {
                    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'woopt-security' ) ) {
                        die( 'Permissions check failed!' );
                    }

                    $key = ! empty( $_POST['key'] ) && ! is_numeric( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ?? '' ) ) : self::generate_key();

                    self::apply_combination( $key );
                    wp_die();
                }

                function ajax_search_term() {
                    if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['nonce'] ) ), 'woopt-security' ) ) {
                        die( 'Permissions check failed!' );
                    }

                    $return = [];
                    $args   = [
                            'taxonomy'   => sanitize_text_field( wp_unslash( $_REQUEST['taxonomy'] ?? '' ) ),
                            'orderby'    => 'id',
                            'order'      => 'ASC',
                            'hide_empty' => false,
                            'fields'     => 'all',
                            'name__like' => sanitize_text_field( wp_unslash( $_REQUEST['q'] ?? '' ) ),
                    ];
                    $terms  = get_terms( $args );

                    if ( count( $terms ) ) {
                        foreach ( $terms as $term ) {
                            $return[] = [ $term->slug, $term->name ];
                        }
                    }

                    wp_send_json( $return );
                    wp_die();
                }

                function process_product_meta( $post_id ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    if ( isset( $_POST['woopt_actions'] ) && is_array( $_POST['woopt_actions'] ) ) {
                        update_post_meta( $post_id, 'woopt_actions', self::sanitize_array( map_deep( wp_unslash( $_POST['woopt_actions'] ?? [] ), 'sanitize_text_field' ) ) );
                    } else {
                        delete_post_meta( $post_id, 'woopt_actions' );
                    }
                }

                function product_columns( $columns ) {
                    $columns['woopt'] = esc_html__( 'Timer', 'woo-product-timer' );

                    return $columns;
                }

                function custom_column( $column, $postid ) {
                    if ( $column === 'woopt' ) {
                        echo '<div class="woopt-icons">';

                        // global actions
                        if ( is_array( self::$global_actions ) && ( count( self::$global_actions ) > 0 ) ) {
                            $global  = false;
                            $running = false;

                            foreach ( self::$global_actions as $global_action ) {
                                $action_data      = self::woopt_action_data( $global_action );
                                $action_apply     = $action_data['apply'];
                                $action_apply_val = $action_data['apply_val'];
                                $action_timer     = $action_data['timer'];

                                if ( self::woopt_check_apply( $postid, $action_apply, $action_apply_val ) ) {
                                    $global = true;

                                    if ( ! empty( $action_timer ) && self::woopt_check_timer( $action_timer, $postid ) ) {
                                        $running = true;
                                    }
                                }
                            }

                            if ( $global ) {
                                if ( $running ) {
                                    echo '<span class="woopt-icon woopt-icon-global running"><span class="dashicons dashicons-admin-site"></span></span>';
                                } else {
                                    echo '<span class="woopt-icon woopt-icon-global"><span class="dashicons dashicons-admin-site"></span></span>';
                                }
                            }
                        }

                        $actions = get_post_meta( $postid, 'woopt_actions', true );

                        if ( is_array( $actions ) && ( count( $actions ) > 0 ) ) {
                            $running = false;

                            foreach ( $actions as $action ) {
                                $action_data = self::woopt_action_data( $action );

                                if ( ! empty( $action_data['timer'] ) && self::woopt_check_timer( $action_data['timer'], $postid ) ) {
                                    $running = true;
                                }
                            }

                            if ( $running ) {
                                echo '<span class="woopt-icon running"><span class="dashicons dashicons-clock"></span></span>';
                            } else {
                                echo '<span class="woopt-icon"><span class="dashicons dashicons-clock"></span></span>';
                            }
                        }

                        echo '</div>';

                        // edit button
                        echo '<a href="#" class="woopt_edit" data-pid="' . esc_attr( $postid ) . '" data-name="' . esc_attr( get_the_title( $postid ) ) . '"><span class="dashicons dashicons-edit"></span></a>';
                    }
                }

                function ajax_edit_timer() {
                    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'woopt-security' ) ) {
                        die( 'Permissions check failed!' );
                    }

                    $product_id = absint( wp_unslash( $_POST['pid'] ?? '' ) );

                    if ( $product_id ) {
                        $actions = get_post_meta( $product_id, 'woopt_actions', true );
                        echo '<textarea class="woopt_edit_data">' . esc_textarea( ! empty( $actions ) ? wp_json_encode( $actions, JSON_PRETTY_PRINT ) : '' ) . '</textarea>';
                        echo '<div class="woopt_edit_actions_row"><button class="button button-primary woopt_edit_save" data-pid="' . esc_attr( $product_id ) . '">' . esc_html__( 'Update', 'woo-product-timer' ) . '</button>';
                        echo '<span class="woopt_edit_message"></span></div>';
                    }

                    wp_die();
                }

                function ajax_save_timer() {
                    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'woopt-security' ) ) {
                        die( 'Permissions check failed!' );
                    }

                    $product_id = absint( wp_unslash( $_POST['pid'] ?? '' ) );
                    $actions    = trim( sanitize_textarea_field( wp_unslash( $_POST['actions'] ?? '' ) ) );

                    if ( empty( $actions ) ) {
                        delete_post_meta( $product_id, 'woopt_actions' );
                        esc_html_e( 'Timer was removed!', 'woo-product-timer' );
                    } else {
                        $actions = json_decode( stripcslashes( $actions ), true );

                        if ( $actions !== null ) {
                            update_post_meta( $product_id, 'woopt_actions', $actions );
                            esc_html_e( 'Updated successfully!', 'woo-product-timer' );
                        } else {
                            esc_html_e( 'Have an error!', 'woo-product-timer' );
                        }
                    }

                    wp_die();
                }

                function export_columns( $columns ) {
                    $columns['woopt_actions'] = esc_html__( 'Timer', 'woo-product-timer' );

                    return $columns;
                }

                function export_data( $value, $product ) {
                    $value = get_post_meta( $product->get_id(), 'woopt_actions', true );

                    if ( ! empty( $value ) ) {
                        return json_encode( $value );
                    } else {
                        return '';
                    }
                }

                function import_options( $options ) {
                    $options['woopt_actions'] = esc_html__( 'Timer', 'woo-product-timer' );

                    return $options;
                }

                function import_columns( $columns ) {
                    $columns['Timer']         = 'woopt_actions';
                    $columns['timer']         = 'woopt_actions';
                    $columns['woopt actions'] = 'woopt_actions';

                    return $columns;
                }

                function import_process( $object, $data ) {
                    if ( ! empty( $data['woopt_actions'] ) ) {
                        $object->update_meta_data( 'woopt_actions', json_decode( html_entity_decode( stripcslashes( $data['woopt_actions'] ) ) ) );
                    }

                    return $object;
                }

                public static function sanitize_array( $arr ) {
                    foreach ( (array) $arr as $k => $v ) {
                        if ( is_array( $v ) ) {
                            $arr[ $k ] = self::sanitize_array( $v );
                        } else {
                            $arr[ $k ] = sanitize_post_field( 'post_content', $v, 0, 'db' );
                        }
                    }

                    return $arr;
                }

                public static function format_price( $price ) {
                    // format price to percentage or amount
                    $price = preg_replace( '/[^.%0-9]/', '', $price );

                    return apply_filters( 'woopt_format_price', $price );
                }

                public static function generate_key() {
                    $key         = '';
                    $key_str     = apply_filters( 'woopt_key_characters', 'abcdefghijklmnopqrstuvwxyz0123456789' );
                    $key_str_len = strlen( $key_str );

                    for ( $i = 0; $i < apply_filters( 'woopt_key_length', 4 ); $i ++ ) {
                        $key .= $key_str[ random_int( 0, $key_str_len - 1 ) ];
                    }

                    if ( is_numeric( $key ) ) {
                        $key = self::generate_key();
                    }

                    return apply_filters( 'woopt_generate_key', $key );
                }
                public static function simulator() {
                    global $wp_roles;
                    ?>
                    <div class="woopt-card">
                        <div class="woopt-card-header">
                            <div>
                                <h2 class="woopt-card-title">
                                    <span class="dashicons dashicons-controls-play"></span>
                                    <?php esc_html_e( 'Timer Simulator', 'woo-product-timer' ); ?>
                                </h2>
                                <p class="woopt-card-desc">
                                    <?php esc_html_e( 'Select a product and specific date & time to test and see which timers (Product or Global) are currently active and affecting it.', 'woo-product-timer' ); ?>
                                </p>
                            </div>
                        </div>

                        <div class="woopt-sim-grid" id="woopt-simulator-form">
                            <div class="woopt-sim-section">
                                <h3 class="woopt-sim-section-title">
                                    <span class="dashicons dashicons-products"></span>
                                    <?php esc_html_e( 'Target Product', 'woo-product-timer' ); ?>
                                </h3>
                                <div class="woopt-sim-row woopt-sim-row-product">
                                    <label for="woopt-sim-product"><?php esc_html_e( 'Product', 'woo-product-timer' ); ?></label>
                                    <select id="woopt-sim-product" class="wc-product-search"
                                            data-placeholder="<?php esc_attr_e( 'Search for a product or variation...', 'woo-product-timer' ); ?>"
                                            data-action="woocommerce_json_search_products_and_variations">
                                    </select>
                                </div>
                                <div class="woopt-sim-row">
                                    <label for="woopt-sim-role"><?php esc_html_e( 'User role', 'woo-product-timer' ); ?></label>
                                    <select id="woopt-sim-role">
                                        <option value="woopt_all"><?php esc_html_e( 'All (Default / Any visitor)', 'woo-product-timer' ); ?></option>
                                        <option value="woopt_guest"><?php esc_html_e( 'Guest (not logged in)', 'woo-product-timer' ); ?></option>
                                        <option value="woopt_user"><?php esc_html_e( 'Logged-in user (Any role)', 'woo-product-timer' ); ?></option>
                                        <?php
                                        if ( ! empty( $wp_roles->roles ) ) {
                                            foreach ( $wp_roles->roles as $role => $details ) {
                                                echo '<option value="' . esc_attr( $role ) . '">' . esc_html( $details['name'] ) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="woopt-sim-section">
                                <h3 class="woopt-sim-section-title">
                                    <span class="dashicons dashicons-clock"></span>
                                    <?php esc_html_e( 'Simulated Date & Time', 'woo-product-timer' ); ?>
                                </h3>
                                <div class="woopt-sim-row">
                                    <label for="woopt-sim-datetime"><?php esc_html_e( 'Date & Time', 'woo-product-timer' ); ?></label>
                                    <div class="woopt-sim-datetime-wrap">
                                        <input type="text" id="woopt-sim-datetime" class="woopt_date_time_input"
                                               value="<?php echo esc_attr( current_time( 'm/d/Y h:i a' ) ); ?>"
                                               placeholder="MM/DD/YYYY HH:MM AM/PM" />
                                        <button type="button" id="woopt-sim-now-btn" class="woopt-action-tool-btn" title="<?php esc_attr_e( 'Set to current server time', 'woo-product-timer' ); ?>">
                                            <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Now', 'woo-product-timer' ); ?>
                                        </button>
                                    </div>
                                </div>
                                <div class="woopt-sim-row">
                                    <label><?php esc_html_e( 'Server Current Time', 'woo-product-timer' ); ?></label>
                                    <div class="woopt-current-time-badges">
                                        <code><?php echo esc_html( current_time( 'm/d/Y h:i a' ) ); ?></code>
                                        <code><?php echo esc_html( current_time( 'l' ) ); ?></code>
                                        <code><?php echo esc_html__( 'Week No.', 'woo-product-timer' ) . ' ' . esc_html( current_time( 'W' ) ); ?></code>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="woopt-sim-actions">
                            <button type="button" id="woopt-sim-run" class="button button-primary">
                                <span class="dashicons dashicons-controls-play"></span>
                                <?php esc_html_e( 'Run Simulation', 'woo-product-timer' ); ?>
                            </button>
                            <button type="button" id="woopt-sim-reset" class="button">
                                <?php esc_html_e( 'Reset', 'woo-product-timer' ); ?>
                            </button>
                            <span id="woopt-sim-spinner" class="spinner"></span>
                        </div>

                        <div id="woopt-sim-results" class="woopt-sim-results woopt_hide"></div>
                    </div>
                    <?php
                }

                public function ajax_simulate() {
                    check_ajax_referer( 'woopt-security', 'nonce' );

                    if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized permission.', 'woo-product-timer' ) ] );
                    }

                    $product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                    $datetime_str = isset( $_POST['datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['datetime'] ) ) : '';
                    $user_role    = isset( $_POST['user_role'] ) ? sanitize_text_field( wp_unslash( $_POST['user_role'] ) ) : 'woopt_all';

                    if ( ! $product_id ) {
                        wp_send_json_error( [ 'message' => esc_html__( 'Please select a valid product.', 'woo-product-timer' ) ] );
                    }

                    $product = wc_get_product( $product_id );
                    if ( ! $product ) {
                        wp_send_json_error( [ 'message' => esc_html__( 'Product not found.', 'woo-product-timer' ) ] );
                    }

                    $timestamp = $datetime_str ? strtotime( $datetime_str ) : current_time( 'U' );
                    if ( ! $timestamp ) {
                        $timestamp = current_time( 'U' );
                    }

                    $sim_now = [
                        'date'      => date_i18n( 'm/d/Y', $timestamp ),
                        'datetime'  => date_i18n( 'm/d/Y h:i a', $timestamp ),
                        'day'       => (int) date_i18n( 'd', $timestamp ),
                        'weekday'   => strtolower( date_i18n( 'D', $timestamp ) ),
                        'week'      => (int) date_i18n( 'W', $timestamp ),
                        'monthday'  => strtolower( date_i18n( 'j', $timestamp ) ),
                        'month'     => (int) date_i18n( 'm', $timestamp ),
                        'timestamp' => $timestamp,
                    ];

                    $is_variation = $product->is_type( 'variation' );
                    $parent_id    = $is_variation ? $product->get_parent_id() : 0;
                    $product_name = $product->get_name();
                    $product_sku  = $product->get_sku() ?: '—';
                    $product_type = $product->get_type();
                    $edit_url     = get_edit_post_link( $is_variation ? $parent_id : $product_id );
                    $image_id     = $product->get_image_id();
                    if ( ! $image_id && $is_variation ) {
                        $parent_prod = wc_get_product( $parent_id );
                        if ( $parent_prod ) {
                            $image_id = $parent_prod->get_image_id();
                        }
                    }
                    $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );

                    // Original product values
                    $orig_stock_status   = $product->get_stock_status();
                    $orig_regular_price  = $product->get_regular_price( 'edit' );
                    $orig_sale_price     = $product->get_sale_price( 'edit' );
                    $orig_price          = $product->get_price( 'edit' );
                    $orig_is_purchasable = $product->is_purchasable();
                    $orig_is_featured    = $product->is_featured();
                    $orig_is_onsale      = $product->is_on_sale( 'edit' );
                    $orig_is_individually= $product->is_sold_individually();
                    $orig_visibility     = $product->get_catalog_visibility();

                    // Feature maps for actions
                    $action_feature_map = [
                        'set_instock'               => 'stock',
                        'set_outofstock'            => 'stock',
                        'set_visible'               => 'visibility',
                        'set_hidden'                => 'visibility',
                        'set_catalog_visibility'    => 'visibility',
                        'set_featured'              => 'featured',
                        'set_unfeatured'            => 'featured',
                        'set_purchasable'           => 'purchasable',
                        'set_unpurchasable'         => 'purchasable',
                        'set_regularprice'          => 'regular_price',
                        'set_saleprice'             => 'sale_price',
                        'enable_onsale'             => 'onsale',
                        'disable_onsale'            => 'onsale',
                        'enable_sold_individually'  => 'sold_individually',
                        'disable_sold_individually' => 'sold_individually',
                    ];

                    $eval_global_actions  = [];
                    $eval_product_actions = [];

                    // Evaluate Global Actions
                    if ( is_array( self::$global_actions ) && count( self::$global_actions ) > 0 ) {
                        foreach ( self::$global_actions as $key => $global_action ) {
                            $action_data      = self::woopt_action_data( $global_action );
                            $action_key       = $action_data['action'];
                            $action_apply     = $action_data['apply'];
                            $action_apply_val = $action_data['apply_val'];
                            $action_timer     = $action_data['timer'];
                            $action_roles     = $action_data['roles'];
                            $action_name      = ! empty( $global_action['name'] ) ? $global_action['name'] : ( '#' . ( is_numeric( $key ) ? ( (int) $key + 1 ) : $key ) );

                            if ( empty( $action_key ) || ! isset( $action_feature_map[ $action_key ] ) ) {
                                continue;
                            }

                            $feat = $action_feature_map[ $action_key ];
                            $feature_enabled = true;
                            if ( ! empty( self::$features ) ) {
                                $feat_toggle_key = $feat;
                                if ( $feat === 'regular_price' || $feat === 'sale_price' ) {
                                    $feat_toggle_key = 'price';
                                } elseif ( $feat === 'sold_individually' ) {
                                    $feat_toggle_key = 'individually';
                                }
                                if ( ! in_array( $feat_toggle_key, self::$features, true ) ) {
                                    $feature_enabled = false;
                                }
                            }

                            // Check apply
                            $match_apply = false;
                            if ( $is_variation ) {
                                if ( $action_apply === 'apply_product' || $action_apply === 'apply_combination' ) {
                                    $match_apply = self::woopt_check_apply( $product_id, $action_apply, $action_apply_val, true );
                                }
                                if ( ! $match_apply ) {
                                    $match_apply = self::woopt_check_apply( $parent_id, $action_apply, $action_apply_val );
                                }
                            } else {
                                $match_apply = self::woopt_check_apply( $product_id, $action_apply, $action_apply_val );
                            }

                            // Check timer
                            $match_timer = self::woopt_check_timer( $action_timer, $product_id, $sim_now );

                            // Check roles
                            $match_roles = self::woopt_check_roles( $action_roles, $user_role );

                            $is_matched = $match_apply && $match_timer && $match_roles;

                            $eval_global_actions[ $key ] = [
                                'key'             => $key,
                                'source'          => 'global',
                                'source_label'    => esc_html__( 'Global Timer', 'woo-product-timer' ),
                                'name'            => $action_name,
                                'action'          => $action_key,
                                'feature'         => $feat,
                                'label'           => self::format_action_label( $action_key, $action_data['action_val'] ),
                                'apply_summary'   => self::format_apply_summary( $action_apply, $action_apply_val ),
                                'timer_summary'   => self::format_timer_summary( $action_timer ),
                                'roles_summary'   => self::format_roles_summary( $action_roles ),
                                'feature_enabled' => $feature_enabled,
                                'match_apply'     => $match_apply,
                                'match_timer'     => $match_timer,
                                'match_roles'     => $match_roles,
                                'is_matched'      => $is_matched,
                                'status'          => 'inactive',
                                'status_label'    => '',
                                'status_reason'   => '',
                                'action_data'     => $action_data,
                            ];
                        }
                    }

                    // Evaluate Product Actions
                    $raw_product_actions = self::get_product_actions_meta( $is_variation ? $parent_id : $product_id );
                    if ( is_array( $raw_product_actions ) && count( $raw_product_actions ) > 0 ) {
                        foreach ( $raw_product_actions as $key => $prod_action ) {
                            $action_data  = self::woopt_action_data( $prod_action );
                            $action_key   = $action_data['action'];
                            $action_timer = $action_data['timer'];
                            $action_roles = $action_data['roles'];
                            $action_name  = ! empty( $prod_action['name'] ) ? $prod_action['name'] : ( '#' . ( is_numeric( $key ) ? ( (int) $key + 1 ) : $key ) );

                            if ( empty( $action_key ) || ! isset( $action_feature_map[ $action_key ] ) ) {
                                continue;
                            }

                            $feat = $action_feature_map[ $action_key ];
                            $feature_enabled = true;
                            if ( ! empty( self::$features ) ) {
                                $feat_toggle_key = $feat;
                                if ( $feat === 'regular_price' || $feat === 'sale_price' ) {
                                    $feat_toggle_key = 'price';
                                } elseif ( $feat === 'sold_individually' ) {
                                    $feat_toggle_key = 'individually';
                                }
                                if ( ! in_array( $feat_toggle_key, self::$features, true ) ) {
                                    $feature_enabled = false;
                                }
                            }

                            $match_apply = true; // Product timers apply directly to this product
                            $match_timer = self::woopt_check_timer( $action_timer, $product_id, $sim_now );
                            $match_roles = self::woopt_check_roles( $action_roles, $user_role );

                            $is_matched = $match_timer && $match_roles && $feature_enabled;

                            $eval_product_actions[ $key ] = [
                                'key'             => $key,
                                'source'          => 'product',
                                'source_label'    => esc_html__( 'Product Timer', 'woo-product-timer' ),
                                'name'            => $action_name,
                                'action'          => $action_key,
                                'feature'         => $feat,
                                'label'           => self::format_action_label( $action_key, $action_data['action_val'] ),
                                'apply_summary'   => esc_html__( 'This product', 'woo-product-timer' ),
                                'timer_summary'   => self::format_timer_summary( $action_timer ),
                                'roles_summary'   => self::format_roles_summary( $action_roles ),
                                'feature_enabled' => $feature_enabled,
                                'match_apply'     => true,
                                'match_timer'     => $match_timer,
                                'match_roles'     => $match_roles,
                                'is_matched'      => $is_matched,
                                'status'          => 'inactive',
                                'status_label'    => '',
                                'status_reason'   => '',
                                'action_data'     => $action_data,
                            ];
                        }
                    }

                    // Determine winning actions per feature
                    $winning_global_actions  = [];
                    $winning_product_actions = [];
                    $final_active_actions    = [];

                    // 1. Resolve Global Actions winners (reverse iterate: last match wins)
                    $reversed_global_keys = array_reverse( array_keys( $eval_global_actions ) );
                    foreach ( $reversed_global_keys as $k ) {
                        $item = $eval_global_actions[ $k ];
                        if ( $item['is_matched'] ) {
                            $f = $item['feature'];
                            if ( ! isset( $winning_global_actions[ $f ] ) ) {
                                $winning_global_actions[ $f ] = $k;
                            } else {
                                $eval_global_actions[ $k ]['status']        = 'overridden';
                                $eval_global_actions[ $k ]['status_label']  = esc_html__( 'Overridden', 'woo-product-timer' );
                                $eval_global_actions[ $k ]['status_reason'] = sprintf( esc_html__( 'Overridden by Global Action %s', 'woo-product-timer' ), $eval_global_actions[ $winning_global_actions[ $f ] ]['name'] );
                            }
                        } else {
                            if ( ! $item['feature_enabled'] ) {
                                $eval_global_actions[ $k ]['status']        = 'feature_disabled';
                                $eval_global_actions[ $k ]['status_label']  = esc_html__( 'Feature disabled', 'woo-product-timer' );
                                $eval_global_actions[ $k ]['status_reason'] = esc_html__( 'Feature is disabled in General Settings', 'woo-product-timer' );
                            } elseif ( ! $item['match_apply'] ) {
                                $eval_global_actions[ $k ]['status']        = 'not_applicable';
                                $eval_global_actions[ $k ]['status_label']  = esc_html__( 'Not applicable', 'woo-product-timer' );
                                $eval_global_actions[ $k ]['status_reason'] = esc_html__( 'Does not target this product', 'woo-product-timer' );
                            } elseif ( ! $item['match_timer'] ) {
                                $eval_global_actions[ $k ]['status']        = 'time_mismatch';
                                $eval_global_actions[ $k ]['status_label']  = esc_html__( 'Time not matched', 'woo-product-timer' );
                                $eval_global_actions[ $k ]['status_reason'] = esc_html__( 'Current simulated time does not fall into time condition', 'woo-product-timer' );
                            } elseif ( ! $item['match_roles'] ) {
                                $eval_global_actions[ $k ]['status']        = 'role_mismatch';
                                $eval_global_actions[ $k ]['status_label']  = esc_html__( 'Role not matched', 'woo-product-timer' );
                                $eval_global_actions[ $k ]['status_reason'] = esc_html__( 'Selected user role does not match condition', 'woo-product-timer' );
                            }
                        }
                    }

                    // 2. Resolve Product Actions winners (reverse iterate: last match wins)
                    $reversed_product_keys = array_reverse( array_keys( $eval_product_actions ) );
                    foreach ( $reversed_product_keys as $k ) {
                        $item = $eval_product_actions[ $k ];
                        if ( $item['is_matched'] ) {
                            $f = $item['feature'];
                            if ( ! isset( $winning_product_actions[ $f ] ) ) {
                                $winning_product_actions[ $f ] = $k;
                            } else {
                                $eval_product_actions[ $k ]['status']        = 'overridden';
                                $eval_product_actions[ $k ]['status_label']  = esc_html__( 'Overridden', 'woo-product-timer' );
                                $eval_product_actions[ $k ]['status_reason'] = sprintf( esc_html__( 'Overridden by Product Action %s', 'woo-product-timer' ), $eval_product_actions[ $winning_product_actions[ $f ] ]['name'] );
                            }
                        } else {
                            if ( ! $item['feature_enabled'] ) {
                                $eval_product_actions[ $k ]['status']        = 'feature_disabled';
                                $eval_product_actions[ $k ]['status_label']  = esc_html__( 'Feature disabled', 'woo-product-timer' );
                                $eval_product_actions[ $k ]['status_reason'] = esc_html__( 'Feature is disabled in General Settings', 'woo-product-timer' );
                            } elseif ( ! $item['match_timer'] ) {
                                $eval_product_actions[ $k ]['status']        = 'time_mismatch';
                                $eval_product_actions[ $k ]['status_label']  = esc_html__( 'Time not matched', 'woo-product-timer' );
                                $eval_product_actions[ $k ]['status_reason'] = esc_html__( 'Current simulated time does not fall into time condition', 'woo-product-timer' );
                            } elseif ( ! $item['match_roles'] ) {
                                $eval_product_actions[ $k ]['status']        = 'role_mismatch';
                                $eval_product_actions[ $k ]['status_label']  = esc_html__( 'Role not matched', 'woo-product-timer' );
                                $eval_product_actions[ $k ]['status_reason'] = esc_html__( 'Selected user role does not match condition', 'woo-product-timer' );
                            }
                        }
                    }

                    // 3. Combine winners: Product Actions override Global Actions
                    $all_features = [ 'stock', 'visibility', 'featured', 'purchasable', 'regular_price', 'sale_price', 'onsale', 'sold_individually' ];
                    foreach ( $all_features as $f ) {
                        if ( isset( $winning_product_actions[ $f ] ) ) {
                            $prod_k = $winning_product_actions[ $f ];
                            $eval_product_actions[ $prod_k ]['status']        = 'applied';
                            $eval_product_actions[ $prod_k ]['status_label']  = esc_html__( 'Active & Applied', 'woo-product-timer' );
                            $eval_product_actions[ $prod_k ]['status_reason'] = esc_html__( 'Winner (Product basis timer overrides global timer)', 'woo-product-timer' );
                            $final_active_actions[ $f ] = $eval_product_actions[ $prod_k ];

                            if ( isset( $winning_global_actions[ $f ] ) ) {
                                $glob_k = $winning_global_actions[ $f ];
                                $eval_global_actions[ $glob_k ]['status']        = 'overridden';
                                $eval_global_actions[ $glob_k ]['status_label']  = esc_html__( 'Overridden by Product', 'woo-product-timer' );
                                $eval_global_actions[ $glob_k ]['status_reason'] = sprintf( esc_html__( 'Overridden by Product Action %s', 'woo-product-timer' ), $eval_product_actions[ $prod_k ]['name'] );
                            }
                        } elseif ( isset( $winning_global_actions[ $f ] ) ) {
                            $glob_k = $winning_global_actions[ $f ];
                            $eval_global_actions[ $glob_k ]['status']        = 'applied';
                            $eval_global_actions[ $glob_k ]['status_label']  = esc_html__( 'Active & Applied', 'woo-product-timer' );
                            $eval_global_actions[ $glob_k ]['status_reason'] = esc_html__( 'Winner from Global Timer', 'woo-product-timer' );
                            $final_active_actions[ $f ] = $eval_global_actions[ $glob_k ];
                        }
                    }

                    // Build Active Effects table data
                    $active_effects = [];

                    // 1. Stock
                    if ( isset( $final_active_actions['stock'] ) ) {
                        $act = $final_active_actions['stock'];
                        $new_stock = $act['action'] === 'set_instock' ? 'instock' : 'outofstock';
                        $active_effects[] = [
                            'feature'        => esc_html__( 'Stock status', 'woo-product-timer' ),
                            'original'       => $orig_stock_status === 'instock' ? esc_html__( 'In stock', 'woo-product-timer' ) : ( $orig_stock_status === 'outofstock' ? esc_html__( 'Out of stock', 'woo-product-timer' ) : esc_html__( 'On backorder', 'woo-product-timer' ) ),
                            'simulated'      => $new_stock === 'instock' ? esc_html__( 'In stock', 'woo-product-timer' ) : esc_html__( 'Out of stock', 'woo-product-timer' ),
                            'changed'        => $orig_stock_status !== $new_stock,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    // 2. Visibility
                    if ( isset( $final_active_actions['visibility'] ) ) {
                        $act = $final_active_actions['visibility'];
                        $sim_vis = $act['action'] === 'set_visible' ? 'visible' : ( $act['action'] === 'set_hidden' ? 'hidden' : 'catalog' );
                        $active_effects[] = [
                            'feature'        => esc_html__( 'Catalog visibility', 'woo-product-timer' ),
                            'original'       => ucfirst( $orig_visibility ),
                            'simulated'      => ucfirst( $sim_vis ),
                            'changed'        => $orig_visibility !== $sim_vis,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    // 3. Regular Price
                    $simulated_regular_price = $orig_regular_price;
                    if ( isset( $final_active_actions['regular_price'] ) ) {
                        $act = $final_active_actions['regular_price'];
                        $val = isset( $act['action_data']['action_val']['value'] ) ? (float) $act['action_data']['action_val']['value'] : 0;
                        $base = ! empty( $act['action_data']['action_val']['base'] ) ? $act['action_data']['action_val']['base'] : 'fa';
                        if ( $base === 'pr' || $base === 'ps' ) {
                            $calc_price = (float) $orig_regular_price * $val / 100;
                        } else {
                            $calc_price = $val;
                        }
                        $simulated_regular_price = $calc_price;
                        $active_effects[] = [
                            'feature'        => esc_html__( 'Regular price', 'woo-product-timer' ),
                            'original'       => wc_price( $orig_regular_price ),
                            'simulated'      => wc_price( $calc_price ),
                            'changed'        => (float) $orig_regular_price !== (float) $calc_price,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    // 4. Sale Price
                    if ( isset( $final_active_actions['sale_price'] ) ) {
                        $act = $final_active_actions['sale_price'];
                        $val = isset( $act['action_data']['action_val']['value'] ) ? (float) $act['action_data']['action_val']['value'] : 0;
                        $base = ! empty( $act['action_data']['action_val']['base'] ) ? $act['action_data']['action_val']['base'] : 'fa';
                        if ( $base === 'pr' ) {
                            $calc_sale = (float) $simulated_regular_price * $val / 100;
                        } elseif ( $base === 'ps' ) {
                            $calc_sale = (float) $orig_sale_price * $val / 100;
                        } else {
                            $calc_sale = $val;
                        }
                        $active_effects[] = [
                            'feature'        => esc_html__( 'Sale price', 'woo-product-timer' ),
                            'original'       => $orig_sale_price !== '' ? wc_price( $orig_sale_price ) : esc_html__( 'None', 'woo-product-timer' ),
                            'simulated'      => wc_price( $calc_sale ),
                            'changed'        => (float) $orig_sale_price !== (float) $calc_sale,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    // 5. Featured
                    if ( isset( $final_active_actions['featured'] ) ) {
                        $act = $final_active_actions['featured'];
                        $new_feat = $act['action'] === 'set_featured';
                        $active_effects[] = [
                            'feature'        => esc_html__( 'Featured product', 'woo-product-timer' ),
                            'original'       => $orig_is_featured ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'simulated'      => $new_feat ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'changed'        => $orig_is_featured !== $new_feat,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    // 6. Purchasable
                    if ( isset( $final_active_actions['purchasable'] ) ) {
                        $act = $final_active_actions['purchasable'];
                        $new_purch = $act['action'] === 'set_purchasable';
                        $active_effects[] = [
                            'feature'        => esc_html__( 'Purchasable', 'woo-product-timer' ),
                            'original'       => $orig_is_purchasable ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'simulated'      => $new_purch ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'changed'        => $orig_is_purchasable !== $new_purch,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    // 7. On Sale
                    if ( isset( $final_active_actions['onsale'] ) ) {
                        $act = $final_active_actions['onsale'];
                        $new_onsale = $act['action'] === 'enable_onsale';
                        $active_effects[] = [
                            'feature'        => esc_html__( 'On sale badge', 'woo-product-timer' ),
                            'original'       => $orig_is_onsale ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'simulated'      => $new_onsale ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'changed'        => $orig_is_onsale !== $new_onsale,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    // 8. Sold Individually
                    if ( isset( $final_active_actions['sold_individually'] ) ) {
                        $act = $final_active_actions['sold_individually'];
                        $new_indiv = $act['action'] === 'enable_sold_individually';
                        $active_effects[] = [
                            'feature'        => esc_html__( 'Sold individually', 'woo-product-timer' ),
                            'original'       => $orig_is_individually ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'simulated'      => $new_indiv ? esc_html__( 'Yes', 'woo-product-timer' ) : esc_html__( 'No', 'woo-product-timer' ),
                            'changed'        => $orig_is_individually !== $new_indiv,
                            'action_name'    => $act['name'],
                            'source'         => $act['source_label'],
                            'source_type'    => $act['source'],
                            'description'    => $act['label'],
                        ];
                    }

                    wp_send_json_success( [
                        'product'           => [
                            'id'           => $product_id,
                            'name'         => $product_name,
                            'sku'          => $product_sku,
                            'type'         => ucfirst( $product_type ),
                            'image'        => $image_url,
                            'edit_url'     => $edit_url,
                            'is_variation' => $is_variation,
                            'orig_stock'   => $orig_stock_status,
                            'orig_price'   => wc_price( $orig_price ),
                        ],
                        'simulated_time'    => [
                            'datetime'   => $sim_now['datetime'],
                            'date'       => $sim_now['date'],
                            'weekday'    => date_i18n( 'l', $timestamp ),
                            'week'       => $sim_now['week'],
                            'role'       => $user_role,
                            'role_label' => $user_role === 'woopt_all' ? esc_html__( 'All visitors', 'woo-product-timer' ) : ( $user_role === 'woopt_guest' ? esc_html__( 'Guest', 'woo-product-timer' ) : ( $user_role === 'woopt_user' ? esc_html__( 'Logged-in user', 'woo-product-timer' ) : ucfirst( $user_role ) ) ),
                        ],
                        'active_effects'    => $active_effects,
                        'evaluated_actions' => [
                            'product_actions' => array_values( $eval_product_actions ),
                            'global_actions'  => array_values( $eval_global_actions ),
                        ],
                        'summary_counts'    => [
                            'total_active'  => count( $active_effects ),
                            'total_product' => count( $eval_product_actions ),
                            'total_global'  => count( $eval_global_actions ),
                        ],
                    ] );
                }

                public static function format_action_label( $action_key, $action_val = [] ) {
                    $labels = [
                        'set_instock'               => esc_html__( 'Set in stock', 'woo-product-timer' ),
                        'set_outofstock'            => esc_html__( 'Set out of stock', 'woo-product-timer' ),
                        'set_visible'               => esc_html__( 'Set visible', 'woo-product-timer' ),
                        'set_hidden'                => esc_html__( 'Set hidden', 'woo-product-timer' ),
                        'set_catalog_visibility'    => esc_html__( 'Set catalog visibility', 'woo-product-timer' ),
                        'set_featured'              => esc_html__( 'Set featured', 'woo-product-timer' ),
                        'set_unfeatured'            => esc_html__( 'Set unfeatured', 'woo-product-timer' ),
                        'set_purchasable'           => esc_html__( 'Set purchasable', 'woo-product-timer' ),
                        'set_unpurchasable'         => esc_html__( 'Set unpurchasable', 'woo-product-timer' ),
                        'enable_onsale'             => esc_html__( 'Enable on-sale', 'woo-product-timer' ),
                        'disable_onsale'            => esc_html__( 'Disable on-sale', 'woo-product-timer' ),
                        'enable_sold_individually'  => esc_html__( 'Enable sold individually', 'woo-product-timer' ),
                        'disable_sold_individually' => esc_html__( 'Disable sold individually', 'woo-product-timer' ),
                    ];

                    if ( isset( $labels[ $action_key ] ) ) {
                        return $labels[ $action_key ];
                    }

                    if ( $action_key === 'set_regularprice' || $action_key === 'set_saleprice' ) {
                        $prefix = $action_key === 'set_regularprice' ? esc_html__( 'Set regular price', 'woo-product-timer' ) : esc_html__( 'Set sale price', 'woo-product-timer' );
                        $val    = isset( $action_val['value'] ) ? $action_val['value'] : '';
                        $base   = ! empty( $action_val['base'] ) ? $action_val['base'] : 'fa';
                        $unit   = ( $base === 'pr' || $base === 'ps' ) ? '%' : ' ' . get_woocommerce_currency_symbol();

                        return $prefix . ': ' . $val . $unit;
                    }

                    return $action_key;
                }

                public static function format_apply_summary( $apply, $apply_val = [] ) {
                    switch ( $apply ) {
                        case 'apply_all':
                            return esc_html__( 'All products', 'woo-product-timer' );
                        case 'apply_variation':
                            return esc_html__( 'Variations only', 'woo-product-timer' );
                        case 'apply_not_variation':
                            return esc_html__( 'Non-variation products', 'woo-product-timer' );
                        case 'apply_product':
                            $count = ! empty( $apply_val['products'] ) ? count( (array) $apply_val['products'] ) : 0;

                            return sprintf( esc_html__( 'Selected products (%d)', 'woo-product-timer' ), $count );
                        case 'apply_combination':
                            return esc_html__( 'Combined conditions', 'woo-product-timer' );
                        default:
                            if ( strpos( $apply, 'apply_' ) === 0 ) {
                                $tax      = substr( $apply, 6 );
                                $tax_obj  = get_taxonomy( $tax );
                                $tax_name = $tax_obj ? $tax_obj->labels->name : $tax;
                                $terms    = ! empty( $apply_val['terms'] ) ? (array) $apply_val['terms'] : [];

                                return $tax_name . ' (' . implode( ', ', array_slice( $terms, 0, 3 ) ) . ( count( $terms ) > 3 ? '...' : '' ) . ')';
                            }

                            return $apply;
                    }
                }

                public static function format_timer_summary( $timer ) {
                    if ( empty( $timer ) || ! is_array( $timer ) ) {
                        return esc_html__( 'Always / No time conditions', 'woo-product-timer' );
                    }

                    $parts = [];
                    foreach ( $timer as $time ) {
                        $type = isset( $time['type'] ) ? $time['type'] : '';
                        $val  = isset( $time['val'] ) ? $time['val'] : '';

                        switch ( $type ) {
                            case 'every_day':
                                $parts[] = esc_html__( 'Everyday', 'woo-product-timer' );
                                break;
                            case 'date_even':
                                $parts[] = esc_html__( 'All even dates', 'woo-product-timer' );
                                break;
                            case 'date_odd':
                                $parts[] = esc_html__( 'All odd dates', 'woo-product-timer' );
                                break;
                            case 'week_even':
                                $parts[] = esc_html__( 'All even weeks', 'woo-product-timer' );
                                break;
                            case 'week_odd':
                                $parts[] = esc_html__( 'All odd weeks', 'woo-product-timer' );
                                break;
                            case 'week_no':
                                $parts[] = sprintf( esc_html__( 'On week No. %s', 'woo-product-timer' ), $val );
                                break;
                            case 'monthly_every':
                                $parts[] = sprintf( esc_html__( 'Monthly on day %s', 'woo-product-timer' ), $val );
                                break;
                            case 'month_no':
                                $parts[] = sprintf( esc_html__( 'On month No. %s', 'woo-product-timer' ), $val );
                                break;
                            case 'date_on':
                                $parts[] = sprintf( esc_html__( 'Date: %s', 'woo-product-timer' ), $val );
                                break;
                            case 'date_before':
                                $parts[] = sprintf( esc_html__( 'Before date %s', 'woo-product-timer' ), $val );
                                break;
                            case 'date_after':
                                $parts[] = sprintf( esc_html__( 'After date %s', 'woo-product-timer' ), $val );
                                break;
                            case 'date_multi':
                                $parts[] = sprintf( esc_html__( 'Multiple dates: %s', 'woo-product-timer' ), $val );
                                break;
                            case 'date_range':
                                $parts[] = sprintf( esc_html__( 'Date range: %s', 'woo-product-timer' ), $val );
                                break;
                            case 'date_time_before':
                                $parts[] = sprintf( esc_html__( 'Before date & time %s', 'woo-product-timer' ), $val );
                                break;
                            case 'date_time_after':
                                $parts[] = sprintf( esc_html__( 'After date & time %s', 'woo-product-timer' ), $val );
                                break;
                            case 'time_range':
                                $parts[] = sprintf( esc_html__( 'Time range: %s', 'woo-product-timer' ), $val );
                                break;
                            case 'time_before':
                                $parts[] = sprintf( esc_html__( 'Before time %s', 'woo-product-timer' ), $val );
                                break;
                            case 'time_after':
                                $parts[] = sprintf( esc_html__( 'After time %s', 'woo-product-timer' ), $val );
                                break;
                            case 'weekly_every':
                                $parts[] = sprintf( esc_html__( 'Weekly on every %s', 'woo-product-timer' ), ucfirst( $val ) );
                                break;
                            case 'days_less_published':
                                $parts[] = sprintf( esc_html__( 'Days published < %s', 'woo-product-timer' ), $val );
                                break;
                            case 'days_greater_published':
                                $parts[] = sprintf( esc_html__( 'Days published > %s', 'woo-product-timer' ), $val );
                                break;
                            default:
                                $parts[] = $type . ( $val ? ( ': ' . $val ) : '' );
                                break;
                        }
                    }

                    return implode( ' | ', $parts );
                }

                public static function format_roles_summary( $roles ) {
                    if ( empty( $roles ) || in_array( 'woopt_all', (array) $roles ) || in_array( 'all', (array) $roles ) ) {
                        return esc_html__( 'All visitors', 'woo-product-timer' );
                    }

                    if ( is_string( $roles ) ) {
                        $roles = explode( ',', $roles );
                    }

                    $parts = [];
                    foreach ( (array) $roles as $r ) {
                        if ( $r === 'woopt_guest' ) {
                            $parts[] = esc_html__( 'Guest', 'woo-product-timer' );
                        } elseif ( $r === 'woopt_user' ) {
                            $parts[] = esc_html__( 'Logged-in user', 'woo-product-timer' );
                        } else {
                            $parts[] = ucfirst( $r );
                        }
                    }

                    return implode( ', ', $parts );
                }

            }

            return WPCleverWoopt::instance();
        }

        return null;
    }
}

if ( ! function_exists( 'woopt_notice_wc' ) ) {
    function woopt_notice_wc() {
        ?>
        <div class="error">
            <p><strong>WPC Product Timer</strong> require WooCommerce version 3.0 or greater.</p>
        </div>
        <?php
    }
}
