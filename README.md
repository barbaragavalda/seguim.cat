# freimguork-skeleton

Base template for starting a new project on top of `freimguork-core` +
`freimguork-appacman` (admin panel included).

## Quick start (recommended)

Everything below is automated by `create-project.sh`, in the VM repo's
`system/scripts/` (see that repo's `README.md`, section 4, for full details):

```bash
cd system/scripts
sh create-project.sh <bitbucket|github> <slug> <prod-domain> [project-name] [description]
```

for example:
```bash
sh create-project.sh bitbucket tv-tracker tvtracker.com "TV Tracker" "Track what you're watching"
```

It creates the repo (Bitbucket or GitHub), the local host/vhost, copies this
skeleton, fills in every placeholder, fetches the latest jQuery, generates
DB credentials and encryption secrets, commits and pushes, creates the
database, imports `db.sql` and runs `composer install`. The only thing it
leaves manual is creating the first admin user (see below) - that needs a
real password you choose, encrypted with the project's own generated secret.

## Manual steps (what the script does, for reference/recovery)

1. Copy this folder with the new project's name (`<name>-local`) and run
   `git init` there (not a fork of this repo — each project is its own
   history).
2. Find and replace the placeholders:
   - `{{project-slug}}` → project slug (`tv-tracker`)
   - `{{project name}}` → readable name (`TV Tracker`)
   - `{{project description}}` → short description
   - `{{project-domain}}` → real production domain
3. Copy each `config/**/*.php.dist` to its non-`.dist` counterpart and fill in
   real values (never commit these files - they're already in
   `.gitignore`):
   - `config/dev/db.php`, `config/prod/db.php`
   - `config/dev/keys.php`, `config/prod/keys.php` (generate the secret with
     `php -r "echo bin2hex(random_bytes(32));"`, a **different** one per
     environment)
   - `config/mail.php` if the project needs it
4. Create the database and import `db.sql` (Appacman's minimal schema +
   data - blocks, field types, profiles and permissions - with no admin
   user yet).
5. `composer install` (also publishes Appacman/AdminLTE assets into
   `web/` via the `AssetPublisher` script).
6. Set up the local vhost pointing `DocumentRoot` to `web/`.
7. Create the repo on Bitbucket/GitHub, `git add`/`commit`/`remote add`/`push`.

## First admin user

`appacman_user.name`/`email` are encrypted (TwoWay) and `password` is hashed
(OneWay) under **this** project's secret (`config/dev/keys.php`), so a
generic admin can't be seeded in `db.sql` - it has to be generated with the
project's real key. With `config/dev/keys.php` already filled in:

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

$db = new PDO('mysql:host=mariadb;dbname=<db-name>', '<db-user>', '<db-password>');
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

(profile `2` = SuperAdmin, already with full permissions over
`appacman_user` in the `db.sql` seed). Adjust `<id>` in `$context` to the
real `id_appacman_user` that `AUTO_INCREMENT` will assign (usually `1` for
the first user).

## Structure

- `config/` - per-environment credentials (`dev/`/`prod/`, gitignored +
  `.dist` committed) and `projects.php` (sub-project map: `wallaby` =
  Appacman, `{lang}` = public site)
- `web/` - served document root (front controllers, `.htaccess`, `static/`
  with a CSS reset and the bundled jQuery, `upload/`)
- `src/Web/` - public site controllers/views (`Home`, `DefaultController`
  as an example)
- `src/cache/` - compiled route cache in prod (gitignored, created
  automatically at runtime - not part of the tracked structure)
- `locale/en_GB/LC_MESSAGES/` - minimal `.po` header only, no project-specific
  msgids yet
- `db.sql` - minimal Appacman schema, no admin user (see above)

## Not included yet

- Composer plugin conversion (`AssetPublisher` is still a script wired by
  hand in `composer.json` - pending decision to apply this consistently
  across the whole `freimguork-*` family)
- Tests / CI
- `Cronjob`/`Import` sub-projects (add them to `config/projects.php` if the
  project needs them, following the same pattern as `wallaby`/`{lang}`)
