<?php
/**
 * Plugin Name: WooCommerce Stripe Order Reconciliation
 * Description: Automatically reconciles WooCommerce orders with Stripe payments when webhooks fail
 * Version: 1.0.2
 * Author: Burnaway
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * Text Domain: wc-stripe-reconciliation
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_Stripe_Reconciliation {
    
    /**
     * Single instance of the class
     */
    protected static $instance = null;
    
    /**
     * Main Instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Wait until plugins_loaded to check for dependencies
        add_action('plugins_loaded', array($this, 'init'), 20);
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load plugin text domain
        load_plugin_textdomain('wc-stripe-reconciliation', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Only proceed if WooCommerce and Stripe Gateway are active
        if (!$this->check_dependencies()) {
            return;
        }

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register the reconciliation hook
        add_action('wc_stripe_reconciliation_hook', array($this, 'reconcile_unpaid_stripe_orders'));

        // Add a manual reconciliation button to the Orders screen
        add_action('woocommerce_admin_order_actions_end', array($this, 'add_manual_reconcile_button'));

        // Handle the manual reconciliation AJAX call
        add_action('wp_ajax_manual_stripe_reconcile', array($this, 'handle_manual_reconciliation'));

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        // Enable debug logging based on setting
        $this->maybe_enable_logging();
    }
    
    /**
     * Check if WooCommerce and Stripe Gateway are active
     */
    private function check_dependencies() {
        $active = true;

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                printf(
                    '<div class="error"><p><strong>%s</strong> %s</p></div>',
                    esc_html__('WooCommerce Stripe Reconciliation:', 'wc-stripe-reconciliation'),
                    esc_html__('WooCommerce must be installed and activated.', 'wc-stripe-reconciliation')
                );
            });
            $active = false;
        }

        // Improved check for Stripe Gateway - check for gateway existence in the payment gateways
        if ($active && function_exists('WC')) {
            // Ensure payment gateways are loaded
            if (WC()->payment_gateways()) {
                $payment_gateways = WC()->payment_gateways->payment_gateways();
                if (!isset($payment_gateways['stripe']) && !class_exists('WC_Gateway_Stripe')) {
                    add_action('admin_notices', function() {
                        printf(
                            '<div class="error"><p><strong>%s</strong> %s</p></div>',
                            esc_html__('WooCommerce Stripe Reconciliation:', 'wc-stripe-reconciliation'),
                            esc_html__('WooCommerce Stripe Gateway must be installed and activated.', 'wc-stripe-reconciliation')
                        );
                    });
                    $active = false;
                }
            }
        }

        return $active;
    }
    
    /**
     * Run on plugin activation
     */
    public function activate() {
        // Schedule the reconciliation event
        if (!wp_next_scheduled('wc_stripe_reconciliation_hook')) {
            wp_schedule_event(time(), 'hourly', 'wc_stripe_reconciliation_hook');
        }
        
        // Add default options
        add_option('wc_stripe_reconciliation_days', 2);
        add_option('wc_stripe_reconciliation_limit', 25);
        add_option('wc_stripe_reconciliation_logging', 'yes');
    }
    
    /**
     * Run on plugin deactivation
     */
    public function deactivate() {
        // Unschedule the reconciliation event
        $timestamp = wp_next_scheduled('wc_stripe_reconciliation_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_stripe_reconciliation_hook');
        }
    }
    
    /**
     * Enable Stripe logging if needed
     */
    private function maybe_enable_logging() {
        if (get_option('wc_stripe_reconciliation_logging') === 'yes' && !defined('WC_STRIPE_LOGGING')) {
            define('WC_STRIPE_LOGGING', true);
        }
    }
    
    /* The rest of the plugin code remains the same as in the previous version */
    
    /**
     * Add admin settings page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Stripe Reconciliation', 'wc-stripe-reconciliation'),
            __('Stripe Reconciliation', 'wc-stripe-reconciliation'),
            'manage_woocommerce',
            'wc-stripe-reconciliation',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Main reconciliation function
     */
    public function reconcile_unpaid_stripe_orders() {
        $days_to_check = get_option('wc_stripe_reconciliation_days', 2);
        $order_limit = get_option('wc_stripe_reconciliation_limit', 25);
        
        // Get orders that are "on-hold" or "pending" with Stripe payment method
        $orders = wc_get_orders(array(
            'status' => array('on-hold', 'pending'),
            'payment_method' => 'stripe',
            'date_created' => '>' . date('Y-m-d', strtotime('-' . $days_to_check . ' days')),
            'limit' => $order_limit,
        ));
        
        if (empty($orders)) {
            $this->log('No pending or on-hold Stripe orders found.');
            return 0;
        }

        // Load Stripe API
        if (!class_exists('WC_Stripe_API')) {
            // Try to load it manually if possible
            if (function_exists('WC') && isset(WC()->payment_gateways)) {
                WC()->payment_gateways->payment_gateways();
            }

            if (!class_exists('WC_Stripe_API')) {
                $this->log('WC_Stripe_API class not available. Aborting reconciliation.');
                return 0;
            }
        }
        
        $reconciled_count = 0;
        
        foreach ($orders as $order) {
            $payment_intent_id = $order->get_meta('_stripe_payment_intent');
            
            if (empty($payment_intent_id)) continue;
            
            // Get payment intent from Stripe
            $response = WC_Stripe_API::request(array(), 'payment_intents/' . $payment_intent_id, 'GET');
            
            if (is_wp_error($response)) {
                $this->log('Error checking payment intent ' . $payment_intent_id . ': ' . $response->get_error_message());
                continue;
            }
            
            // If payment succeeded but order not updated
            if (isset($response->status) && $response->status === 'succeeded') {
                $order->payment_complete($payment_intent_id);
                $order->add_order_note(__('Payment reconciled automatically: Stripe payment was successful.', 'wc-stripe-reconciliation'));
                $reconciled_count++;
                $this->log('Successfully reconciled order #' . $order->get_id() . ' with payment intent ' . $payment_intent_id);
            }
        }
        
        $this->log('Reconciliation completed. Checked ' . count($orders) . ' orders, reconciled ' . $reconciled_count);
        
        return $reconciled_count;
    }
    
    /**
     * Log messages if logging is enabled
     */
    private function log($message) {
        if (get_option('wc_stripe_reconciliation_logging') === 'yes') {
            if (!class_exists('WC_Logger')) {
                return;
            }
            $logger = wc_get_logger();
            $logger->info($message, array('source' => 'stripe-reconciliation'));
        }
    }
    
    /**
     * Add a manual reconcile button to orders
     */
    public function add_manual_reconcile_button($order) {
        if ($order->get_payment_method() !== 'stripe') {
            return;
        }
        
        if (!in_array($order->get_status(), array('on-hold', 'pending'))) {
            return;
        }
        
        printf(
            '<button type="button" class="button reconcile-stripe-payment" data-order-id="%d">%s</button>',
            esc_attr($order->get_id()),
            esc_html__('Reconcile Stripe', 'wc-stripe-reconciliation')
        );
        
        // Add the JavaScript for the button
        wc_enqueue_js('
            jQuery(".reconcile-stripe-payment").click(function(e) {
                e.preventDefault();
                var $button = jQuery(this);
                $button.text("Checking...").prop("disabled", true);
                
                jQuery.post(
                    ajaxurl, 
                    {
                        action: "manual_stripe_reconcile",
                        order_id: $button.data("order-id"),
                        security: "' . wp_create_nonce('stripe-reconcile-nonce') . '"
                    },
                    function(response) {
                        if (response.success) {
                            $button.text("Success!");
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        } else {
                            $button.text("Failed").prop("disabled", false);
                            alert(response.data);
                        }
                    }
                );
            });
        ');
    }
    
    /**
     * Handle manual reconciliation AJAX request
     */
    public function handle_manual_reconciliation() {
        check_ajax_referer('stripe-reconcile-nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'wc-stripe-reconciliation'));
            return;
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(__('No order specified', 'wc-stripe-reconciliation'));
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(__('Order not found', 'wc-stripe-reconciliation'));
            return;
        }

        if ($order->get_payment_method() !== 'stripe') {
            wp_send_json_error(__('Not a Stripe order', 'wc-stripe-reconciliation'));
            return;
        }

        $payment_intent_id = $order->get_meta('_stripe_payment_intent');

        if (empty($payment_intent_id)) {
            wp_send_json_error(__('No Stripe Payment Intent found', 'wc-stripe-reconciliation'));
            return;
        }

        // Load Stripe API
        if (!class_exists('WC_Stripe_API')) {
            wp_send_json_error(__('Stripe API not available', 'wc-stripe-reconciliation'));
            return;
        }

        // Get payment intent from Stripe
        $response = WC_Stripe_API::request(array(), 'payment_intents/' . $payment_intent_id, 'GET');

        if (is_wp_error($response)) {
            /* translators: %s: error message from Stripe API */
            wp_send_json_error(sprintf(__('Error checking payment: %s', 'wc-stripe-reconciliation'), $response->get_error_message()));
            return;
        }

        // If payment succeeded but order not updated
        if (isset($response->status) && $response->status === 'succeeded') {
            $order->payment_complete($payment_intent_id);
            $order->add_order_note(__('Payment manually reconciled: Stripe payment was successful.', 'wc-stripe-reconciliation'));
            $this->log('Manual reconciliation successful for order #' . $order_id);
            wp_send_json_success(__('Order updated successfully', 'wc-stripe-reconciliation'));
        } else {
            /* translators: %s: payment status from Stripe */
            wp_send_json_error(sprintf(__('Payment not successful in Stripe. Status: %s', 'wc-stripe-reconciliation'), $response->status));
        }
    }
    
    /**
     * Add settings link on plugin page
     */
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="admin.php?page=wc-stripe-reconciliation">%s</a>',
            __('Settings', 'wc-stripe-reconciliation')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Admin settings page
     */
    public function admin_page() {
        // Save settings if submitted
        if (isset($_POST['wc_stripe_reconciliation_submit'])) {
            check_admin_referer('wc_stripe_reconciliation_settings');

            $days = isset($_POST['days_to_check']) ? absint($_POST['days_to_check']) : 2;
            $limit = isset($_POST['order_limit']) ? absint($_POST['order_limit']) : 25;
            $logging = isset($_POST['enable_logging']) ? 'yes' : 'no';

            // Validate ranges
            $days = max(1, min(30, $days));
            $limit = max(5, min(100, $limit));

            update_option('wc_stripe_reconciliation_days', $days);
            update_option('wc_stripe_reconciliation_limit', $limit);
            update_option('wc_stripe_reconciliation_logging', $logging);

            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html__('Settings saved!', 'wc-stripe-reconciliation')
            );
        }
        
        // Run manual reconciliation if requested
        if (isset($_GET['run_now']) && $_GET['run_now'] === '1') {
            check_admin_referer('wc_stripe_reconciliation_run_now');
            $count = $this->reconcile_unpaid_stripe_orders();
            printf(
                '<div class="notice notice-info"><p>%s</p></div>',
                /* translators: %d: number of orders updated */
                sprintf(esc_html__('Reconciliation completed. %d orders were updated.', 'wc-stripe-reconciliation'), $count)
            );
        }
        
        // Get current settings
        $days = get_option('wc_stripe_reconciliation_days', 2);
        $limit = get_option('wc_stripe_reconciliation_limit', 25);
        $logging = get_option('wc_stripe_reconciliation_logging', 'yes');
        
        // Display the settings page
        ?>
        <div class="wrap">
            <h1>WooCommerce Stripe Reconciliation</h1>
            
            <div class="card">
                <h2>Settings</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('wc_stripe_reconciliation_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Days to check</th>
                            <td>
                                <input type="number" name="days_to_check" value="<?php echo esc_attr($days); ?>" min="1" max="30" />
                                <p class="description">How many days of past orders to check for missed payments.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Orders to check</th>
                            <td>
                                <input type="number" name="order_limit" value="<?php echo esc_attr($limit); ?>" min="5" max="100" />
                                <p class="description">Maximum number of orders to check per run.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Enable logging</th>
                            <td>
                                <input type="checkbox" name="enable_logging" <?php checked($logging, 'yes'); ?> />
                                <p class="description">Log reconciliation activity (found in WooCommerce → Status → Logs).</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="wc_stripe_reconciliation_submit" class="button-primary" value="Save Settings" />
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2>Manual Reconciliation</h2>
                <p>Click the button below to run the reconciliation process now.</p>
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('run_now', '1'), 'wc_stripe_reconciliation_run_now')); ?>" class="button button-secondary">Run Reconciliation Now</a>
            </div>
            
            <div class="card">
                <h2>About</h2>
                <p>This plugin automatically checks pending and on-hold Stripe orders to see if they have been paid but the webhook failed to mark them as completed.</p>
                <p>Reconciliation runs hourly via WordPress cron, but you can also run it manually or use the "Reconcile Stripe" button on individual orders.</p>
            </div>
        </div>
        <?php
    }
}

// Initialize the plugin using singleton pattern
function wc_stripe_reconciliation() {
    return WC_Stripe_Reconciliation::instance();
}

// Start the plugin
add_action('plugins_loaded', 'wc_stripe_reconciliation', 10);