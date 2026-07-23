# Seguim (tv-tracker)

Personal TV series tracker backed by [TheTVDB](https://thetvdb.com/) v4 API — built after TV Time
shut down. Built on `freimguork-core` + `freimguork-appacman` (admin panel) +
`freimguork-webservice` (token auth). Production domain: https://seguim.cat/.

## What's here

- **`{lang}` (public site, `src/Web/`)** — placeholder public pages (`ca`/`es`), not the real
  product yet.
- **`wallaby` (`/wallaby`, Appacman)** — admin backoffice, out of the box from
  `freimguork-appacman`.
- **`api` (`/api`, `src/Api/`)** — the actual product backend, consumed by the (not yet built)
  Flutter client:
  - Auth (from `freimguork-webservice`, via the `vendorApps` config key — not duplicated here):
    `POST /api/register` (`email`, `password`, `username` — 3-20 chars, letters/numbers/`_`/`.`,
    unique), `POST /api/login`, `POST /api/logout`, `DELETE /api/account` (deletes the user and
    revokes every device token), `POST /api/password/forgot` (`email` — always the same response
    whether or not it's registered, emails a 6-digit code valid 15 min if it is),
    `POST /api/password/reset` (`email`, `code`, `password` — max 5 wrong attempts before the code
    is locked out; also revokes every device token, so a compromised account gets logged out
    everywhere on reset).
  - TV series tracking (this project's own code, `src/Api/{Controller,Model}/`):

    | Method | Path                        | Purpose                                              |
    |--------|-----------------------------|-------------------------------------------------------|
    | GET    | `/api/series/search`        | Search TheTVDB series (`?query=`, `?page=`, 0-based - response includes `hasMore`) |
    | GET    | `/api/series/{tvdbId}`      | Series detail + episode list (lazy-mirrors from TheTVDB) |
    | GET    | `/api/watchlist`            | The logged-in user's watchlist                        |
    | POST   | `/api/watchlist/{tvdbId}`   | Add a series to the watchlist                          |
    | DELETE | `/api/watchlist/{tvdbId}`   | Remove a series from the watchlist                     |
    | POST   | `/api/episode/{tvdbId}/watched` | Mark an episode watched                            |
    | DELETE | `/api/episode/{tvdbId}/watched` | Mark an episode unwatched                          |

    Every request needs an `Authorization` header: the app's own shared secret
    (`config/api/{dev,prod}/webservice.php`) on `register`/`login`, the user's own token
    (returned by `register`/`login`) on everything else.

    Series/episode metadata is **lazily mirrored**: TheTVDB is only called (and the result cached
    into the local `series`/`episode` tables, refreshed after 24h) the first time a user actually
    searches for or opens a given series — there's no full-catalog background sync. Scoped to TV
    series only for now; no movies, ratings, or "mark whole season watched" yet.

## Setup

1. `composer install` (also publishes Appacman/AdminLTE assets into `web/` via the
   `AssetPublisher` script).
2. Copy each `config/**/*.php.dist` to its non-`.dist` counterpart and fill in real values (never
   commit these — already gitignored):
   - `config/dev/db.php`, `config/prod/db.php`
   - `config/dev/keys.php`, `config/prod/keys.php` (generate with
     `php -r "echo bin2hex(random_bytes(32));"`, a **different** secret per environment)
   - `config/api/dev/webservice.php`, `config/api/prod/webservice.php` (the Flutter app's own
     shared secret, same generation command as above)
   - `config/api/dev/thetvdb.php`, `config/api/prod/thetvdb.php` — a real TheTVDB v4 `apikey`
     (get one at https://thetvdb.com/dashboard/account/apikey; `pin` is only needed for
     subscriber-model keys)
   - `config/mail.php` — real SMTP credentials, needed for `POST /api/password/forgot` to actually
     deliver reset codes (in dev, if this isn't set up yet, the code is logged via `error_log()`
     instead of failing the request - see `Webservice\Controller\ForgotPassword`)
3. Create the database and import `db.sql` (Appacman's minimal schema + this project's own
   `user`/`user_token`/`series`/`episode`/`user_watchlist`/`user_episode_watched` tables — no admin
   user seeded, see below).
4. Set up the local vhost pointing `DocumentRoot` to `web/`.

## First admin user

`appacman_user.name`/`email` are encrypted (TwoWay) and `password` is hashed (OneWay) under
**this** project's secret (`config/dev/keys.php`), so a generic admin can't be seeded in `db.sql` -
it has to be generated with the project's real key. With `config/dev/keys.php` already filled in:

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

(profile `2` = SuperAdmin, already with full permissions over `appacman_user` in the `db.sql`
seed). Adjust `<id>` in `$context` to the real `id_appacman_user` that `AUTO_INCREMENT` will
assign (usually `1` for the first user).

## Structure

- `config/` - per-environment credentials (`dev/`/`prod/`, gitignored + `.dist` committed) and
  `projects.php` (sub-project map: `wallaby` = Appacman, `api` = the tracking backend, `{lang}` =
  public site)
- `web/` - served document root (front controllers, `.htaccess`, `static/`, `upload/`)
- `src/Web/` - public site controllers/views (placeholder, not the real product)
- `src/Api/` - the tracking backend: `Controller/{Series,Watchlist,Episode}/` (routes),
  `Model/{Series,Episode,Watchlist,WatchedEpisode}.php` (local mirror + user state),
  `Model/TheTvdb/Client.php` (TheTVDB v4 HTTP client - does its own plain `curl_init()` call
  rather than `Core\Model\Utils\Curl`, which forces every request through a bogus local proxy in
  dev mode and breaks real third-party calls)
- `src/cache/` - compiled route cache in prod + the TheTVDB bearer-token cache
  (`src/cache/{dev,prod}/thetvdb/token.json`), both gitignored, created automatically at runtime
- `locale/en_GB/LC_MESSAGES/` - minimal `.po` header only, no project-specific msgids yet
- `db.sql` - Appacman's minimal schema + this project's own tables, no admin user (see above)

## Not included yet

- The Flutter client itself (hasn't been started)
- Movies, ratings, "mark whole season watched", full-catalog `/updates`-based sync
- Rate-limiting on login/register (password reset/forgot-password is done, see above)
- Composer plugin conversion (`AssetPublisher` is still a script wired by hand in `composer.json`)
- Automated tests / CI
- `Cronjob`/`Import` sub-projects (add them to `config/projects.php` if ever needed, following the
  same pattern as `wallaby`/`{lang}`)
