<?php
/**
 * Jeux de règles de retour : un jeu par défaut (droit de rétractation du
 * consommateur, configuration globale) et des jeux propres par groupe de
 * clients (politique commerciale du marchand, ex. professionnels).
 *
 * Le jeu d'un client est choisi sur son groupe par défaut
 * (Customer::id_default_group), critère que PrestaShop utilise déjà pour
 * l'affichage des prix. Un groupe sans jeu propre suit le jeu par défaut.
 *
 * Les jeux par groupe sont stockés en JSON dans une seule clé de
 * configuration : rien à migrer, une boutique qui met à jour sans rien
 * configurer garde le comportement d'origine pour tout le monde.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/RetractationDelai.php';

class RetractationRules
{
    const CONFIG_KEY = 'RETRACTATION_GROUP_RULES';

    /** @var array<int, array> cache par groupe, le temps de la requête */
    protected static $cache = [];

    /**
     * Jeu par défaut : la configuration globale, vocabulaire légal.
     */
    public static function getDefault()
    {
        return [
            'legal' => true,
            'days' => RetractationDelai::getDelaiJours(),
            'link_label' => (string) Configuration::get('RETRACTATION_LINK_LABEL'),
            'form_title' => '',
            'form_intro' => '',
            'conditions_text' => '',
            'procedure_text' => (string) Configuration::get('RETRACTATION_PROCEDURE_TEXT'),
            'excluded_cats' => (string) Configuration::get('RETRACTATION_EXCLUDED_CATS'),
            'excluded_products' => (string) Configuration::get('RETRACTATION_EXCLUDED_PRODUCTS'),
        ];
    }

    /**
     * Jeux propres enregistrés, indexés par id_group (bruts, sans repli).
     *
     * @return array<int, array>
     */
    public static function getAll()
    {
        $all = json_decode((string) Configuration::get(self::CONFIG_KEY), true);

        return is_array($all) ? $all : [];
    }

    public static function saveAll(array $rules)
    {
        self::$cache = [];

        return Configuration::updateValue(self::CONFIG_KEY, json_encode($rules, JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * Jeu applicable à un groupe : le jeu propre s'il existe, complété par le
     * jeu par défaut pour les champs laissés vides, sinon le jeu par défaut.
     * Exception voulue : un libellé de lien vide masque le lien.
     */
    public static function forGroup($idGroup)
    {
        $idGroup = (int) $idGroup;
        if (isset(self::$cache[$idGroup])) {
            return self::$cache[$idGroup];
        }

        $default = self::getDefault();
        $all = self::getAll();
        if (empty($all[$idGroup]) || !is_array($all[$idGroup])) {
            return self::$cache[$idGroup] = $default;
        }

        $own = $all[$idGroup];
        $rule = $default;
        $rule['legal'] = false;
        $rule['days'] = RetractationDelai::getDelaiJours(isset($own['days']) ? (int) $own['days'] : 0) ?: $default['days'];
        $rule['link_label'] = isset($own['link_label']) ? trim((string) $own['link_label']) : '';
        foreach (['form_title', 'form_intro', 'conditions_text', 'procedure_text'] as $key) {
            if (isset($own[$key]) && trim((string) $own[$key]) !== '') {
                $rule[$key] = (string) $own[$key];
            }
        }
        // Les exclusions légales (L221-28) ne s'appliquent pas à un jeu marchand :
        // seules celles saisies pour ce groupe comptent.
        foreach (['excluded_cats', 'excluded_products'] as $key) {
            $rule[$key] = isset($own[$key]) ? (string) $own[$key] : '';
        }
        if ($rule['form_title'] === '') {
            $rule['form_title'] = $rule['link_label'] !== ''
                ? $rule['link_label']
                : Context::getContext()->getTranslator()->trans('Retour de commande', [], 'Modules.Retractationcommande.Shop');
        }

        return self::$cache[$idGroup] = $rule;
    }

    public static function forCustomer($idCustomer)
    {
        $customer = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($customer)) {
            return self::getDefault();
        }

        return self::forGroup((int) $customer->id_default_group);
    }

    public static function forOrder(Order $order)
    {
        return self::forCustomer((int) $order->id_customer);
    }

    /**
     * Jeu du visiteur courant : celui de son groupe s'il est connecté, le jeu
     * par défaut sinon (le parcours invité ne connaît pas encore le client).
     */
    public static function forContext()
    {
        $customer = Context::getContext()->customer;
        if ($customer && $customer->isLogged()) {
            return self::forGroup((int) $customer->id_default_group);
        }

        return self::getDefault();
    }
}
