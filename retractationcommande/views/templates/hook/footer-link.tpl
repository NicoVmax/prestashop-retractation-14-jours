{**
 * Lien "Exercer mon droit de rétractation" — pied de page de toutes les pages
 * (fonctionnalité visible et facilement accessible, ordonnance n°2026-2).
 *
 * Le lien est inséré à la fin d'UNE seule liste du footer : celle désignée par
 * le sélecteur CSS saisi en configuration, à défaut la dernière liste de liens
 * CMS (ps_linklist : Livraison, Mentions légales, …) pour s'intégrer au thème.
 * Si aucune liste n'est trouvée, le bloc autonome ci-dessous sert de solution
 * de repli.
 *}
<div class="retractation-footer-link" id="retractation-footer-fallback" style="display:none;">
  <a href="{$retractation_link_url}" title="{$retractation_link_label|escape:'html'}">
    {$retractation_link_label}
  </a>
</div>
<script>
(function () {
  var url = {$retractation_link_url|json_encode nofilter};
  var label = {$retractation_link_label|json_encode nofilter};
  var target = {$retractation_footer_target|default:''|json_encode nofilter};

  // Liste désignée en configuration. Sélecteur invalide ou absent de la page :
  // on retombe sur la détection automatique.
  function targetList() {
    if (!target) {
      return null;
    }
    var el;
    try {
      el = document.querySelector(target);
    } catch (e) {
      return null;
    }
    if (!el) {
      return null;
    }

    // Le marchand peut viser la liste elle-même ou le bloc qui la contient.
    return el.tagName === 'UL' || el.tagName === 'OL' ? el : el.querySelector('ul, ol');
  }

  function insert() {
    if (document.querySelector('.retractation-cms-link')) {
      return;
    }

    // Listes du footer contenant des liens CMS (thème classic : ul#footer_sub_menu_X)
    var lists = [];
    document.querySelectorAll('.footer-container a.cms-page-link, footer a.cms-page-link').forEach(function (a) {
      var ul = a.closest('ul');
      if (ul && lists.indexOf(ul) === -1) {
        lists.push(ul);
      }
    });

    // Une seule insertion : la liste choisie en configuration, sinon la
    // dernière liste CMS — par convention le bloc légal (Livraison, Mentions
    // légales, CGV) suit le bloc produits.
    var ul = targetList() || (lists.length ? lists[lists.length - 1] : null);

    if (!ul) {
      var fallback = document.getElementById('retractation-footer-fallback');
      if (fallback) {
        fallback.style.display = '';
      }
      return;
    }

    var li = document.createElement('li');
    var a = document.createElement('a');
    a.className = 'cms-page-link retractation-cms-link';
    a.href = url;
    a.title = label;
    a.textContent = label;
    li.appendChild(a);
    ul.appendChild(li);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', insert);
  } else {
    insert();
  }
})();
</script>
