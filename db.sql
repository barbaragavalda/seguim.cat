-- Minimal Appacman schema + seed data to bootstrap a new project from zero.
-- No admin user is seeded here on purpose: appacman_user.name/email are
-- TwoWay-encrypted and password is OneWay-hashed under THIS project's own
-- config/dev/keys.php `encryption.secret` (see Core\Model\Encryptor\Secret),
-- so a row encrypted under some other project's key would be garbage here
-- anyway. Generate your own secret first, then create the first admin - see
-- README.md "First admin user".

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for appacman_app_config
-- Queried unconditionally by Webservice\Model\App::onMaintenance() on every
-- api request; missing it doesn't just log a warning, it triggers dev-mode
-- debug output that runs before headers are sent and silently drops the
-- rest of the response's headers (Access-Control-Allow-Origin included).
-- ----------------------------
DROP TABLE IF EXISTS `appacman_app_config`;
CREATE TABLE `appacman_app_config` (
  `id_appacman_app_config` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `platform` varchar(255) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_appacman_app_config`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_app_config` VALUES (1, 'VERSION', 'ios', '1.0.0');
INSERT INTO `appacman_app_config` VALUES (2, 'VERSION', 'android', '1.0.0');
INSERT INTO `appacman_app_config` VALUES (3, 'MAINTENANCE', NULL, '');
COMMIT;

-- ----------------------------
-- Table structure for appacman_block
-- ----------------------------
DROP TABLE IF EXISTS `appacman_block`;
CREATE TABLE `appacman_block` (
  `id_appacman_block` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `order` tinyint(4) unsigned NOT NULL,
  PRIMARY KEY (`id_appacman_block`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_block` VALUES (1, 100);
COMMIT;

-- ----------------------------
-- Table structure for appacman_block_lang
-- ----------------------------
DROP TABLE IF EXISTS `appacman_block_lang`;
CREATE TABLE `appacman_block_lang` (
  `id_appacman_block_lang` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `id_appacman_block` tinyint(3) unsigned NOT NULL,
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_block_lang`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_block_lang` VALUES (1, 1, 1, 'Gestor');
INSERT INTO `appacman_block_lang` VALUES (2, 1, 2, 'Gestor');
COMMIT;

-- ----------------------------
-- Table structure for appacman_config
-- ----------------------------
DROP TABLE IF EXISTS `appacman_config`;
CREATE TABLE `appacman_config` (
  `id_appacman_config` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_config`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_config` VALUES (1, 'logo', '');
INSERT INTO `appacman_config` VALUES (2, 'name', 'Seguim');
INSERT INTO `appacman_config` VALUES (3, 'support_email', '<support-email>');
COMMIT;

-- ----------------------------
-- Table structure for appacman_content
-- ----------------------------
DROP TABLE IF EXISTS `appacman_content`;
CREATE TABLE `appacman_content` (
  `id_appacman_content` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(255) NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `id_appacman_block` tinyint(3) unsigned DEFAULT NULL,
  `id_appacman_list_type` tinyint(3) unsigned DEFAULT NULL,
  `order_by` varchar(255) DEFAULT NULL,
  `order` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_appacman_content`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_content` VALUES (1, 'appacman_user', 'fa-user', 1, 1, NULL, 1);
COMMIT;

-- ----------------------------
-- Table structure for appacman_content_lang
-- ----------------------------
DROP TABLE IF EXISTS `appacman_content_lang`;
CREATE TABLE `appacman_content_lang` (
  `id_appacman_content_lang` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `id_appacman_content` tinyint(3) unsigned NOT NULL,
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_content_lang`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_content_lang` VALUES (1, 1, 1, 'Administradors');
INSERT INTO `appacman_content_lang` VALUES (2, 1, 2, 'Administradores');
COMMIT;

-- ----------------------------
-- Table structure for appacman_field
-- ----------------------------
DROP TABLE IF EXISTS `appacman_field`;
CREATE TABLE `appacman_field` (
  `id_appacman_field` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `id_appacman_content` tinyint(3) unsigned NOT NULL,
  `field_name` varchar(255) NOT NULL,
  `id_appacman_field_type` tinyint(3) unsigned DEFAULT NULL,
  `order` smallint(5) unsigned DEFAULT NULL,
  `show_on_list` tinyint(1) unsigned DEFAULT NULL,
  `show_on_breadcrumb` tinyint(1) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_appacman_field`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_field` VALUES (1, 1, 'name', 9, 1, 1, 1);
INSERT INTO `appacman_field` VALUES (2, 1, 'email', 9, 2, 1, NULL);
INSERT INTO `appacman_field` VALUES (3, 1, 'password', 10, 3, NULL, NULL);
INSERT INTO `appacman_field` VALUES (4, 1, 'id_appacman_user_profile', 2, 4, NULL, NULL);
INSERT INTO `appacman_field` VALUES (5, 1, 'changing_password', 4, 5, NULL, NULL);
INSERT INTO `appacman_field` VALUES (6, 1, 'created', NULL, 6, NULL, NULL);
COMMIT;

-- ----------------------------
-- Table structure for appacman_field_lang
-- ----------------------------
DROP TABLE IF EXISTS `appacman_field_lang`;
CREATE TABLE `appacman_field_lang` (
  `id_appacman_field_lang` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `id_appacman_field` smallint(5) unsigned NOT NULL,
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `hint` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_appacman_field_lang`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_field_lang` VALUES (1, 1, 1, 'Nom', NULL);
INSERT INTO `appacman_field_lang` VALUES (2, 1, 2, 'Nombre', NULL);
INSERT INTO `appacman_field_lang` VALUES (3, 2, 1, 'Email', NULL);
INSERT INTO `appacman_field_lang` VALUES (4, 2, 2, 'Email', NULL);
INSERT INTO `appacman_field_lang` VALUES (5, 3, 1, 'Contrasenya', NULL);
INSERT INTO `appacman_field_lang` VALUES (6, 3, 2, 'Contraseña', NULL);
INSERT INTO `appacman_field_lang` VALUES (7, 4, 1, 'Perfil', NULL);
INSERT INTO `appacman_field_lang` VALUES (8, 4, 2, 'Perfil', NULL);
INSERT INTO `appacman_field_lang` VALUES (9, 5, 1, 'Token email', NULL);
INSERT INTO `appacman_field_lang` VALUES (10, 5, 2, 'Token email', NULL);
INSERT INTO `appacman_field_lang` VALUES (11, 6, 1, 'Data creació', NULL);
INSERT INTO `appacman_field_lang` VALUES (12, 6, 2, 'Fecha creación', NULL);
COMMIT;

-- ----------------------------
-- Table structure for appacman_field_type
-- ----------------------------
DROP TABLE IF EXISTS `appacman_field_type`;
CREATE TABLE `appacman_field_type` (
  `id_appacman_field_type` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_field_type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_field_type` VALUES (1, 'image');
INSERT INTO `appacman_field_type` VALUES (2, 'select');
INSERT INTO `appacman_field_type` VALUES (3, 'check');
INSERT INTO `appacman_field_type` VALUES (4, 'unmodifiable');
INSERT INTO `appacman_field_type` VALUES (5, 'imageSeeOnly');
INSERT INTO `appacman_field_type` VALUES (6, 'selectMulti');
INSERT INTO `appacman_field_type` VALUES (7, 'textSimple');
INSERT INTO `appacman_field_type` VALUES (8, 'uri');
INSERT INTO `appacman_field_type` VALUES (9, 'encryptedTwoWay');
INSERT INTO `appacman_field_type` VALUES (10, 'encryptedOneWay');
INSERT INTO `appacman_field_type` VALUES (11, 'genericFile');
INSERT INTO `appacman_field_type` VALUES (12, 'link');
INSERT INTO `appacman_field_type` VALUES (13, 'number');
INSERT INTO `appacman_field_type` VALUES (14, 'selectEncryptedTwoWay');
INSERT INTO `appacman_field_type` VALUES (15, 'time');
INSERT INTO `appacman_field_type` VALUES (16, 'dateTime');
INSERT INTO `appacman_field_type` VALUES (17, 'selectPush');
INSERT INTO `appacman_field_type` VALUES (18, 'selectDeepLink');
INSERT INTO `appacman_field_type` VALUES (19, 'seeOnly');
INSERT INTO `appacman_field_type` VALUES (20, 'address');
INSERT INTO `appacman_field_type` VALUES (21, 'dynamic');
INSERT INTO `appacman_field_type` VALUES (22, 'hidden');
INSERT INTO `appacman_field_type` VALUES (23, 'colorPicker');
INSERT INTO `appacman_field_type` VALUES (24, 'encryptedTwoWaySeeOnly');
INSERT INTO `appacman_field_type` VALUES (25, 'genericFileSeeOnly');
COMMIT;

-- ----------------------------
-- Table structure for appacman_file
-- ----------------------------
DROP TABLE IF EXISTS `appacman_file`;
CREATE TABLE `appacman_file` (
  `id_appacman_file` mediumint(7) unsigned NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_file`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for appacman_file_resize
-- ----------------------------
DROP TABLE IF EXISTS `appacman_file_resize`;
CREATE TABLE `appacman_file_resize` (
  `id_appacman_file_resize` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `id_appacman_field` smallint(5) unsigned NOT NULL,
  `width` smallint(6) unsigned NOT NULL,
  `height` smallint(6) unsigned NOT NULL,
  `suffix` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_file_resize`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for appacman_lang
-- ----------------------------
DROP TABLE IF EXISTS `appacman_lang`;
CREATE TABLE `appacman_lang` (
  `id_appacman_lang` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `culture` varchar(10) NOT NULL,
  `order` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_appacman_lang`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;

-- id 3 (English) is used by Api\Model\SerieLang (serie_lang.id_appacman_lang)
-- for TheTVDB translations - not one of Appacman's own 'languages' (still
-- just ['ca'], see config/projects.php), added here since this is the
-- project's one shared language lookup table
BEGIN;
INSERT INTO `appacman_lang` VALUES (1, 'Català', 'ca', 1);
INSERT INTO `appacman_lang` VALUES (2, 'Castellano', 'es', 2);
INSERT INTO `appacman_lang` VALUES (3, 'English', 'en', 3);
COMMIT;

-- ----------------------------
-- Table structure for appacman_list_type
-- ----------------------------
DROP TABLE IF EXISTS `appacman_list_type`;
CREATE TABLE `appacman_list_type` (
  `id_appacman_list_type` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_list_type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_list_type` VALUES (1, 'table');
INSERT INTO `appacman_list_type` VALUES (2, 'cart');
COMMIT;

-- ----------------------------
-- Table structure for appacman_user
-- ----------------------------
DROP TABLE IF EXISTS `appacman_user`;
CREATE TABLE `appacman_user` (
  `id_appacman_user` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `id_appacman_user_profile` tinyint(3) unsigned DEFAULT NULL,
  `changing_password` varchar(255) DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_appacman_user`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
-- intentionally empty - see "First admin user" in README.md

-- ----------------------------
-- Table structure for appacman_user_permission
-- ----------------------------
DROP TABLE IF EXISTS `appacman_user_permission`;
CREATE TABLE `appacman_user_permission` (
  `id_appacman_user_permission` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_user_permission`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_user_permission` VALUES (1, 'create');
INSERT INTO `appacman_user_permission` VALUES (2, 'edit');
INSERT INTO `appacman_user_permission` VALUES (3, 'delete');
INSERT INTO `appacman_user_permission` VALUES (4, 'see');
INSERT INTO `appacman_user_permission` VALUES (5, 'export');
INSERT INTO `appacman_user_permission` VALUES (6, 'lock');
INSERT INTO `appacman_user_permission` VALUES (7, 'own');
INSERT INTO `appacman_user_permission` VALUES (8, 'duplicate');
INSERT INTO `appacman_user_permission` VALUES (9, 'firebase');
INSERT INTO `appacman_user_permission` VALUES (10, 'send-changes');
COMMIT;

-- ----------------------------
-- Table structure for appacman_user_permission_lang
-- ----------------------------
DROP TABLE IF EXISTS `appacman_user_permission_lang`;
CREATE TABLE `appacman_user_permission_lang` (
  `id_appacman_user_permission_lang` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `id_appacman_user_permission` tinyint(3) unsigned NOT NULL,
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_user_permission_lang`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_user_permission_lang` VALUES (1, 1, 1, 'Crear');
INSERT INTO `appacman_user_permission_lang` VALUES (2, 1, 2, 'Crear');
INSERT INTO `appacman_user_permission_lang` VALUES (3, 2, 1, 'Editar');
INSERT INTO `appacman_user_permission_lang` VALUES (4, 2, 2, 'Editar');
INSERT INTO `appacman_user_permission_lang` VALUES (5, 3, 1, 'Eliminar');
INSERT INTO `appacman_user_permission_lang` VALUES (6, 3, 2, 'Eliminar');
INSERT INTO `appacman_user_permission_lang` VALUES (7, 4, 1, 'Veure');
INSERT INTO `appacman_user_permission_lang` VALUES (8, 4, 2, 'Ver');
INSERT INTO `appacman_user_permission_lang` VALUES (9, 5, 1, 'Exportar');
INSERT INTO `appacman_user_permission_lang` VALUES (10, 5, 2, 'Exportar');
INSERT INTO `appacman_user_permission_lang` VALUES (11, 6, 1, 'Bloquejar');
INSERT INTO `appacman_user_permission_lang` VALUES (12, 6, 2, 'Bloquear');
INSERT INTO `appacman_user_permission_lang` VALUES (13, 7, 1, 'Propi');
INSERT INTO `appacman_user_permission_lang` VALUES (14, 7, 2, 'Propio');
INSERT INTO `appacman_user_permission_lang` VALUES (15, 8, 1, 'Duplicar');
INSERT INTO `appacman_user_permission_lang` VALUES (16, 8, 2, 'Duplicar');
INSERT INTO `appacman_user_permission_lang` VALUES (17, 9, 1, 'Firebase');
INSERT INTO `appacman_user_permission_lang` VALUES (18, 9, 2, 'Firebase');
INSERT INTO `appacman_user_permission_lang` VALUES (19, 10, 1, 'Els canvis s''envien als admins');
INSERT INTO `appacman_user_permission_lang` VALUES (20, 10, 2, 'Los cambios se envian a los admins');
COMMIT;

-- ----------------------------
-- Table structure for appacman_user_profile
-- ----------------------------
DROP TABLE IF EXISTS `appacman_user_profile`;
CREATE TABLE `appacman_user_profile` (
  `id_appacman_user_profile` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id_appacman_user_profile`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_user_profile` VALUES (1);
INSERT INTO `appacman_user_profile` VALUES (2);
COMMIT;

-- ----------------------------
-- Table structure for appacman_user_profile_lang
-- ----------------------------
DROP TABLE IF EXISTS `appacman_user_profile_lang`;
CREATE TABLE `appacman_user_profile_lang` (
  `id_appacman_user_profile_lang` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `id_appacman_user_profile` tinyint(3) unsigned NOT NULL,
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id_appacman_user_profile_lang`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8;

BEGIN;
INSERT INTO `appacman_user_profile_lang` VALUES (1, 1, 1, 'Administrador');
INSERT INTO `appacman_user_profile_lang` VALUES (2, 1, 2, 'Administrador');
INSERT INTO `appacman_user_profile_lang` VALUES (3, 2, 1, 'SuperAdministrador');
INSERT INTO `appacman_user_profile_lang` VALUES (4, 2, 2, 'SuperAdministrador');
COMMIT;

-- ----------------------------
-- Table structure for appacman_user_profile_permission
-- ----------------------------
DROP TABLE IF EXISTS `appacman_user_profile_permission`;
CREATE TABLE `appacman_user_profile_permission` (
  `id_appacman_user_profile` tinyint(3) unsigned NOT NULL,
  `id_appacman_content` tinyint(3) unsigned NOT NULL,
  `id_appacman_user_permission` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id_appacman_user_profile`,`id_appacman_content`,`id_appacman_user_permission`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- SuperAdministrador (2) gets full CRUD + own on appacman_user (1), so the
-- first admin created (see README.md) can manage other admins.
BEGIN;
INSERT INTO `appacman_user_profile_permission` VALUES (2, 1, 1);
INSERT INTO `appacman_user_profile_permission` VALUES (2, 1, 2);
INSERT INTO `appacman_user_profile_permission` VALUES (2, 1, 3);
INSERT INTO `appacman_user_profile_permission` VALUES (2, 1, 4);
INSERT INTO `appacman_user_profile_permission` VALUES (2, 1, 7);
COMMIT;

-- ----------------------------
-- Table structure for user (app users, distinct from appacman_user above,
-- which is only for the Appacman backoffice admins)
-- ----------------------------
DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `id_user` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  -- AES-256-GCM ciphertext (Core\Model\Encryptor\TwoWay), not the plaintext
  -- email - `text` because the encrypted form is noticeably longer than the
  -- plaintext (nonce+tag+hex-encoding overhead), a fixed varchar(255) would
  -- silently truncate a long real address
  `email` text NOT NULL,
  -- deterministic HMAC-SHA256 of the plaintext email (Core\Model\Encryptor\
  -- BlindIndex), the actual uniqueness/lookup key - `email` itself can't be
  -- one: TwoWay's random nonce means the same address encrypts to a
  -- different ciphertext every time, so a UNIQUE KEY on `email` would never
  -- collide and silently stop enforcing uniqueness at all
  `email_bidx` char(64) NOT NULL,
  `password` varchar(255) NOT NULL,
  `username` varchar(20) NOT NULL,
  -- captured at registration (Webservice\Controller\Register, via
  -- Core\Utils\Language::getLanguageID()) so a later notification (e.g.
  -- ForgotPassword's reset email) is sent in the language this user
  -- actually registered with, not whatever a later request resolves to
  `id_appacman_lang` tinyint(3) unsigned DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`) USING BTREE,
  UNIQUE KEY `email_bidx` (`email_bidx`),
  -- case-insensitive under whatever *_ci collation utf8mb4 defaults to on
  -- this server, so "Bar"/"bar" already collide without any extra
  -- normalization - confirmed empirically, not assumed from the charset name
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_token
-- ----------------------------
DROP TABLE IF EXISTS `user_token`;
CREATE TABLE `user_token` (
  `id_user_token` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  -- SHA-256 hash of the token (Webservice\Model\UserToken::hash()), not the
  -- plaintext - only the client ever sees the real value, so DB read access
  -- alone can't be used to hijack a session; still varchar(64) since a hash
  -- is exactly the same length as the token it replaced
  `token` varchar(64) NOT NULL,
  `device_label` varchar(255) DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id_user_token`) USING BTREE,
  UNIQUE KEY `token` (`token`),
  KEY `id_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for serie (local lazy mirror of TheTVDB series)
-- ----------------------------
DROP TABLE IF EXISTS `serie`;
CREATE TABLE `serie` (
  `id_serie` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `tvdb_id` int(10) unsigned NOT NULL,
  -- per-language translated name/overview live in serie_lang, not here.
  -- default_name/default_overview below are TheTVDB's own (untranslated,
  -- normally original-language) base record fields, used as a fallback
  -- when serie_lang has no translation for the app's current language
  `default_name` varchar(255) DEFAULT NULL,
  `default_overview` text DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  -- fanart/backdrop (TheTVDB artwork type 3, "Background"), from the
  -- highest-scored of the (typically dozens of) results on the separate
  -- GET /series/{id}/artworks?type=3 endpoint - not part of the base series
  -- call above, one extra TheTVDB request per sync (same 24h TTL as the
  -- rest of this table, so still cheap)
  `background` varchar(500) DEFAULT NULL,
  -- both derived from TheTVDB's firstAired/lastAired (full dates - only the
  -- year is kept); replaces the old single `year` column, which was just
  -- firstAired's year and became redundant once year_start existed.
  -- year_end is nullable: an ongoing/"Continuing" show still gets one (the
  -- latest aired episode's year), not a real end date - check `status`
  `year_start` varchar(4) DEFAULT NULL,
  `year_end` varchar(4) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `average_runtime` smallint(5) unsigned DEFAULT NULL,
  -- regular numbered seasons only (season 0/specials excluded), derived
  -- from our own already-synced 'official'-type local episodes rather than
  -- TheTVDB's /extended `seasons` array, which mixes several season-type
  -- schemes (official/dvd/absolute/alternate) in one list and would badly
  -- overcount - confirmed empirically (22 entries for a 6-season show)
  `season_count` tinyint(3) unsigned DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_serie`) USING BTREE,
  UNIQUE KEY `tvdb_id` (`tvdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for serie_lang (translated name/overview, lazily synced
-- from TheTVDB's GET /series/{id}/translations/{language} - NOT the same as
-- serie.nameTranslations/overviewTranslations, which are only lists of
-- language codes that HAVE a translation, not the translated text itself.
-- id_<table>_lang / id_<table> / id_appacman_lang is this project's existing
-- _lang companion-table convention, same shape as appacman_content_lang etc.)
-- ----------------------------
DROP TABLE IF EXISTS `serie_lang`;
CREATE TABLE `serie_lang` (
  `id_serie_lang` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_serie` mediumint(8) unsigned NOT NULL,
  -- matches this project's existing appacman_lang lookup table (see above),
  -- extended with id 3 = English for this - not TheTVDB's own 3-letter
  -- language code (cat/spa/eng), that mapping lives in Api\Model\SerieLang
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  -- both nullable: a NULL row (not a missing row) means TheTVDB was asked
  -- and confirmed it has no translation in that language - storing that
  -- fact, with its own synced_at, is what stops every request from
  -- re-attempting the same doomed API call until the TTL expires
  `name` varchar(255) DEFAULT NULL,
  `overview` text,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_serie_lang`) USING BTREE,
  UNIQUE KEY `id_serie_lang_lookup` (`id_serie`, `id_appacman_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for episode (local lazy mirror of TheTVDB episodes)
-- ----------------------------
DROP TABLE IF EXISTS `episode`;
CREATE TABLE `episode` (
  `id_episode` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_serie` mediumint(8) unsigned NOT NULL,
  `tvdb_id` int(10) unsigned NOT NULL,
  `season_number` smallint(5) unsigned NOT NULL DEFAULT 0,
  `episode_number` smallint(5) unsigned NOT NULL DEFAULT 0,
  -- per-language translated name/overview live in episode_lang, not here.
  -- default_name/default_overview below are TheTVDB's own (untranslated,
  -- normally original-language) base record fields, used as a fallback
  -- when episode_lang has no translation for the app's current language -
  -- same pattern as serie/serie_lang's default_name/default_overview
  `default_name` varchar(255) DEFAULT NULL,
  `default_overview` text DEFAULT NULL,
  `aired` date DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  `runtime` smallint(5) unsigned DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_episode`) USING BTREE,
  UNIQUE KEY `tvdb_id` (`tvdb_id`),
  KEY `id_serie` (`id_serie`, `season_number`, `episode_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for episode_lang (translated name/overview, lazily synced
-- in bulk - one TheTVDB call per series+language returns every episode's
-- translation at once, see Api\Model\TheTvdb\Client::getSeriesEpisodesTranslated()
-- and Api\Model\EpisodeLang - not one call per episode)
-- ----------------------------
DROP TABLE IF EXISTS `episode_lang`;
CREATE TABLE `episode_lang` (
  `id_episode_lang` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_episode` mediumint(8) unsigned NOT NULL,
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  -- both nullable: a NULL row (not a missing row) means TheTVDB was asked
  -- and confirmed it has no translation for this specific episode in this
  -- language (common - confirmed empirically only ~95% of a real series'
  -- episodes had a Spanish name), same "cache the absence" reasoning as
  -- serie_lang
  `name` varchar(255) DEFAULT NULL,
  `overview` text,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_episode_lang`) USING BTREE,
  UNIQUE KEY `id_episode_lang_lookup` (`id_episode`, `id_appacman_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_watchlist
-- ----------------------------
DROP TABLE IF EXISTS `user_watchlist`;
CREATE TABLE `user_watchlist` (
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_serie` mediumint(8) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`, `id_serie`) USING BTREE,
  KEY `id_serie` (`id_serie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_episode_watched (presence = watched; DELETE = unwatched)
-- ----------------------------
DROP TABLE IF EXISTS `user_episode_watched`;
CREATE TABLE `user_episode_watched` (
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_episode` mediumint(8) unsigned NOT NULL,
  `watched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`, `id_episode`) USING BTREE,
  KEY `id_episode` (`id_episode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for password_reset (Webservice\Model\PasswordReset - this
-- package doesn't own the user/user_token schema either, see those tables'
-- own comments above)
-- ----------------------------
DROP TABLE IF EXISTS `password_reset`;
CREATE TABLE `password_reset` (
  `id_password_reset` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  -- SHA-256 hash of the 6-digit code, same reasoning as user_token.token
  `code` varchar(64) NOT NULL,
  -- locks the code out after PasswordReset::MAX_ATTEMPTS (5) wrong guesses -
  -- a 6-digit code alone (1M combinations) isn't brute-force-resistant
  -- without this
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id_password_reset`) USING BTREE,
  KEY `id_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
