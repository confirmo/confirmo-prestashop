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

{if $prestashop_15}
    <p class="payment_module">
      <a href="{$payment_url|escape:'html':'UTF-8'}" title="{l s='Pay with Crypto' mod='confirmo'}">
        <img src="views/img/ccy_crypto.svg" height="50" />
        {l s='Pay with Crypto' mod='confirmo'}
      </a>
    </p>
{else}
    <div class="row">
      <div class="col-xs-12">
        <p class="payment_module">
          <a class="confirmo bankwire" href="{$payment_url|escape:'html':'UTF-8'}" title="{l s='Pay with Crypto' mod='confirmo'}" style="background-image: url('/modules/confirmo/views/img/ccy_crypto.svg'); background-position: 15px 50%;">
            {l s='Pay with Crypto' mod='confirmo'}
          </a>
        </p>
      </div>
    </div>
{/if}
