# API reference

Base path `/api`. Every request needs an `Authorization` header: either the app's own shared
secret (`config/api/{dev,prod}/webservice.php`) or the user's own token (returned by
`register`/`login`). **Auth** column: `shared` = either works, `user` = must be the caller's own.

## Auth & account

| Method | Path | Auth | What it does |
|---|---|---|---|
| POST | `/register` | shared | Create an account (`email`, `password`, `username`) |
| POST | `/login` | shared | Log in, returns a token |
| POST | `/logout` | user | Revoke the current device's token |
| POST | `/login/google` | shared | Sign in / register via Google |
| POST | `/password/forgot` | shared | Email a reset code (`email`) |
| POST | `/password/reset` | shared | Reset with the code (`email`, `code`, `password`) |
| GET | `/account` | user | Current username/email/language |
| POST | `/account/username` | user | Rename |
| POST | `/account/password` | user | Change password |
| POST | `/account/email` | user | Request an email change (confirmed by code) |
| POST | `/account/email/confirm` | user | Confirm a pending email change |
| POST | `/account/language` | user | Change stored language |
| DELETE | `/account` | user | Delete the account and its data |

## Series & movies

| Method | Path | Auth | What it does |
|---|---|---|---|
| GET | `/series/search` | shared | Search series |
| GET | `/series/{tvdbId}` | shared | Series detail + episode list |
| GET | `/movies/search` | shared | Search movies |
| GET | `/movies/{tvdbId}` | shared | Movie detail |
| GET | `/search` | shared | Unified series + movie search |
| POST/DELETE | `/episode/{tvdbId}/watched` | user | Mark an episode watched / unwatched |
| POST/DELETE | `/episode/{tvdbId}/rewatch` | user | Record / undo a rewatch |
| POST/DELETE | `/movies/{tvdbId}/watched` | user | Mark a movie watched / unwatched |
| POST/DELETE | `/movies/{tvdbId}/rewatch` | user | Record / undo a rewatch |

## Watchlist

| Method | Path | Auth | What it does |
|---|---|---|---|
| GET | `/watchlist` | user | All series (`?status=`, paginated) |
| GET | `/watchlist/watching` | user | In-progress series |
| GET | `/watchlist/not-started` | user | Series with nothing watched yet |
| POST/DELETE | `/watchlist/{tvdbId}` | user | Add / remove a series |
| POST/DELETE | `/watchlist/{tvdbId}/archived` | user | Archive / unarchive |
| POST/DELETE | `/watchlist/{tvdbId}/removed` | user | Mark removed / restore |
| GET | `/movies/watchlist` | user | Movie watchlist |
| POST/DELETE | `/movies/{tvdbId}/watchlist` | user | Add / remove a movie |

## Favorites

| Method | Path | Auth | What it does |
|---|---|---|---|
| GET | `/favorites/summary` | user | Preview counts for the profile screen |
| GET/POST/DELETE | `/favorites/series` / `/favorites/series/{tvdbId}` | user | List / add / remove |
| GET/POST/DELETE | `/favorites/movies` / `/favorites/movies/{tvdbId}` | user | List / add / remove |

## Lists

| Method | Path | Auth | What it does |
|---|---|---|---|
| GET/POST | `/lists` | user | List / create |
| POST/DELETE | `/lists/{id}` | user | Rename / delete |
| GET | `/lists/{id}` | user | A list's series + movies |
| POST | `/lists/{id}/reorder` | user | Move the list itself |
| POST/DELETE | `/lists/{id}/series/{tvdbId}` | user | Add / remove a series |
| POST/DELETE | `/lists/{id}/movies/{tvdbId}` | user | Add / remove a movie |
| POST | `/lists/{id}/series\|movies/{tvdbId}/reorder` | user | Move an item within the list |
| GET | `/lists/membership/{tvdbId}` | user | Which lists contain this series |
| GET | `/lists/membership/movie/{tvdbId}` | user | Which lists contain this movie |

## TV Time import

| Method | Path | Auth | What it does |
|---|---|---|---|
| POST | `/import/tvtime` | user | Upload a GDPR export, queues a job |
| GET | `/import/tvtime/current` | user | This user's own in-progress job, if any |
| GET | `/import/tvtime/{id}` | user | Poll a job's status/summary (also advances it) |
| GET/POST | `/import/tvtime/process` | shared | Cron backstop - advances the oldest queued job |
| GET | `/import/series/pending` / `/import/movies/pending` | user | Ambiguous matches awaiting resolution |
| POST | `/import/series\|movies/pending/{id}/resolve` | user | Resolve one (`tvdb_id`) |
| DELETE | `/import/series\|movies/pending/{id}` | user | Skip one |

## Misc

| Method | Path | Auth | What it does |
|---|---|---|---|
| GET | `/version` | shared | Deployed build version |
