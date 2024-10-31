<?php

/**
 * Paybox Payment Gateway
 *
 * Provides a PayBox.
 *
 * @class  woocommerce_paybox
 * @package WooCommerce
 * @category Payment Gateways
 * @author PayBox
 * @license GPLv2
 */
class WC_Paybox_Payment_Gateway extends WC_Payment_Gateway
{
    /**
     * Version
     *
     * @var string
     */
    public $version;

    /**
     * @access protected
     * @var array $data_to_send
     */
    protected $data_to_send = array();

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->version = WC_GATEWAY_PAYBOX_VERSION;
        $this->id = 'paybox';
        $this->method_title = __('PayBox', 'paybox-payment-gateway');
        $this->method_description = sprintf(
            __(
                'PayBox works by sending the user to %1$sPayBox%2$s to enter their payment information.',
                'paybox-payment-gateway'
            ),
            '<a href="https://paybox.kz">',
            '</a>'
        );
        $this->icon = WP_PLUGIN_URL . '/' . plugin_basename(dirname(__DIR__)) . '/assets/images/icon.png';
        $this->debug_email = get_option('admin_email');
        $this->available_countries = array('KZ', 'RU', 'KG');
        $this->available_currencies = (array)apply_filters(
            'woocommerce_gateway_paybox_available_currencies',
            array('KZT', 'RUR', 'RUB', 'USD', 'EUR', 'KGS', 'UZS')
        );

        $this->supports = array(
            'products',
            'pre-orders',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change'
        );
        $this->init_form_fields();
        $this->init_settings();

        if (!is_admin()) {
            $this->setup_constants();
        }

        // Setup default merchant data.
        $this->merchant_id = $this->get_option('merchant_id');
        $this->merchant_key = $this->get_option('merchant_key');
        $this->pass_phrase = $this->get_option('pass_phrase');
        $this->title = $this->get_option('title');
        $this->send_debug_email = 'yes' === $this->get_option('send_debug_email');
        $this->description = $this->get_option('description');
        $this->enabled = $this->is_valid_for_use() ? 'yes' : 'no'; // Check if the base currency supports this gateway.
        $this->enable_logging = 'yes' === $this->get_option('enable_logging');

        // Setup the test data, if in test mode.
        if ('yes' === $this->get_option('testmode')) {
            $this->test_mode = true;
            $this->add_testmode_admin_settings_notice();
        } else {
            $this->send_debug_email = false;
        }

        add_action('woocommerce_check_cart_items', array($this, 'check_total'));
        add_action('woocommerce_api_wc_paybox_payment_gateway', array($this, 'check_itn_response'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_paybox', array($this, 'receipt_page'));
        add_action(
            'woocommerce_scheduled_subscription_payment_' . $this->id,
            array($this, 'scheduled_subscription_payment')
        );
        add_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_listener'));
        add_action(
            'wc_pre_orders_process_pre_order_completion_payment_' . $this->id,
            array($this, 'process_pre_order_payments')
        );
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Check price product
     *
     * @since 1.0.0
     */
    public function check_total()
    {
        $items = WC()->cart->get_cart();

        if ((float)WC()->cart->get_cart_contents_total() <= 0) {
            foreach ($items as $item => $values) {
                $product = wc_get_product($values['data']->get_id());
                $price = get_post_meta($values['product_id'], '_price', true);

                if ((float)$price <= 0) {
                    echo
                        '<div class="inline error" style="border-color: firebrick; border-style: solid; margin-bottom: 5px; text-align: center; vertical-align: middle"><p style="">'
                        . __(
                            'Product ' .
                            '<b>' .
                            $product->get_title() .
                            '</b>' .
                            ' incorrect price.Payment form will not be generated',
                            'paybox-payment-gateway'
                        )
                        . '</p></div>';
                }
            }
        }
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @since 1.0.0
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'                   => array(
                'title'       => __('Активировать плагин', 'paybox-payment-gateway'),
                'label'       => __('Enable PayBox', 'paybox-payment-gateway'),
                'type'        => 'checkbox',
                'description' => __(
                    'This controls whether or not this gateway is enabled within WooCommerce.',
                    'paybox-payment-gateway'
                ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'title'                     => array(
                'title'       => __('Заголовок', 'paybox-payment-gateway'),
                'type'        => 'text',
                'description' => __(
                    'This controls the title which the user sees during checkout.',
                    'paybox-payment-gateway'
                ),
                'default'     => __('PayBox', 'paybox-payment-gateway'),
                'desc_tip'    => true,
            ),
            'description'               => array(
                'title'       => __('Описание', 'paybox-payment-gateway'),
                'type'        => 'text',
                'description' => __(
                    'This controls the description which the user sees during checkout.',
                    'paybox-payment-gateway'
                ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'testmode'                  => array(
                'title'       => __('Тестовый режим', 'paybox-payment-gateway'),
                'type'        => 'checkbox',
                'description' => __('Place the payment gateway in development mode.', 'paybox-payment-gateway'),
                'default'     => 'yes',
            ),
            'language'                  => array(
                'title'       => __('Язык страницы оплаты', 'paybox-payment-gateway'),
                'type'        => 'select',
                'description' => __('Place the payment gateway in development mode.', 'paybox-payment-gateway'),
                'default'     => 'ru',
                'options'     => array(
                    'en' => __('English', 'paybox-payment-gateway'),
                    'ru' => __('Russian', 'paybox-payment-gateway'),
                ),
            ),
            'merchant_id'               => array(
                'title'       => __('Merchant ID', 'paybox-payment-gateway'),
                'type'        => 'text',
                'description' => __(
                    '* Required. This is the merchant ID, received from PayBox.',
                    'paybox-payment-gateway'
                ),
                'default'     => '',
            ),
            'merchant_key'              => array(
                'title'       => __('Merchant Key', 'paybox-payment-gateway'),
                'type'        => 'text',
                'description' => __(
                    '* Required. This is the merchant key, received from PayBox.',
                    'paybox-payment-gateway'
                ),
                'default'     => '',
            ),
            'api_url'                   => array(
                'title'   => __('API URL', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => explode(',', 'api.paybox.money,api.paybox.ru')[0],
                'options' => $this->getApiUrlOptions(),
            ),
            'success_status'            => array(
                'title'   => __('Статус заказа при успешном платеже', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => 'wc-processing',
                'options' => wc_get_order_statuses(),
            ),
            'failure_status'            => array(
                'title'   => __('Статус заказа при ошибке оплаты', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => 'wc-failed',
                'options' => wc_get_order_statuses(),
            ),
            'ofd'                       => array(
                'title'       => __('ФФД', 'paybox-payment-gateway'),
                'type'        => 'checkbox',
                'description' => __('Enable generation of fiscal documents', 'paybox-payment-gateway'),
                'default'     => ''
            ),
            'ofd_version'               => array(
                'title'   => __('Версия ФФД', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => 'old_ru_1_05',
                'options' => array(
                    'old_ru_1_05' => __('FFD v1', 'paybox-payment-gateway'),
                    'ru_1_05'     => __('FFD v2 atol', 'paybox-payment-gateway'),
                    'uz_1_0'     => __('FFD v2 gnk', 'paybox-payment-gateway'),
                ),
            ),
            'taxation_system'           => array(
                'title'   => __('Система налогообложения', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    'osn'                => __('Общая система налогообложения', 'paybox-payment-gateway'),
                    'usn_income'         => __('Упрощенная (УСН, доходы)', 'paybox-payment-gateway'),
                    'usn_income_outcome' => __('Упрощенная (УСН, доходы минус расходы)', 'paybox-payment-gateway'),
                    'envd'               => __('Единый налог на вмененный доход (ЕНВД)', 'paybox-payment-gateway'),
                    'esn'                => __(
                        'Единый сельскохозяйственный налог (ЕСН)',
                        'paybox-payment-gateway'
                    ),
                    'patent'             => __('Патентная система налогообложения', 'paybox-payment-gateway'),
                ),
            ),
            'payment_method'            => array(
                'title'   => __('Признак способа расчета', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    'full_prepayment'    => __('Предоплата', 'paybox-payment-gateway'),
                    'partial_prepayment' => __('Частичная предоплата', 'paybox-payment-gateway'),
                    'advance'            => __('Аванс', 'paybox-payment-gateway'),
                    'full_payment'       => __('Полный расчет', 'paybox-payment-gateway'),
                    'partial_payment'    => __('Частичный расчет и кредит', 'paybox-payment-gateway'),
                    'credit'             => __('Передача в кредит', 'paybox-payment-gateway'),
                    'credit_payment'     => __('Выплата по кредиту', 'paybox-payment-gateway'),
                ),
            ),
            'payment_object'            => array(
                'title'   => __('Признак предмета расчета товара', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    'goods'                   => __('Товар', 'paybox-payment-gateway'),
                    'excise_goods'            => __('Подакцизный товар', 'paybox-payment-gateway'),
                    'job'                     => __('Работа', 'paybox-payment-gateway'),
                    'service'                 => __('Услуга', 'paybox-payment-gateway'),
                    'gambling_bet'            => __('Ставка азартной игры', 'paybox-payment-gateway'),
                    'gambling_win'            => __('Выигрыш азартной игры', 'paybox-payment-gateway'),
                    'lottery_ticket'          => __('Лотерейный билет', 'paybox-payment-gateway'),
                    'lottery_win'             => __('Выигрыш в лотереи', 'paybox-payment-gateway'),
                    'intellectual_activity'   => __(
                        'Результаты интеллектуальной деятельности',
                        'paybox-payment-gateway'
                    ),
                    'payment'                 => __('Платеж', 'paybox-payment-gateway'),
                    'agent_commission'        => __('Агентское вознаграждение', 'paybox-payment-gateway'),
                    'payout'                  => __('Выплата', 'paybox-payment-gateway'),
                    'another_subject'         => __('Иной предмет расчета', 'paybox-payment-gateway'),
                    'property_right'          => __('Имущественное право', 'paybox-payment-gateway'),
                    'non_operating_income'    => __('Внереализационный доход', 'paybox-payment-gateway'),
                    'insurance_contributions' => __('Страховые взносы', 'paybox-payment-gateway'),
                    'trade_collection'        => __('Торговый сбор', 'paybox-payment-gateway'),
                    'resort_collection'       => __('Курортный сбор', 'paybox-payment-gateway'),
                    'pledge'                  => __('Залог', 'paybox-payment-gateway'),
                    'expense'                 => __('Расход', 'paybox-payment-gateway'),
                    'pension_insurance_ip'    => __(
                        'Взносы на обязательное пенсионное страхование ИП',
                        'paybox-payment-gateway'
                    ),
                    'pension_insurance'       => __(
                        'Взносы на обязательное пенсионное страхование',
                        'paybox-payment-gateway'
                    ),
                    'health_insurance_ip'     => __(
                        'Взносы на обязательное медицинское страхование ИП',
                        'paybox-payment-gateway'
                    ),
                    'health_insurance'        => __(
                        'Взносы на обязательное медицинское страхование',
                        'paybox-payment-gateway'
                    ),
                    'social_insurance'        => __(
                        'Взносы на обязательное социальное страхование',
                        'paybox-payment-gateway'
                    ),
                    'casino'                  => __('Платеж казино', 'paybox-payment-gateway'),
                    'insurance_collection'    => __('Страховые взносы', 'paybox-payment-gateway'),
                ),
            ),
            'tax'                       => array(
                'title'   => __('НДС на товары для ФФД старой версии', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    '0' => __('Without VAT (Webkassa, RocketR)', 'paybox-payment-gateway'),
                    '1' => __('0% (Webkassa, RocketR)', 'paybox-payment-gateway'),
                    '2' => __('12% (−)', 'paybox-payment-gateway'),
                    '3' => __('12/112 (Webkassa)', 'paybox-payment-gateway'),
                    '4' => __('18% (−)', 'paybox-payment-gateway'),
                    '5' => __('18/118 (−)', 'paybox-payment-gateway'),
                    '6' => __('10% (RocketR)', 'paybox-payment-gateway'),
                    '7' => __('10/110 (RocketR)', 'paybox-payment-gateway'),
                    '8' => __('20% (RocketR)', 'paybox-payment-gateway'),
                    '9' => __('20/120 (RocketR)', 'paybox-payment-gateway'),
                ),
            ),
            'new_tax'                   => array(
                'title'   => __('НДС на товары', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    'none'    => __('Без НДС', 'paybox-payment-gateway'),
                    'vat_0'   => __('НДС 0%', 'paybox-payment-gateway'),
                    'vat_10'  => __('НДС 10%', 'paybox-payment-gateway'),
                    'vat_12'  => __('НДС 12/112', 'paybox-payment-gateway'),
                    'vat_20'  => __('НДС 20%', 'paybox-payment-gateway'),
                    'vat_110' => __('НДС 10/110', 'paybox-payment-gateway'),
                    'vat_120' => __('НДС 20/120', 'paybox-payment-gateway'),
                ),
            ),
            'ofd_in_delivery'           => array(
                'title'       => __('Учитывать доставку в ФФД', 'paybox-payment-gateway'),
                'type'        => 'checkbox',
                'description' => __('Include delivery in OFD', 'paybox-payment-gateway'),
                'default'     => ''
            ),
            'delivery_payment_object'   => array(
                'title'   => __('Признак предмета расчета доставки', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    'job'     => __('Работа', 'paybox-payment-gateway'),
                    'service' => __('Услуга', 'paybox-payment-gateway'),
                ),
            ),
            'delivery_tax'              => array(
                'title'   => __('НДС на доставку для ФФД старой версии', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    '0' => __('Without VAT (Webkassa, RocketR)', 'paybox-payment-gateway'),
                    '1' => __('0% (Webkassa, RocketR)', 'paybox-payment-gateway'),
                    '2' => __('12% (−)', 'paybox-payment-gateway'),
                    '3' => __('12/112 (Webkassa)', 'paybox-payment-gateway'),
                    '4' => __('18% (−)', 'paybox-payment-gateway'),
                    '5' => __('18/118 (−)', 'paybox-payment-gateway'),
                    '6' => __('10% (RocketR)', 'paybox-payment-gateway'),
                    '7' => __('10/110 (RocketR)', 'paybox-payment-gateway'),
                    '8' => __('20% (RocketR)', 'paybox-payment-gateway'),
                    '9' => __('20/120 (RocketR)', 'paybox-payment-gateway'),
                ),
            ),
            'delivery_new_tax'          => array(
                'title'   => __('НДС на доставку', 'paybox-payment-gateway'),
                'type'    => 'select',
                'default' => '',
                'options' => array(
                    'none'    => __('Без НДС', 'paybox-payment-gateway'),
                    'vat_0'   => __('НДС 0%', 'paybox-payment-gateway'),
                    'vat_10'  => __('НДС 10%', 'paybox-payment-gateway'),
                    'vat_12'  => __('НДС 12/112', 'paybox-payment-gateway'),
                    'vat_20'  => __('НДС 20%', 'paybox-payment-gateway'),
                    'vat_110' => __('НДС 10/110', 'paybox-payment-gateway'),
                    'vat_120' => __('НДС 20/120', 'paybox-payment-gateway'),
                ),
            ),
            'delivery_gnk_ikpu_code'    => array(
                'title'   => __('ИКПУ код для доставки', 'paybox-payment-gateway'),
                'type'    => 'text',
                'default' => '',
            ),
            'delivery_gnk_package_code' => array(
                'title'   => __('Код упаковки для доставки', 'paybox-payment-gateway'),
                'type'    => 'text',
                'default' => '',
            ),
            'delivery_gnk_unit_code'    => array(
                'title'   => __('Код единицы измерения доставки', 'paybox-payment-gateway'),
                'type'    => 'text',
                'default' => '',
            ),
        );
    }

    /**
     * add_testmode_admin_settings_notice()
     * Add a notice to the merchant_key and merchant_id fields when in test mode.
     *
     * @since 1.0.0
     */
    public function add_testmode_admin_settings_notice()
    {
        $this->form_fields['merchant_id']['description'] .= ' <strong>' . __(
                'Sandbox Merchant ID currently in use',
                'paybox-payment-gateway'
            ) . ' ( ' . esc_html($this->merchant_id) . ' ).</strong>';
        $this->form_fields['merchant_key']['description'] .= ' <strong>' . __(
                'Sandbox Merchant Key currently in use',
                'paybox-payment-gateway'
            ) . ' ( ' . esc_html($this->merchant_key) . ' ).</strong>';
    }

    /**
     * is_valid_for_use()
     *
     * Check if this gateway is enabled and available in the base currency being traded with.
     *
     * @return bool
     * @since 1.0.0
     */
    public function is_valid_for_use()
    {
        $is_available = false;
        $is_available_currency = in_array(get_woocommerce_currency(), $this->available_currencies);

        if ($is_available_currency && $this->merchant_id && $this->merchant_key) {
            $is_available = true;
        }

        return $is_available;
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.0.0
     */
    public function admin_options()
    {
        if (in_array(get_woocommerce_currency(), $this->available_currencies)) {
            ?>

            <h3><?php
                echo (!empty($this->method_title)) ? $this->method_title : __(
                    'Settings',
                    'paybox-payment-gateway'
                ); ?></h3>

            <?php
            echo (!empty($this->method_description)) ? wpautop($this->method_description) : ''; ?>
            <script type="application/javascript">
                jQuery(document).ready(function () {
                    if (jQuery('input[name="woocommerce_paybox_ofd"]').is(':checked')) {
                        jQuery('input[name="woocommerce_paybox_tax"]').prop("disabled", false);
                    } else {
                        jQuery('input[name="woocommerce_paybox_tax"]').prop("disabled", true);
                    }

                    jQuery('input[name="woocommerce_paybox_ofd"]').change(function () {
                        if (this.checked) {
                            jQuery('input[name="woocommerce_paybox_tax"]').prop("disabled", false);
                        } else {
                            jQuery('input[name="woocommerce_paybox_tax"]').prop("disabled", true);
                        }
                    })
                })
            </script>


            <table class="form-table">
                <?php
                $this->generate_settings_html(); ?>
            </table><?php
        } else {
            ?>
            <h3><?php
                _e('PayBox', 'paybox-payment-gateway'); ?></h3>
            <div class="inline error"><p><strong><?php
                        _e('Gateway Disabled', 'paybox-payment-gateway'); ?></strong> <?php
                    /* translators: 1: a href link 2: closing href */
                    echo
                    sprintf(
                        __(
                            'Choose KZT, RUR, USD, EUR or KGS as your store currency in %1$sGeneral Settings%2$s to enable the PayBox Gateway.',
                            'paybox-payment-gateway'
                        ),
                        '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">',
                        '</a>'
                    ); ?></p></div>
            <?php
        }
    }

    /**
     * Generate the PayBox button link.
     *
     * @since 1.0.0
     */
    public function generate_Paybox_form($order_id)
    {
        $order = wc_get_order($order_id);

        // Construct variables for post
        $orderId = (!empty($order_id))
            ? $order_id
            : (!empty(self::get_order_prop($order, 'id'))
                ? self::get_order_prop($order, 'id')
                : (!empty($order->get_order_number())
                    ? $order->get_order_number()
                    : 0)
            );

        if (method_exists($order, 'get_currency')) {
            $currency = $order->get_currency();
        } else {
            $currency = $order->get_order_currency();
        }

        $this->data_to_send = array(
            'pg_amount'             => (float)$order->get_total(),
            'pg_description'        => 'Оплата заказа №' . $orderId,
            'pg_encoding'           => 'UTF-8',
            'pg_currency'           => $currency,
            'pg_user_ip'            => sanitize_text_field($_SERVER['REMOTE_ADDR']),
            'pg_lifetime'           => 86400,
            'pg_language'           => $this->get_option('language'),
            'pg_merchant_id'        => (int)$this->merchant_id,
            'pg_order_id'           => (string)$orderId,
            'pg_result_url'         => get_site_url(),
            'pg_request_method'     => 'POST',
            'pg_salt'               => (string)mt_rand(21, 43433),
            'pg_success_url'        => get_site_url() . '/checkout/order-received/',
            'pg_failure_url'        => get_site_url(),
            'pg_user_phone'         => preg_replace('/\D/', '', self::get_order_prop($order, 'billing_phone')),
            'pg_user_contact_email' => self::get_order_prop($order, 'billing_email'),
            'pg_auto_clearing'      => 1,
        );

        $this->data_to_send['pg_testing_mode'] = ('yes' === $this->get_option('testmode')) ? 1 : 0;

        if ('yes' === $this->get_option('ofd')) {
            $ofdVersion = $this->get_option('ofd_version');

            switch ($ofdVersion) {
                case 'old_ru_1_05':
                    $this->generateReceiptPositions($order_id);
                    break;
                case 'uz_1_0':
                    $this->generateReceiptsForUZ_1_0($order_id);
                    break;
                case 'ru_1_05':
                    $this->generateReceiptsForRU_1_05($order_id);
            }
        }

        $sign_data = $this->prepare_request_data($this->data_to_send);

        $url = 'init_payment.php';
        ksort($sign_data);
        array_unshift($sign_data, $url);
        $sign_data[] = $this->merchant_key;
        $str = implode(';', $sign_data);
        $this->data_to_send['pg_sig'] = md5($str);
        $this->url = 'https://' . $this->get_option('api_url') . "/$url";

        // add subscription parameters
        if ($this->order_contains_subscription($order_id)) {
            $this->data_to_send['subscription_type'] = '2';
        }

        if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
            $subscriptions = wcs_get_subscriptions_for_renewal_order($order_id);
            // For renewal orders that have subscriptions with renewal flag,
            // we will create a new subscription in PayBox and link it to the existing ones in WC.
            // The old subscriptions in PayBox will be cancelled once we handle the itn request.
            if (count($subscriptions) > 0 && $this->_has_renewal_flag(reset($subscriptions))) {
                $this->data_to_send['subscription_type'] = '2';
            }
        }

        // pre-order: add the subscription type for pre order that require tokenization
        // at this point we assume that the order pre order fee and that
        // we should only charge that on the order. The rest will be charged later.
        if ($this->order_contains_pre_order($order_id)
            && $this->order_requires_payment_tokenization($order_id)) {
            $this->data_to_send['amount'] = (float)$this->get_pre_order_fee($order_id);
            $this->data_to_send['subscription_type'] = '2';
        }

        $ch = curl_init($this->url);

        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($this->data_to_send, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        curl_close($ch);

        $result = curl_exec($ch);

        $paymentUrl = substr(
            strstr($result, 'https://'),
            0,
            strpos(strstr($result, 'https://'), '</')
        );


        wp_redirect($paymentUrl);
    }

    /**
     * Process the payment and return the result.
     *
     * @throws Exception
     * @since 1.0.0
     */
    public function process_payment($order_id)
    {
        if ($this->order_contains_pre_order($order_id)
            && $this->order_requires_payment_tokenization($order_id)
            && !$this->cart_contains_pre_order_fee()) {
            throw new Exception(
                'PayBox does not support transactions without any upfront costs or fees. Please select another gateway'
            );
        }

        $order = wc_get_order($order_id);

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    /**
     * Receipt page.
     *
     * Display text and a button to direct the user to PayBox.
     *
     * @throws JsonException
     * @since 1.0.0
     */
    public function receipt_page($order)
    {
        echo '<p>' . __(
                'Thank you for your order, please click the button below to pay with PayBox.',
                'paybox-payment-gateway'
            ) . '</p>';

        $this->generate_Paybox_form($order);
    }

    /**
     * Check PayBox ITN response.
     *
     * @since 1.0.0
     */
    public function check_itn_response()
    {
        $this->handle_itn_request();

        // Notify PayBox that information has been received
        header('HTTP/1.0 200 OK');
        flush();
    }

    /**
     * Check PayBox ITN validity.
     * @since 1.0.0
     */
    public function handle_itn_request()
    {
        $this->log(
            PHP_EOL
            . '----------'
            . PHP_EOL . 'PayBox ITN call received'
            . PHP_EOL . '----------'
        );

        $this->log('Get sent data');

        if (!empty(sanitize_text_field($_REQUEST['pg_order_id'])) && !empty(
            sanitize_text_field(
                $_REQUEST['pg_result']
            )
            )) {
            $order = wc_get_order(sanitize_text_field($_REQUEST['pg_order_id']));

            if (sanitize_text_field($_REQUEST['pg_result']) == 1) {
                if ($order->get_status() == 'pending' || $order->get_status() == 'on-hold') {
                    $this->log("updating order status");
                    $order->update_status(
                        $this->get_option('success_status'),
                        __('PayBox Order payment success', 'paybox-payment-gateway')
                    );
                    $this->log("order status is set to " . $order->get_status());
                }
            } elseif ($order->get_status() == 'pending' || $order->get_status() == 'on-hold') {
                $order->update_status(
                    $this->get_option('failure_status'),
                    __('PayBox Order payment failed', 'paybox-payment-gateway')
                );
            }

            header('Location:/');
        }

        $this->log(
            PHP_EOL
            . '----------'
            . PHP_EOL . 'End ITN call'
            . PHP_EOL . '----------'
        );
    }

    /**
     * Handle logging the order details.
     *
     * @since 1.4.5
     */
    public function log_order_details($order)
    {
        if (version_compare(WC_VERSION, '3.0.0', '<')) {
            $customer_id = get_post_meta($order->get_id(), '_customer_user', true);
        } else {
            $customer_id = $order->get_user_id();
        }

        $details = "Order Details:"
            . PHP_EOL . 'customer id:' . $customer_id
            . PHP_EOL . 'order id:   ' . $order->get_id()
            . PHP_EOL . 'parent id:  ' . $order->get_parent_id()
            . PHP_EOL . 'status:     ' . $order->get_status()
            . PHP_EOL . 'total:      ' . $order->get_total()
            . PHP_EOL . 'currency:   ' . $order->get_currency()
            . PHP_EOL . 'key:        ' . $order->get_order_key();

        $this->log($details);
    }

    /**
     * This function mainly responds to ITN cancel requests initiated on PayBox, but also acts
     * just in case they are not cancelled.
     * @param array $data should be from the Gateway ITN callback.
     * @param WC_Order $order
     * @version 1.4.3 Subscriptions flag
     *
     */
    public function handle_itn_payment_cancelled($data, $order, $subscriptions)
    {
        remove_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_listener'));

        foreach ($subscriptions as $subscription) {
            if ('cancelled' !== $subscription->get_status()) {
                $subscription->update_status(
                    'cancelled',
                    __('Merchant cancelled subscription on PayBox.', 'paybox-payment-gateway')
                );
                $this->_delete_subscription_token($subscription);
            }
        }

        add_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_listener'));
    }

    /**
     * This function handles payment complete request by PayBox.
     * @param array $data should be from the Gateway ITN callback.
     * @param WC_Order $order
     * @version 1.4.3 Subscriptions flag
     */
    public function handle_itn_payment_complete($data, $order, $subscriptions)
    {
        $this->log('- Complete');
        $order->add_order_note(__('ITN payment completed', 'paybox-payment-gateway'));
        $order_id = self::get_order_prop($order, 'id');

        // Store token for future subscription deductions.
        if (count($subscriptions) > 0 && isset($data['token'])) {
            if ($this->_has_renewal_flag(reset($subscriptions))) {
                // renewal flag is set to true, so we need to cancel previous token since we will create a new one
                $this->log(
                    'Cancel previous subscriptions with token ' . $this->_get_subscription_token(reset($subscriptions))
                );

                // only request API cancel token for the first subscription since all of them are using the same token
                $this->cancel_subscription_listener(reset($subscriptions));
            }

            $token = sanitize_text_field($data['token']);

            foreach ($subscriptions as $subscription) {
                $this->_delete_renewal_flag($subscription);
                $this->_set_subscription_token($token, $subscription);
            }
        }

        // the same mechanism (adhoc token) is used to capture payment later
        if ($this->order_contains_pre_order($order_id)
            && $this->order_requires_payment_tokenization($order_id)) {
            $token = sanitize_text_field($data['token']);
            $is_pre_order_fee_paid = get_post_meta($order_id, '_pre_order_fee_paid', true) === 'yes';

            if (!$is_pre_order_fee_paid) {
                /* translators: 1: gross amount 2: payment id */
                $order->add_order_note(
                    sprintf(
                        __('PayBox pre-order fee paid: R %1$s (%2$s)', 'paybox-payment-gateway'),
                        $data['amount_gross'],
                        $data['pf_payment_id']
                    )
                );
                $this->_set_pre_order_token($token, $order);
                // set order to pre-ordered
                WC_Pre_Orders_Order::mark_order_as_pre_ordered($order);
                update_post_meta($order_id, '_pre_order_fee_paid', 'yes');
                WC()->cart->empty_cart();
            } else {
                /* translators: 1: gross amount 2: payment id */
                $order->add_order_note(
                    sprintf(
                        __(
                            'PayBox pre-order product line total paid: R %1$s (%2$s)',
                            'paybox-payment-gateway'
                        ),
                        $data['amount_gross'],
                        $data['pf_payment_id']
                    )
                );
                $order->payment_complete();
                $this->cancel_pre_order_subscription($token);
            }
        } else {
            $order->payment_complete();
        }

        $debug_email = $this->get_option('debug_email', get_option('admin_email'));
        $vendor_name = get_bloginfo('name');
        $vendor_url = home_url('/');

        if ($this->send_debug_email) {
            $subject = 'PayBox ITN on your site';
            $body =
                "Hi,\n\n"
                . "A PayBox transaction has been completed on your website\n"
                . "------------------------------------------------------------\n"
                . 'Site: ' . $vendor_name . ' (' . $vendor_url . ")\n"
                . 'Purchase ID: ' . esc_html($data['m_payment_id']) . "\n"
                . 'PayBox Transaction ID: ' . esc_html($data['pf_payment_id']) . "\n"
                . 'PayBox Payment Status: ' . esc_html($data['payment_status']) . "\n"
                . 'Order Status Code: ' . self::get_order_prop($order, 'status');
            wp_mail($debug_email, $subject, $body);
        }
    }

    /**
     * @param $data
     * @param $order
     */
    public function handle_itn_payment_failed($data, $order)
    {
        $this->log('- Failed');
        /* translators: 1: payment status */
        $order->update_status(
            $this->get_option('failure_status'),
            sprintf(
                __('Payment %s via ITN.', 'paybox-payment-gateway'),
                strtolower(sanitize_text_field($data['payment_status']))
            )
        );
        $debug_email = $this->get_option('debug_email', get_option('admin_email'));
        $vendor_name = get_bloginfo('name');
        $vendor_url = home_url('/');

        if ($this->send_debug_email) {
            $subject = 'PayBox ITN Transaction on your site';
            $body =
                "Hi,\n\n" .
                "A failed PayBox transaction on your website requires attention\n" .
                "------------------------------------------------------------\n" .
                'Site: ' . $vendor_name . ' (' . $vendor_url . ")\n" .
                'Purchase ID: ' . self::get_order_prop($order, 'id') . "\n" .
                'User ID: ' . self::get_order_prop($order, 'user_id') . "\n" .
                'PayBox Transaction ID: ' . esc_html($data['pf_payment_id']) . "\n" .
                'PayBox Payment Status: ' . esc_html($data['payment_status']);
            wp_mail($debug_email, $subject, $body);
        }
    }

    /**
     * @param $data
     * @param $order
     * @since 1.4.0 introduced
     */
    public function handle_itn_payment_pending($data, $order)
    {
        $this->log('- Pending');
        // Need to wait for "Completed" before processing
        /* translators: 1: payment status */
        $order->update_status(
            'on-hold',
            sprintf(
                __('Payment %s via ITN.', 'paybox-payment-gateway'),
                strtolower(sanitize_text_field($data['payment_status']))
            )
        );
    }

    /**
     * @param string $order_id
     * @return double
     */
    public function get_pre_order_fee($order_id)
    {
        foreach (wc_get_order($order_id)->get_fees() as $fee) {
            if (is_array($fee) && 'Pre-Order Fee' == $fee['name']) {
                return (float)$fee['line_total'] + (float)$fee['line_tax'];
            }
        }
    }

    /**
     * @param string $order_id
     * @return bool
     */
    public function order_contains_pre_order($order_id)
    {
        if (class_exists('WC_Pre_Orders_Order')) {
            return WC_Pre_Orders_Order::order_contains_pre_order($order_id);
        }

        return false;
    }

    /**
     * @param string $order_id
     *
     * @return bool
     */
    public function order_requires_payment_tokenization($order_id)
    {
        if (class_exists('WC_Pre_Orders_Order')) {
            return WC_Pre_Orders_Order::order_requires_payment_tokenization($order_id);
        }

        return false;
    }

    /**
     * @return bool
     */
    public function cart_contains_pre_order_fee()
    {
        if (class_exists('WC_Pre_Orders_Cart')) {
            return WC_Pre_Orders_Cart::cart_contains_pre_order_fee();
        }

        return false;
    }

    /**
     * Store the PayBox subscription token
     *
     * @param string $token
     * @param WC_Subscription $subscription
     */
    protected function _set_subscription_token($token, $subscription)
    {
        update_post_meta(self::get_order_prop($subscription, 'id'), '_Paybox_subscription_token', $token);
    }

    /**
     * Retrieve the PayBox subscription token for a given order id.
     *
     * @param WC_Subscription $subscription
     * @return mixed
     */
    protected function _get_subscription_token($subscription)
    {
        return get_post_meta(self::get_order_prop($subscription, 'id'), '_Paybox_subscription_token', true);
    }

    /**
     * Retrieve the PayBox subscription token for a given order id.
     *
     * @param WC_Subscription $subscription
     * @return mixed
     */
    protected function _delete_subscription_token($subscription)
    {
        return delete_post_meta(self::get_order_prop($subscription, 'id'), '_Paybox_subscription_token');
    }

    /**
     * Store the PayBox renewal flag
     * @param WC_Subscription $subscription
     * @since 1.4.3
     */
    protected function _set_renewal_flag($subscription)
    {
        if (version_compare(WC_VERSION, '3.0', '<')) {
            update_post_meta(self::get_order_prop($subscription, 'id'), '_Paybox_renewal_flag', 'true');
        } else {
            $subscription->update_meta_data('_Paybox_renewal_flag', 'true');
            $subscription->save_meta_data();
        }
    }

    /**
     * Retrieve the PayBox renewal flag for a given order id.
     * @param WC_Subscription $subscription
     * @return bool
     * @since 1.4.3
     *
     */
    protected function _has_renewal_flag($subscription)
    {
        if (version_compare(WC_VERSION, '3.0', '<')) {
            return 'true' === get_post_meta(
                    self::get_order_prop($subscription, 'id'),
                    '_Paybox_renewal_flag',
                    true
                );
        }

        return 'true' === $subscription->get_meta('_Paybox_renewal_flag', true);
    }

    /**
     * Retrieve the PayBox renewal flag for a given order id.
     * @param WC_Subscription $subscription
     * @return mixed
     * @since 1.4.3
     *
     */
    protected function _delete_renewal_flag($subscription)
    {
        if (version_compare(WC_VERSION, '3.0', '<')) {
            return delete_post_meta(self::get_order_prop($subscription, 'id'), '_Paybox_renewal_flag');
        }

        $subscription->delete_meta_data('_Paybox_renewal_flag');
        $subscription->save_meta_data();
    }

    /**
     * Store the PayBox pre_order_token token
     *
     * @param string $token
     * @param WC_Order $order
     */
    protected function _set_pre_order_token($token, $order)
    {
        update_post_meta(self::get_order_prop($order, 'id'), '_Paybox_pre_order_token', $token);
    }

    /**
     * Retrieve the PayBox pre-order token for a given order id.
     *
     * @param WC_Order $order
     * @return mixed
     */
    protected function _get_pre_order_token($order)
    {
        return get_post_meta(self::get_order_prop($order, 'id'), '_Paybox_pre_order_token', true);
    }

    /**
     * Wrapper function for wcs_order_contains_subscription
     *
     * @param WC_Order $order
     * @return bool
     */
    public function order_contains_subscription($order)
    {
        if (!function_exists('wcs_order_contains_subscription')) {
            return false;
        }

        return wcs_order_contains_subscription($order);
    }

    /**
     * @param $amount_to_charge
     * @param WC_Order $renewal_order
     */
    public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
    {
        $subscription = wcs_get_subscription(
            get_post_meta(self::get_order_prop($renewal_order, 'id'), '_subscription_renewal', true)
        );
        $this->log('Attempting to renew subscription from renewal order ' . self::get_order_prop($renewal_order, 'id'));

        if (empty($subscription)) {
            $this->log('Subscription from renewal order was not found.');
            return;
        }

        $response = $this->submit_subscription_payment($subscription, $amount_to_charge);

        if (is_wp_error($response)) {
            /* translators: 1: error code 2: error message */
            $renewal_order->update_status(
                $this->get_option('failure_status'),
                sprintf(
                    __(
                        'PayBox Subscription renewal transaction failed (%1$s:%2$s)',
                        'paybox-payment-gateway'
                    ),
                    $response->get_error_code(),
                    $response->get_error_message()
                )
            );
        }
        // Payment will be completion will be capture only when the ITN callback is sent to $this->handle_itn_request().
        $renewal_order->add_order_note(
            __('PayBox Subscription renewal transaction submitted.', 'paybox-payment-gateway')
        );
    }

    /**
     * Get a name for the subscription item. For multiple
     * item only Subscription $date will be returned.
     *
     * For subscriptions with no items Site/Blog name will be returned.
     *
     * @param WC_Subscription $subscription
     * @return string
     */
    public function get_subscription_name($subscription)
    {
        if ($subscription->get_item_count() > 1) {
            return $subscription->get_date_to_display('start');
        }

        $items = $subscription->get_items();

        if (empty($items)) {
            return get_bloginfo('name');
        }

        $item = array_shift($items);

        return $item['name'];
    }


    /**
     * Responds to Subscriptions extension cancellation event.
     *
     * @param WC_Subscription $subscription
     * @since 1.4.0 introduced.
     */
    public function cancel_subscription_listener($subscription)
    {
        $token = $this->_get_subscription_token($subscription);

        if (empty($token)) {
            return;
        }

        $this->api_request('cancel', $token, array(), 'PUT');
    }

    /**
     * @param string $token
     *
     * @return bool|WP_Error
     * @since 1.4.0
     */
    public function cancel_pre_order_subscription($token)
    {
        return $this->api_request('cancel', $token, array(), 'PUT');
    }

    /**
     * @param      $api_data
     * @param bool $sort_data_before_merge ? default true.
     * @param bool $skip_empty_values Should key value pairs be ignored when generating signature?  Default true.
     *
     * @return string
     * @since 1.4.0 introduced.
     */
    protected function _generate_parameter_string($api_data, $sort_data_before_merge = true, $skip_empty_values = true)
    {
        // if sorting is required the passphrase should be added in before sort.
        if (!empty($this->pass_phrase) && $sort_data_before_merge) {
            $api_data['passphrase'] = $this->pass_phrase;
        }

        if ($sort_data_before_merge) {
            ksort($api_data);
        }

        // concatenate the array key value pairs.
        $parameter_string = '';

        foreach ($api_data as $key => $val) {
            if ($skip_empty_values && empty($val)) {
                continue;
            }

            if ('signature' !== $key) {
                $val = urlencode($val);
                $parameter_string .= "$key=$val&";
            }
        }
        // when not sorting passphrase should be added to the end before md5
        if ($sort_data_before_merge) {
            $parameter_string = rtrim($parameter_string, '&');
        } elseif (!empty($this->pass_phrase)) {
            $parameter_string .= 'passphrase=' . urlencode($this->pass_phrase);
        } else {
            $parameter_string = rtrim($parameter_string, '&');
        }

        return $parameter_string;
    }

    /**
     * Setup constants.
     *
     * Setup common values and messages used by the PayBox gateway.
     *
     * @since 1.0.0
     */
    public function setup_constants()
    {
        // Create user agent string.
        define('PPWC_SOFTWARE_NAME', 'WooCommerce');
        define('PPWC_SOFTWARE_VER', WC_VERSION);
        define('PPWC_MODULE_NAME', 'PayBox');
        define('PPWC_MODULE_VER', $this->version);

        // Features
        // - PHP
        $pf_features = 'PHP ' . PHP_VERSION . ';';

        // - cURL
        if (in_array('curl', get_loaded_extensions())) {
            define('PPWC_CURL', '');
            $pf_version = curl_version();
            $pf_features .= ' curl ' . $pf_version['version'] . ';';
        } else {
            $pf_features .= ' nocurl;';
        }

        // Create user agrent
        define(
            'PPWCUSER_AGENT',
            PPWC_SOFTWARE_NAME . '/' . PPWC_SOFTWARE_VER . ' (' . trim(
                $pf_features
            ) . ') ' . PPWC_MODULE_NAME . '/' . PPWC_MODULE_VER
        );

        // General Defines
        define('PPWC_TIMEOUT', 15);
        define('PPWC_EPSILON', 0.01);

        // Messages
        // Error
        define('PPWC_ERR_AMOUNT_MISMATCH', __('Amount mismatch', 'paybox-payment-gateway'));
        define('PPWC_ERR_BAD_ACCESS', __('Bad access of page', 'paybox-payment-gateway'));
        define('PPWC_ERR_BAD_SOURCE_IP', __('Bad source IP address', 'paybox-payment-gateway'));
        define(
            'PPWC_ERR_CONNECT_FAILED',
            __('Failed to connect to PayBox', 'paybox-payment-gateway')
        );
        define(
            'PPWC_ERR_INVALID_SIGNATURE',
            __('Security signature mismatch', 'paybox-payment-gateway')
        );
        define('PPWC_ERR_MERCHANT_ID_MISMATCH', __('Merchant ID mismatch', 'paybox-payment-gateway'));
        define(
            'PPWC_ERR_NO_SESSION',
            __('No saved session found for ITN transaction', 'paybox-payment-gateway')
        );
        define(
            'PPWC_ERR_ORDER_ID_MISSING_URL',
            __('Order ID not present in URL', 'paybox-payment-gateway')
        );
        define('PPWC_ERR_ORDER_ID_MISMATCH', __('Order ID mismatch', 'paybox-payment-gateway'));
        define('PPWC_ERR_ORDER_INVALID', __('This order ID is invalid', 'paybox-payment-gateway'));
        define(
            'PPWC_ERR_ORDER_NUMBER_MISMATCH',
            __('Order Number mismatch', 'paybox-payment-gateway')
        );
        define(
            'PPWC_ERR_ORDER_PROCESSED',
            __('This order has already been processed', 'paybox-payment-gateway')
        );
        define('PPWC_ERR_PDT_FAIL', __('PDT query failed', 'paybox-payment-gateway'));
        define(
            'PPWC_ERR_PDT_TOKEN_MISSING',
            __('PDT token not present in URL', 'paybox-payment-gateway')
        );
        define('PPWC_ERR_SESSIONID_MISMATCH', __('Session ID mismatch', 'paybox-payment-gateway'));
        define('PPWC_ERR_UNKNOWN', __('Unknown error occurred', 'paybox-payment-gateway'));

        // General
        define('PPWC_MSG_OK', __('Payment was successful', 'paybox-payment-gateway'));
        define('PPWC_MSG_FAILED', __('Payment has failed', 'paybox-payment-gateway'));
        define(
            'PPWC_MSG_PENDING',
            __(
                'The payment is pending. Please note, you will receive another Instant Transaction Notification when the payment status changes to "Completed", or "Failed"',
                'paybox-payment-gateway'
            )
        );

        do_action('woocommerce_gateway_Paybox_setup_constants');
    }

    /**
     * Log system processes.
     * @since 1.0.0
     */
    public function log($message)
    {
        if ('yes' === $this->get_option('testmode') || $this->enable_logging) {
            if (empty($this->logger)) {
                $this->logger = new WC_Logger();
            }

            $this->logger->add('PayBox', $message);
        }
    }

    /**
     * validate_signature()
     *
     * Validate the signature against the returned data.
     *
     * @param array $data
     * @param string $signature
     * @return bool
     * @since 1.0.0
     */
    public function validate_signature($data, $signature)
    {
        $result = $data['signature'] === $signature;
        $this->log('Signature = ' . ($result ? 'valid' : 'invalid'));

        return $result;
    }

    /**
     * Validate the IP address to make sure it's coming from PayBox.
     *
     * @param array $source_ip
     * @return bool
     * @since 1.0.0
     */
    public function is_valid_ip($source_ip)
    {
        // Variable initialization
        $valid_hosts = array(
            'www.PayBox.co.za',
            'sandbox.PayBox.co.za',
            'w1w.PayBox.co.za',
            'w2w.PayBox.co.za',
        );

        $valid_ips = array();

        foreach ($valid_hosts as $pf_hostname) {
            $ips = gethostbynamel($pf_hostname);

            if (false !== $ips) {
                $valid_ips = array_merge($valid_ips, $ips);
            }
        }

        // Remove duplicates
        $valid_ips = array_unique($valid_ips);

        $this->log("Valid IPs:\n" . print_r($valid_ips, true));
        $is_valid_ip = in_array($source_ip, $valid_ips);

        return apply_filters('woocommerce_gateway_Paybox_is_valid_ip', $is_valid_ip, $source_ip);
    }

    /**
     * validate_response_data()
     *
     * @param array $post_data
     * @param string $proxy Address of proxy to use or NULL if no proxy.
     * @return bool
     * @since 1.0.0
     */
    public function validate_response_data($post_data, $proxy = null)
    {
        $this->log('Host = ' . $this->validate_url);
        $this->log('Params = ' . print_r($post_data, true));

        if (!is_array($post_data)) {
            return false;
        }

        $response = wp_remote_post($this->validate_url, array(
            'body'       => $post_data,
            'timeout'    => 70,
            'user-agent' => PPWC_USER_AGENT,
        ));

        if (is_wp_error($response) || empty($response['body'])) {
            $this->log("Response error:\n" . print_r($response, true));
            return false;
        }

        parse_str($response['body'], $parsed_response);

        $response = $parsed_response;

        $this->log("Response:\n" . print_r($response, true));

        // Interpret Response
        return is_array($response) && array_key_exists('VALID', $response);
    }

    /**
     * amounts_equal()
     *
     * Checks to see whether the given amounts are equal using a proper floating
     * point comparison with an Epsilon which ensures that insignificant decimal
     * places are ignored in the comparison.
     *
     * eg. 100.00 is equal to 100.0001
     *
     * @param $amount1 Float 1st amount for comparison
     * @param $amount2 Float 2nd amount for comparison
     * @return bool
     * @since 1.0.0
     */
    public function amounts_equal($amount1, $amount2)
    {
        return !(abs((float)$amount1 - (float)$amount2) > PPWC_EPSILON);
    }

    /**
     * Get order property with compatibility check on order getter introduced
     * in WC 3.0.
     *
     * @param WC_Order $order Order object.
     * @param string $prop Property name.
     *
     * @return mixed Property value
     * @since 1.4.1
     *
     */
    public static function get_order_prop($order, $prop)
    {
        switch ($prop) {
            case 'order_total':
                $getter = array($order, 'get_total');
                break;
            default:
                $getter = array($order, 'get_' . $prop);
                break;
        }

        return is_callable($getter) ? call_user_func($getter) : $order->{$prop};
    }

    /**
     *  Show possible admin notices
     *
     */
    public function admin_notices()
    {
        if ('yes' == $this->get_option('enabled')) {
            if (empty($this->merchant_id)) {
                echo '<div class="error paybox-passphrase-message"><p>'
                    . __('PayBox requires a Merchant ID to work.', 'paybox-payment-gateway')
                    . '</p></div>';
            }
            if (empty($this->merchant_key)) {
                echo '<div class="error paybox-passphrase-message"><p>'
                    . __('PayBox required a Merchant Key to work.', 'paybox-payment-gateway')
                    . '</p></div>';
            }
        }
    }

    private function getApiUrlOptions()
    {
        $options = [];

        foreach (explode(',', 'api.paybox.money,api.paybox.ru') as $url) {
            $options[$url] = __($url, 'paybox-payment-gateway');
        }

        return $options;
    }

    private function generateReceiptsForUZ_1_0($orderId)
    {
        $order = wc_get_order($orderId);
        $discountPercentage = ($order->get_total() - $order->get_total_discount()) / $order->get_total() * 100;
        $ofdAmount = 0;
        $receipt['receipt_format'] = 'uz_1_0';
        $receipt['positions'] = [];

        foreach ($order->get_items() as $itemId => $item) {
            $count = $order->get_item_meta($itemId, '_qty', true);
            $itemPrice = floor($item->get_product()->get_price() * $discountPercentage) / 100;

            $receipt['positions'][] = [
                'quantity'     => (int)$count,
                'name'         => $item['name'],
                'price'        => (float)$itemPrice,
                'vat_code'     => $this->get_option('new_tax'),
                'ikpu_code'    => $item->get_product()->get_attribute('ikpu_code'),
                'package_code' => $item->get_product()->get_attribute('package_code'),
                'unit_code'    => $item->get_product()->get_attribute('unit_code'),
            ];

            $ofdAmount += $itemPrice * $count;
        }

        if ('yes' === $this->get_option('ofd_in_delivery')) {
            $receipt['positions'][] = [
                'quantity'     => 1,
                'name'         => !empty($order->get_shipping_method()) ? $order->get_shipping_method() : 'delivery',
                'price'        => $order->get_shipping_total(),
                'vat_code'     => $this->get_option('delivery_new_tax'),
                'ikpu_code'    => $this->get_option('delivery_gnk_ikpu_code'),
                'package_code' => $this->get_option('delivery_gnk_package_code'),
                'unit_code'    => $this->get_option('delivery_gnk_unit_code'),
            ];

            $ofdAmount += (float)$order->get_shipping_total();
        }

        $sumDifference = $order->get_total() - $ofdAmount;
        $receipt['positions'][0]['price'] += floor(
                $sumDifference / $receipt['positions'][0]['quantity'] * 100
            ) / 100;

        $this->data_to_send['pg_receipt'] = $receipt;
    }

    private function generateReceiptsForRU_1_05($orderId)
    {
        $order = wc_get_order($orderId);
        $discountPercentage = ($order->get_total() - $order->get_total_discount()) / $order->get_total() * 100;
        $ofdAmount = 0;
        $receipt['receipt_format'] = $this->get_option('ofd_version');
        $receipt['operation_type'] = $this->get_option('taxation_system');
        $receipt['customer'] = $this->getReceiptCustomer();
        $receipt['positions'] = [];

        foreach ($order->get_items() as $itemId => $item) {
            $count = $order->get_item_meta($itemId, '_qty', true);
            $itemPrice = floor($item->get_product()->get_price() * $discountPercentage) / 100;
            $receipt = [];

            $receipt['positions'][] = [
                'quantity'       => $count,
                'name'           => $item['name'],
                'price'          => $itemPrice,
                'vat_code'       => $this->get_option('new_tax'),
                'payment_method' => $this->get_option('payment_method'),
                'payment_object' => $this->get_option('payment_object'),
            ];

            $ofdAmount += $itemPrice * $count;
        }

        if ('yes' === $this->get_option('ofd_in_delivery')) {
            $receipt['positions'][] = [
                'quantity'       => 1,
                'name'           => $order->get_shipping_method(),
                'price'          => $order->get_shipping_total(),
                'vat_code'       => $this->get_option('delivery_new_tax'),
                'payment_method' => $this->get_option('payment_method'),
                'payment_object' => $this->get_option('delivery_payment_object'),
            ];

            $ofdAmount += (float)$order->get_shipping_total();
        }

        $sumDifference = $order->get_total() - $ofdAmount;
        $receipt['positions'][0]['price'] += floor(
                $sumDifference / $receipt['positions'][0]['quantity'] * 100
            ) / 100;

        $this->data_to_send['pg_receipt'] = $receipt;
    }

    private function getReceiptCustomer()
    {
        $customer = [];

        if (!empty($this->data_to_send['pg_user_contact_email'])) {
            $customer['email'] = $this->data_to_send['pg_user_contact_email'];
        }

        if (!empty($this->data_to_send['pg_user_phone'])) {
            $customer['phone'] = $this->data_to_send['pg_user_phone'];
        }

        return $customer;
    }

    private function generateReceiptPositions($orderId)
    {
        $order = wc_get_order($orderId);
        $discountPercentage = ($order->get_total() - $order->get_total_discount()) / $order->get_total() * 100;
        $ofdAmount = 0;

        foreach ($order->get_items() as $itemId => $item) {
            $count = $order->get_item_meta($itemId, '_qty', true);
            $itemPrice = floor($item->get_product()->get_price() * $discountPercentage) / 100;

            $this->data_to_send['pg_receipt_positions'][] = [
                'count'    => $count,
                'name'     => $item['name'],
                'price'    => $itemPrice,
                'tax_type' => $this->get_option('tax')
            ];

            $ofdAmount += $itemPrice * $count;
        }

        if ('yes' === $this->get_option('ofd_in_delivery')) {
            $this->data_to_send['pg_receipt_positions'][] = [
                'count'    => 1,
                'name'     => $order->get_shipping_method(),
                'price'    => $order->get_shipping_total(),
                'tax_type' => $this->get_option('tax')
            ];

            $ofdAmount += $order->get_shipping_total();
        }

        $sumDifference = $order->get_total() - $ofdAmount;
        $this->data_to_send['pg_receipt_positions'][0]['price'] += floor(
                $sumDifference / $this->data_to_send['pg_receipt_positions'][0]['count'] * 100
            ) / 100;
    }

    /**
     * @param $data
     * @param string $parent_name
     * @return array|string[]
     */
    private function prepare_request_data($data, $parent_name = '')
    {
        if (!is_array($data)) {
            return $data;
        }

        $result = [];
        $i = 0;

        foreach ($data as $key => $val) {
            $name = $parent_name . ((string)$key) . sprintf('%03d', ++$i);

            if (is_array($val)) {
                $result = array_merge($result, $this->prepare_request_data($val, $name));
                continue;
            }

            $result += [$name => (string)$val];
        }

        return $result;
    }
}
