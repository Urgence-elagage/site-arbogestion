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

## Conditions générales de vente (CGV)

Source unique : `www/cgv/AAAA-MM-JJ/` (un dossier par version). Elles sont :
- publiées sur https://arbogestion.fr/cgv.php (avec téléchargement PDF et anciennes versions) ;
- jointes automatiquement à chaque devis créé par le site (PDF annexé au devis Pennylane), dans la version en vigueur ;
- la version jointe est enregistrée avec la demande (preuve de ce que le client a accepté).

**Modifier les CGV** (sans toucher au code) :
1. Sur GitHub, ouvrir `www/cgv/` puis le dossier le plus récent.
2. Pour chaque fichier, créer une copie dans un nouveau dossier daté du jour d'entrée en vigueur
   (bouton **Ajouter un fichier** → **Créer un nouveau fichier**, nom : `www/cgv/2027-01-01/particuliers.txt`),
   en collant le texte modifié. Copier aussi les fichiers inchangés : chaque dossier doit contenir
   `identite.txt`, `collectivites.txt`, `particuliers.txt`, `professionnels.txt`.
3. **Valider les modifications**. La mise en ligne est automatique ; à partir de la date du dossier,
   tous les nouveaux devis reçoivent la nouvelle version. Une date future prépare une version à l'avance.

Ne jamais modifier un dossier déjà en vigueur : des clients ont signé sur cette version.
Format : `# Titre`, `## Titre d'article` puis un paragraphe par ligne, `### Titre` pour un encadré,
`> texte` pour la mention finale. Les articles sont numérotés automatiquement.
