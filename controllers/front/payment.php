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
 * Controller generating payment URLs and redirecting customers to the payment gateway.
 */
class ConfirmoPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_column_left = false;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;

        // if cart is empty then redirect to the home page
        if ($cart->nbProducts() <= 0) {
            Tools::redirect('index.php');
        }

        // if current currency isn't enabled for this method, then display error
        if (!$this->module->checkCurrency($cart)) {
            $this->displayError("Current currency not enabled for this payment method.");
        }

        $cryptoCurrency = Tools::getValue('currency');
        // if crypto currency isn't set, then display error
        if (!$cryptoCurrency) {
            $this->displayError("Payment cryptocurrency not set.");
        }

        // attempt to create a new Confirmo payment
        try {
            $response = $this->module->createPayment($cart, $cryptoCurrency);

            // if the response does not contain URL to the payment gateway, display error
            if (!empty($response->url)) {
                Tools::redirect($response->url);
            } else {
                $this->displayError("Failed to retrieve CONFIRMO payment URL.");
            }
        } catch (Exception $e) {
            $this->displayError($e->getMessage());
        }
    }

    /**
     * Redirects to the error page and displays message to the customer.
     *
     * @param string $errorMessage error message to display to the customer
     */
    public function displayError($errorMessage)
    {
        // display payment request error page
        $heading = $this->module->l("CONFIRMO Error");
        if (isset($this->context->smarty->tpl_vars['meta_title'])) {
            $meta_title = $heading . ' - ' . $this->context->smarty->tpl_vars['meta_title']->value;
        } else {
            $meta_title = $heading;
        }

        $this->context->smarty->assign(array(
            'heading' => $heading,
            'meta_title' => $meta_title,
            'error' => $errorMessage,
            'hide_left_column' => true,
        ));

        if (version_compare(_PS_VERSION_, '1.7', '>=') === true) {
            $this->setTemplate('module:confirmo/views/templates/front/payment_error17.tpl');
        } else {
            $this->setTemplate('payment_error.tpl');
        }
    }
}
