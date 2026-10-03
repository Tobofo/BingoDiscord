<?php
$config = require __DIR__ . '/config.php';

$url = "https://discord.com/api/v10/applications/" . $config['discord_client_id'] . "/commands";

$commandes = [
    [
        'name'        => 'ajouter-phrase',
        'description' => 'Ajoute une nouvelle phrase au Bingo',
        'options'     => [
            [
                'type'        => 3, // STRING
                'name'        => 'phrase',
                'description' => 'La phrase à ajouter dans la base Firebase',
                'required'    => true
            ]
        ]
    ],
    [
        'name'        => 'cooldown-flash',
        'description' => 'Modifie le temps de recharge des flashs (0 à 5 secondes)',
        'options'     => [
            [
                'type'        => 4, // INTEGER
                'name'        => 'secondes',
                'description' => 'Durée du cooldown en secondes (0 à 5)',
                'required'    => true,
                'min_value'   => 0,
                'max_value'   => 5
            ]
        ]
    ],
    [
        'name'        => 'list-phrases',
        'description' => 'Affiche et gère les phrases du Bingo'
    ]
];

foreach ($commandes as $cmd) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($cmd, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bot ' . $config['discord_bot_token'],
            'Content-Type: application/json'
        ]
    ]);

    $reponse = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        echo "✔ Commande /{$cmd['name']} enregistrée avec succès !\n";
    } else {
        echo "✘ Erreur ($code) pour /{$cmd['name']} : $reponse\n";
    }
}