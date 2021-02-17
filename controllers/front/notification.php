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

/**
 * Controller for Confirmo callbacks.
 */
class ConfirmoNotificationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();

        // get the callback data
        $callback = Tools::file_get_contents('php://input');
        // log all callbacks received for debugging purposes
        //$this->error("Callback received:", $callback, false);
        if (!$callback) {
            // the callback data is empty, just die without logging anything
            die;
        }

        // check callback password if set
        if (!$this->module->checkCallbackPassword($callback)) {
            $this->error("Callback password validation failed.", $callback);
        }

        // check that the callback has the reference data we need
        $callbackData = json_decode($callback);
        if (empty($callbackData->reference)) {
            $this->error("Reference data missing from callback.", $callback);
        }
        $reference = json_decode($callbackData->reference);
        if (empty($reference->cart_id)) {
            $this->error("Cart ID missing from callback.", $callback);
        }

        // check that the cart and currency are both valid
        $cart = new Cart((int)$reference->cart_id);
        if (!$callbackData->merchantAmount || !$callbackData->merchantAmount->currency || !$callbackData->merchantAmount->amount) {
            $this->error("Invoice, invoice amount or invoice currency not set.", $callback);
        }
        $currency = Currency::getCurrencyInstance((int)Currency::getIdByIsoCode($callbackData->merchantAmount->currency));
        if (!Validate::isLoadedObject($cart) || (!Validate::isLoadedObject($currency) || $currency->id != $cart->id_currency)) {
            $this->error("Cart or currency in callback is invalid.", $callback);
        }

        // check customer and secure key
        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->error("Customer not found or invalid.", $callback);
        } elseif ($customer->secure_key != Tools::getValue('key')) {
            $this->error("Secure key is invalid.", $callback);
        }

        // set context variables
        $this->context->cart = $cart;
        $this->context->customer = $customer;

        // set order status according to payment status
        switch ($callbackData->status) {
            case 'paid':
                $orderStatus = (int)$this->module->getConfigValue('STATUS_CONFIRMED');
                break;
            case 'confirming':
                $orderStatus = (int)$this->module->getConfigValue('STATUS_RECEIVED');
                break;
            case 'expired':
            case 'error':
                $orderStatus = (int)$this->module->getConfigValue('STATUS_ERROR');
                break;
            case 'prepared':
            case 'active':
                // still waiting for payment
                $orderStatus = (int)$this->module->getConfigValue('STATUS_CREATED');
                break;
            default:
                // payment status is one we don't handle, so just stop processing
                $this->error("Unknown callback status:", $callback);
                die;
        }

        // check if this cart has already been converted into an order
        if ($cart->orderExists()) {
            $order = new Order((int)OrderCore::getOrderByCartId($cart->id));

            // if the order status is different from the current one, add order history
            if ($order->current_state != $orderStatus) {
                $orderHistory = new OrderHistory();
                $orderHistory->id_order = $order->id;
                $orderHistory->changeIdOrderState($orderStatus, $order, true);
                $orderHistory->addWithemail(true);
            }

            // attach new note for updated payment status
            $message = new Message();
            $message->message = $this->module->l('Updated Payment Status') . ': ' . $this->module->getStatusDesc($callbackData->status, $callbackData->unhandledExceptions);
            $message->id_cart = $order->id_cart;
            $message->id_customer = $order->id_customer;
            $message->id_order = $order->id;
            $message->private = true;
            $message->add();

        } else {
            // create order
            $extra = array('transaction_id' => $callbackData->id);
            $shop = !empty($reference->shop_id) ? new Shop((int)$reference->shop_id) : null;

            $payment_method = $this->module->l("CONFIRMO");
            $this->module->validateOrder($cart->id, $orderStatus, $callbackData->merchantAmount->amount, $payment_method, null, $extra, null, false, $customer->secure_key, $shop);
            $order = new Order($this->module->currentOrder);

            // add Confirmo payment info to private order note for admin reference
            $messageLines = array(
                $this->module->l('Payment Status') . ': ' . $this->module->getStatusDesc($callbackData->status, $callbackData->unhandledExceptions),
                $this->module->l('Payment ID') . ': ' . $callbackData->id,
                $this->module->l('Payment Address') . ': ' . $callbackData->address,
            );
            if ($callbackData->customerAmount) {
                $messageLines[] = $this->module->l('Requested Amount') . ': ' . sprintf('%f', $callbackData->customerAmount->amount) . ' ' . $callbackData->customerAmount->currency;
            }
            if ($callbackData->paid) {
                $messageLines[] = $this->module->l('Paid Amount') . ': ' . sprintf('%f', $callbackData->paid->amount) . ' ' . $callbackData->paid->currency;
                $messageLines[] = $this->module->l('Difference') . ': ' . sprintf('%f', $callbackData->paid->diff) . ' ' . $callbackData->paid->currency;
            }
            if ($callbackData->rate) {
                $messageLines[] = $this->module->l('Exchange rate') . ': ' . sprintf('%f', $callbackData->rate->value) . ' ' . $callbackData->rate->currencyFrom . '/' . $callbackData->rate->currencyTo;
            }
            if (!empty($callbackData->url)) {
                $messageLines[] = $this->module->l('Invoice URL') . ': ' . $callbackData->url;
            }
            $message = new Message();
            $message->message = implode(PHP_EOL . ' ', $messageLines);
            $message->id_cart = $order->id_cart;
            $message->id_customer = $order->id_customer;
            $message->id_order = $order->id;
            $message->private = true;
            $message->add();

            // add Confirmo invoice URL to customer order note if enabled
            if (!empty($callbackData->url) && $this->module->getConfigValue('INVOICE_URL_MESSAGE')) {
                $customer_thread = new CustomerThread();
                $customer_thread->id_contact = 0;
                //$customer_thread->id_customer = 0;
                $customer_thread->id_order = (int)$order->id;
                $customer_thread->id_shop = !empty($shop) ? (int)$shop->id : null;
                $customer_thread->id_lang = (int)$this->context->language->id;
                //$customer_thread->email = $customer->email;
                $customer_thread->status = 'closed';
                $customer_thread->token = Tools::passwdGen(12);
                $customer_thread->add();
                $customer_message = new CustomerMessage();
                $customer_message->id_customer_thread = $customer_thread->id;
                $customer_message->id_employee = 0;
                $customer_message->message = $this->module->l('CONFIRMO Invoice URL') . ': ' . $callbackData->url;
                $customer_message->private = 0;
                $customer_message->add();
            }
        }

        // we're done doing what we need to do, so make sure nothing else happens
        die;
    }

    /**
     * Writes an error message to /log/confirmo_errors.log and halts execution if not set otherwise.
     *
     * @param string $message error message
     * @param string $dataString callback string
     * @param bool $die halts the whole execution after writing to log if true
     */
    public function error($message, $dataString = "", $die = true)
    {
        $entry = date('Y-m-d H:i:s P') . " -- " . $message;

        if ($dataString != "") {
            $entry .= PHP_EOL . $dataString;
        }

        error_log($entry . PHP_EOL, 3, _PS_ROOT_DIR_ . '/log/confirmo_errors.log');

        if ($die) {
            die;
        }
    }
}
