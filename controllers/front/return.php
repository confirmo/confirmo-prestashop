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
 * Controller taking care of situation when customer returns from the payment gateway.
 */
class ConfirmoReturnModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();

        $returnStatus = Tools::getValue('bitcoinpay-status');

        if (!empty($returnStatus)) {
            switch ($returnStatus) {
                case 'paid':
                case 'confirming':
                    $cart_id = (int)Tools::getValue('cart_id');
                    $secure_key = Tools::getValue('key');

                    $cart = new Cart($cart_id);
                    $customer = new Customer($cart->id_customer);

                    // first verify the secure key
                    if ($customer->secure_key != $secure_key) {
                        Tools::redirect('index.php');
                        break;
                    }

                    // check if order has been created yet (via the callback)
                    if ($cart->orderExists()) {
                        // order has been created, so redirect to order confirmation page
                        Tools::redirectLink($this->context->link->getPageLink('order-confirmation', true, null, array(
                            'id_cart' => $cart_id,
                            'id_module' => $this->module->id,
                            'bitcoinpay-status' => $returnStatus,
                            'key' => $secure_key,
                        )));
                    } else {
                        // oh snap! the order hasn't been created yet which means the callback is not being sent/received or there is another problem
                        // let's show an appropriate error page to the customer and hope for the best
                        $heading = $this->module->l("CONFIRMO Error");

                        if (isset($this->context->smarty->tpl_vars['meta_title'])) {
                            $meta_title = $heading . ' - ' . $this->context->smarty->tpl_vars['meta_title']->value;
                        } else {
                            $meta_title = $heading;
                        }

                        $this->context->smarty->assign(array(
                            'heading' => $heading,
                            'meta_title' => $meta_title,
                            'hide_left_column' => true,
                        ));

                        if (version_compare(_PS_VERSION_, '1.7', '>=') === true) {
                            $this->setTemplate('module:confirmo/views/templates/front/callback_error17.tpl');
                        } else {
                            $this->setTemplate('callback_error.tpl');
                        }
                    }

                    break;
                case 'expired':
                case 'cancel':
                default:
                    // redirect to order payment page so customer can try another payment method
                    Tools::redirect('index.php?controller=order?step=3');
                    break;
            }
        }
    }
}
