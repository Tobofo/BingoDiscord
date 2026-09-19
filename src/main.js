import { DiscordSDK } from "@discord/embedded-app-sdk";

const CLIENT_ID = document.querySelector('meta[name="client-id"]').content;
const $ = (id) => document.getElementById(id);

// Les 12 lignes gagnantes possibles : 5 lignes, 5 colonnes, 2 diagonales
const LIGNES = [];
for (let i = 0; i < 5; i++) {
  LIGNES.push([0, 1, 2, 3, 4].map((j) => i * 5 + j));
  LIGNES.push([0, 1, 2, 3, 4].map((j) => j * 5 + i));
}
LIGNES.push([0, 6, 12, 18, 24], [4, 8, 12, 16, 20]);

let sdk = null;
let moi = null;                       // l'utilisateur Discord
let cochees = new Array(25).fill(false);

// Appel de notre API PHP (GET par défaut, POST si on passe un corps)
async function api(action, corps) {
  const options = corps
    ? { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(corps) }
    : undefined;
  const reponse = await fetch(`/api.php?action=${action}`, options);
  const donnees = await reponse.json();
  if (!reponse.ok) throw new Error(donnees.erreur || "Erreur serveur");
  return donnees;
}

function afficherGrille(phrases) {
  const grille = $("grille");
  grille.innerHTML = "";
  cochees = new Array(25).fill(false);
  phrases.forEach((phrase, i) => {
    const div = document.createElement("div");
    div.className = "case";
    div.textContent = phrase;
    div.addEventListener("click", () => {
      cochees[i] = !cochees[i];
      div.classList.toggle("cochee", cochees[i]);
      synchroniser();
    });
    grille.appendChild(div);
  });
  synchroniser();
}

// Envoie mon avancement (totaux seulement) et reçoit celui des autres
async function synchroniser() {
  if (!moi) return;
  const coches = cochees.filter(Boolean).length;
  const lignes = LIGNES.filter((l) => l.every((i) => cochees[i])).length;
  try {
    const { joueurs } = await api("sync", {
      instance: sdk.instanceId,
      id: moi.id,
      nom: moi.global_name || moi.username,
      coches,
      lignes,
    });
    afficherJoueurs(joueurs);
  } catch (e) {
    // Pas grave : on réessaiera au prochain passage
  }
}

function afficherJoueurs(joueurs) {
  const zone = $("joueurs");
  zone.innerHTML = "";
  joueurs
    .filter((j) => j.id !== String(moi.id))
    .forEach((j) => {
      const carte = document.createElement("div");
      carte.className = "joueur";
      const nom = document.createElement("strong");
      nom.textContent = j.nom;
      const barre = document.createElement("progress");
      barre.max = 25;
      barre.value = j.coches;
      const info = document.createElement("small");
      info.textContent = `${j.coches}/25` + (j.lignes ? ` · ${j.lignes} ligne${j.lignes > 1 ? "s" : ""}` : "");
      carte.append(nom, barre, info);
      zone.appendChild(carte);
    });
  if (!zone.children.length) zone.textContent = "En attente d'autres joueurs…";
}

async function nouvelleGrille() {
  const { grille } = await api("grille");
  afficherGrille(grille);
}

async function main() {
  try {
    sdk = new DiscordSDK(CLIENT_ID);
    await sdk.ready();

    // Connexion du joueur : Discord donne un "code", PHP l'échange contre un token
    const { code } = await sdk.commands.authorize({
      client_id: CLIENT_ID,
      response_type: "code",
      state: "",
      prompt: "none",
      scope: ["identify"],
    });
    const { access_token } = await api("token", { code });
    const auth = await sdk.commands.authenticate({ access_token });
    moi = auth.user;

    await nouvelleGrille();
    setInterval(synchroniser, 500);   // rafraîchit l'avancement des autres
  } catch (e) {
    $("message").textContent = e.message;
  }
}

$("nouvelle").addEventListener("click", () => {
  $("message").textContent = "";
  nouvelleGrille().catch((e) => ($("message").textContent = e.message));
});

main();
