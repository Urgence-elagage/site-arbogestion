<?php
/*
 * Après la signature d'un devis dans Pennylane (vérifié par la tâche planifiée) :
 *  - particuliers et pros : demande de mandat SEPA GoCardless envoyée par Pennylane + abonnement Pennylane prélevé par GoCardless ;
 *  - collectivités : lien de dépôt du bon de commande ; l'abonnement est créé à réception du bon.
 */
declare(strict_types=1);
require_once __DIR__ . '/demande.php';

function url_site(string $chemin): string { return rtrim((string)cfg('url_site', 'https://arbogestion.fr'), '/') . '/' . ltrim($chemin, '/'); }

/** Ligne d'abonnement pour la facturation récurrente. */
function abonnement_pennylane(array $row, array $dem, array $calc, string $debut): array {
    $ab = $calc['abonnement'];
    $formule = PROFILS[$dem['profil']]['formule'];
    $mensuel = $dem['paiement'] === 'mensuel' && $dem['profil'] !== 'collectivite';
    $col = $dem['profil'] === 'collectivite';
    return [
        'start' => $debut,
        'label' => "Abonnement $formule – " . $row['nom_client'],
        'mode' => ['type' => (string)cfg('abonnement_mode', 'awaiting_validation')],
        'payment_conditions' => $col ? '30_days' : ($mensuel ? 'upon_receipt' : '30_days'),
        'payment_method' => $col ? 'offline' : 'gocardless_direct_debit',
        'recurring_rule' => $mensuel ? ['type' => 'monthly', 'interval' => 1, 'day_of_month' => 10] : ['type' => 'yearly', 'interval' => 1],
        'customer_id' => (int)$row['pl_client_id'],
        'customer_invoice_data' => [
            'language' => 'fr_FR',
            'pdf_invoice_subject' => "Abonnement Arbogestion – $formule",
            'special_mention' => 'Contrat d’abonnement accepté par la signature du devis n° ' . $row['pl_devis_numero'] . '.' . ($col ? ' Facture à déposer sur Chorus Pro.' : ' Prélèvement SEPA via GoCardless.'),
            'invoice_lines' => [[
                'label' => "Abonnement $formule – jusqu'à {$ab['max']} arbres",
                'description' => $mensuel ? 'Mensualité' : 'Forfait annuel',
                'quantity' => 1, 'unit' => $mensuel ? 'mois' : 'an',
                'raw_currency_unit_price' => prix6($mensuel ? $ab['prix'] / 12 : $ab['prix']),
                'vat_rate' => 'FR_200',
            ]],
        ],
    ];
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

    if ($dem['profil'] === 'collectivite') {
        $lien = url_site('bon-de-commande.php?t=' . jeton_signe($id));
        maj_demande($id, ['statut' => 'signe_attente_bdc', 'notes' => ajouter_note($row, 'Devis signé ; lien de dépôt du bon de commande envoyé.')]);
        envoyer_mail($c['email'], 'Arbogestion – Devis ' . $row['pl_devis_numero'] . ' signé : transmission du bon de commande',
            "Bonjour,\n\nNous vous remercions pour la signature du devis n° {$row['pl_devis_numero']}.\n\nPour finaliser l'engagement, merci de nous transmettre le bon de commande ou la décision d'engagement de la collectivité (PDF), à l'adresse sécurisée suivante :\n$lien\n\nLa facture sera ensuite déposée sur Chorus Pro.\n\nBien cordialement,\nArbogestion – arboriste conseil",
            (string)cfg('mail_kevin'));
        envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Devis {$row['pl_devis_numero']} signé – {$row['nom_client']} (collectivité)",
            "Le devis {$row['pl_devis_numero']} a été signé.\nLe lien de dépôt du bon de commande a été envoyé au référent ({$c['email']}).\n" . ($avec_abonnement ? "L'abonnement sera créé dans Pennylane dès réception du bon." : 'Prestation ponctuelle : pas d\'abonnement.') . "\n\nÀ faire de ton côté : planifier le démarrage (recensement, diagnostic) et facturer le démarrage après réalisation.");
        return 'collectivité : lien bon de commande envoyé';
    }

    $acompte = null; $acompte_txt = '';
    if (!empty($calc['acompte_ht'])) {
        try { $acompte = creer_acompte($row, $calc); $acompte_txt = "\nFacture d'acompte de " . eur(round($calc['acompte_ht'] * 1.2, 2)) . " TTC créée en BROUILLON dans Pennylane : à finaliser et envoyer au client avec le mode de paiement choisi."; }
        catch (Throwable $e) { $acompte_txt = "\nATTENTION : la facture d'acompte n'a pas pu être créée (" . $e->getMessage() . ") : à faire à la main."; }
    }
    if (!$avec_abonnement) { // prestation ponctuelle (ou abonnement sur devis) : rien de récurrent
        maj_demande($id, ['statut' => 'signe_ponctuel', 'notes' => ajouter_note($row, 'Devis signé (prestation ponctuelle).' . ($acompte ? " Acompte brouillon $acompte." : ''))]);
        envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Devis {$row['pl_devis_numero']} signé – {$row['nom_client']} (prestation ponctuelle)",
            "Le devis {$row['pl_devis_numero']} (prestation ponctuelle, sans abonnement) a été signé." . $acompte_txt . (!empty($dem['execution_anticipee']) ? "\nLe client a demandé une exécution avant la fin du délai de rétractation." : ($dem['profil'] === 'particulier' ? "\nAttention : particulier sans demande d'exécution anticipée, intervenir après le délai de rétractation de 14 jours." : '')) . "\n\nÀ faire : convenir de la date d'intervention avec le client ({$c['email']}" . (empty($c['tel']) ? '' : ", {$c['tel']}") . "), puis facturer le solde après réalisation (Pennylane > Devis > Transformer en facture, en déduisant l'acompte).");
        envoyer_mail($c['email'], 'Arbogestion – Votre devis ' . $row['pl_devis_numero'] . ' est validé',
            "Bonjour,\n\nMerci pour la signature de votre devis n° {$row['pl_devis_numero']}. Nous revenons vers vous très prochainement pour convenir de la date d'intervention.\n\nBien cordialement,\nArbogestion – arboriste conseil", (string)cfg('mail_kevin'));
        return 'prestation ponctuelle : rendez-vous à fixer';
    }

    // Particuliers et pros : début après le délai de rétractation pour un particulier (sauf demande d'exécution anticipée)
    $debut = $dem['date_debut'];
    if ($dem['profil'] === 'particulier' && empty($dem['execution_anticipee'])) {
        $min = date('Y-m-d', strtotime('+15 days'));
        if ($debut < $min) { $debut = $min; }
    }
    $faits = [];
    pennylane('POST', '/gocardless_mandates/mail_requests', [
        'customer_id' => (int)$row['pl_client_id'],
        'email' => [
            'recipients' => [$c['email']],
            'subject' => 'Arbogestion – Mise en place de votre prélèvement SEPA',
            'body' => "Bonjour,\n\nMerci pour la signature de votre devis n° {$row['pl_devis_numero']}. Pour la mise en place du prélèvement de votre abonnement, merci de compléter votre mandat SEPA en ligne (sécurisé par GoCardless), en suivant le lien ci-dessous.\n\nBien cordialement,\nArbogestion",
        ],
    ]);
    $faits[] = 'demande de mandat SEPA envoyée';
    $abo_id = null;
    if ($avec_abonnement) {
        $abo = pennylane('POST', '/billing_subscriptions', abonnement_pennylane($row, $dem, $calc, $debut));
        $abo_id = (string)($abo['id'] ?? '');
        $faits[] = 'abonnement créé (début ' . date_fr($debut) . ', mode ' . cfg('abonnement_mode', 'awaiting_validation') . ')';
    }
    maj_demande($id, ['statut' => 'signe_mandat_demande', 'pl_abonnement_id' => $abo_id, 'notes' => ajouter_note($row, 'Devis signé : ' . implode(', ', $faits) . '.')]);
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
    if (!$abo_id && $ab && empty($ab['sur_devis'])) {
        try {
            $abo = pennylane('POST', '/billing_subscriptions', abonnement_pennylane($row, $dem, $calc, max($dem['date_debut'], date('Y-m-d'))));
            $abo_id = (string)($abo['id'] ?? ''); $faits[] = 'abonnement annuel créé dans Pennylane';
        } catch (Throwable $e) { $faits[] = 'abonnement NON créé (' . $e->getMessage() . ')'; }
    }
    maj_demande($id, ['statut' => 'bdc_recu', 'bon_commande' => basename($dest), 'pl_abonnement_id' => $abo_id, 'notes' => ajouter_note($row, implode(', ', $faits))]);
    envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Bon de commande reçu – {$row['nom_client']}",
        "La collectivité {$row['nom_client']} a transmis son bon de commande (devis {$row['pl_devis_numero']}, fichier d'origine : $nom_origine).\n" . implode(' ; ', $faits) . '.', null, $dest);
}
