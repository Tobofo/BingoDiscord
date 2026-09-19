<?php
// ---------------------------------------------------------------
// API de l'activité : ?action=token | etat | nouvelle | proposer | voter
//
// Le serveur est l'arbitre : il tire les grilles, gère les votes et calcule
// lui-même les cases cochées. Les joueurs ne peuvent donc pas se les cocher.
// L'état de chaque partie est dans data/<instance>.json
// ---------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';

const DUREE_VOTE = 30;   // secondes pour voter
const INACTIF    = 20;   // secondes sans nouvelles => joueur retiré de la partie

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

// Refus « normal » d'une règle du jeu (message affiché au joueur)
function refuser(string $message): never
{
    throw new RuntimeException($message, 409);
}

// ---------- 1) Connexion : échange du "code" Discord contre un access_token ----------
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

// ---------- Outils du jeu ----------

// Lit la liste des phrases dans Firebase (nettoyée, sans doublons)
function chargerPhrases(array $config): array
{
    $url = $config['firebase_url'];   // adresse complète de la liste, terminée par .json
    if (!empty($config['firebase_token'])) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'auth=' . urlencode($config['firebase_token']);
    }
    $json = @file_get_contents($url);
    if ($json === false) {
        throw new RuntimeException("Impossible de lire Firebase : " . (error_get_last()['message'] ?? 'erreur inconnue'), 500);
    }
    $donnees = json_decode($json, true);
    $valeurs = is_array($donnees) ? $donnees : [];
    // Accepte aussi une liste rangée sous une clé « phrases »
    if (isset($valeurs['phrases']) && is_array($valeurs['phrases'])) {
        $valeurs = $valeurs['phrases'];
    }

    $phrases = [];
    foreach ($valeurs as $valeur) {
        if (is_array($valeur)) {
            $valeur = $valeur['texte'] ?? '';
        }
        if (is_string($valeur) && trim($valeur) !== '') {
            $phrases[] = trim($valeur);
        }
    }
    $phrases = array_values(array_unique($phrases));
    if (count($phrases) < 25) {
        throw new RuntimeException("Il faut au moins 25 phrases différentes dans la base (il y en a " . count($phrases) . ").", 500);
    }
    return $phrases;
}

// Ouvre le fichier de la partie (verrouillé), exécute $traitement, puis sauvegarde
function avecPartie(string $instance, callable $traitement): array
{
    @mkdir(__DIR__ . '/data');
    $h = fopen(__DIR__ . '/data/' . $instance . '.json', 'c+');
    flock($h, LOCK_EX);

    $partie = json_decode(stream_get_contents($h), true);
    if (!is_array($partie) || !isset($partie['joueurs'], $partie['valides'])) {
        $partie = ['phrases' => [], 'joueurs' => [], 'valides' => [], 'vote' => null, 'dernier' => null, 'numero' => 0];
    }

    try {
        return $traitement($partie);
    } finally {
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($partie, JSON_UNESCAPED_UNICODE));
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// Tire 25 phrases (leurs numéros) parmi celles qui ne sont pas déjà validées
function tirerGrille(array $p): array
{
    $dispo = array_values(array_diff(array_keys($p['phrases']), $p['valides']));
    if (count($dispo) < 25) {
        refuser("Il ne reste pas assez de phrases pour te faire une grille.");
    }
    shuffle($dispo);
    return array_slice($dispo, 0, 25);
}

// Retire les joueurs inactifs et conclut le vote s'il est terminé
function actualiser(array &$p): void
{
    $maintenant = time();

    foreach ($p['joueurs'] as $id => $j) {
        if ($j['vu'] < $maintenant - INACTIF) {
            unset($p['joueurs'][$id]);
        }
    }

    if (empty($p['vote'])) {
        return;
    }
    $v = $p['vote'];

    // Électeurs = joueurs présents au début du vote et toujours connectés
    $electeurs = array_values(array_filter($v['electeurs'], fn($x) => isset($p['joueurs'][$x])));
    $oui = 0;
    $non = 0;
    foreach ($electeurs as $x) {
        $choix = $v['votes'][$x] ?? null;
        if ($choix === true) {
            $oui++;
        } elseif ($choix === false) {
            $non++;
        }
    }

    $resultat = null;
    if (count($electeurs) > 0 && $oui === count($electeurs)) {
        $resultat = 'ok';                                   // unanimité
    } elseif ($non > 0) {
        $resultat = 'refuse';                               // un seul « non » suffit
    } elseif (count($electeurs) === 0 || $maintenant - $v['debut'] > DUREE_VOTE) {
        $resultat = 'expire';                               // temps écoulé
    }

    if ($resultat !== null) {
        if ($resultat === 'ok') {
            $p['valides'][] = $v['phrase'];
        }
        $p['numero']++;
        $p['dernier'] = ['numero' => $p['numero'], 'phrase' => $v['phrase'], 'resultat' => $resultat];
        $p['vote'] = null;
    }
}

// Crée le joueur au besoin, note qu'il est présent et lui donne une grille
function assurerJoueur(array &$p, string $id, string $nom, array $config): void
{
    if (empty($p['phrases'])) {
        $p['phrases'] = chargerPhrases($config);
    }
    if (!isset($p['joueurs'][$id])) {
        $p['joueurs'][$id] = ['nom' => $nom, 'grille' => null, 'vu' => time()];
    }
    $p['joueurs'][$id]['nom'] = $nom;
    $p['joueurs'][$id]['vu']  = time();
    if (empty($p['joueurs'][$id]['grille'])) {
        $p['joueurs'][$id]['grille'] = tirerGrille($p);
    }
}

// Cases cochées d'un joueur : "0"/"1" pour chacune de ses 25 cases
function masque(array $joueur, array $valides): string
{
    $s = '';
    foreach ($joueur['grille'] as $numero) {
        $s .= in_array($numero, $valides, true) ? '1' : '0';
    }
    return $s;
}

// Ce que le navigateur du joueur $id a besoin de savoir
function etatPour(array $p, string $id): array
{
    $joueurs = [];
    foreach ($p['joueurs'] as $jid => $j) {
        $joueurs[] = ['id' => (string)$jid, 'nom' => $j['nom'], 'masque' => masque($j, $p['valides'])];
    }

    $vote = null;
    if (!empty($p['vote'])) {
        $v = $p['vote'];
        $electeurs = array_values(array_filter($v['electeurs'], fn($x) => isset($p['joueurs'][$x])));
        $oui = 0;
        foreach ($electeurs as $x) {
            if (($v['votes'][$x] ?? null) === true) {
                $oui++;
            }
        }
        $vote = [
            'id'      => $v['id'],
            'phrase'  => $v['phrase'],
            'parNom'  => $p['joueurs'][$v['par']]['nom'] ?? '',
            'oui'     => $oui,
            'total'   => count($electeurs),
            'electeur' => in_array($id, $electeurs, true),
            'monVote' => $v['votes'][$id] ?? null,
            'restant' => max(0, DUREE_VOTE - (time() - $v['debut'])),
        ];
    }

    return [
        'phrases' => $p['phrases'],
        'grille'  => $p['joueurs'][$id]['grille'],
        'joueurs' => $joueurs,
        'valides' => $p['valides'],
        'vote'    => $vote,
        'dernier' => $p['dernier'],
    ];
}

// ---------- 2) Actions de jeu ----------
if (in_array($action, ['etat', 'nouvelle', 'proposer', 'voter'], true)) {
    $e        = corpsJson();
    $instance = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($e['instance'] ?? ''));
    $id       = preg_replace('/[^0-9]/', '', (string)($e['id'] ?? ''));
    if ($instance === '' || $id === '') {
        repondre(['erreur' => 'Paramètres manquants.'], 400);
    }
    $nom = nomCourt(trim((string)($e['nom'] ?? '')) ?: 'Joueur');

    try {
        $etat = avecPartie($instance, function (array &$p) use ($action, $e, $id, $nom, $config): array {
            actualiser($p);
            assurerJoueur($p, $id, $nom, $config);

            switch ($action) {
                case 'nouvelle':
                    if (!empty($p['valides'])) {
                        refuser("La partie a commencé : impossible de changer de grille.");
                    }
                    $p['joueurs'][$id]['grille'] = tirerGrille($p);
                    break;

                case 'proposer':
                    $phrase = (int)($e['phrase'] ?? -1);
                    if (!isset($p['phrases'][$phrase])) {
                        refuser("Phrase inconnue.");
                    }
                    if (in_array($phrase, $p['valides'], true)) {
                        refuser("Cette phrase est déjà validée.");
                    }
                    if (!empty($p['vote'])) {
                        refuser("Un vote est déjà en cours.");
                    }
                    $p['vote'] = [
                        'id'        => bin2hex(random_bytes(4)),
                        'phrase'    => $phrase,
                        'par'       => $id,
                        'debut'     => time(),
                        'electeurs' => array_map('strval', array_keys($p['joueurs'])),
                        'votes'     => [$id => true],   // celui qui propose vote « oui »
                    ];
                    actualiser($p);   // s'il est seul, la phrase est validée tout de suite
                    break;

                case 'voter':
                    $v = $p['vote'] ?? null;
                    if ($v && $v['id'] === (string)($e['vote'] ?? '') && in_array($id, $v['electeurs'], true)) {
                        $p['vote']['votes'][$id] = (bool)($e['choix'] ?? false);
                        actualiser($p);
                    }
                    break;
            }

            return etatPour($p, $id);
        });
    } catch (RuntimeException $ex) {
        repondre(['erreur' => $ex->getMessage()], $ex->getCode() ?: 500);
    }
    repondre($etat);
}

repondre(['erreur' => 'Action inconnue.'], 404);