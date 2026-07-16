{*
 * Bon de retour — à imprimer par le client et coller sur l'extérieur du colis.
 * Rendu en HTML pour TCPDF (styles inline, pas de flexbox/grid).
 *}
<style>
    .rc-slip-title { font-size: 20px; font-weight: bold; color: #1b4d20; margin: 0 0 4px; }
    .rc-slip-shop { font-size: 13px; color: #444; margin: 0 0 12px; }
    .rc-slip-stick { border: 2px solid #2e7d32; background: #eef5ee; color: #1b4d20; padding: 10px 12px; font-size: 13px; font-weight: bold; }
    .rc-slip-box { border: 1px solid #ccc; padding: 10px 12px; margin-top: 14px; font-size: 12px; }
    .rc-slip-box h3 { font-size: 13px; margin: 0 0 6px; color: #2e7d32; }
    .rc-slip-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 12px; }
    .rc-slip-table th { text-align: left; border-bottom: 1px solid #ccc; padding: 4px 6px; }
    .rc-slip-table td { border-bottom: 1px solid #eee; padding: 4px 6px; }
    .rc-slip-instr { font-size: 12px; }
    .rc-slip-cut { border-top: 1px dashed #999; margin-top: 18px; padding-top: 6px; font-size: 10px; color: #999; }
</style>

<div class="rc-slip-title">{l s='Bon de retour' mod='retractationcommande'}</div>
<div class="rc-slip-shop">{$rc_shop_name|escape:'html':'UTF-8'}</div>

<div class="rc-slip-stick">
    {l s='À imprimer et à coller sur l\'extérieur du colis. Sans ce bon, votre retour ne pourra pas être accepté par notre service logistique.' mod='retractationcommande'}
</div>

<table width="100%" style="margin-top:14px; font-size:12px;">
    <tr>
        <td width="50%"><strong>{l s='Client' mod='retractationcommande'} :</strong> {$rc_customer_name|escape:'html':'UTF-8'}</td>
        <td width="50%"><strong>{l s='Commande' mod='retractationcommande'} :</strong> {$rc_order_ref|escape:'html':'UTF-8'}</td>
    </tr>
    <tr>
        <td><strong>{l s='N° de demande' mod='retractationcommande'} :</strong> {$rc_request_ref|escape:'html':'UTF-8'}</td>
        <td><strong>{l s='Date' mod='retractationcommande'} :</strong> {$rc_date|escape:'html':'UTF-8'}</td>
    </tr>
</table>

{if $rc_products}
    <div class="rc-slip-box">
        <h3>{l s='Produits retournés' mod='retractationcommande'}</h3>
        <table class="rc-slip-table">
            <thead>
                <tr>
                    <th>{l s='Produit' mod='retractationcommande'}</th>
                    <th>{l s='Référence' mod='retractationcommande'}</th>
                    <th style="text-align:center;">{l s='Qté' mod='retractationcommande'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$rc_products item=p}
                    <tr>
                        <td>{$p.product_name|escape:'html':'UTF-8'}</td>
                        <td>{$p.product_reference|escape:'html':'UTF-8'}</td>
                        <td style="text-align:center;">{$p.product_quantity|intval}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}

{if $rc_return_address}
    <div class="rc-slip-box">
        <h3>{l s='Adresse de retour' mod='retractationcommande'}</h3>
        {$rc_return_address nofilter}
    </div>
{/if}

{if $rc_instructions}
    <div class="rc-slip-box">
        <h3>{l s='Instructions' mod='retractationcommande'}</h3>
        <div class="rc-slip-instr">{$rc_instructions nofilter}</div>
    </div>
{/if}

<div class="rc-slip-cut">{l s='Document généré automatiquement — service client' mod='retractationcommande'} · {$rc_shop_name|escape:'html':'UTF-8'}</div>
