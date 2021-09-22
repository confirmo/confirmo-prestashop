<?php
/**
 * Copyright © 2018 Tomas Hubik <hubik.tomas@gmail.com>
 *
 * NOTICE OF LICENSE
 *
 * This file is part of Confirmo PrestaShop module.
 *
 * Confirmo PrestaShop module is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * Confirmo PrestaShop module is distributed in the hope that it will be
 * useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this Confirmo
 * module to newer versions in the future. If you wish to customize this module
 * for your needs please refer to http://www.prestashop.com for more information.
 *
 *  @author Tomas Hubik <hubik.tomas@gmail.com>
 *  @copyright  2018 Tomas Hubik
 *  @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU General Public License (GPLv3)
 */

defined('_PS_VERSION_') or die;

/**
 * Confirmo main module class.
 */
class Confirmo extends PaymentModule
{
    protected $apiUrl = 'https://confirmo.net/api/v3/';
    protected $apiKey;
    protected $defaultValues = array();
    protected $cryptoCurrencies = array(
        'BTC' => 'Bitcoin',
        'LTC' => 'Litecoin'
    );
    protected $payoutCurrencies = array(
        array('code' => 'CRYPTO', 'name' => 'Same crypto customer pays'),
        array('code' => 'BTC', 'name' => 'BTC'),
        array('code' => 'CZK', 'name' => 'CZK'),
        array('code' => 'EUR', 'name' => 'EUR'),
        array('code' => 'USD', 'name' => 'USD'),
        array('code' => 'PLN', 'name' => 'PLN'),
        array('code' => 'GBP', 'name' => 'GBP')
    );

    /**
     * @see Module::__construct()
     */
    public function __construct()
    {
        $this->name = 'confirmo';
        $this->tab = 'payments_gateways';
        $this->version = '3.2.1';
        $this->author = 'Tomas Hubik';
        $this->author_uri = 'https://github.com/confirmo';
        $this->ps_versions_compliancy = array('min' => '1.5', 'max' => '1.7');
        $this->controllers = array('payment', 'notification', 'return');

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l("CONFIRMO");
        $this->description = $this->l("Accept payments in cryptocurrencies and receive payouts in multiple currencies.");

        if (!$this->getConfigValue('API_KEY')) {
            $this->warning = $this->l("Account settings must be configured before using this module.");
        }

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l("No currencies have been enabled for this module.");
        }

        $apiKey = $this->getConfigValue('API_KEY');
        if ($apiKey) {
            $this->apiKey = $apiKey;
        }
    }

    /**
     * @see Module::install()
     */
    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()
            // only for PrestaShop 1.6 and lower
            || (version_compare(_PS_VERSION_, '1.7', '<') && !$this->registerHook('payment'))
            // only for PrestaShop 1.7 and higher
            || (version_compare(_PS_VERSION_, '1.7', '>=') && !$this->registerHook('paymentOptions'))
            || !$this->registerHook('paymentReturn')
        ) {
            return false;
        }

        // create custom order statuses
        $this->createOrderStatus('PAYMENT_RECEIVED', "Payment received (unconfirmed)", array(
            'color' => '#FF8C00',
            'paid' => false,
        ));

        $this->createOrderStatus('WAITING_FOR_PAYMENT', "Waiting for payment", array(
            'color' => '#FF8000',
            'paid' => false,
        ));

        return true;
    }

    /**
     * @see Module::uninstall()
     */
    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('CONFIRMO_API_KEY') ||
            !Configuration::deleteByName('CONFIRMO_CALLBACK_PASSWORD') ||
            !Configuration::deleteByName('CONFIRMO_PAYOUT_CURRENCY') ||
            !Configuration::deleteByName('CONFIRMO_CALLBACK_SSL') ||
            !Configuration::deleteByName('CONFIRMO_INVOICE_URL_MESSAGE') ||
            !Configuration::deleteByName('CONFIRMO_NOTIFY_EMAIL') ||
            !Configuration::deleteByName('CONFIRMO_STATUS_CREATED') ||
            !Configuration::deleteByName('CONFIRMO_STATUS_RECEIVED') ||
            !Configuration::deleteByName('CONFIRMO_STATUS_CONFIRMED') ||
            !Configuration::deleteByName('CONFIRMO_STATUS_ERROR') ||
            !Configuration::deleteByName('CONFIRMO_UPDATE_ORDER_MESSAGES')/* ||
            !Configuration::deleteByName('CONFIRMO_STATUS_REFUND')*/
        ) {
            return false;
        }

        // delete custom order statuses
        $this->deleteOrderStatus('PAYMENT_RECEIVED');
        $this->deleteOrderStatus('WAITING_FOR_PAYMENT');

        return true;
    }

    /**
     * Handles the configuration page.
     *
     * @return string form html with eventual error/notification messages
     */
    public function getContent()
    {
        $output = "";
        $loadInitial = true;

        // check if form has been submitted
        if (Tools::isSubmit('submit' . $this->name)) {
            $fieldValues = $this->getConfigFieldValues(false);

            // check api key
            if ($fieldValues['CONFIRMO_API_KEY'] == "") {
                $output .= $this->displayError($this->l("API Key is required."));
            }

            // check callback password
            if ($fieldValues['CONFIRMO_CALLBACK_PASSWORD'] == "") {
                $output .= $this->displayError($this->l("Callback Password is required."));
            }

            // check payout currency
            if ($fieldValues['CONFIRMO_PAYOUT_CURRENCY'] == "") {
                $output .= $this->displayError($this->l("Payout Currency is required."));
            }

            // verify api key and payout currency with account (if there are no prior validation errors)
            if ($output == "") {
                try {
                    // get list of settlement currencies from the account
                    $this->apiKey = $fieldValues['CONFIRMO_API_KEY'];
                    $response = $this->apiRequest('settlement-methods');

                    if (!$response->data || !is_array($response->data)) {
                        $errorMsg = $this->l("Error while retrieving settlement methods from you account.");
                        $output .= $this->displayError($errorMsg);
                    } else {
                        // Extract only enabled currencies (settlement set in the account)
                        $enabledCurrenciesArray = $this->extractEnabledSettlementCurrencies($response->data);
                        if (empty($enabledCurrenciesArray)) {
                            $errorMsg = $this->l("Settlement methods not set in your CONFIRMO account. Go to Settings > Settlement Methods and add settlement methods first.");
                            $output .= $this->displayError($errorMsg);
                        } else {
                            // Check if there is a settlement currency for payout currency in fiat
                            if ($fieldValues['CONFIRMO_PAYOUT_CURRENCY'] != 'CRYPTO' && !in_array($fieldValues['CONFIRMO_PAYOUT_CURRENCY'], $enabledCurrenciesArray)) {
                                $errorMsg = $this->l("Settlement method is not set in your CONFIRMO account. You currently have the following settlement methods:");
                                $errorMsg .= '<br> <ul><li>' . implode('</li><li>', $enabledCurrenciesArray) . '</li></ul>';
                                $errorMsg .= sprintf($this->l("Please add %s settlement method in your Confirmo account first: Settings > Settlement methods > Add settlement method"), $fieldValues['CONFIRMO_PAYOUT_CURRENCY']);
                                $output .= $this->displayError($errorMsg);
                            }
                        }
                    }
                } catch (Exception $e) {
                    $output .= $this->displayError($e->getMessage());
                }
            }

            // save only if there are no validation errors
            if ($output == "") {
                $loadInitial = true;
                foreach ($fieldValues as $fieldName => $fieldValue) {
                    Configuration::updateValue($fieldName, $fieldValue);
                }

                $output .= $this->displayConfirmation($this->l("CONFIRMO settings saved."));
            } else {
                $loadInitial = false;
            }
        }

        return $output . $this->renderSettingsForm($loadInitial);
    }

    /**
     * Renders the settings form for the configuration page.
     *
     * @param bool $initial if the form should load initial values from the database (true) or not (false)
     *
     * @return string form html
     */
    public function renderSettingsForm($initial = true)
    {
        // get default language
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        // get all order statuses to use for order status options
        $orderStatuses = OrderState::getOrderStates((int)$this->context->cookie->id_lang);

        // form fields
        $formFields = array(
            array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l("CONFIRMO Settings"),
                        'icon' => 'icon-cog'
                    ),
                    'tabs' => array(
                        'general' => $this->l("General"),
                        'order_statuses' => $this->l("Order Statuses")
                    ),
                    'input' => array(
                        array(
                            'tab' => 'general',
                            'name' => 'CONFIRMO_API_KEY',
                            'type' => 'text',
                            'label' => $this->l("API Key"),
                            'desc' => $this->l("API key is used for backend authentication and you should keep it private. To find your API key, go to Settings > API Keys."),
                            'required' => true
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'CONFIRMO_CALLBACK_PASSWORD',
                            'type' => 'text',
                            'label' => $this->l("Callback Password"),
                            'desc' => $this->l("Used as a data validation for stronger security. Callback password must be set under Settings > Security in your CONFIRMO account."),
                            'required' => true
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'CONFIRMO_PAYOUT_CURRENCY',
                            'label' => $this->l("Payout Currency"),
                            'type' => 'select',
                            'desc' => $this->l("Currency of settlement. You must first set a settlement method for the currency in your CONFIRMO account in Settings > Settlement Methods."),
                            'options' => array(
                                'query' => $this->payoutCurrencies,
                                'id' => 'code',
                                'name' => 'name'
                            ),
                            'required' => true
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'CONFIRMO_CALLBACK_SSL',
                            'type' => 'switch',
                            'label' => $this->l("Callback SSL"),
                            'desc' => $this->l("Allows SSL (HTTPS) to be used for payment callbacks sent to your server. Note that some SSL certificates may not work (such as self-signed certificates), so be sure to do a test payment if you enable this to verify that your server is able to receive callbacks successfully."),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l("Enable")
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l("Disable")
                                )
                            )
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'CONFIRMO_INVOICE_URL_MESSAGE',
                            'type' => 'switch',
                            'label' => $this->l("Customer Message with Invoice URL"),
                            'desc' => $this->l("Creates new message with CONFIRMO invoice URL for every order so that customer can access it from order detail page. This setting will create new customer thread for every order."),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l("Enable")
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l("Disable")
                                )
                            )
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'CONFIRMO_NOTIFY_EMAIL',
                            'type' => 'text',
                            'label' => $this->l("Notification Email"),
                            'desc' => $this->l("Email address to send payment status notifications to. Leave blank to disable.")
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'CONFIRMO_UPDATE_ORDER_MESSAGES',
                            'type' => 'switch',
                            'label' =>  $this->l("Update order messages"),
                            'desc' =>  $this->l("When enabled, payment status is added to the order as a message."),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l("Enable")
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l("Disable")
                                )
                            )
                        ),
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'CONFIRMO_STATUS_CONFIRMED',
                            'type' => 'select',
                            'label' => $this->l("PAID"),
                            'desc' => $this->l("The invoice is paid and has enough confirmations."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        ),
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'CONFIRMO_STATUS_RECEIVED',
                            'type' => 'select',
                            'label' => $this->l("CONFIRMING"),
                            'desc' => $this->l("At least the required amount has been paid but a sufficient number of confirmations has not been received yet."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        ),
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'CONFIRMO_STATUS_CREATED',
                            'type' => 'select',
                            'label' => $this->l("ACTIVE"),
                            'desc' => $this->l("Order was created, but was not paid yet."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        ),
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'CONFIRMO_STATUS_ERROR',
                            'type' => 'select',
                            'label' => $this->l("ERROR"),
                            'desc' => $this->l("Invoice expired, or any other unexpected behaviour happened."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        )/*,
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'CONFIRMO_STATUS_ERROR',
                            'type' => 'select',
                            'label' => $this->l("Payment Error"),
                            'desc' => $this->l("The invoice has not been paid in the required timeframe or amount."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        ),
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'CONFIRMO_STATUS_REFUND',
                            'type' => 'select',
                            'label' => $this->l("Payment Refund"),
                            'desc' => $this->l("The payment has been returned to the customer."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        )*/
                    ),
                    'submit' => array(
                        'title' => $this->l("Save")
                    )
                )
            )
        );

        // set up form
        $helper = new HelperForm;

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l("Save"),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules')
            ),
            'back' => array(
                'desc' => $this->l("Back to List"),
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules')
            )
        );

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldValues($initial),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm($formFields);
    }

    /**
     * Loads module config parameters values.
     *
     * @return array array of config values
     */
    public function getConfigFieldValues()
    {
        return array(
            'CONFIRMO_API_KEY' => $this->getConfigValue('API_KEY', true),
            'CONFIRMO_CALLBACK_PASSWORD' => $this->getConfigValue('CALLBACK_PASSWORD', true),
            'CONFIRMO_CALLBACK_SSL' => $this->getConfigValue('CALLBACK_SSL', true),
            'CONFIRMO_INVOICE_URL_MESSAGE' => $this->getConfigValue('INVOICE_URL_MESSAGE', true),
            'CONFIRMO_PAYOUT_CURRENCY' => $this->getConfigValue('PAYOUT_CURRENCY', true),
            'CONFIRMO_NOTIFY_EMAIL' => $this->getConfigValue('NOTIFY_EMAIL', true),
            'CONFIRMO_STATUS_CREATED' => $this->getConfigValue('STATUS_CREATED', true),
            'CONFIRMO_STATUS_CONFIRMED' => $this->getConfigValue('STATUS_CONFIRMED', true),
            'CONFIRMO_STATUS_RECEIVED' => $this->getConfigValue('STATUS_RECEIVED', true),
            'CONFIRMO_STATUS_ERROR' => $this->getConfigValue('STATUS_ERROR', true),
            'CONFIRMO_UPDATE_ORDER_MESSAGES' => $this->getConfigValue('UPDATE_ORDER_MESSAGES', true)/*,
            'CONFIRMO_STATUS_REFUND' => $this->getConfigValue('STATUS_REFUND', true)*/
        );
    }

    /**
     * Loads module default config parameters values.
     *
     * @return array array of module default config values
     */
    public function getDefaultValues()
    {
        if (!$this->defaultValues) {
            $this->defaultValues = array(
                'CONFIRMO_STATUS_CONFIRMED' => Configuration::get('PS_OS_PAYMENT'),
                'CONFIRMO_STATUS_RECEIVED' => $this->getOrderStatus('PAYMENT_RECEIVED'),
                'CONFIRMO_STATUS_CREATED' => $this->getOrderStatus('WAITING_FOR_PAYMENT'),
                'CONFIRMO_STATUS_ERROR' => Configuration::get('PS_OS_ERROR'),
                //'CONFIRMO_STATUS_REFUND' => Configuration::get('PS_OS_REFUND'),
            );
        }

        return $this->defaultValues;
    }

    /**
     * Reads configuration parameter value from the database or form POST data if required.
     *
     * @param string $key name of the parameter without the prefix
     * @param bool $post whether to read the value from form POST data (true) or database (false)
     *
     * @return config parameter value
     */
    public function getConfigValue($key, $post = false)
    {
        $name = 'CONFIRMO_' . $key;
        $value = trim($post && Tools::getIsset($name) ? Tools::getValue($name) : Configuration::get($name));

        // use default value if empty
        if (!Tools::strlen($value)) {
            $defaultValues = $this->getDefaultValues();

            if (isset($defaultValues[$name])) {
                $value = $defaultValues[$name];
            }
        }

        return $value;
    }

    /**
     * Handles hook for payment options for PS < 1.7.
     */
    public function hookPayment($params)
    {
        if (!$this->active || !$this->apiKey || !$this->checkCurrency($params['cart'])) {
            return;
        }

        $paymentUrl = $this->context->link->getModuleLink($this->name, 'payment', array(), Configuration::get('PS_SSL_ENABLED'));

        $this->smarty->assign(array(
            'prestashop_15' => version_compare(_PS_VERSION_, '1.5', '>=') && version_compare(_PS_VERSION_, '1.6', '<'),
            'payment_url' => $paymentUrl
        ));

        return $this->display(__FILE__, 'payment.tpl');
    }

    /**
     * Handles hook for payment options for PS >= 1.7.
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->apiKey || !$this->checkCurrency($params['cart'])) {
            return;
        }

        $paymentButtons = array();
        $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $newOption->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with Crypto'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), Configuration::get('PS_SSL_ENABLED')))
            ->setAdditionalInformation($this->context->smarty->fetch('module:confirmo/views/templates/front/payment_infos.tpl'))
            ->setLogo($this->_path . 'views/img/ccy_crypto_small.svg');
        $paymentButtons[] = $newOption;

        return $paymentButtons;
    }

    /**
     * Handles hook for return from the payment gateway.
     */
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        if (version_compare(_PS_VERSION_, '1.7', '>=') === true) {
            $order = $params['order'];
        } else {
            $order = $params['objOrder'];
        }

        $id_order_states = Db::getInstance()->ExecuteS('
            SELECT `id_order_state`
            FROM `' . _DB_PREFIX_ . 'order_history`
            WHERE `id_order` = ' . $order->id . '
            ORDER BY `date_add` DESC, `id_order_history` DESC
        ');

        $created = false;
        $outofstock = false;
        $confirmed = false;
        $received = false;
        $refunded = false;
        $error = false;
        foreach ($id_order_states as $state) {
            if ($state['id_order_state'] == (int)Configuration::get('PS_OS_OUTOFSTOCK')) {
                $outofstock = true;
            }
            if ($state['id_order_state'] == $this->getConfigValue('STATUS_CONFIRMED')) {
                $confirmed = true;
            }
            if ($state['id_order_state'] == $this->getConfigValue('STATUS_RECEIVED')) {
                $received = true;
            }
            if ($state['id_order_state'] == $this->getConfigValue('STATUS_CREATED')) {
                $created = true;
            }
            /*if ($state['id_order_state'] == $this->getConfigValue('STATUS_REFUND')) {
                $refunded = true;
            }
            if ($state['id_order_state'] == $this->getConfigValue('STATUS_ERROR')) {
                $error = true;
            }*/
        }

        $this->smarty->assign(array(
            'products' => $order->getProducts(),
            'confirmed' => $confirmed,
            'received' => $received,
            'refunded' => $refunded,
            'error' => $error,
            'outofstock' => $outofstock,
            'created' => $created
        ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Translates status codes to human readable descriptions.
     *
     * @param string $status status code
     * @param bool $unhandledExceptions if there was an unhandledExceptions flag included in the invoice model
     *
     * @return string human readable status description
     */
    public function getStatusDesc($status, $unhandledExceptions)
    {
        if ($status ==='active') {
            return $this->l("Active — Waiting for payment.");
        } elseif ($status ==='expired' && !$unhandledExceptions) {
            return $this->l("Expired — Not paid in the configurable required timeframe.");
        } elseif ($status ==='expired' && $unhandledExceptions) {
            return $this->l("Underpayment — Paid amount is lower than required.");
        } elseif ($status ==='confirming' && !$unhandledExceptions) {
            return $this->l("Confirming — Payment has been received but not confirmed yet.");
        } elseif ($status ==='confirming' && $unhandledExceptions) {
            return $this->l("Overpayment — Payment has been received but not confirmed yet, more funds than requested received.");
        } elseif ($status ==='paid' && !$unhandledExceptions) {
            return $this->l("Paid — Payment is confirmed.");
        } elseif ($status ==='paid' && $unhandledExceptions) {
            return $this->l("Overpayment — Payment is confirmed, more funds than requested received.");
        } else {
            return $status . ', exception: ' . $unhandledExceptions ? "yes" : "no";
        }
    }

    /**
     * Requests a new Confirmo invoice.
     *
     * @param Cart $cart cart object to use for the payment request
     * @param string $cryptoCurrency cryptocurrency to use for the payment request
     * @param array $requestData optional array of request data to override values retrieved from the order object
     *
     * @return array response data with new invoice
     *
     * @throws UnexpectedValueException if no API key has been set
     * @throws Exception if an unexpected API response was returned
     */
    public function createPayment($cart, $requestData = array())
    {
        if (!$this->apiKey) {
            throw new UnexpectedValueException("CONFIRMO API Key has not been set.");
        }

        $customer = new Customer($cart->id_customer);

        // build request data
        $request = array(
            'invoice' => array(
                'amount' => (string)$cart->getOrderTotal(),
                'currencyFrom' => Currency::getCurrencyInstance($cart->id_currency)->iso_code,
                'currencyTo' => null
            ),
            'settlement' => array(
                // for cryptocurrencies, settlement currency must match the invoice currency - custom settlement currency is possible only for fiat currencies
                'currency' => $this->getConfigValue('PAYOUT_CURRENCY') == 'CRYPTO' ? null : $this->getConfigValue('PAYOUT_CURRENCY')
            ),
            'reference' => json_encode(array(
                'cart_id' => (string)$cart->id,
                'shop_id' => (string)$cart->id_shop,
                'customer_name' => $customer->firstname . " " . $customer->lastname,
                'customer_email' => $customer->email
            )),
            'returnUrl' => $this->context->link->getModuleLink($this->name, 'return', array('cart_id' => $cart->id, 'key' => $customer->secure_key), true),
            'notifyUrl' => $this->context->link->getModuleLink($this->name, 'notification', array('key' => $customer->secure_key), (bool)$this->getConfigValue('CALLBACK_SSL'))
        );
        if ($notifyEmail = $this->getConfigValue('NOTIFY_EMAIL')) {
            $request['notifyEmail'] = $notifyEmail;
        }
        // override default request data if set
        if ($requestData) {
            $request = array_merge_recursive($request, $requestData);
        }

        // request new payment
        return $this->apiRequest('invoices', $request);
    }

    /**
     * Makes a new API request to Confirmo.
     *
     * @param string $endpoint API endpoint URI segment, after `.../api/v1/` for example
     * @param array $request API request post data
     * @param bool $returnRaw return the raw response string
     *
     * @return stdClass response data after json_decode
     *
     * @throws Exception
     */
    public function apiRequest($endpoint, $request = array(), $returnRaw = false)
    {
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $this->apiUrl . ltrim($endpoint, '/'),
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->apiKey,
                "X-Payment-Module: PrestaShop",
            ),
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ));

        // if $request is set then POST it, otherwise just GET it
        if ($request) {
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($request),
            ));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // return just raw response if required
        if ($returnRaw) {
            return $response;
        }

        if (trim($response)) {
            $data = json_decode($response);

            // check if the response contains information about errors
            if (isset($data->errors)) {
                $error = $data->errors;
            } elseif (isset($data->error)) {
                $error = $data->error;
            } else {
                return $data;
            }
        }

        // if the response contained information about error, then compile the whole error sting and throw new exception
        if (is_string($error)) {
            $error = "CONFIRMO: " . ($error ?: "Unknown API error.");
        } else {
            if (defined('JSON_PRETTY_PRINT')) {
                $error = json_encode($error, JSON_PRETTY_PRINT);
            } else {
                $error = json_encode($error);
            }
        }
        throw new Exception($error);
    }

    /**
     * Creates a custom order status for this module.
     *
     * @param string $name new status name
     * @param string $label new status lables
     * @param array $options optional additional options
     * @param string $template optional template
     * @param string $icon status icon
     *
     * @return int|bool false on failure, new status ID if successful
     */
    public function createOrderStatus($name, $label, $options = array(), $template = null, $icon = 'status.gif')
    {
        $osName = 'CONFIRMO_OS_' . Tools::strtoupper($name);

        if (!Configuration::get($osName)) {
            $os = new OrderState();
            $os->module_name = $this->name;

            // set label for each language
            $os->name = array();
            foreach (Language::getLanguages() as $language) {
                $os->name[$language['id_lang']] = $label;

                if ($template !== null) {
                    $os->template[$language['id_lang']] = $template;
                }
            }

            // set order status options
            foreach ($options as $optionName => $optionValue) {
                if (property_exists($os, $optionName)) {
                    $os->$optionName = $optionValue;
                }
            }

            if ($os->add()) {
                Configuration::updateValue($osName, (int)$os->id);

                // copy icon image to os folder
                if ($icon) {
                    copy(dirname(__FILE__) . '/views/img/' . $icon, _PS_ROOT_DIR_ . '/img/os/' . $os->id . '.gif');
                }

                return (int)$os->id;
            } else {
                return false;
            }
        }
    }

    /**
     * Deletes custom order status for this module by name.
     *
     * @param string $name status name
     */
    public function deleteOrderStatus($name)
    {
        $osName = 'CONFIRMO_OS_' . Tools::strtoupper($name);
        $osId = Configuration::get($osName);

        if ($osId) {
            $os = new OrderState($osId);
            $os->delete();

            Configuration::deleteByName($osName);

            @unlink(_PS_ROOT_DIR_ . '/img/os/' . $osId . '.gif');
        }
    }

    /**
     * Gets the custom order status ID by name.
     *
     * @param string $name status name
     *
     * @return int|bool false on failure to retrieve, status ID if successful
     */
    public function getOrderStatus($name)
    {
        return (int)ConfigurationCore::get('CONFIRMO_OS_' . Tools::strtoupper($name));
    }


    /**
     * Validates Confirmo response callback password.
     *
     * @param string $callback raw callback json string
     *
     * @return bool true if the validation is successful
     */
    public function checkCallbackPassword($callback)
    {
        // check callback password if it has been set
        $callbackPassword = $this->getConfigValue('CALLBACK_PASSWORD');
        if ($callbackPassword) {
            if (!isset($_SERVER['HTTP_BP_SIGNATURE']) || $_SERVER['HTTP_BP_SIGNATURE'] != hash('sha256', $callback . $callbackPassword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check that this payment method is enabled for the cart currency.
     *
     * @param Cart $cart cart object
     *
     * @return bool true if this payment method is enabled for the cart currency
     */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency((int)($cart->id_currency));
        $currencies_module = $this->getCurrency((int)$cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Translates currency code to currency name.
     *
     * @param string $currencyCode currency code
     *
     * @return string currency name
     */
    public function currencyCodeToName($currencyCode)
    {
        return $this->cryptoCurrencies[$currencyCode];
    }

    /**
     * Extracts enabled settlement currencies from the API response object.
     *
     * @param array $settlementMethods response object from the API
     *
     * @return array array of enabled settlement currency codes
     */
    private function extractEnabledSettlementCurrencies($settlementMethods)
    {
        $currencies = array();
        foreach ($settlementMethods as $currency) {
            if ($currency->active)
                $currencies[] = $currency->currency;
        }
        return $currencies;
    }
}
