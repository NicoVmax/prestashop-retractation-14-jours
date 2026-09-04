<?php
/**
 * BO SAV > Rétractations : liste des demandes, vérification d'éligibilité,
 * validation (envoi de la procédure de retour), refus, remboursement.
 * Synchronise l'état du retour natif PrestaShop lié.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationDelai.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationRequest.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationPdf.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationPhoto.php';

class AdminRetractationController extends ModuleAdminController
{
    /**
     * Compat traduction cross-version : PrestaShop 9 a retiré la méthode legacy
     * l() des contrôleurs admin (UndefinedMethodError). On délègue au natif sur
     * 1.7/8, sinon à Module::l() (repli ultime : chaîne source).
     */
    public function l($string, $class = null, $addslashes = false, $htmlentities = true)
    {
        if (method_exists(get_parent_class($this), 'l')) {
            return parent::l($string, $class, $addslashes, $htmlentities);
        }
        if (isset($this->module) && $this->module instanceof Module) {
            return $this->module->l($string, 'adminretractationcontroller');
        }

        return $string;
    }

    public function __construct()
    {
        $this->table = 'retractation_request';
        $this->className = 'RetractationRequest';
        $this->identifier = 'id_retractation_request';
        $this->bootstrap = true;
        $this->list_no_link = false;
        $this->allow_export = true;
        $this->_orderBy = 'date_add';
        $this->_orderWay = 'DESC';

        parent::__construct();

        $this->_select = 'o.reference AS order_reference, CONCAT(c.firstname, " ", c.lastname) AS customer_name, c.email AS customer_email';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON (o.`id_order` = a.`id_order`)
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = a.`id_customer`)';

        $this->fields_list = [
            'id_retractation_request' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            // La liste joint `orders` et `customer` : toute colonne de la table du module
            // doit être préfixée via filter_key, sinon le filtre produit un WHERE ambigu
            // (erreur SQL 1052) sur les noms partagés (reference, date_add).
            'reference' => [
                'title' => $this->l('Référence'),
                'class' => 'fixed-width-sm',
                'filter_key' => 'a!reference',
            ],
            'order_reference' => ['title' => $this->l('Commande'), 'havingFilter' => true],
            'customer_name' => ['title' => $this->l('Client'), 'havingFilter' => true],
            'customer_email' => ['title' => $this->l('Email'), 'havingFilter' => true],
            'date_add' => ['title' => $this->l('Demandée le'), 'type' => 'datetime', 'filter_key' => 'a!date_add'],
            'legal_deadline' => ['title' => $this->l('Date limite légale'), 'type' => 'datetime', 'filter_key' => 'a!legal_deadline'],
            'within_deadline' => [
                'title' => $this->l('Dans les délais'),
                'align' => 'center',
                'type' => 'bool',
                'callback' => 'displayWithinDeadline',
                'filter_key' => 'a!within_deadline',
            ],
            'status' => [
                'title' => $this->l('Statut'),
                'align' => 'center',
                'type' => 'select',
                'list' => self::getStatusLabels(),
                'filter_key' => 'a!status',
                'callback' => 'displayStatus',
            ],
        ];

        $this->actions = ['view'];
    }

    public static function getStatusLabels()
    {
        return [
            RetractationRequest::STATUS_PENDING => 'À vérifier',
            RetractationRequest::STATUS_ACCEPTED => 'Conforme — procédure envoyée',
            RetractationRequest::STATUS_REFUSED => 'Refusée',
            RetractationRequest::STATUS_REFUNDED => 'Remboursée',
        ];
    }

    public function displayStatus($value)
    {
        $labels = self::getStatusLabels();
        $classes = [
            RetractationRequest::STATUS_PENDING => 'badge-warning',
            RetractationRequest::STATUS_ACCEPTED => 'badge-info',
            RetractationRequest::STATUS_REFUSED => 'badge-danger',
            RetractationRequest::STATUS_REFUNDED => 'badge-success',
        ];

        return '<span class="badge ' . ($classes[$value] ?? 'badge-default') . '">' . ($labels[$value] ?? $value) . '</span>';
    }

    public function displayWithinDeadline($value)
    {
        return $value
            ? '<span class="badge badge-success">' . $this->l('Oui') . '</span>'
            : '<span class="badge badge-danger">' . $this->l('Non') . '</span>';
    }

    /* ------------------------------------------------------------------ */
    /* Vue détaillée                                                       */
    /* ------------------------------------------------------------------ */

    public function renderView()
    {
        $request = $this->loadObject();
        if (!Validate::isLoadedObject($request)) {
            $this->errors[] = $this->l('Demande introuvable.');

            return '';
        }

        $order = new Order((int) $request->id_order);
        $customer = new Customer((int) $request->id_customer);
        $products = Validate::isLoadedObject($order) ? $order->getProducts() : [];
        $excluded = Validate::isLoadedObject($order) ? RetractationRequest::getExcludedProducts($order) : [];
        $excludedIds = array_map(static function ($p) {
            return (int) $p['id_order_detail'];
        }, $excluded);

        $orderReturnLink = null;
        if ($request->id_order_return) {
            $orderReturnLink = $this->context->link->getAdminLink('AdminReturn', true, [], [
                'id_order_return' => (int) $request->id_order_return,
                'updateorder_return' => 1,
            ]);
        }

        // Quantités demandées par le client (snapshot figé au dépôt).
        $requestedQty = [];
        foreach (RetractationRequest::decodeSnapshot($request->products_snapshot) as $line) {
            $requestedQty[(int) ($line['id_order_detail'] ?? 0)] = (int) ($line['quantity'] ?? 0);
        }

        // Photos jointes par le client : URLs servies par ce contrôleur (token).
        $rcPhotos = [];
        $photoNames = json_decode((string) $request->photos, true);
        if (is_array($photoNames)) {
            foreach ($photoNames as $pn) {
                $pn = basename((string) $pn);
                if ($pn !== '' && RetractationPhoto::getPath($pn)) {
                    $rcPhotos[] = self::$currentIndex . '&id_retractation_request=' . (int) $request->id
                        . '&downloadRetractationPhoto&photo=' . urlencode($pn)
                        . '&token=' . $this->token;
                }
            }
        }

        $this->context->smarty->assign([
            'rc_request' => $request,
            'rc_photos' => $rcPhotos,
            'rc_status_labels' => self::getStatusLabels(),
            'rc_order' => Validate::isLoadedObject($order) ? $order : null,
            'rc_customer' => Validate::isLoadedObject($customer) ? $customer : null,
            'rc_products' => $products,
            'rc_requested_qty' => $requestedQty,
            'rc_excluded_ids' => $excludedIds,
            'rc_order_link' => Validate::isLoadedObject($order)
                ? $this->context->link->getAdminLink('AdminOrders', true, [], ['id_order' => (int) $order->id, 'vieworder' => 1])
                : null,
            'rc_order_return_link' => $orderReturnLink,
            'rc_pdf_available' => (bool) RetractationPdf::getPath($request->pdf_filename),
            'rc_current_index' => self::$currentIndex . '&id_retractation_request=' . (int) $request->id
                . '&viewretractation_request&token=' . $this->token,
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'retractationcommande/views/templates/admin/view.tpl'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Actions SAV                                                         */
    /* ------------------------------------------------------------------ */

    public function postProcess()
    {
        if (Tools::isSubmit('submitAcceptRetractation')) {
            $this->processAccept();
        } elseif (Tools::isSubmit('submitRefuseRetractation')) {
            $this->processRefuse();
        } elseif (Tools::isSubmit('submitRefundRetractation')) {
            $this->processRefund();
        } elseif (Tools::isSubmit('downloadRetractationPdf')) {
            $this->processDownloadPdf();
        } elseif (Tools::isSubmit('downloadRetractationPhoto')) {
            $this->processDownloadPhoto();
        }

        return parent::postProcess();
    }

    protected function loadRequestOrFail()
    {
        $request = new RetractationRequest((int) Tools::getValue('id_retractation_request'));
        if (!Validate::isLoadedObject($request)) {
            $this->errors[] = $this->l('Demande introuvable.');

            return null;
        }

        return $request;
    }

    /**
     * Demande conforme.
     * - Commande livrée au moment de la demande : envoi de la procédure de
     *   retour, retour natif passé à "En attente du colis".
     * - Commande non expédiée : annulation avant expédition — aucun retour
     *   de produit, email dédié (annuler l'expédition puis rembourser).
     */
    protected function processAccept()
    {
        $request = $this->loadRequestOrFail();
        if (!$request) {
            return;
        }

        $request->status = RetractationRequest::STATUS_ACCEPTED;
        $request->update();

        // Phase figée au dépôt (repli sur delivery_date pour les anciennes demandes).
        $phase = $request->shipping_phase ?: ($request->delivery_date ? 'delivered' : 'pending');

        if ($phase === 'pending') {
            // Commande non expédiée : annulation, aucun retour produit.
            $this->sendCustomerEmail(
                $request,
                'retractation_annulation',
                $this->l('Votre rétractation est validée — commande annulée avant expédition')
            );
            $this->confirmations[] = $this->l('Demande validée (commande non expédiée) : le client a été informé de l\'annulation. Pensez à annuler l\'expédition et à effectuer le remboursement depuis la fiche commande, puis marquez la demande comme remboursée.');
        } else {
            // Livrée ou en cours d'acheminement : procédure de retour.
            $request->setNativeReturnState(RetractationCommande::OR_STATE_WAITING_PACKAGE);

            $order = new Order((int) $request->id_order);
            $customer = new Customer((int) $request->id_customer);

            // Procédure (texte du jeu de règles du client : par défaut ou par
            // groupe) + adresse de retour configurée (centre logistique…) si
            // renseignée — ajoutée au contenu sans toucher aux templates.
            $procedure = (string) RetractationRules::forCustomer((int) $request->id_customer)['procedure_text'];
            $returnAddress = trim((string) Configuration::get('RETRACTATION_RETURN_ADDRESS'));
            if ($returnAddress !== '') {
                $procedure .= '<p style="margin-top:14px"><strong>' . $this->l('Adresse de retour') . ' :</strong><br>'
                    . nl2br(htmlspecialchars($returnAddress, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            // Instructions spécifiques (config) — affichées dans l'e-mail ET sur le bon de retour.
            $instructions = trim((string) Configuration::get('RETRACTATION_RETURN_INSTRUCTIONS'));
            if ($instructions !== '') {
                $procedure .= '<p style="margin-top:14px"><strong>' . $this->l('Instructions') . ' :</strong><br>' . $instructions . '</p>';
            }

            // Bon de retour PDF joint + consigne d'impression / collage sur le colis.
            $attachment = null;
            if (Validate::isLoadedObject($order) && Validate::isLoadedObject($customer)) {
                $attachment = $this->buildReturnSlipAttachment($request, $order, $customer);
            }
            if ($attachment) {
                $procedure .= '<p style="margin-top:14px; padding:10px 12px; border:2px solid #2e7d32; background:#eef5ee; color:#1b4d20;"><strong>'
                    . $this->l('Un bon de retour est joint à cet e-mail (PDF). Imprimez-le et collez-le sur l\'extérieur du colis : sans ce bon, votre retour ne pourra pas être accepté par notre service logistique.')
                    . '</strong></p>';
            }

            $this->sendCustomerEmail(
                $request,
                'retractation_procedure',
                $this->l('Votre rétractation est validée — procédure de retour'),
                ['{procedure}' => $procedure],
                $attachment
            );
            $this->confirmations[] = ($phase === 'shipped')
                ? $this->l('Demande validée (commande en cours d\'acheminement) : la procédure de retour a été envoyée au client. Il pourra refuser le colis ou le renvoyer.')
                : $this->l('Demande validée : la procédure de retour a été envoyée au client.');
        }
    }

    /**
     * Demande non conforme (hors délai, exclusion légale…).
     */
    protected function processRefuse()
    {
        $request = $this->loadRequestOrFail();
        if (!$request) {
            return;
        }

        $reason = trim((string) Tools::getValue('refusal_reason'));
        if (!$reason) {
            $this->errors[] = $this->l('Merci d\'indiquer le motif du refus (il sera communiqué au client).');

            return;
        }

        $request->status = RetractationRequest::STATUS_REFUSED;
        $request->refusal_reason = $reason;
        $request->update();
        $request->setNativeReturnState(RetractationCommande::OR_STATE_DENIED);

        $this->sendCustomerEmail(
            $request,
            'retractation_refus',
            $this->l('Votre demande de rétractation'),
            ['{reason}' => nl2br(htmlspecialchars($reason))]
        );

        $this->confirmations[] = $this->l('Demande refusée : le client a été informé du motif.');
    }

    /**
     * Produit reçu, contrôlé et remboursé. Le remboursement lui-même se fait
     * via la commande (remboursement standard/partiel natif).
     */
    protected function processRefund()
    {
        $request = $this->loadRequestOrFail();
        if (!$request) {
            return;
        }

        $request->status = RetractationRequest::STATUS_REFUNDED;
        $request->update();
        $request->setNativeReturnState(RetractationCommande::OR_STATE_COMPLETED);

        $this->sendCustomerEmail(
            $request,
            'retractation_remboursee',
            $this->l('Votre rétractation a été remboursée'),
            [
                '{refund_intro}' => $request->delivery_date
                    ? $this->l('le produit a été réceptionné et contrôlé, et')
                    : $this->l('votre commande a été annulée avant expédition, et'),
            ]
        );

        $this->confirmations[] = $this->l('Demande marquée comme remboursée, le client a été informé. Pensez à effectuer le remboursement réel depuis la fiche commande si ce n\'est pas déjà fait.');
    }

    protected function processDownloadPdf()
    {
        $request = $this->loadRequestOrFail();
        $path = $request ? RetractationPdf::getPath($request->pdf_filename) : null;
        if (!$path) {
            $this->errors[] = $this->l('PDF introuvable.');

            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    protected function processDownloadPhoto()
    {
        $request = $this->loadRequestOrFail();
        if (!$request) {
            return;
        }
        // Sécurité : la photo demandée doit appartenir à CETTE demande.
        $requested = basename((string) Tools::getValue('photo'));
        $allowed = json_decode((string) $request->photos, true);
        if (!is_array($allowed) || !in_array($requested, array_map('basename', $allowed), true)) {
            $this->errors[] = $this->l('Photo introuvable.');

            return;
        }
        $path = RetractationPhoto::getPath($requested);
        if (!$path) {
            $this->errors[] = $this->l('Photo introuvable.');

            return;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        header('Content-Type: ' . (isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    protected function sendCustomerEmail(RetractationRequest $request, $template, $subject, array $extraVars = [], $attachment = null)
    {
        $order = new Order((int) $request->id_order);
        $customer = new Customer((int) $request->id_customer);
        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($customer)) {
            return;
        }

        $vars = array_merge([
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_ref}' => $order->reference,
            '{request_ref}' => $request->reference,
            '{request_id}' => (int) $request->id,
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
        ], $extraVars);

        // Retour commercial (jeu par groupe) : un seul template neutre, sans
        // mention du droit de rétractation ; titre, intro et corps selon l'étape.
        if (!RetractationRules::forCustomer((int) $customer->id)['legal']) {
            $refs = [$request->reference, $order->reference];
            switch ($template) {
                case 'retractation_annulation':
                    $subject = $this->l('Votre demande de retour est acceptée — commande annulée avant expédition');
                    $intro = vsprintf($this->l('Votre demande %s concernant la commande %s a été acceptée.'), $refs);
                    $body = '<p>' . $this->l('Votre commande n\'ayant pas encore été expédiée, aucun retour de produit n\'est nécessaire : l\'expédition est annulée et le remboursement vous sera adressé par le même moyen de paiement.') . '</p>';
                    break;
                case 'retractation_refus':
                    $subject = $this->l('Votre demande de retour');
                    $intro = vsprintf($this->l('Votre demande de retour %s concernant la commande %s n\'a pas pu être acceptée.'), $refs);
                    $body = '<p><strong>' . $this->l('Motif') . '</strong><br>' . ($vars['{reason}'] ?? '') . '</p>';
                    break;
                case 'retractation_remboursee':
                    $subject = $this->l('Votre retour a été remboursé');
                    $intro = vsprintf($this->l('Votre demande de retour %s concernant la commande %s a été remboursée.'), $refs);
                    $body = '<p>' . $this->l('Le remboursement a été effectué par le même moyen de paiement que celui de la commande.') . '</p>';
                    break;
                default: // retractation_procedure
                    $subject = $this->l('Votre demande de retour est acceptée — procédure de retour');
                    $intro = vsprintf($this->l('Votre demande de retour %s concernant la commande %s a été vérifiée et acceptée.'), $refs);
                    $body = (string) ($vars['{procedure}'] ?? '');
            }
            $template = 'retour_notification';
            $vars['{title}'] = $subject;
            $vars['{intro}'] = $intro;
            $vars['{body}'] = $body;
        }

        Mail::Send(
            RetractationCommande::getMailLangId((int) $order->id_lang, $template),
            $template,
            $subject . ' - ' . $order->reference,
            $vars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            $attachment,
            null,
            _PS_MODULE_DIR_ . 'retractationcommande/mails/',
            false,
            (int) $order->id_shop
        );
    }

    /**
     * Génère le PDF « bon de retour » (à imprimer et coller sur le colis) et
     * retourne une pièce jointe prête pour Mail::Send, ou null si indisponible.
     */
    protected function buildReturnSlipAttachment(RetractationRequest $request, Order $order, Customer $customer)
    {
        $products = RetractationRequest::decodeSnapshot($request->products_snapshot);
        $returnAddress = trim((string) Configuration::get('RETRACTATION_RETURN_ADDRESS'));
        $instructions = trim((string) Configuration::get('RETRACTATION_RETURN_INSTRUCTIONS'));

        $this->context->smarty->assign([
            'rc_shop_name' => Configuration::get('PS_SHOP_NAME'),
            'rc_customer_name' => trim($customer->firstname . ' ' . $customer->lastname),
            'rc_order_ref' => $order->reference,
            'rc_request_ref' => $request->reference,
            'rc_date' => Tools::displayDate(date('Y-m-d H:i:s'), true),
            'rc_products' => is_array($products) ? $products : [],
            'rc_return_address' => $returnAddress !== '' ? nl2br(htmlspecialchars($returnAddress, ENT_QUOTES, 'UTF-8')) : '',
            'rc_instructions' => $instructions !== '' ? $instructions : '',
        ]);

        // display() (et non fetch('module:...')) pour une résolution fiable du
        // template depuis le contexte back-office.
        $html = $this->module->display(
            _PS_MODULE_DIR_ . 'retractationcommande/retractationcommande.php',
            'views/templates/front/pdf-bon-retour.tpl'
        );
        $filename = RetractationPdf::generate($html, (int) $request->id, null, 'bon_retour');
        if (!$filename) {
            return null;
        }
        $path = RetractationPdf::getPath($filename);
        if (!$path) {
            return null;
        }

        return [
            'content' => file_get_contents($path),
            'name' => 'bon-de-retour-' . $order->reference . '.pdf',
            'mime' => 'application/pdf',
        ];
    }
}
