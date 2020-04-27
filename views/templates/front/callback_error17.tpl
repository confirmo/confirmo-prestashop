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

{extends file='page.tpl'}

{block name="page_content"}
  <h1 class="page-heading">{$heading|escape:'html':'UTF-8'}</h1>

  <div class="alert alert-warning">
    {l s='Oh snap! Something went wrong and we were unable to verify your payment to CONFIRMO.' mod='confirmo'}
  </div>

<p>
  {l s='Please wait a short while then click the "Check again" below. If there is no change, please' mod='confirmo'} <a href="{$link->getPageLink('contact')|escape:'html':'UTF-8'}">{l s='contact us' mod='confirmo'}</a> {l s='before placing another order so we can try to manually verify your payment.' mod='confirmo'}
</p>

  <p class="cart_navigation clearfix" id="cart_navigation">
    <a href="" class="button-exclusive btn btn-default">
      <i class="icon-refresh"></i>{l s='Check again' mod='confirmo'}
    </a>
  </p>
{/block}