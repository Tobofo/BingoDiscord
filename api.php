<?php
// ---------------------------------------------------------------
// API de l'activité : ?action=token | grille | sync
// ---------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';

function repondre(array $donnees, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

// Corps JSON envoyé par le navigateur, sous forme de tableau
function corpsJson(): array
{
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Garde les 32 premiers caractères (UTF-8), sans dépendre de l'extension mbstring
function nomCourt(string $nom): string
{
    preg_match('/^.{0,32}/su', $nom, $m);
    return $m[0] ?? 'Joueur';
}

// 1) Échange du "code" Discord contre un access_token (le secret reste côté serveur)
if ($action === 'token') {
    $contexte = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: BingoActivity (php)\r\n",
        'content'       => http_build_query([
            'client_id'     => $config['discord_client_id'],
            'client_secret' => $config['discord_client_secret'],
            'grant_type'    => 'authorization_code',
            'code'          => corpsJson()['code'] ?? '',
        ]),
        'ignore_errors' => true,
    ]]);
    $reponse = @file_get_contents('https://discord.com/api/oauth2/token', false, $contexte);
    if ($reponse === false) {
        repondre(['erreur' => "Discord injoignable depuis PHP : " . (error_get_last()['message'] ?? 'erreur inconnue')], 500);
    }
    $token = json_decode($reponse, true)['access_token'] ?? null;
    if (!$token) {
        repondre(['erreur' => "Discord a refusé l'authentification (Client ID / secret corrects ?)."], 401);
    }
    repondre(['access_token' => $token]);
}

// 2) Une grille de 25 phrases tirées au hasard dans Firebase
if ($action === 'grille') {
    $url = rtrim($config['firebase_url']);
    if (!empty($config['firebase_token'])) {
        $url .= '?auth=' . urlencode($config['firebase_token']);
    }
    $json = @file_get_contents($url);
    if ($json === false) {
        repondre(['erreur' => "Impossible de lire Firebase : " . (error_get_last()['message'] ?? 'erreur inconnue')], 500);
    }
    $donnees = json_decode($json, true);
    $valeurs = is_array($donnees) ? $donnees : [];

    $phrases = [];
    foreach ($valeurs as $valeur) {
        if (is_array($valeur)) {
            $valeur = $valeur['texte'] ?? '';
        }
        if (is_string($valeur) && trim($valeur) !== '') {
            $phrases[] = trim($valeur);
        }
    }
    $phrases = array_unique($phrases);
    if (count($phrases) < 25) {
        repondre(['erreur' => "Il faut au moins 25 phrases différentes dans la base (il y en a " . count($phrases) . ")."], 422);
    }
    shuffle($phrases);
    repondre(['grille' => array_slice($phrases, 0, 25)]);
}

// 3) Avancement des joueurs : chacun envoie le sien et reçoit celui de tous.
//    Seuls des totaux sont stockés (cases cochées, lignes), jamais le détail.
if ($action === 'sync') {
    $e        = corpsJson();
    $instance = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($e['instance'] ?? ''));
    $id       = preg_replace('/[^0-9]/', '', (string)($e['id'] ?? ''));
    if ($instance === '' || $id === '') {
        repondre(['erreur' => 'Paramètres manquants.'], 400);
    }

    @mkdir(__DIR__ . '/data');
    $f = fopen(__DIR__ . '/data/' . $instance . '.json', 'c+');
    flock($f, LOCK_EX);
    $joueurs = json_decode(stream_get_contents($f), true) ?: [];

    $joueurs[$id] = [
        'nom'    => nomCourt((string)($e['nom'] ?? 'Joueur')),
        'coches' => max(0, min(25, (int)($e['coches'] ?? 0))),
        'lignes' => max(0, min(12, (int)($e['lignes'] ?? 0))),
        'vu'     => time(),
    ];
    // On oublie les joueurs qui n'ont plus donné signe de vie depuis 15 s
    $joueurs = array_filter($joueurs, fn($j) => $j['vu'] > time() - 15);

    ftruncate($f, 0);
    rewind($f);
    fwrite($f, json_encode($joueurs));
    flock($f, LOCK_UN);
    fclose($f);

    $liste = [];
    foreach ($joueurs as $jid => $j) {
        $liste[] = ['id' => (string)$jid, 'nom' => $j['nom'], 'coches' => $j['coches'], 'lignes' => $j['lignes']];
    }
    repondre(['joueurs' => $liste]);
}

repondre(['erreur' => 'Action inconnue.'], 404);