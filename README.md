## 🇭🇹 **README pour le module Immigration**

# 🛂 eTicket - Module Immigration

Ce dépôt contient le **backend Laravel** du module **Immigration** du projet **eTicket-Haïti**.  
Il gère les formulaires d’entrée et de sortie, les ports d’arrivée, les décisions d’immigration et la gestion des tickets électroniques.

---

## 🏗️ Structure du module
```markdown
immigration/
│
├── app/ # Code source Laravel
├── bootstrap/
├── config/
├── database/
│ ├── factories/
│ ├── migrations/
│ ├── seeders/
│
├── routes/
│ └── api.php # Routes API pour l’immigration
│
├── tests/
│
├── Dockerfile # Image Docker du backend Immigration
├── .env # Variables d’environnement spécifiques
└── README.md
```

## ⚙️ Environnement Docker

Le module **Immigration** fonctionne comme un service Docker indépendant (`immigration-app`) géré par le `docker-compose.yml` global du projet.

### 🧩 Démarrage (depuis la racine du projet eTicket-Haïti)

```bash
docker-compose up -d --build immigration-app
```

📜 Arrêt du service Immigration
```bash
docker-compose stop immigration-app
```

⚙️ Exemple .env pour Immigration
Crée un fichier .env dans le dossier immigration/ :
```bash
APP_NAME=eTicket-Immigration
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8081

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=immigration-db
DB_PORT=3306
DB_DATABASE=immigration_db
DB_USERNAME=immigration_user
DB_PASSWORD=secret

QUEUE_CONNECTION=sync
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120
```

# URL du frontend
```bash
FRONTEND_URL=http://localhost:5173
```

🧰 Commandes utiles
```bash
# Installer Laravel (si le dossier est vide)
docker run --rm -v ${PWD}:/app -w /app composer:2 create-project laravel/laravel . "12.*"

# Installer les dépendances
docker exec -it immigration-app composer install

# Générer la clé d’application
docker exec -it immigration-app php artisan key:generate

# Exécuter les migrations
docker exec -it immigration-app php artisan migrate --seed

# Lancer les tests
docker exec -it immigration-app php artisan test
```

🔗 Communication avec le Frontend
Le frontend commun (Vue 3) communique avec l’API Immigration via :

```bash
http://localhost:8081/api
```

Définis cette variable dans le .env du frontend :

```env
VITE_API_IMMIGRATION=http://localhost:8081/api
```

📦 Endpoints principaux
```
Endpoint	            Méthode	       Description
/api/tickets	        GET	        Liste des tickets Immigration
/api/passenger-forms	POST	    Soumission d’un formulaire passager
/api/decisions	        POST	    Décision d’un officier d’immigration
/api/ports	            GET	        Liste des ports d’entrée/sortie
```
👤 Auteur
```
Ben-Nael Jean Baptiste
GitHub — LinkedIn
```
🧾 Licence
```
Distribué sous licence MIT.
```
