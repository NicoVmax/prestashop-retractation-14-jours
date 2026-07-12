<?php
/**
 * Upgrade 1.4.5 — ajoute la colonne `photos` (introduite en 1.4.4) à la table
 * des demandes de rétractation.
 *
 * En 1.4.4, la colonne n'était créée qu'à l'installation (createDbTable /
 * migrateDbColumns), jamais lors d'une mise à jour PrestaShop : les boutiques
 * ayant migré depuis une version antérieure se retrouvaient sans la colonne,
 * d'où une erreur 500 (« Unknown column 'photos' ») au dépôt d'une demande.
 * Ce script rétablit la colonne pour toutes les montées de version. Idempotent.
 *
 * @param Module $module
 * @return bool
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_5($module)
{
    $prefix = _DB_PREFIX_;
    $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $prefix . 'retractation_request`');
    $existing = array_column($columns ?: [], 'Field');

    if (in_array('photos', $existing, true)) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . $prefix . 'retractation_request` ADD `photos` TEXT AFTER `pdf_filename`'
    );
}
