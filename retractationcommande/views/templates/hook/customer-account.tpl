{**
 * Lien dans la page "Votre compte" de l'espace client.
 * Deux markups selon le thème : Hummingbird (menu compte moderne) vs classic (grille).
 *}
{if $retractation_account_menu_style}
  <a class="account-menu__link" id="retractation-account-link" href="{$retractation_link_url}">
    <i class="account-menu__icon material-icons" aria-hidden="true">undo</i>
    {$retractation_link_label}
  </a>
{else}
  <a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="retractation-account-link" href="{$retractation_link_url}">
    <span class="link-item">
      <i class="material-icons">undo</i>
      {$retractation_link_label}
    </span>
  </a>
{/if}
