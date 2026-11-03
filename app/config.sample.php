<?php
/*
 * Configuration Arbogestion — MODÈLE.
 * Copier ce fichier en « config.php » dans le même dossier, SUR LE SERVEUR UNIQUEMENT
 * (dossier /home/urgencs/arbogestion/app/), puis remplir les valeurs.
 * config.php n'est jamais envoyé sur GitHub ni écrasé par la mise en ligne automatique.
 */
return [
    // Base MySQL OVH (Hébergements > Bases de données)
    'db_host' => 'urgencsarbogest.mysql.db',
    'db_name' => 'urgencsarbogest',
    'db_user' => 'urgencsarbogest',
    'db_pass' => 'À_REMPLIR',

    // Pennylane : Paramètres > Connectivité > Développeurs > Générer un jeton API (v2)
    // Droits nécessaires : customers:all, quotes:all, billing_subscriptions:all, customer_mandates:all
    'pennylane_token' => 'À_REMPLIR',

    // Courriels
    'mail_from'   => 'noreply@arbogestion.fr',   // expéditeur technique
    'mail_kevin'  => 'contact@arbogestion.fr',   // reçoit les alertes (nouvelle demande, devis signé…)

    // Clé secrète (au moins 32 caractères au hasard) : sert à signer les liens envoyés aux clients
    'secret' => 'À_REMPLIR_AVEC_32_CARACTERES_AU_HASARD',

    // Jeton pour lancer la tâche planifiée par adresse web (si OVH ne lance pas le fichier directement)
    'cron_token' => 'À_REMPLIR',

    // Abonnements créés dans Pennylane après signature :
    //  'awaiting_validation' = chaque facture attend ta validation dans Pennylane (recommandé au démarrage)
    //  'finalized'           = factures émises automatiquement
    'abonnement_mode' => 'awaiting_validation',

    // Mode essai : true = aucune écriture dans Pennylane (journal seulement). Mettre false pour la production.
    'mode_essai' => true,
];
