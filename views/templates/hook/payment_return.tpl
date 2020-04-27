{*
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
 *}

{if $refunded}
  <div class="alert alert-info">
    {l s='Your payment has been refunded. Please make sure, you send the payment on time in full amount including network fee. Please' mod='confirmo'} <a href="{$link->getPageLink('contact')|escape:'html':'UTF-8'}">{l s='contact us' mod='confirmo'}</a> {l s='if you need further assistance.' mod='confirmo'}
  </div>
{elseif $confirmed}
  {if $outofstock }
    <div class="alert alert-success">
      {l s='Thank you for your payment. Your order has been successfully completed. Unfortunately, the item(s) that you ordered are now out-of-stock.' mod='confirmo'}
    </div>
  {else}
    <div class="alert alert-success">
      {l s='Thank you for your payment. Your order has been successfully completed.' mod='confirmo'}
    </div>
  {/if}
{elseif $received}
  <div class="alert alert-success">
    {l s='Thank you for your payment. It might take several minutes for your payment to get validated by the network. You should receive a confirmation email shortly.' mod='confirmo'}
  </div>
{elseif $error}
  <div class="alert alert-danger">
    {l s='There was a problem processing your order. We recommend to press back button in your web browser and request the refund via CONFIRMO.' mod='confirmo'}
  </div>
{else}
  <div class="alert alert-danger">
    {l s='Unexpected error, please' mod='confirmo'} <a href="{$link->getPageLink('contact')|escape:'html':'UTF-8'}">{l s='contact us' mod='confirmo'}</a>.
  </div>
{/if}
