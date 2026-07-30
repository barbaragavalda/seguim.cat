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
    `POST /api/register` (`email`, `password` — min. 8 chars, no composition rules, see
    `Webservice\Model\User::PASSWORD_MIN_LENGTH`, `username` — 3-20 chars, letters/numbers/`_`/`.`,
    unique), `POST /api/login`, `POST /api/logout`, `DELETE /api/account` (deletes the user,
    revokes every device token, and - via this project's own `Api\Controller\Account\Delete`,
    which overrides `Webservice\Controller\DeleteAccount` on the same path since a project's own
    routes win over a vendor package's, see `Core\Bootstrap::loadRoutes()` - also removes the
    user's own `user_serie_watchlist`/`user_episode_watched` rows, which the vendor package has no idea
    exist), `POST /api/password/forgot` (`email` - always the same response
    whether or not it's registered, emails a 6-digit code valid 15 min if it is),
    `POST /api/password/reset` (`email`, `code`, `password` — max 5 wrong attempts before the code
    is locked out; also revokes every device token, so a compromised account gets logged out
    everywhere on reset). The reset code email is sent in **the language the user registered
    with** (`user.id_appacman_lang`, captured once at `register` time), not whatever
    `Accept-Language` the `password/forgot` request itself happens to carry — a password-reset
    request is often made from an unfamiliar device, so the current request's own language can't
    be trusted to match the account's.
  - **Profile editing** (this project's own code, `src/Api/Controller/Account/`, all requiring the
    user's own token):

    | Method | Path                          | Purpose                                              |
    |--------|-------------------------------|--------------------------------------------------------|
    | GET    | `/api/account`                | Current `username`/`email`/`language`                 |
    | POST   | `/api/account/username`       | Rename (`username` - same pattern/uniqueness rule as register) |
    | POST   | `/api/account/password`       | Change password (`current_password`, `new_password`)  |
    | POST   | `/api/account/email`          | Request an email change (`email`) - see below         |
    | POST   | `/api/account/email/confirm`  | Confirm it (`code`)                                    |
    | POST   | `/api/account/language`       | Change the stored language (`language`, a culture code) |

    `POST /account/password` revokes every other device's token afterwards (same reasoning as
    `password/reset`: a changed password can mean the old one was known to someone it shouldn't
    have been) but re-issues one for *this* session (returned as `token`), so the device making the
    change isn't logged out for a change it just asked for itself.

    `POST /account/email` only *requests* the change - it doesn't take effect until confirmed.
    `Webservice\Model\EmailChange` (same shape as `PasswordReset`: 6-digit code, 15 min TTL, 5 max
    attempts) stages the new address and emails a code there; `/account/email/confirm` only applies
    it once that code is redeemed. Without this, a stolen or shared-device session token could
    permanently hijack an account by pointing it at an attacker-controlled address with no proof it
    was ever actually reachable. A heads-up notice also goes to the *current* email at request time,
    in case the request itself wasn't legitimate. Uniqueness is checked both at request time and
    again at confirm time (another account could take the exact same address during the 15-minute
    window in between).

  - TV series tracking (this project's own code, `src/Api/{Controller,Model}/`):

    | Method | Path                        | Purpose                                              |
    |--------|-----------------------------|-------------------------------------------------------|
    | GET    | `/api/series/search`        | Search TheTVDB series (`?query=`, `?page=`, 0-based - response includes `hasMore`) |
    | GET    | `/api/series/{tvdbId}`      | Series detail + episode list (lazy-mirrors from TheTVDB), plus `in_watchlist`/`archived`/`removed` for the current user (all `false` if logged out or never added) |
    | GET    | `/api/watchlist/watching`   | Series with ≥1 watched episode and something left to watch, most-recently-watched first (no pagination - see below) |
    | GET    | `/api/watchlist/not-started` | Series with 0 watched episodes, most-recently-added first (`?page=`, 0-based - response includes `hasMore`) |
    | GET    | `/api/watchlist`            | All series, filtered by `?status=` (`all`/`removed`/`archived`/`watching`/`not_started`/`finished`) and optional `?search=` (title), paginated (`?page=`) - see below |
    | POST   | `/api/watchlist/{tvdbId}`   | Add a series to the watchlist                          |
    | DELETE | `/api/watchlist/{tvdbId}`   | Remove a series from the watchlist (hard delete - see below) |
    | POST   | `/api/watchlist/{tvdbId}/archived` | Archive a series (hidden from both lists, not deleted) |
    | DELETE | `/api/watchlist/{tvdbId}/archived` | Unarchive it                                   |
    | POST   | `/api/watchlist/{tvdbId}/removed` | Mark a series removed (hidden from both lists, not deleted) |
    | DELETE | `/api/watchlist/{tvdbId}/removed` | Restore it                                      |
    | POST   | `/api/episode/{tvdbId}/watched` | Mark an episode watched - a no-op if already watched (see below) |
    | DELETE | `/api/episode/{tvdbId}/watched` | Mark an episode unwatched (a full reset - see below)   |
    | POST   | `/api/episode/{tvdbId}/rewatch` | Record another watch, even if already watched          |
    | DELETE | `/api/episode/{tvdbId}/rewatch` | Collapse back down to a single watch (undo any rewatches, not a full reset - see below) |
    | POST   | `/api/import/tvtime`        | Upload a TV Time GDPR data export (`multipart/form-data`, field `file`), queues an import job |
    | GET    | `/api/import/tvtime/{id}`   | Poll an import job's status/summary                    |
    | POST   | `/api/import/tvtime/process` | Works through one batch of the oldest queued import job - see below |

    Every request needs an `Authorization` header: the app's own shared secret
    (`config/api/{dev,prod}/webservice.php`) on `register`/`login`/`import/tvtime/process`, the
    user's own token (returned by `register`/`login`) on everything else.

    `user_episode_watched` holds one row per *watch event*, not one per episode - rewatching adds
    another row rather than updating an existing one, so both the total count and each event's own
    date survive. `watched`/`watch_count` on `series/{tvdbId}`'s episode list reflect this
    (`watch_count` is just `0` for never-watched). `/watched` (`POST`/`DELETE`) is unchanged from a
    caller's perspective - `POST` stays a no-op once already watched, `DELETE` still fully resets an
    episode back to never-watched. `/rewatch` is the pair that manages watch events beyond the
    first: `POST` always adds a new one regardless of what's already there, `DELETE` collapses
    every event for that episode back down to just the earliest one - "undo my rewatches, but keep
    it watched once" - distinct from `DELETE /watched`'s full reset to zero.

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
    stored - but it's purely informational now, not what drives `watching`/`finished` (see below): a
    series can have `remaining_episodes > 0` (earlier gaps still unwatched) and still be `finished`,
    since watching the finale is what "finished" means here, not a full gap-free watch-through
    (confirmed with the user after they noticed a show with unwatched early episodes still counted
    as in-progress). A series drops out of `watching` the moment its last aired regular episode is
    watched, and reappears there on its own if a new episode later airs.

    `not-started` additionally returns `premiere_in_days`: a series with zero watched episodes can
    have `remaining_episodes = 0` for two very different reasons - already fully watched (which
    can't actually happen here, since `not-started` is zero-watched by definition) or *hasn't aired
    at all yet*. `premiere_in_days` (days until the earliest still-upcoming aired date, `null`
    otherwise) exists so a client can tell "coming soon" apart from "you're all caught up" instead
    of both looking identical (`next_episode: null`). Archived/removed shows (`user_serie_watchlist`'s
    `archived`/`removed` flags - set automatically by the TV Time importer below, or toggled by hand
    via the `archived`/`removed` endpoints above) never appear in either watchlist endpoint - the
    rows stay in the database (watched-episode history included), just hidden from both lists. This
    is deliberately different from `DELETE /watchlist/{tvdbId}`, which actually deletes the
    `user_serie_watchlist` row.

    `GET /watchlist` is a separate, unified "browse everything" view (for a profile-style screen),
    distinct from the two opinionated home-screen lists above - every one of its 6 `?status=`
    values is paginated (unlike `watching`), since `archived`/`removed`/`finished` alone can
    accumulate hundreds of rows after a TV Time import. 5 of the 6 statuses partition every
    possible combination of `archived`/`removed`/watched-vs-last-episode-watched exactly once,
    computed at the SQL level (not filtered in PHP afterwards) so pagination stays correct per
    status; `removed` wins over `archived` when a series is somehow flagged as both. `watching` vs
    `finished` splits on whether the series' last aired regular episode has been watched, not on
    whether every aired episode has - a show can be `finished` with earlier gaps still unwatched
    (see `watching`'s own docblock in `Watchlist::listByStatus()`). The 6th, `all`, is unfiltered -
    every series in the watchlist regardless of those flags. `?search=` further filters by title (a
    plain `LIKE`, no full-text index - this app's personal-tracker scale doesn't need one).

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

    `rewatched_episode.csv` is parsed separately into its own `rewatches` structure rather than
    folded into the union above - confirmed empirically it's a *count* of extra watches beyond the
    first (`cpt`, always 1 or 2, never more than one row per episode), not a discrete per-event
    log, so the importer records `cpt` additional `WatchedEpisode::markRewatched()` events (all
    stamped with the same single date the export provides - individual rewatch dates aren't
    recoverable) on top of whatever base watch the union above already found, applied even when
    that base watch wasn't found at all (TV Time's own count still means something either way).

    Syncing a full history (frequently 900+ shows) reliably outlasts Apache's own 60s reverse-proxy
    timeout - confirmed against a real export - so `POST /import/tvtime` only stores the upload and
    queues a job; `POST /import/tvtime/process` (meant to be pinged repeatedly by a system cron -
    this framework has no queue/worker infra, same pattern as `freimguork-core`'s `Cronjob`
    sub-project convention) works through one ~45s batch of the oldest queued/in-progress job per
    call, persisting which shows are already done (`tvtime_import.processed_show_ids`) so the next
    call resumes rather than restarting. `GET /import/tvtime/{id}` polls a job's `status`
    (`pending`/`processing`/`done`/`failed`) and `summary` (shows synced/failed, episodes watched/
    rewatched).

    A show still followed in TV Time but archived there sets `user_serie_watchlist.archived`; one with
    watch history but no longer followed at all (unfollowed/deleted) sets `.removed` instead - both
    are still imported, just hidden from both watchlist endpoints (see above) rather than dropped,
    in case the app wants to surface them differently later.

    The same import also recreates the account's own custom lists (see below) from the export's
    `lists-prod-lists.csv`, once every show above has finished syncing (lists are the far smaller,
    less TheTVDB-call-heavy piece, and most of their series are already synced by the time shows
    finish anyway). Movie-only list entries are skipped (TV Time list rows can be `type:series`,
    which has a usable TheTVDB id, or `type:movie`, which only has an opaque TV Time-internal uuid
    - out of scope, same as the rest of this app). Unnamed lists (TV Time auto-generates several
    per account with no user-chosen name - the majority in a real export) get a placeholder name,
    `"List from {creation date}"`.

  - **Custom lists** (`src/Api/Model/UserList.php`, `src/Api/Model/UserListSerie.php`,
    `src/Api/Controller/Lists/`): user-created, freely-orderable collections of series, separate
    from the watchlist.

    | Method | Path                                    | Purpose                                  |
    |--------|------------------------------------------|-------------------------------------------|
    | GET    | `/api/lists`                              | The user's lists, in order (`?page=`, 0-based - `hasMore`) |
    | POST   | `/api/lists`                              | Create a list (`name`)                    |
    | POST   | `/api/lists/{id}`                         | Rename it (`name`) - can be changed any time |
    | DELETE | `/api/lists/{id}`                         | Delete it (and its series)                |
    | POST   | `/api/lists/{id}/reorder`                 | Move it - see below                       |
    | GET    | `/api/lists/{id}`                         | The list's series, in order (`?page=`, 0-based - `hasMore`) |
    | POST   | `/api/lists/{id}/series/{tvdbId}`         | Add a series (lazy-mirrors it first, like watchlist add) |
    | DELETE | `/api/lists/{id}/series/{tvdbId}`         | Remove a series                           |
    | POST   | `/api/lists/{id}/series/{tvdbId}/reorder` | Move it within the list - see below       |
    | GET    | `/api/lists/membership/{tvdbId}`          | Every one of the user's lists, each flagged `in_list` - backs the client's multi-list "add to a list" picker |

    Every list-scoped route checks `UserList::belongsToUser()` first and returns a plain `404` if
    the list isn't the caller's - prevents cross-account tampering via a guessed id, same pattern
    as everywhere else per-user resources are addressed by their own numeric id in this app.
    Deleting a list or removing a series from it is a no-op if it wasn't there to begin with
    (matches `Watchlist::remove()`'s own tolerant-delete convention).

    **Reordering is pagination-safe by design**: both "move this list" and "move this series
    within its list" take a single `after` param - the id of whatever the client already has
    visible on the current page, right before the drop target - rather than the collection's full
    new order, since a client paging through hundreds of imported list entries may never have every
    item loaded at once. `after` empty/omitted means "move to the front". Under the hood, each item
    has an `ordering` integer spaced 1000 apart (`UserList`/`UserListSerie`'s `GAP` constant); a
    move computes the new value as the midpoint between the target neighbor and whatever already
    sits right after it (or `target + GAP` if it's last, or `MIN - GAP` for "to the front"). If two
    items' `ordering` are already adjacent integers and no midpoint fits, the whole collection is
    renumbered (1000, 2000, 3000...) and the move retried once - verified by manually forcing a
    collision and confirming the API rebalances before completing the move. This way a single move
    only ever costs the visible neighbor's id, never the full cross-page ordering.

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
   `user`/`user_token`/`user_password_reset`/`email_change`/`serie`/`serie_lang`/`episode`/
   `episode_lang`/`user_serie_watchlist`/`user_episode_watched`/`tvtime_import` tables — no admin user
   seeded, see below). **Careful re-importing this later**: `serie`/`serie_lang`/`episode`/
   `episode_lang` hold the TheTVDB mirror cache, which can mean hundreds of shows re-fetched from
   scratch if wiped - apply schema changes to those four tables with `ALTER TABLE` against the live
   database instead of blindly re-running the whole file once real data exists.
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
  (routes - `Account/{Get,UpdateUsername,ChangePassword,UpdateEmail,ConfirmEmailChange,
  UpdateLanguage,Delete}.php` are the profile-editing endpoints, `Delete.php` also overriding
  `freimguork-webservice`'s own `DELETE /account` (see above); `Import/{TvTime,TvTimeStatus,
  TvTimeProcess}.php` are the TV Time importer's upload/poll/cron-tick endpoints, see above),
  `Model/{Series,SerieLang,Episode,EpisodeLang,Watchlist,WatchedEpisode,TvTimeImport}.php` (local
  mirror + user state + import job tracking), `Model/TvTimeImport/{Parser,Processor}.php` (export
  parsing + applying a batch to a real account), `Model/TheTvdb/{Client,Languages}.php` (TheTVDB v4
  HTTP client - does its own plain `curl_init()` call rather than `Core\Model\Utils\Curl`, which
  forces every request through a bogus local proxy in dev mode and breaks real third-party calls;
  `Languages` maps this project's own `id_appacman_lang` to TheTVDB's 3-letter language codes,
  shared by `SerieLang`/`EpisodeLang`). `Webservice\Model\{User,UserToken,PasswordReset,
  EmailChange}` (profile/auth logic reusable by other consuming apps) live in the shared
  `freimguork-webservice` package instead, not here.
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
