{**
 * Encart rétractation sur la page de détail de commande (hook displayOrderDetail).
 * Le badge de statut et le bouton peuvent coexister : une rétractation
 * partielle laisse les quantités restantes rétractables pendant la fenêtre légale.
 * Vocabulaire légal ou commercial selon le jeu de règles du client.
 *}
{if $retractation_existing || $retractation_eligible}
  <div class="retractation-orderdetail-box">
    <p class="retractation-status">
      {if $retractation_legal}{l s='Droit de rétractation' mod='retractationcommande'}{else}{$retractation_form_title}{/if}
    </p>

    {if $retractation_existing}
      <p>
        <span class="retractation-status-badge">{$retractation_existing_label}</span>
        <br>
        <small>
          {l s='Demande' mod='retractationcommande'} {$retractation_existing.reference}
          {l s='du' mod='retractationcommande'} {dateFormat date=$retractation_existing.date_add full=0}
        </small>
      </p>
    {/if}

    {if $retractation_eligible}
      <p>
        {if $retractation_legal}
          {l s='Vous disposez d\'un délai légal de 14 jours pour vous rétracter' mod='retractationcommande'}
        {else}
          {l s='Vous pouvez demander un retour sous' mod='retractationcommande'} {$retractation_days} {l s='jours' mod='retractationcommande'}
        {/if}
        {if $retractation_deadline_text}({$retractation_deadline_text}){/if}.
      </p>
      <a href="#" class="retractation-btn cssTrans btn btn-secondary"
         data-id-order="{$retractation_id_order|intval}"
         data-rtoken="{$retractation_token}">
        {if $retractation_legal}{l s='Se rétracter' mod='retractationcommande'}{else}{l s='Demander un retour' mod='retractationcommande'}{/if}
      </a>
    {/if}
  </div>
{/if}
