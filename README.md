# Site arbogestion.fr

Site vitrine d'Arbogestion (arboriste conseil, Cambes — Lot).

- `www/` : contenu publié sur https://arbogestion.fr (hébergement OVH, dossier `arbogestion/www`).
- Chaque modification de `www/` sur la branche `main` est mise en ligne automatiquement
  (`.github/workflows/deploiement-ovh.yml`). Rien n'est supprimé côté serveur.
- Mise en ligne manuelle : onglet **Actions** → « Mise en ligne sur OVH » → **Exécuter le workflow**.

Secrets à renseigner dans Paramètres → Secrets et variables → Actions :
`OVH_HOTE`, `OVH_UTILISATEUR`, `OVH_MOT_DE_PASSE`.

## Devis et contrats en ligne (dossier `app/`, hors partie publique)

- `www/tarifs.json` : **tous les prix**, lus à la fois par le calculateur et par le serveur.
- `www/api/demande.php` : reçoit la demande du site → client + **devis brouillon** dans Pennylane, contrat PDF (`app/contrat.php`) joint en annexe, courriels.
- `app/cron.php` (tâche planifiée OVH, toutes les heures) : devis signés → particuliers/pros : demande de mandat GoCardless + abonnement Pennylane ; collectivités : lien `bon-de-commande.php`.
- `app/config.php` : uniquement sur le serveur (modèle : `app/config.sample.php`). Jamais sur GitHub.
