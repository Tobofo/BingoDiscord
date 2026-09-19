<?php
// Réglages et secrets de l'activité.
// ⚠️ Ne partage jamais ce fichier (ni sur GitHub, ni en capture d'écran).
return [
    // Discord : portail développeur > ton application > OAuth2
    'discord_client_id'     => '1550630231356739696',
    'discord_client_secret' => '9cHFOkVK2N8nOwQ0k3cGPAwz-_AGU5L6',

    // Firebase Realtime Database : adresse affichée en haut de l'onglet « Données ».
    // Sans "/" final. Si tes phrases sont rangées sous un autre nœud, ajoute-le ici.
    'firebase_url'   => 'https://bingo-ad927-default-rtdb.europe-west1.firebasedatabase.app/bingo.json',

    // Laisse vide si la lecture de "phrases" est publique (voir les règles Firebase).
    'firebase_token' => '',
];
