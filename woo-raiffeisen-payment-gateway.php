<?php
/*
Plugin Name:       WooCommerce Raiffeisen Payment Gateway
Plugin URI:        #
Description:       A WooCommerce Extension that adds payment gateway "Raiffeisen Payment Gateway"
Version:           1.0.0
Author:            Indrit Qoku
Author URI:        http://commprog.com
License:           GPL-2.0+
License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
*/;

if (!defined('ABSPATH'))
    exit;

add_action('plugins_loaded', 'wc_spg_init');

function wc_spg_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    /**
     * Gateway class
     */
    class WC_Gateway_Raiffeisen_Payment_Gateway extends WC_Payment_Gateway
    {
        private $merchant_id;
        private $terminal_id;
        private $liveurl;
        private $notify_url;
        private $msg;

        public function __construct()
        {
            $this->id = 'spg';
            $this->method_title = __('Raiffeisen Payment Gateway', 'wc_spg');
            $this->icon = plugins_url('images/logo.png', __FILE__);
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->terminal_id = $this->get_option('terminal_id');

            $this->liveurl = "https://secure.upc.ua/rbal/enter";
            $this->notify_url = str_replace('https:', 'http:', home_url('/wc-api/WC_Gateway_Raiffeisen_Payment_Gateway'));

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            add_action('woocommerce_api_wc_gateway_raiffeisen_payment_gateway', array($this, 'check_spg_response'));
            add_action('valid-spg-request', array($this, 'successful_request'));
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_spg', array($this, 'receipt_page'));
            add_action('woocommerce_thankyou_spg', array($this, 'thankyou_page'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc_spg'),
                    'type' => 'checkbox',
                    'label' => __('Enable Raiffeisen Payment Gateway', 'wc_spg'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'wc_spg'),
                    'type' => 'text',
                    'description' => __('Payment method title which the customer will see during checkout', 'wc_spg'),
                    'default' => __('Raiffeisen Bank.', 'wc_spg'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'wc_spg'),
                    'type' => 'textarea',
                    'description' => __('Payment method description which the customer will see during checkout', 'wc_spg'),
                    'default' => __('', 'wc_spg'),
                    'desc_tip' => true,
                ),
                'merchant_id' => array(
                    'title' => __('Merchant ID', 'wc_spg'),
                    'type' => 'text',
                    'description' => __('Terminal  ID provided by bank', 'wc_spg'),
                    'default' => __('', 'wc_spg'),
                    'desc_tip' => true,
                ),
                'terminal_id' => array(
                    'title' => __('Terminal  ID', 'wc_spg'),
                    'type' => 'text',
                    'description' => __('Terminal  ID provided by bank', 'wc_spg'),
                    'default' => __('', 'wc_spg'),
                    'desc_tip' => true,
                )
            );
        }

        function admin_options()
        {
            ?>
            <h3><?php _e('Raiffeisen Payment Gateway', 'wc_spg'); ?></h3>
            <p><?php _e('Raiffeisen is a popular payment gateway for online shopping in Albania', 'wc_spg'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        /**
         *  There are no payment fields for Raiffeisen, but we want to show the description if set.
         **/
        function payment_fields()
        {
            if ($this->description) echo wpautop(wptexturize($this->description));
        }

        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order)
        {

            echo '<p>Thank you for your order, please click the button below to pay with Raiffeisen.</p>';
            echo $this->generate_raiffeisen_form($order);
        }

        /**
         * Check for valid Raiffeisen server callback
         **/
        function check_spg_response()
        {
            global $woocommerce;

            $msg['class'] = 'error';
            $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
            $myaccounturl = get_permalink(woocommerce_get_page_id('myaccount'));

            if (isset($_POST['OrderID'])) {
                require_once('Notify.php');
                $notify = new Raiffeisen\Notify($myaccounturl, $myaccounturl, $_POST);

                if ($notify->isValid('1.1.1.1')) {
                    if (1) {
                        try {
                            $order = new WC_Order($_POST['OrderID']);
                            $msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
                            $msg['class'] = 'success';

                            if ($order->status != 'processing') {
                                $order->payment_complete();
                                $order->add_order_note('Raiffeisen payment successful<br/>');
                                $woocommerce->cart->empty_cart();
                            }
                            echo $notify->success();
                        } catch (Exception $e) {
                            $msg['class'] = 'error';
                            $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                            echo $notify->error();
                        }

                        echo $notify->error();
                    } else {
                        $msg['class'] = 'error';
                        $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                        echo $notify->error();
                    }
                }

                if (function_exists('wc_add_notice')) {
                    wc_add_notice($msg['message'], $msg['class']);

                } else {
                    if ($msg['class'] == 'success') {
                        $woocommerce->add_message($msg['message']);
                    } else {
                        $woocommerce->add_error($msg['message']);

                    }
                    $woocommerce->set_messages();
                }
            }
        }


        /**
         * Generate CCAvenue button link
         **/
        public function generate_raiffeisen_form($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $total = $order->get_total();

            $options = [
                'purchase_time' => date('ymdHis', strtotime('-1 hour')), // koha kur eshte kryer porosia
                'order_id' => $order_id, // ID e porosise
                'currency_id' => 'eur', // Valuta (all, usd, eur)
                'session_data' => session_id(), // Sesioni
                'cert_dir' => 'cert' // Direktoria ku ndodhet certifikata http://store.commprog.com/
            ];

            require_once('Authenticate.php');
            $auth = new Raiffeisen\Authenticate($this->merchant_id, $this->terminal_id, $total, $options);
            $data = $auth->generate();

            wc_enqueue_js('
                    $.blockUI({
                        message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Raiffeisen to make payment.', 'woocommerce')) . '",
                        baseZ: 99999,
                        overlayCSS:
                        {
                            background: "#fff",
                            opacity: 0.6
                        },
                        css: {
                            padding:        "20px",
                            zindex:         "9999999",
                            textAlign:      "center",
                            color:          "#555",
                            border:         "3px solid #aaa",
                            backgroundColor:"#fff",
                            cursor:         "wait",
                            lineHeight:     "24px",
                        }
                    });
                    jQuery("#submit_raiffeisen_payment_form").click();
                    var cmimi =  jQuery("#TotalAmount").val();
                    console.log("Cmimi: " + cmimi);
                ');

            $form = '<form action="' . $this->liveurl . '" method="post" id="raiffeisen_payment_form" target="_top">
                <div class="payment_buttons">
                    <input name="Version" type="hidden" value="1" >
                    <input name="MerchantID" type="hidden" value="' . $data['merchant_id'] . '">
                    <input name="TerminalID" type="hidden" value="' . $data['terminal_id'] . '">
                    <input name="TotalAmount" type="hidden" id="TotalAmount" value="' . $total . '">
                    <input name="SD" type="hidden" value="' . $data['session_data'] . '">
                    <input name="Currency" type="hidden" value="' . $data['currency_id'] . '">
                    <input name="Locale" type="hidden" value="sq">
                    <input name="OrderID" type="hidden" value="' . $data['order_id'] . '"  >
                    <input name="PurchaseTime" type="hidden" value="' . $data['purchase_time'] . '">
                    <input name="Signature" type="hidden" value="' . $data['signature'] . '" >
                    <input type="submit" class="button alt" id="submit_raiffeisen_payment_form" value="Submit" /> 
                    <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
                </div>
                <script type="text/javascript">
                    jQuery(".payment_buttons").hide();
                </script>
            </form>';

            return $form;
        }

        // get all pages
        function get_pages($title = false, $indent = true)
        {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }
    }

    function add_spg($methods)
    {
        $methods[] = 'WC_Gateway_Raiffeisen_Payment_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_spg');
}
