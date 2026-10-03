<?php
$config = require __DIR__ . '/config.php';

$url = "https://discord.com/api/v10/applications/" . $config['discord_client_id'] . "/commands";

$payload = [
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
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bot ' . $config['discord_bot_token'],
        'Content-Type: application/json'
    ]
]);

$reponse = curl_exec($ch);
$code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Code HTTP : $code\nRéponse : $reponse\n";