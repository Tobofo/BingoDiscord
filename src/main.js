import { DiscordSDK } from "@discord/embedded-app-sdk";

const CLIENT_ID = document.querySelector('meta[name="client-id"]').content;
const $ = (id) => document.getElementById(id);

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

// Envoie les POSITIONS cochées (25 caractères 0/1, jamais les phrases)
// et reçoit celles des autres joueurs
async function synchroniser() {
  if (!moi) return;
  try {
    const { joueurs } = await api("sync", {
      instance: sdk.instanceId,
      id: moi.id,
      nom: moi.global_name || moi.username,
      masque: cochees.map((c) => (c ? "1" : "0")).join(""),
    });
    afficherJoueurs(joueurs);
  } catch (e) {
    // Pas grave : on réessaiera au prochain passage
  }
}

// Une mini-grille 5x5 par autre joueur : cases vertes = cases cochées
function afficherJoueurs(joueurs) {
  const zone = $("joueurs");
  zone.innerHTML = "";
  joueurs
    .filter((j) => j.id !== String(moi.id))
    .forEach((j) => {
      const carte = document.createElement("div");
      carte.className = "joueur";

      const mini = document.createElement("div");
      mini.className = "mini";
      for (let i = 0; i < 25; i++) {
        const c = document.createElement("span");
        if (j.masque[i] === "1") c.className = "on";
        mini.appendChild(c);
      }

      const nom = document.createElement("small");
      nom.textContent = j.nom;

      carte.append(mini, nom);
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
    setInterval(synchroniser, 3000);   // rafraîchit les grilles des autres
  } catch (e) {
    $("message").textContent = e.message;
  }
}

$("nouvelle").addEventListener("click", () => {
  $("message").textContent = "";
  nouvelleGrille().catch((e) => ($("message").textContent = e.message));
});

main();