<?php
/*
 * Catalogue d'articles Pennylane (« Produits ») : recherche, rapprochement et création.
 * Les lignes de devis sont reliées à l'article existant (même libellé) au lieu d'en recréer un.
 * Jeton Pennylane : droit « Produits » en lecture et écriture.
 */
declare(strict_types=1);
require_once __DIR__ . '/core.php';

const PRODUITS_CACHE_S = 600; // 10 min

function produit_cle(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['’' => "'", '–' => '-', '—' => '-', 'œ' => 'oe', 'æ' => 'ae']);
    $s = (string)(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $s));
}

function produits_fichier(): string { return ARBO_APP . '/storage/produits-pennylane.json'; }

/** Tous les articles Pennylane (cache de 10 minutes). */
function pl_produits(bool $forcer = false): array {
    $f = produits_fichier();
    if (!$forcer && is_file($f) && filemtime($f) > time() - PRODUITS_CACHE_S) { return json_decode((string)file_get_contents($f), true) ?: []; }
    $tous = []; $curseur = null;
    for ($page = 0; $page < 20; $page++) {
        $r = pennylane('GET', '/products?limit=100' . ($curseur ? '&cursor=' . rawurlencode($curseur) : ''));
        foreach (($r['items'] ?? []) as $p) {
            $tous[] = ['id' => (string)$p['id'], 'label' => (string)($p['label'] ?? ''), 'description' => (string)($p['description'] ?? ''),
                'unit' => (string)($p['unit'] ?? ''), 'prix' => isset($p['price_before_tax']) ? (float)$p['price_before_tax'] : null,
                'tva' => (string)($p['vat_rate'] ?? ''), 'reference' => (string)($p['reference'] ?? '')];
        }
        if (empty($r['has_more']) || empty($r['next_cursor'])) { break; }
        $curseur = (string)$r['next_cursor'];
    }
    @mkdir(dirname($f), 0700, true);
    file_put_contents($f, json_encode($tous, JSON_UNESCAPED_UNICODE));
    return $tous;
}

/** Recherche (libellé ou référence contenant tous les mots saisis). */
function produits_chercher(string $q, int $max = 8): array {
    $mots = array_filter(explode(' ', produit_cle($q)));
    if (!$mots) { return []; }
    $res = [];
    foreach (pl_produits() as $p) {
        $txt = produit_cle($p['label'] . ' ' . $p['reference']);
        foreach ($mots as $m) { if (!str_contains($txt, $m)) { continue 2; } }
        $res[] = $p + ['_rang' => str_starts_with(produit_cle($p['label']), produit_cle($q)) ? 0 : 1];
    }
    usort($res, fn($a, $b) => [$a['_rang'], mb_strlen($a['label'])] <=> [$b['_rang'], mb_strlen($b['label'])]);
    return array_slice($res, 0, $max);
}

function pl_produit_par_libelle(string $label): ?array {
    $k = produit_cle($label);
    foreach (pl_produits() as $p) { if (produit_cle($p['label']) === $k) { return $p; } }
    return null;
}

/** Article Pennylane du libellé : existant, sinon créé. Renvoie son id (null si impossible : la ligne reste libre). */
function pl_produit_assurer(string $label, string $unite, float $pu, string $desc = ''): ?string {
    static $faits = [];
    $k = produit_cle($label);
    if ($k === '') { return null; }
    if (array_key_exists($k, $faits)) { return $faits[$k]; }
    try {
        if ($p = pl_produit_par_libelle($label)) { return $faits[$k] = $p['id']; }
        $r = pennylane('POST', '/products', ['label' => $label, 'description' => $desc, 'unit' => $unite, 'price_before_tax' => number_format($pu, 2, '.', ''),
            'vat_rate' => 'FR_200', 'currency' => 'EUR']);
        if (!empty($r['essai'])) { return $faits[$k] = null; }
        $id = (string)($r['id'] ?? '');
        if ($id !== '') {
            journal("Pennylane : article créé « $label » ($id)");
            @unlink(produits_fichier());
        }
        return $faits[$k] = ($id ?: null);
    } catch (Throwable $e) {
        journal("Pennylane : article « $label » non relié : " . $e->getMessage());
        return $faits[$k] = null;
    }
}
