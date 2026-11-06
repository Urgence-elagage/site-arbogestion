<?php
/*
 * Après la signature d'un devis dans Pennylane (vérifié par la tâche planifiée) :
 *  - particuliers et pros : lien de paiement Stripe (acompte par carte pour une prestation ponctuelle ; mandat de prélèvement SEPA pour un abonnement)
 *    + abonnement Pennylane (factures prélevées sur Stripe par la tâche planifiée, voir stripe.php) ;
 *  - collectivités : lien de dépôt du bon de commande ; l'abonnement est créé à réception du bon.
 */
declare(strict_types=1);
require_once __DIR__ . '/demande.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/agenda.php';

function url_site(string $chemin): string { return rtrim((string)cfg('url_site', 'https://arbogestion.fr'), '/') . '/' . ltrim($chemin, '/'); }

/** Ligne d'abonnement pour la facturation récurrente. */
function abonnement_pennylane(array $row, array $dem, array $calc, string $debut, bool $an1 = false): array {
    $ab = $calc['abonnement'];
    $prix = $an1 ? (float)$ab['prix_an1'] : (float)$ab['prix'];
    $formule = PROFILS[$dem['profil']]['formule'];
    $mensuel = $dem['paiement'] === 'mensuel' && $dem['profil'] !== 'collectivite';
    $col = $dem['profil'] === 'collectivite';
    return [
        'start' => $debut,
        'label' => "Abonnement $formule – " . $row['nom_client'] . ($an1 ? ' – 1re année (installation)' : ''),
        'mode' => ['type' => (string)cfg('abonnement_mode', 'awaiting_validation')],
        'payment_conditions' => $col ? '30_days' : ($mensuel ? 'upon_receipt' : '30_days'),
        'payment_method' => 'offline',
        'recurring_rule' => ($mensuel ? ['type' => 'monthly', 'interval' => 1, 'day_of_month' => 10] : ['type' => 'yearly', 'interval' => 1]) + ($an1 ? ['count' => $mensuel ? 12 : 1] : []),
        'customer_id' => (int)$row['pl_client_id'],
        'customer_invoice_data' => [
            'language' => 'fr_FR',
            'pdf_invoice_subject' => "Abonnement Arbogestion – $formule",
            'special_mention' => 'Contrat d’abonnement accepté par la signature du devis n° ' . $row['pl_devis_numero'] . '.' . ($col ? ' Facture à déposer sur Chorus Pro.' : ' Paiement par prélèvement SEPA (Stripe), à réception de la facture.'),
            'invoice_lines' => [[
                'label' => "Abonnement $formule – jusqu'à {$ab['max']} arbres" . ($an1 ? ' – année d’installation' : ''),
                'description' => ($mensuel ? 'Mensualité' : 'Forfait annuel') . ($an1 ? ' – accès à Grality, hébergement et assistance (tiers du forfait)' : ''),
                'quantity' => 1, 'unit' => $mensuel ? 'mois' : 'an',
                'raw_currency_unit_price' => prix6($mensuel ? $prix / 12 : $prix),
                'vat_rate' => 'FR_200',
            ]],
        ],
    ];
}

/**
 * Abonnement avec recensement initial : créé quand le recensement est réalisé (agenda ou fiche du devis).
 * 1re année au tiers du forfait (12 mensualités ou 1 annuité), puis forfait plein à la date anniversaire.
 */
function demarrer_abonnement(int $id, string $date): string {
    $s = db()->prepare('SELECT * FROM demandes WHERE id = ?'); $s->execute([$id]); $row = $s->fetch();
    if (!$row) { throw new RuntimeException('Devis introuvable.'); }
    if (!empty($row['pl_abonnement_id'])) { return 'abonnement déjà créé'; }
    $dem = json_decode($row['donnees'], true); $calc = json_decode($row['calcul'], true);
    $ab = $calc['abonnement'] ?? null;
    if (!$ab || !empty($ab['sur_devis'])) { return 'pas d’abonnement à créer'; }
    if ($dem['profil'] === 'collectivite' && $row['statut'] !== 'bdc_recu') { throw new RuntimeException('Bon de commande de la collectivité pas encore reçu.'); }
    $ids = []; $faits = [];
    if (!empty($ab['prix_an1'])) {
        $a1 = pennylane('POST', '/billing_subscriptions', abonnement_pennylane($row, $dem, $calc, $date, true));
        $ids[] = (string)($a1['id'] ?? '');
        $anniv = date('Y-m-d', strtotime($date . ' +1 year'));
        $a2 = pennylane('POST', '/billing_subscriptions', abonnement_pennylane($row, $dem, $calc, $anniv));
        $ids[] = (string)($a2['id'] ?? '');
        $faits[] = '1re année au tiers du forfait à partir du ' . date_fr($date) . ', puis forfait plein à partir du ' . date_fr($anniv);
    } else {
        $a = pennylane('POST', '/billing_subscriptions', abonnement_pennylane($row, $dem, $calc, $date));
        $ids[] = (string)($a['id'] ?? ''); $faits[] = 'abonnement à partir du ' . date_fr($date);
    }
    maj_demande($id, ['pl_abonnement_id' => implode(',', array_filter($ids)), 'notes' => ajouter_note($row, 'Abonnement créé dans Pennylane : ' . implode(' ; ', $faits) . '.')]);
    envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Abonnement démarré – {$row['nom_client']}", "Recensement réalisé le " . date_fr($date) . ".
Abonnement créé dans Pennylane (mode " . cfg('abonnement_mode', 'awaiting_validation') . ") : " . implode(' ; ', $faits) . ".

À faire : ouvrir les accès Grality du client, et facturer le solde du démarrage.");
    if (filter_var((string)$row['email'], FILTER_VALIDATE_EMAIL)) {
        envoyer_mail((string)$row['email'], 'Arbogestion – Votre suivi annuel démarre',
            "Bonjour,\n\nLe recensement de votre patrimoine arboré est terminé : merci pour votre accueil.\n\nVotre abonnement de suivi prend effet le " . date_fr($date) . '.'
            . (!empty($ab['prix_an1']) ? " La première année (installation) est facturée au tiers du forfait : vous accédez à vos données sur la plateforme Grality, avec notre assistance. Le forfait annuel complet, qui comprend le re-diagnostic d'environ 1/6 de votre parc chaque année, s'applique à partir du " . date_fr(date('Y-m-d', strtotime($date . ' +1 year'))) . '.' : '')
            . "\n\nVos identifiants d'accès à Grality vous sont adressés séparément.\n\nBien cordialement,\nArbogestion – arboriste conseil", (string)cfg('mail_kevin'));
    }
    return implode(' ; ', $faits);
}

/** Facture d'acompte BROUILLON dans Pennylane (compte 4191) : Kevin la finalise et choisit le mode de paiement. */
function creer_acompte(array $row, array $calc): ?string {
    if (empty($calc['acompte_ht'])) { return null; }
    $A = tarifs()['acompte'];
    $ligne = ['label' => 'Acompte de ' . $A['pourcentage'] . ' % – devis n° ' . $row['pl_devis_numero'], 'description' => 'Acompte à la signature sur les prestations du devis ; le solde est facturé après réalisation',
        'quantity' => 1, 'unit' => 'forfait', 'raw_currency_unit_price' => prix6($calc['acompte_ht']), 'vat_rate' => 'FR_200'];
    try {
        $f = pl_filtre([['field' => 'number', 'operator' => 'eq', 'value' => '4191']]);
        $la = pennylane('GET', '/ledger_accounts?filter=' . $f . '&filters=' . $f);
        if (!empty($la['items'][0]['id'])) { $ligne['ledger_account_id'] = (int)$la['items'][0]['id']; }
    } catch (Throwable $e) { journal('Compte 4191 introuvable : ' . $e->getMessage()); }
    $fa = pennylane('POST', '/customer_invoices', [
        'date' => date('Y-m-d'), 'deadline' => date('Y-m-d', strtotime('+8 days')), 'customer_id' => (int)$row['pl_client_id'], 'draft' => true,
        'language' => 'fr_FR', 'pdf_invoice_subject' => 'Facture d’acompte – devis n° ' . $row['pl_devis_numero'], 'invoice_lines' => [$ligne],
    ] + (pl_modele('facture') ? ['customer_invoice_template_id' => pl_modele('facture')] : []));
    return (string)($fa['id'] ?? '');
}

function maj_demande(int $id, array $champs): void {
    $champs['maj_le'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($champs)));
    db()->prepare("UPDATE demandes SET $set WHERE id = ?")->execute([...array_values($champs), $id]);
}

function ajouter_note(array $row, string $note): string {
    return trim(($row['notes'] ?? '') . "\n" . date('d/m/Y H:i') . ' ' . $note);
}

/** Vérifie les devis en attente de signature et déclenche la suite. Renvoie un compte rendu. */
function traiter_signatures(): array {
    $cr = [];
    $rows = db()->query("SELECT * FROM demandes WHERE pl_devis_id IS NOT NULL AND statut IN ('devis_brouillon','devis_envoye') ORDER BY id")->fetchAll();
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (!ctype_digit((string)$row['pl_devis_id'])) { // demande faite en mode essai : rien à suivre dans Pennylane
            if (!cfg('mode_essai')) { maj_demande($id, ['statut' => 'essai']); }
            continue;
        }
        try {
            $q = pennylane('GET', '/quotes/' . rawurlencode($row['pl_devis_id']));
            $statut = (string)($q['status'] ?? '');
            if ($statut === 'accepted' || $statut === 'invoiced') {
                $cr[] = "$id signé : " . suite_signature($row);
            } elseif ($statut === 'denied' || $statut === 'expired') {
                maj_demande($id, ['statut' => $statut === 'denied' ? 'refuse' : 'expire']);
                envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Devis {$row['pl_devis_numero']} " . ($statut === 'denied' ? 'refusé' : 'expiré') . " – {$row['nom_client']}",
                    "Le devis {$row['pl_devis_numero']} ({$row['nom_client']}) est passé au statut « $statut » dans Pennylane.");
                $cr[] = "$id $statut";
            }
        } catch (Throwable $e) {
            journal("Signatures, demande $id : " . $e->getMessage());
            $cr[] = "$id erreur : " . $e->getMessage();
        }
    }
    return $cr;
}

function suite_signature(array $row): string {
    $id = (int)$row['id'];
    $dem = json_decode($row['donnees'], true);
    $calc = json_decode($row['calcul'], true);
    $c = $dem['client'];
    $ab = $calc['abonnement'] ?? null;
    $avec_abonnement = $ab && empty($ab['sur_devis']);
    try { if ($n = agenda_creer_depuis_demande($row)) { $row['notes'] = ajouter_note($row, "$n intervention(s) ajoutée(s) à l'agenda, à planifier."); } }
    catch (Throwable $e) { journal('Agenda, demande ' . $id . ' : ' . $e->getMessage()); }

    if ($dem['profil'] === 'collectivite') {
        $lien = url_site('bon-de-commande.php?t=' . jeton_signe($id));
        maj_demande($id, ['statut' => 'signe_attente_bdc', 'notes' => ajouter_note($row, 'Devis signé ; lien de dépôt du bon de commande envoyé.')]);
        envoyer_mail($c['email'], 'Arbogestion – Devis ' . $row['pl_devis_numero'] . ' signé : transmission du bon de commande',
            "Bonjour,\n\nNous vous remercions pour la signature du devis n° {$row['pl_devis_numero']}.\n\nPour finaliser l'engagement, merci de nous transmettre le bon de commande ou la décision d'engagement de la collectivité (PDF), sur notre page sécurisée.\n\n" . bouton_mail('Déposer le bon de commande', $lien) . "\n\nLa facture sera ensuite déposée sur Chorus Pro.\n\nBien cordialement,\nArbogestion – arboriste conseil",
            (string)cfg('mail_kevin'));
        envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Devis {$row['pl_devis_numero']} signé – {$row['nom_client']} (collectivité)",
            "Le devis {$row['pl_devis_numero']} a été signé.\nLe lien de dépôt du bon de commande a été envoyé au référent ({$c['email']}).\n" . ($avec_abonnement ? "L'abonnement sera créé dans Pennylane dès réception du bon." : 'Prestation ponctuelle : pas d\'abonnement.') . "\n\nÀ faire de ton côté : planifier le démarrage (recensement, diagnostic) et facturer le démarrage après réalisation.");
        return 'collectivité : lien bon de commande envoyé';
    }

    $acompte = null; $acompte_txt = '';
    if (!empty($calc['acompte_ht'])) {
        try { $acompte = creer_acompte($row, $calc); installer_stripe(); maj_demande($id, ['pl_acompte_id' => $acompte]);
              $acompte_txt = "\nFacture d'acompte de " . eur(round($calc['acompte_ht'] * 1.2, 2)) . " TTC créée en BROUILLON dans Pennylane : à finaliser (elle sera marquée payée automatiquement dès que le client aura réglé l'acompte sur Stripe)."; }
        catch (Throwable $e) { $acompte_txt = "\nATTENTION : la facture d'acompte n'a pas pu être créée (" . $e->getMessage() . ") : à faire à la main."; }
    }
    if (!$avec_abonnement) { // prestation ponctuelle (ou abonnement sur devis) : rien de récurrent
        maj_demande($id, ['statut' => 'signe_ponctuel', 'notes' => ajouter_note($row, 'Devis signé (prestation ponctuelle).' . ($acompte ? " Acompte brouillon $acompte." : ''))]);
        envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Devis {$row['pl_devis_numero']} signé – {$row['nom_client']} (prestation ponctuelle)",
            "Le devis {$row['pl_devis_numero']} (prestation ponctuelle, sans abonnement) a été signé." . $acompte_txt . (!empty($dem['execution_anticipee']) ? "\nLe client a demandé une exécution avant la fin du délai de rétractation." : ($dem['profil'] === 'particulier' ? "\nAttention : particulier sans demande d'exécution anticipée, intervenir après le délai de rétractation de 14 jours." : '')) . "\n\nÀ faire : convenir de la date d'intervention avec le client ({$c['email']}" . (empty($c['tel']) ? '' : ", {$c['tel']}") . "), puis facturer le solde après réalisation (Pennylane > Devis > Transformer en facture, en déduisant l'acompte).");
        $payer = !empty($calc['acompte_ht']) && stripe_actif() && !cfg('mode_essai');
        envoyer_mail($c['email'], 'Arbogestion – Votre devis ' . $row['pl_devis_numero'] . ' est validé',
            "Bonjour,\n\nMerci pour la signature de votre devis n° {$row['pl_devis_numero']}."
            . ($payer ? "\n\nPour confirmer votre commande, il vous reste à régler l'acompte de " . eur(round($calc['acompte_ht'] * (1 + (float)tarifs()['tva']), 2)) . " TTC, par carte bancaire ou Wero.\n\n" . bouton_mail('Payer mon acompte', lien_paiement($id)) . "\n\nLe paiement est sécurisé par Stripe. La facture d'acompte vous sera adressée par courriel." : '')
            . "\n\nNous revenons vers vous très prochainement pour convenir de la date d'intervention.\n\nBien cordialement,\nArbogestion – arboriste conseil", (string)cfg('mail_kevin'));
        if ($payer) { maj_demande($id, ['paiement_statut' => 'attente']); }
        return 'prestation ponctuelle : rendez-vous à fixer' . ($acompte ? " ; facture d'acompte brouillon créée" : (!empty($calc['acompte_ht']) ? " ; ACOMPTE NON CRÉÉ (voir courriel)" : ''));
    }

    // Particuliers et pros : début après le délai de rétractation pour un particulier (sauf demande d'exécution anticipée)
    $debut = $dem['date_debut'];
    if ($dem['profil'] === 'particulier' && empty($dem['execution_anticipee'])) {
        $min = date('Y-m-d', strtotime('+15 days'));
        if ($debut < $min) { $debut = $min; }
    }
    $faits = [];
    if (stripe_actif() && !cfg('mode_essai')) {
        maj_demande($id, ['paiement_statut' => 'attente']);
        $acompte_cl = !empty($calc['acompte_ht']) ? "régler l'acompte de " . eur(round($calc['acompte_ht'] * (1 + (float)tarifs()['tva']), 2)) . " TTC et à " : '';
        envoyer_mail($c['email'], 'Arbogestion – Mise en place du paiement de votre abonnement',
            "Bonjour,\n\nMerci pour la signature de votre devis n° {$row['pl_devis_numero']}.\n\nPour finaliser, il vous reste à {$acompte_cl}signer en ligne le mandat de prélèvement SEPA de votre abonnement (il suffit de votre IBAN).\n\n" . bouton_mail(!empty($calc['acompte_ht']) ? 'Payer mon acompte et signer mon mandat SEPA' : 'Signer mon mandat de prélèvement SEPA', lien_paiement($id))
            . "\n\nLe paiement est sécurisé par Stripe. Chaque facture vous est adressée par courriel avant d'être prélevée.\n\nBien cordialement,\nArbogestion – arboriste conseil", (string)cfg('mail_kevin'));
        $faits[] = 'lien de paiement Stripe envoyé au client';
    } else {
        $faits[] = 'Stripe non configuré : moyen de paiement à mettre en place à la main';
    }
    $abo_id = null;
    if ($avec_abonnement && empty($ab['prix_an1'])) { // abonnement seul (parc déjà recensé) : démarre tout de suite
        $abo = pennylane('POST', '/billing_subscriptions', abonnement_pennylane($row, $dem, $calc, $debut));
        $abo_id = (string)($abo['id'] ?? '');
        $faits[] = 'abonnement créé (début ' . date_fr($debut) . ', mode ' . cfg('abonnement_mode', 'awaiting_validation') . ')';
    } elseif ($avec_abonnement) {
        $faits[] = 'abonnement créé automatiquement quand le recensement sera marqué « Réalisé » dans l’agenda (1re année au tiers, puis plein tarif à la date anniversaire)';
    }
    maj_demande($id, ['statut' => 'signe_paiement_demande', 'pl_abonnement_id' => $abo_id, 'notes' => ajouter_note($row, 'Devis signé : ' . implode(', ', $faits) . '.')]);
    envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Devis {$row['pl_devis_numero']} signé – {$row['nom_client']}",
        "Le devis {$row['pl_devis_numero']} a été signé.\nFait automatiquement : " . implode(' ; ', $faits) . "." . $acompte_txt . "\n\nÀ faire de ton côté : planifier le démarrage, puis facturer le démarrage après réalisation (Pennylane > Devis > Transformer en facture, en retirant la ligne d'abonnement, facturée par l'abonnement, et en déduisant l'acompte).");
    return implode(', ', $faits);
}

/** Bon de commande reçu (collectivité) : enregistrement, joint au devis, abonnement créé. */
function recevoir_bon_commande(int $id, string $tmp, string $nom_origine): void {
    $row = db()->prepare('SELECT * FROM demandes WHERE id = ?');
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row || $row['profil'] !== 'collectivite') { throw new ErreurDemande('Demande introuvable.'); }
    $dir = ARBO_APP . '/storage/bons-de-commande';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $dest = $dir . '/bdc-' . $id . '-' . date('Ymd-His') . '.pdf';
    if (!move_uploaded_file($tmp, $dest) && !rename($tmp, $dest)) { throw new RuntimeException('Enregistrement du fichier impossible.'); }
    $dem = json_decode($row['donnees'], true);
    $calc = json_decode($row['calcul'], true);
    $faits = ['bon de commande enregistré'];
    try { pennylane('POST', '/quotes/' . $row['pl_devis_id'] . '/appendices', null, $dest); $faits[] = 'joint au devis dans Pennylane'; } catch (Throwable $e) { $faits[] = 'non joint au devis (' . $e->getMessage() . ')'; }
    $abo_id = $row['pl_abonnement_id'];
    $ab = $calc['abonnement'] ?? null;
    if (!$abo_id && $ab && empty($ab['sur_devis']) && !empty($ab['prix_an1'])) {
        $faits[] = 'abonnement créé quand le recensement sera marqué « Réalisé » dans l’agenda';
    } elseif (!$abo_id && $ab && empty($ab['sur_devis'])) {
        try {
            $abo = pennylane('POST', '/billing_subscriptions', abonnement_pennylane($row, $dem, $calc, max($dem['date_debut'], date('Y-m-d'))));
            $abo_id = (string)($abo['id'] ?? ''); $faits[] = 'abonnement annuel créé dans Pennylane';
        } catch (Throwable $e) { $faits[] = 'abonnement NON créé (' . $e->getMessage() . ')'; }
    }
    maj_demande($id, ['statut' => 'bdc_recu', 'bon_commande' => basename($dest), 'pl_abonnement_id' => $abo_id, 'notes' => ajouter_note($row, implode(', ', $faits))]);
    envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Bon de commande reçu – {$row['nom_client']}",
        "La collectivité {$row['nom_client']} a transmis son bon de commande (devis {$row['pl_devis_numero']}, fichier d'origine : $nom_origine).\n" . implode(' ; ', $faits) . '.', null, $dest);
}
