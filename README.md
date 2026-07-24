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
    unique), `POST /api/login`, `POST /api/logout`, `DELETE /api/account` (deletes the user,
    revokes every device token, and - via this project's own `Api\Controller\Account\Delete`,
    which overrides `Webservice\Controller\DeleteAccount` on the same path since a project's own
    routes win over a vendor package's, see `Core\Bootstrap::loadRoutes()` - also removes the
    user's own `user_watchlist`/`user_episode_watched` rows, which the vendor package has no idea
    exist), `POST /api/password/forgot` (`email` - always the same response
    whether or not it's registered, emails a 6-digit code valid 15 min if it is),
    `POST /api/password/reset` (`email`, `code`, `password` — max 5 wrong attempts before the code
    is locked out; also revokes every device token, so a compromised account gets logged out
    everywhere on reset). The reset code email is sent in **the language the user registered
    with** (`user.id_appacman_lang`, captured once at `register` time), not whatever
    `Accept-Language` the `password/forgot` request itself happens to carry — a password-reset
    request is often made from an unfamiliar device, so the current request's own language can't
    be trusted to match the account's.
  - TV series tracking (this project's own code, `src/Api/{Controller,Model}/`):

    | Method | Path                        | Purpose                                              |
    |--------|-----------------------------|-------------------------------------------------------|
    | GET    | `/api/series/search`        | Search TheTVDB series (`?query=`, `?page=`, 0-based - response includes `hasMore`) |
    | GET    | `/api/series/{tvdbId}`      | Series detail + episode list (lazy-mirrors from TheTVDB) |
    | GET    | `/api/watchlist/watching`   | Series with ≥1 watched episode and something left to watch, most-recently-watched first (no pagination - see below) |
    | GET    | `/api/watchlist/not-started` | Series with 0 watched episodes, most-recently-added first (`?page=`, 0-based - response includes `hasMore`) |
    | POST   | `/api/watchlist/{tvdbId}`   | Add a series to the watchlist                          |
    | DELETE | `/api/watchlist/{tvdbId}`   | Remove a series from the watchlist                     |
    | POST   | `/api/episode/{tvdbId}/watched` | Mark an episode watched                            |
    | DELETE | `/api/episode/{tvdbId}/watched` | Mark an episode unwatched                          |
    | POST   | `/api/import/tvtime`        | Upload a TV Time GDPR data export (`multipart/form-data`, field `file`), queues an import job |
    | GET    | `/api/import/tvtime/{id}`   | Poll an import job's status/summary                    |
    | POST   | `/api/import/tvtime/process` | Works through one batch of the oldest queued import job - see below |

    Every request needs an `Authorization` header: the app's own shared secret
    (`config/api/{dev,prod}/webservice.php`) on `register`/`login`/`import/tvtime/process`, the
    user's own token (returned by `register`/`login`) on everything else.

    Series/episode metadata is **lazily mirrored**: TheTVDB is only called (and the result cached
    into the local `serie`/`episode` tables, refreshed after 24h) the first time a user actually
    searches for or opens a given series — there's no full-catalog background sync. Scoped to TV
    series only for now; no movies, ratings, or "mark whole season watched" yet. `serie`'s own
    `name`/`overview` are translated per language into `serie_lang` (ca/es/en, via TheTVDB's
    `GET /series/{id}/translations/{language}`) rather than duplicated on `serie` itself, and
    `episode`'s own `name`/`overview` likewise live in `episode_lang` (via
    `GET /series/{id}/episodes/{season-type}/{lang}`, which conveniently translates every episode
    of a series in one call) - the `api` sub-project's `Config::getLanguage()` (`Accept-Language`-
    resolved, see below) picks which language a given response actually returns, falling back to
    TheTVDB's own `default_name`/`default_overview` when that language has no translation.

    Both watchlist endpoints return, per series: the translated `name`/`overview` (same fallback as
    above), `image` (the series' background/fanart - the poster is dropped here, unlike
    `series/{tvdbId}` which returns both), `next_episode` (the next unwatched episode as
    `"T{season} - E{episode}"`) and `next_episode_name` (kept as its own field rather than
    concatenated into `next_episode`, so a client can still parse the season/episode numbers), and
    `remaining_episodes` (how many aired-but-unwatched episodes are left). Season 0 (specials) is
    always excluded from the last three - TheTVDB has no reliable field to tell a plot-relevant
    special apart from a clip-show/recap one (checked empirically against Lost and Euphoria (US):
    `airsBeforeSeason`/`airsBeforeEpisode`/`finaleType` are set inconsistently on both kinds in both
    shows), so there's no sound way to count only the specials that matter. Unaired episodes are
    excluded too - nothing to watch yet. `remaining_episodes` is computed fresh on every call, not
    stored, which is also why a series that's fully caught up (`remaining_episodes` reaches 0)
    simply drops out of `watching` rather than needing any explicit "mark as finished" step - it
    reappears there on its own once a new episode airs.

    `not-started` additionally returns `premiere_in_days`: a series with zero watched episodes can
    have `remaining_episodes = 0` for two very different reasons - already fully watched (which
    can't actually happen here, since `not-started` is zero-watched by definition) or *hasn't aired
    at all yet*. `premiere_in_days` (days until the earliest still-upcoming aired date, `null`
    otherwise) exists so a client can tell "coming soon" apart from "you're all caught up" instead
    of both looking identical (`next_episode: null`). Archived/removed shows (see the TV Time
    importer below) never appear in either watchlist endpoint - the rows stay in the database,
    just hidden from both lists.

  - **TV Time importer** (`src/Api/Model/TvTimeImport/`, `src/Api/Controller/Import/`): lets a user
    upload the GDPR data export TV Time offered before shutting down, recovering their watchlist
    and watch history into this app. TV Time's own `tv_show_id`/`episode_id` turned out to be
    TheTVDB's own ids directly (confirmed empirically), so shows/episodes are matched and synced
    through the same `Series`/`Episode` models the rest of the app uses - no fuzzy name matching.
    No single file in the export lists every watched episode; `TvTimeImport\Parser` takes the union
    of several tracking/seen-episode CSVs (validated against the export's own per-show watched
    count) to recover 97%+ of a real watch history - what's left is old history TV Time itself
    never logged per-episode, with no reliable way to guess which episodes those were. The parser
    also preserves each source row's own `created_at` (earliest wins when an episode appears in more
    than one file) rather than stamping everything with the import time, so the watchlist's own
    date-ordered views stay meaningful for imported data too.

    Syncing a full history (frequently 900+ shows) reliably outlasts Apache's own 60s reverse-proxy
    timeout - confirmed against a real export - so `POST /import/tvtime` only stores the upload and
    queues a job; `POST /import/tvtime/process` (meant to be pinged repeatedly by a system cron -
    this framework has no queue/worker infra, same pattern as `freimguork-core`'s `Cronjob`
    sub-project convention) works through one ~45s batch of the oldest queued/in-progress job per
    call, persisting which shows are already done (`tvtime_import.processed_show_ids`) so the next
    call resumes rather than restarting. `GET /import/tvtime/{id}` polls a job's `status`
    (`pending`/`processing`/`done`/`failed`) and `summary` (shows synced/failed, episodes watched).

    A show still followed in TV Time but archived there sets `user_watchlist.archived`; one with
    watch history but no longer followed at all (unfollowed/deleted) sets `.removed` instead - both
    are still imported, just hidden from both watchlist endpoints (see above) rather than dropped,
    in case the app wants to surface them differently later.

  - **Language resolution**: the `api` sub-project isn't `{lang}`-prefixed like the public site, so
    its language comes from `Accept-Language` (falling back to session, then the project's default
    `ca` — see `Core\Utils\Language::initLanguage()`). This resolves *per request*, which is right
    for browsing endpoints but wrong for anything sent later out-of-band (a password-reset request
    is often made from a different device than the one that registered) — see `password/forgot`
    above for how that case uses the *stored* `user.id_appacman_lang` instead
    (`Core\Utils\Language::withCulture()`).

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
   `user`/`user_token`/`password_reset`/`serie`/`serie_lang`/`episode`/`episode_lang`/
   `user_watchlist`/`user_episode_watched`/`tvtime_import` tables — no admin user seeded, see
   below). **Careful re-importing this later**: `serie`/`serie_lang`/`episode`/`episode_lang` hold
   the TheTVDB mirror cache, which can mean hundreds of shows re-fetched from scratch if wiped -
   apply schema changes to those four tables with `ALTER TABLE` against the live database instead
   of blindly re-running the whole file once real data exists.
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
- `src/Api/` - the tracking backend: `Controller/{Series,Watchlist,Episode,Account,Import}/`
  (routes - `Account/Delete.php` overrides `freimguork-webservice`'s own `DELETE /account`, see
  above; `Import/{TvTime,TvTimeStatus,TvTimeProcess}.php` are the TV Time importer's upload/poll/
  cron-tick endpoints, see above), `Model/{Series,SerieLang,Episode,EpisodeLang,Watchlist,
  WatchedEpisode,TvTimeImport}.php` (local mirror + user state + import job tracking),
  `Model/TvTimeImport/{Parser,Processor}.php` (export parsing + applying a batch to a real
  account), `Model/TheTvdb/{Client,Languages}.php` (TheTVDB v4 HTTP client - does its own plain
  `curl_init()` call rather than `Core\Model\Utils\Curl`, which forces every request through a
  bogus local proxy in dev mode and breaks real third-party calls; `Languages` maps this project's
  own `id_appacman_lang` to TheTVDB's 3-letter language codes, shared by `SerieLang`/`EpisodeLang`)
- `src/cache/` - compiled route cache in prod, the TheTVDB bearer-token cache
  (`src/cache/{dev,prod}/thetvdb/token.json`), and uploaded TV Time exports/their extracted CSVs
  while a job is in progress (`src/cache/{dev,prod}/imports/`) - all gitignored, created
  automatically at runtime
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
