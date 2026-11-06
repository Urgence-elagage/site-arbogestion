<?php
/*
 * Paiements Stripe (particuliers et professionnels) — Pennylane reste le seul outil qui émet les factures.
 *  - Après signature du devis : page de paiement Stripe (acompte par carte ; enregistrement du moyen de paiement
 *    — mandat SEPA ou carte — pour les abonnements).
 *  - Chaque facture d'abonnement finalisée dans Pennylane est prélevée sur Stripe (montant exact, une seule fois),
 *    puis marquée payée dans Pennylane. Rejet ou échec : alerte à Kevin.
 *  - On n'utilise pas Stripe Billing (frais supplémentaires) ni les factures Stripe.
 * config.php : 'stripe_secret' => 'sk_test_…' (ou sk_live_… / rk_live_… en production).
 */
declare(strict_types=1);
require_once __DIR__ . '/core.php';

class ErreurStripe extends RuntimeException {}

function stripe(string $methode, string $chemin, array $params = [], ?string $idempotence = null): array {
    $cle = (string)cfg('stripe_secret', '');
    if ($cle === '') { throw new ErreurStripe('Clé Stripe absente de config.php (stripe_secret).'); }
    $url = rtrim((string)cfg('stripe_base', 'https://api.stripe.com/v1'), '/') . $chemin;
    $corps = http_build_query($params, '', '&');
    if ($methode === 'GET' && $corps !== '') { $url .= '?' . $corps; }
    $ch = curl_init($url);
    $entetes = ['Authorization: Bearer ' . $cle];
    if ($idempotence) { $entetes[] = 'Idempotency-Key: ' . $idempotence; }
    $opts = [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $entetes];
    if ($methode !== 'GET') { $opts[CURLOPT_POSTFIELDS] = $corps; }
    curl_setopt_array($ch, $opts);
    $rep = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$rep, true) ?? [];
    if ($rep === false || $code >= 400) {
        $msg = $json['error']['message'] ?? ($err ?: substr((string)$rep, 0, 300));
        journal("ERREUR Stripe $methode $chemin HTTP $code $msg");
        $e = new ErreurStripe("Stripe HTTP $code : $msg");
        throw $e;
    }
    return $json;
}

function stripe_actif(): bool { return (string)cfg('stripe_secret', '') !== ''; }

/** Colonnes et table ajoutées pour Stripe (installées automatiquement). */
function installer_stripe(): void {
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    $pdo = db();
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    foreach (['stripe_client' => 'VARCHAR(60)', 'stripe_session' => 'VARCHAR(120)', 'stripe_pm' => 'VARCHAR(60)', 'stripe_pm_type' => 'VARCHAR(30)',
              'stripe_acompte_pi' => 'VARCHAR(60)', 'pl_acompte_id' => 'VARCHAR(40)', 'paiement_statut' => 'VARCHAR(30)'] as $col => $type) {
        try { $pdo->query("SELECT $col FROM demandes LIMIT 1"); }
        catch (Throwable $e) { $pdo->exec("ALTER TABLE demandes ADD COLUMN $col $type NULL"); }
    }
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS prelevements (
        id $id,
        demande_id INT NOT NULL,
        pl_facture_id VARCHAR(40) NOT NULL,
        pl_facture_numero VARCHAR(60) NULL,
        montant_cts INT NOT NULL,
        pi_id VARCHAR(60) NULL,
        statut VARCHAR(20) NOT NULL,
        erreur VARCHAR(500) NULL,
        cree_le VARCHAR(19) NOT NULL,
        maj_le VARCHAR(19) NULL,
        UNIQUE (pl_facture_id)
    )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
}

function maj_demande_s(int $id, array $champs): void {
    $champs['maj_le'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($champs)));
    db()->prepare("UPDATE demandes SET $set WHERE id = ?")->execute([...array_values($champs), $id]);
}

function lien_paiement(int $id): string {
    return rtrim((string)cfg('url_site', 'https://arbogestion.fr'), '/') . '/paiement.php?t=' . jeton_signe($id);
}

/** Client Stripe de la demande (créé une fois). */
function stripe_client_demande(array $row): string {
    if (!empty($row['stripe_client'])) { return (string)$row['stripe_client']; }
    $dem = json_decode($row['donnees'], true);
    $cu = stripe('POST', '/customers', [
        'email' => $dem['client']['email'], 'name' => $row['nom_client'], 'preferred_locales' => ['fr'],
        'metadata' => ['demande' => (string)$row['id'], 'pennylane_client' => (string)$row['pl_client_id']],
    ], 'client-demande-' . $row['id']);
    maj_demande_s((int)$row['id'], ['stripe_client' => $cu['id']]);
    return (string)$cu['id'];
}

/** Ce qu'il faut payer ou enregistrer sur la page de paiement. */
function besoin_paiement(array $row): array {
    $calc = json_decode($row['calcul'], true);
    $ab = $calc['abonnement'] ?? null;
    $abo = $ab && empty($ab['sur_devis']);
    $acompte_ttc = !empty($calc['acompte_ht']) ? round($calc['acompte_ht'] * (1 + (float)tarifs()['tva']), 2) : 0.0;
    return ['acompte_ttc' => $acompte_ttc, 'abonnement' => $abo];
}

/** Crée une session de paiement Stripe Checkout (valable 24 h ; recréée à chaque visite du lien personnel). */
function creer_session_paiement(array $row): string {
    installer_stripe();
    $b = besoin_paiement($row);
    $client = stripe_client_demande($row);
    $retour = lien_paiement((int)$row['id']);
    $p = [
        'customer' => $client, 'locale' => 'fr', 'client_reference_id' => (string)$row['id'],
        'success_url' => $retour . '&retour=ok', 'cancel_url' => $retour . '&retour=annule',
        'metadata' => ['demande' => (string)$row['id'], 'devis' => (string)$row['pl_devis_numero']],
    ];
    if ($b['acompte_ttc'] > 0) {
        $p['mode'] = 'payment';
        $p['line_items'] = [[
            'quantity' => 1,
            'price_data' => ['currency' => 'eur', 'unit_amount' => (int)round($b['acompte_ttc'] * 100),
                'product_data' => ['name' => 'Acompte – devis n° ' . $row['pl_devis_numero'], 'description' => 'Arbogestion – acompte de ' . tarifs()['acompte']['pourcentage'] . ' % à la signature']],
        ]];
        $p['payment_intent_data'] = ['description' => 'Acompte devis ' . $row['pl_devis_numero'], 'metadata' => ['demande' => (string)$row['id']]]
            + ($b['abonnement'] ? ['setup_future_usage' => 'off_session'] : []);
    } else {
        $p['mode'] = 'setup';
        $p['currency'] = 'eur';
        $p['setup_intent_data'] = ['description' => 'Abonnement Arbogestion – devis ' . $row['pl_devis_numero'], 'metadata' => ['demande' => (string)$row['id']]];
    }
    /*
     * Les moyens de paiement proposés sont ceux activés dans le tableau de bord Stripe (Stripe n'accepte plus de les lister ici).
     * On écarte ceux qui ne conviennent pas : ponctuel = carte, Apple Pay / Google Pay, Wero ; abonnement = carte ou prélèvement SEPA.
     */
    $hors_sujet = ['amazon_pay', 'kakao_pay', 'naver_pay', 'payco', 'samsung_pay', 'satispay', 'mb_way', 'bancontact', 'blik', 'eps', 'klarna', 'pix',
        'paypal', 'revolut_pay', 'alma', 'billie', 'scalapay', 'ideal', 'p24', 'multibanco', 'wechat_pay', 'alipay', 'link', 'bizum', 'twint', 'mobilepay', 'swish'];
    $exclus = $b['abonnement'] ? ['wero'] : ['sepa_debit'];
    $essais = [array_values(array_unique(array_merge($exclus, $hors_sujet))), $exclus, []];
    $s = null; $derniere = null;
    foreach ($essais as $ex) {
        $q = $p; if ($ex) { $q['excluded_payment_method_types'] = $ex; }
        try { $s = stripe('POST', '/checkout/sessions', $q); break; }
        catch (ErreurStripe $e) { $derniere = $e; journal('Session Stripe refusée avec ' . count($ex) . ' exclusion(s), nouvel essai : ' . $e->getMessage()); }
    }
    if (!$s) { throw $derniere; }
    maj_demande_s((int)$row['id'], ['stripe_session' => $s['id'], 'paiement_statut' => $row['paiement_statut'] ?: 'attente']);
    return (string)$s['url'];
}

/**
 * Lit la session Stripe de la demande et met à jour : moyen de paiement enregistré, acompte payé.
 * Renvoie un court compte rendu, ou '' si rien n'a changé.
 */
function verifier_session(array $row): string {
    if (empty($row['stripe_session']) || $row['paiement_statut'] !== 'attente') { return ''; }
    $id = (int)$row['id'];
    $s = stripe('GET', '/checkout/sessions/' . $row['stripe_session'], ['expand' => ['payment_intent.payment_method', 'setup_intent.payment_method']]);
    if (($s['status'] ?? '') !== 'complete') { return ''; }
    $pi = is_array($s['payment_intent'] ?? null) ? $s['payment_intent'] : null;
    $si = is_array($s['setup_intent'] ?? null) ? $s['setup_intent'] : null;
    $pm = $pi['payment_method'] ?? ($si['payment_method'] ?? null);
    $b = besoin_paiement($row);
    $champs = [];
    $nouveau_moyen = false;
    if ($b['abonnement'] && is_array($pm)) {
        $champs['stripe_pm'] = $pm['id']; $champs['stripe_pm_type'] = $pm['type'];
        $nouveau_moyen = $pm['id'] !== $row['stripe_pm'];
        try { stripe('POST', '/customers/' . $row['stripe_client'], ['invoice_settings' => ['default_payment_method' => $pm['id']]]); } catch (Throwable $e) {}
    }
    if ($pi) {
        $champs['stripe_acompte_pi'] = $pi['id'];
        $etat = ($pi['status'] ?? '') === 'succeeded' ? 'acompte_paye' : 'acompte_en_cours';
    } else {
        $etat = !empty($row['stripe_acompte_pi']) ? 'acompte_paye' : 'moyen_enregistre';
    }
    $champs['paiement_statut'] = $etat;
    maj_demande_s($id, $champs);
    if ($nouveau_moyen) { // un nouveau moyen de paiement : les prélèvements en échec seront représentés
        db()->prepare("DELETE FROM prelevements WHERE demande_id = ? AND statut = 'echec'")->execute([$id]);
    }
    $mp = is_array($pm) ? ($pm['type'] === 'sepa_debit' ? 'mandat SEPA, IBAN se terminant par ' . ($pm['sepa_debit']['last4'] ?? '?') : 'carte se terminant par ' . ($pm['card']['last4'] ?? '?')) : '';
    $txt = [
        'acompte_paye' => $pi ? "L'acompte de " . eur($b['acompte_ttc']) . " TTC a été payé sur Stripe" . ($mp ? " ($mp)" : '') . '.' : "Le nouveau moyen de paiement de l'abonnement est enregistré sur Stripe ($mp).",
        'acompte_en_cours' => "L'acompte de " . eur($b['acompte_ttc']) . " TTC est en cours d'encaissement sur Stripe ($mp) : un prélèvement SEPA peut prendre quelques jours.",
        'moyen_enregistre' => "Le moyen de paiement de l'abonnement est enregistré sur Stripe ($mp).",
    ][$etat];
    envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] Paiement – {$row['nom_client']} (devis {$row['pl_devis_numero']})",
        $txt . ($b['abonnement'] ? "\nLes factures de l'abonnement seront prélevées automatiquement dès qu'elles seront finalisées dans Pennylane." : '')
        . ($pi && $etat === 'acompte_paye' ? "\nFinalise la facture d'acompte dans Pennylane si ce n'est pas fait : elle sera marquée payée automatiquement." : ''));
    return "paiement $etat";
}

/** Facture d'acompte finalisée dans Pennylane + acompte payé sur Stripe : on la marque payée. */
function rapprocher_acompte(array $row): string {
    if ($row['paiement_statut'] === 'acompte_en_cours' && !empty($row['stripe_acompte_pi'])) {
        $pi = stripe('GET', '/payment_intents/' . $row['stripe_acompte_pi']);
        if (($pi['status'] ?? '') === 'succeeded') { maj_demande_s((int)$row['id'], ['paiement_statut' => 'acompte_paye']); $row['paiement_statut'] = 'acompte_paye'; }
        elseif (in_array($pi['status'] ?? '', ['requires_payment_method', 'canceled'], true)) {
            maj_demande_s((int)$row['id'], ['paiement_statut' => 'acompte_echec']);
            envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] ÉCHEC de paiement de l'acompte – {$row['nom_client']}",
                "Le paiement de l'acompte du devis {$row['pl_devis_numero']} a échoué sur Stripe (" . ($pi['last_payment_error']['message'] ?? 'motif non précisé') . ").\nLe client peut recommencer avec son lien personnel : " . lien_paiement((int)$row['id']));
            return 'acompte en échec';
        }
    }
    if ($row['paiement_statut'] !== 'acompte_paye' || empty($row['pl_acompte_id'])) { return ''; }
    $f = pennylane('GET', '/customer_invoices/' . $row['pl_acompte_id']);
    if (!empty($f['draft'])) { return ''; } // pas encore finalisée par Kevin
    if (empty($f['paid'])) { pennylane('PUT', '/customer_invoices/' . $row['pl_acompte_id'] . '/mark_as_paid'); }
    maj_demande_s((int)$row['id'], ['paiement_statut' => 'complet']);
    return 'acompte marqué payé dans Pennylane';
}

/** Prélève les factures d'abonnement finalisées et non payées (une seule fois par facture). */
function prelever_abonnement(array $row): array {
    if (empty($row['stripe_pm']) || empty($row['pl_abonnement_id'])) { return []; }
    $cr = [];
    $f = pl_filtre([['field' => 'billing_subscription_id', 'operator' => 'eq', 'value' => (string)$row['pl_abonnement_id']], ['field' => 'draft', 'operator' => 'eq', 'value' => 'false']]);
    $liste = pennylane('GET', '/customer_invoices?limit=100&filter=' . $f);
    foreach (($liste['items'] ?? []) as $fa) {
        if (!empty($fa['paid']) || !empty($fa['draft']) || in_array($fa['status'] ?? '', ['cancelled', 'archived', 'paid', 'credit_note'], true)) { continue; }
        $reste = (float)($fa['remaining_amount_with_tax'] ?? $fa['amount'] ?? 0);
        if ($reste <= 0) { continue; }
        $deja = db()->prepare('SELECT id FROM prelevements WHERE pl_facture_id = ?');
        $deja->execute([(string)$fa['id']]);
        if ($deja->fetch()) { continue; }
        $cts = (int)round($reste * 100);
        db()->prepare('INSERT INTO prelevements (demande_id, pl_facture_id, pl_facture_numero, montant_cts, statut, cree_le) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$row['id'], (string)$fa['id'], (string)($fa['invoice_number'] ?? ''), $cts, 'cree', date('Y-m-d H:i:s')]);
        try {
            $pi = stripe('POST', '/payment_intents', [
                'amount' => $cts, 'currency' => 'eur', 'customer' => $row['stripe_client'], 'payment_method' => $row['stripe_pm'],
                'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'], 'off_session' => 'true', 'confirm' => 'true',
                'description' => 'Facture ' . ($fa['invoice_number'] ?? $fa['id']) . ' – abonnement Arbogestion',
                'metadata' => ['demande' => (string)$row['id'], 'pennylane_facture' => (string)$fa['id'], 'facture' => (string)($fa['invoice_number'] ?? '')],
            ], 'pl-facture-' . $fa['id'] . '-' . $row['stripe_pm']);
            $st = ($pi['status'] ?? '') === 'succeeded' ? 'paye_stripe' : 'en_cours';
            db()->prepare('UPDATE prelevements SET pi_id = ?, statut = ?, maj_le = ? WHERE pl_facture_id = ?')->execute([$pi['id'], $st, date('Y-m-d H:i:s'), (string)$fa['id']]);
            $cr[] = 'facture ' . ($fa['invoice_number'] ?? $fa['id']) . ' : prélèvement lancé (' . eur($reste) . ')';
        } catch (Throwable $e) {
            db()->prepare('UPDATE prelevements SET statut = ?, erreur = ?, maj_le = ? WHERE pl_facture_id = ?')->execute(['echec', substr($e->getMessage(), 0, 500), date('Y-m-d H:i:s'), (string)$fa['id']]);
            alerte_echec($row, $fa['invoice_number'] ?? (string)$fa['id'], $reste, $e->getMessage());
            $cr[] = 'facture ' . ($fa['invoice_number'] ?? $fa['id']) . ' : ÉCHEC';
        }
    }
    return $cr;
}

function alerte_echec(array $row, string $facture, float $montant, string $motif): void {
    envoyer_mail((string)cfg('mail_kevin'), "[Arbogestion] ÉCHEC de prélèvement – {$row['nom_client']} (facture $facture)",
        "Le prélèvement de la facture $facture (" . eur($montant) . " TTC) a échoué sur Stripe.\nMotif : $motif\n\nÀ faire : contacter le client. Il peut enregistrer un nouveau moyen de paiement avec son lien personnel (à lui transférer) :\n" . lien_paiement((int)$row['id'])
        . "\nDès qu'un nouveau moyen de paiement est enregistré, la facture est représentée automatiquement. Si le client paie autrement, marque la facture payée dans Pennylane : elle ne sera plus prélevée.");
}

/** Suit les prélèvements en cours : payé → facture marquée payée dans Pennylane ; échec → alerte. */
function suivre_prelevements(): array {
    $cr = [];
    $rows = db()->query("SELECT p.*, d.nom_client, d.id AS did, d.pl_devis_numero FROM prelevements p JOIN demandes d ON d.id = p.demande_id WHERE p.statut IN ('en_cours','paye_stripe')")->fetchAll();
    foreach ($rows as $p) {
        try {
            $st = $p['statut'];
            if ($st === 'en_cours') {
                $pi = stripe('GET', '/payment_intents/' . $p['pi_id']);
                if (($pi['status'] ?? '') === 'succeeded') { $st = 'paye_stripe'; }
                elseif (in_array($pi['status'] ?? '', ['requires_payment_method', 'canceled'], true)) {
                    $motif = $pi['last_payment_error']['message'] ?? 'rejet du prélèvement';
                    db()->prepare('UPDATE prelevements SET statut = ?, erreur = ?, maj_le = ? WHERE id = ?')->execute(['echec', substr($motif, 0, 500), date('Y-m-d H:i:s'), $p['id']]);
                    alerte_echec(['id' => $p['did'], 'nom_client' => $p['nom_client']], (string)($p['pl_facture_numero'] ?: $p['pl_facture_id']), $p['montant_cts'] / 100, $motif);
                    $cr[] = 'facture ' . ($p['pl_facture_numero'] ?: $p['pl_facture_id']) . ' : rejet';
                    continue;
                }
            }
            if ($st === 'paye_stripe') {
                try { pennylane('PUT', '/customer_invoices/' . $p['pl_facture_id'] . '/mark_as_paid'); } catch (Throwable $e) { journal('mark_as_paid ' . $p['pl_facture_id'] . ' : ' . $e->getMessage()); }
                $st = 'paye';
                $cr[] = 'facture ' . ($p['pl_facture_numero'] ?: $p['pl_facture_id']) . ' : payée';
            }
            if ($st !== $p['statut']) { db()->prepare('UPDATE prelevements SET statut = ?, maj_le = ? WHERE id = ?')->execute([$st, date('Y-m-d H:i:s'), $p['id']]); }
        } catch (Throwable $e) { journal('Suivi prélèvement ' . $p['id'] . ' : ' . $e->getMessage()); }
    }
    return $cr;
}

/** Récapitulatif mensuel (le 1er du mois, au premier passage après 8 h) et alerte des factures restées impayées. */
function recap_mensuel(): ?string {
    $marque = ARBO_APP . '/storage/recap-' . date('Y-m') . '.txt';
    if (date('j') !== '1' || (int)date('G') < 8 || is_file($marque)) { return null; }
    @file_put_contents($marque, date('c'));
    $mois = date('Y-m', strtotime('first day of last month'));
    $s = db()->prepare("SELECT p.*, d.nom_client FROM prelevements p JOIN demandes d ON d.id = p.demande_id WHERE p.cree_le LIKE ? ORDER BY p.id");
    $s->execute([$mois . '%']);
    $l = $s->fetchAll(); $tot = ['paye' => 0, 'en_cours' => 0, 'echec' => 0];
    $txt = '';
    foreach ($l as $p) {
        $k = $p['statut'] === 'paye' ? 'paye' : ($p['statut'] === 'echec' ? 'echec' : 'en_cours');
        $tot[$k] += $p['montant_cts'];
        $txt .= '- ' . $p['nom_client'] . ' – facture ' . ($p['pl_facture_numero'] ?: $p['pl_facture_id']) . ' : ' . eur($p['montant_cts'] / 100) . ' – ' . ['paye' => 'payée', 'en_cours' => 'en cours', 'echec' => 'ÉCHEC'][$k] . "\n";
    }
    $attente = db()->query("SELECT nom_client, pl_devis_numero FROM demandes WHERE paiement_statut = 'attente' AND stripe_session IS NOT NULL")->fetchAll();
    envoyer_mail((string)cfg('mail_kevin'), '[Arbogestion] Récapitulatif des prélèvements – ' . date_fr($mois . '-01'),
        "Prélèvements lancés le mois dernier : " . count($l) . "\nPayés : " . eur($tot['paye'] / 100) . "\nEn cours : " . eur($tot['en_cours'] / 100) . "\nEn échec : " . eur($tot['echec'] / 100) . "\n\n" . ($txt ?: "Aucun prélèvement.\n")
        . ($attente ? "\nClients qui n'ont pas encore enregistré leur moyen de paiement :\n" . implode("\n", array_map(fn($r) => '- ' . $r['nom_client'] . ' (devis ' . $r['pl_devis_numero'] . ')', $attente)) . "\n" : ''));
    return 'récapitulatif mensuel envoyé';
}

/** Point d'entrée de la tâche planifiée pour Stripe. */
function traiter_paiements(): array {
    if (!stripe_actif() || cfg('mode_essai')) { return []; }
    installer_stripe();
    $cr = [];
    $rows = db()->query("SELECT * FROM demandes WHERE stripe_client IS NOT NULL ORDER BY id")->fetchAll();
    foreach ($rows as $row) {
        try {
            $r = verifier_session($row);
            if ($r) { $cr[] = "{$row['id']} $r"; $s = db()->prepare('SELECT * FROM demandes WHERE id = ?'); $s->execute([$row['id']]); $row = $s->fetch(); }
            $r = rapprocher_acompte($row); if ($r) { $cr[] = "{$row['id']} $r"; }
            foreach (prelever_abonnement($row) as $r) { $cr[] = "{$row['id']} $r"; }
        } catch (Throwable $e) { journal("Paiements, demande {$row['id']} : " . $e->getMessage()); $cr[] = "{$row['id']} erreur paiement : " . $e->getMessage(); }
    }
    foreach (suivre_prelevements() as $r) { $cr[] = $r; }
    try { $r = recap_mensuel(); if ($r) { $cr[] = $r; } } catch (Throwable $e) { journal('Récap : ' . $e->getMessage()); }
    return $cr;
}
