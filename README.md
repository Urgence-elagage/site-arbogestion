# Site arbogestion.fr

Site vitrine d'Arbogestion (arboriste conseil, Cambes — Lot).

- `www/` : contenu publié sur https://arbogestion.fr (hébergement OVH, dossier `arbogestion/www`).
- Chaque modification de `www/` sur la branche `main` est mise en ligne automatiquement
  (`.github/workflows/deploiement-ovh.yml`). Rien n'est supprimé côté serveur.
- Mise en ligne manuelle : onglet **Actions** → « Mise en ligne sur OVH » → **Exécuter le workflow**.

Secrets à renseigner dans Paramètres → Secrets et variables → Actions :
`OVH_HOTE`, `OVH_UTILISATEUR`, `OVH_MOT_DE_PASSE`.
