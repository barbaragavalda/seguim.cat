# Seguim (tv-tracker)

Personal TV series & movie tracker backed by [TheTVDB](https://thetvdb.com/) v4 API — built after
TV Time shut down. Built on `freimguork-core` + `freimguork-appacman` (admin panel) +
`freimguork-webservice` (token auth). Consumed by the **Seguim!** Flutter app (separate repo).
Production domain: https://seguim.cat/.

## What's here

- **`{lang}`** (public site, `src/Web/`) — marketing landing page + privacy policy, `ca`/`es`/`en`.
- **`wallaby`** (`/wallaby`, Appacman) — admin backoffice, out of the box from `freimguork-appacman`.
- **`api`** (`/api`, `src/Api/`) — the real backend (see [API.md](API.md) for every endpoint):
  - Auth: register/login/logout (own token + Google Sign-In), password reset, email change,
    account deletion.
  - Profile editing: username, password, email, language.
  - Series & movie tracking: search, detail, watchlist (watching/not-started/archived/removed/
    finished), watched/rewatch.
  - Favorites and user-created, reorderable lists (series + movies).
  - TV Time importer (`src/Api/Model/TvTimeImport/`) — recovers a watchlist/watch history from
    TV Time's GDPR data export.
  - TheTVDB search results are disk-cached for 10 minutes (`Api\Controller\Controller::cached()`).

See each controller's own docblock for the actual business rules — this file only orients.

## Setup

1. `composer install` (also publishes Appacman/AdminLTE assets into `web/`).
2. Copy each `config/**/*.php.dist` to its non-`.dist` counterpart and fill in real values (never
   commit these — already gitignored):
   - `config/dev/db.php`, `config/prod/db.php`
   - `config/dev/keys.php`, `config/prod/keys.php` — generate with
     `php -r "echo bin2hex(random_bytes(32));"`, a **different** secret per environment
   - `config/api/dev/webservice.php`, `config/api/prod/webservice.php` — the Flutter app's own
     shared secret, same generation command
   - `config/api/dev/thetvdb.php`, `config/api/prod/thetvdb.php` — a real TheTVDB v4 `apikey`
     ([get one here](https://thetvdb.com/dashboard/account/apikey))
   - `config/mail.php` — real SMTP credentials (in dev, if unset, reset codes are logged via
     `error_log()` instead of emailed)
3. Create the database and import `db.sql`. **Careful re-importing this later**: `serie`/
   `movie`/their `_lang`/`episode` tables hold the TheTVDB mirror cache — apply schema changes with
   `ALTER TABLE` against the live database instead of re-running the whole file once real data
   exists.
4. Set up the local vhost pointing `DocumentRoot` to `web/`.

## First admin user

`appacman_user.name`/`email`/`password` are encrypted/hashed under **this** project's own secret,
so a generic admin can't be seeded in `db.sql`. With `config/dev/keys.php` filled in:

```php
<?php
require 'vendor/autoload.php';
const DIR_ROOT = './';
$_ENV['IS_DEV'] = true;

$name    = 'Admin';
$email   = 'admin@example.com';
$plain   = '<real-password>';
$created = date('Y-m-d H:i:s');
$context = fn($field) => "1_{$created}_{$field}"; // <id>_<created>_<field>

$db = new PDO('mysql:host=mariadb;dbname=tv-tracker', '<db-user>', '<db-password>');
$db->prepare('
    INSERT INTO appacman_user (name, email, password, id_appacman_user_profile, created)
    VALUES (:name, :email, :password, 2, :created)
')->execute([
    'name'     => Core\Model\Encryptor\TwoWay::encrypt($name, $context('name')),
    'email'    => Core\Model\Encryptor\TwoWay::encrypt($email, $context('email')),
    'password' => Core\Model\Encryptor\OneWay::encrypt($plain, $context('password')),
    'created'  => $created,
]);
```

(profile `2` = SuperAdmin.) Adjust `<id>` in `$context` to the real `id_appacman_user` that
`AUTO_INCREMENT` will assign (usually `1` for the first user).

## Structure

- `config/` — per-environment credentials (gitignored + `.dist` committed), `projects.php`
  (sub-project map), `google.php` (Google Sign-In client ids, not secret)
- `web/` — served document root
- `src/Web/` — public site (controllers/models/views)
- `src/Api/Controller/` — `Account/`, `Login/`, `Series/`, `Movie/`, `Watchlist/`,
  `MovieWatchlist/`, `Episode/`, `Favorites/`, `Lists/`, `Search/`, `Import/`
- `src/Api/Model/` — local mirror + user state per entity (series/movie, watchlist, favorites,
  lists), `TvTimeImport/`, `TheTvdb/` (HTTP client), `GoogleAuth/`
- `src/cache/` — route cache, TheTVDB token/search cache, in-progress import uploads — gitignored,
  created at runtime
- `db.sql` — Appacman's minimal schema + this project's own tables, no admin user (see above)
- `tests/` — PHPUnit; `phpstan.neon` — PHPStan (level 5)
