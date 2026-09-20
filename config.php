<?php
// Réglages et secrets de l'activité.
// ⚠️ Ne partage jamais ce fichier une fois rempli (ni sur GitHub, ni en capture d'écran).
// Les valeurs sensibles sont désormais lues depuis les variables d'environnement
// (voir docker-compose.yml / .env), avec repli sur les anciennes valeurs pour le dev local.
return [
    // Discord : portail développeur > ton application > OAuth2
    'discord_client_id'     => getenv('DISCORD_CLIENT_ID') ?: '1550630231356739696',
    'discord_client_secret' => getenv('DISCORD_CLIENT_SECRET') ?: '',

    // Firebase Realtime Database : adresse affichée en haut de l'onglet « Données ».
    // Sans "/" final. Si tes phrases sont rangées sous un autre nœud, ajoute-le ici.
    'firebase_url'   => getenv('FIREBASE_URL') ?: 'https://bingo-ad927-default-rtdb.europe-west1.firebasedatabase.app/bingo.json',

    // Laisse vide si la lecture de "phrases" est publique (voir les règles Firebase).
    'firebase_token' => getenv('FIREBASE_TOKEN') ?: '',
];
