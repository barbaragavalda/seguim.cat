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
-- Table structure for movie (local lazy mirror of TheTVDB movies - same
-- on-demand lazy-mirroring shape as serie above, but a movie's own
-- GET /movies/{id}/extended already includes its artworks array inline, so
-- syncing one only ever costs a single TheTVDB request (no separate
-- /artworks call like serie.background needs)
-- ----------------------------
DROP TABLE IF EXISTS `movie`;
CREATE TABLE `movie` (
  `id_movie` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `tvdb_id` int(10) unsigned NOT NULL,
  -- per-language translated name/overview live in movie_lang, not here -
  -- same default_name/default_overview fallback convention as serie
  `default_name` varchar(255) DEFAULT NULL,
  `default_overview` text DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  -- highest-scored artwork of type 15 ("Background", confirmed via
  -- GET /artwork/types - NOT type 3, which is series' own background type)
  -- among /movies/{id}/extended's inline `artworks` array
  `background` varchar(500) DEFAULT NULL,
  -- a movie only has one release year (unlike serie's year_start/year_end
  -- range) - TheTVDB's own `year` field on the base/extended record
  `year` varchar(4) DEFAULT NULL,
  -- full date, TheTVDB's own `first_release.date` (its "global" release,
  -- not tied to any one country - unlike the many country-specific dates in
  -- its `releases` array) - `year` above stays as its own column since
  -- other screens (search/lists/my movies) only ever show the year
  `release_date` date DEFAULT NULL,
  -- minutes, TheTVDB's own `runtime` field
  `runtime` smallint(5) unsigned DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_movie`) USING BTREE,
  UNIQUE KEY `tvdb_id` (`tvdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for movie_lang (translated name/overview, lazily synced
-- from TheTVDB's GET /movies/{id}/translations/{language} - same shape and
-- "NULL row = confirmed no translation" convention as serie_lang)
-- ----------------------------
DROP TABLE IF EXISTS `movie_lang`;
CREATE TABLE `movie_lang` (
  `id_movie_lang` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_movie` mediumint(8) unsigned NOT NULL,
  `id_appacman_lang` tinyint(3) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `overview` text,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_movie_lang`) USING BTREE,
  UNIQUE KEY `id_movie_lang_lookup` (`id_movie`, `id_appacman_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for movie_genre (TheTVDB's genre taxonomy has no
-- per-language translation - confirmed empirically, a genre's name never
-- varies with Accept-Language - so unlike movie_lang there's no synced_at/
-- TTL here either, just a full replace each movie sync cycle. `slug` lets
-- the app localize the label itself client-side, `name` is the raw English
-- fallback for a slug it doesn't have a translation for yet)
-- ----------------------------
DROP TABLE IF EXISTS `movie_genre`;
CREATE TABLE `movie_genre` (
  `id_movie` mediumint(8) unsigned NOT NULL,
  `tvdb_genre_id` smallint(5) unsigned NOT NULL,
  `slug` varchar(100) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_movie`, `tvdb_genre_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for movie_cast (top-billed cast, peopleType === 'Actor'
-- rows only from TheTVDB's `characters` array - the rest are crew. Person/
-- character names have no per-language translation in practice, same
-- reasoning as movie_genre)
-- ----------------------------
DROP TABLE IF EXISTS `movie_cast`;
CREATE TABLE `movie_cast` (
  `id_movie_cast` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_movie` mediumint(8) unsigned NOT NULL,
  `tvdb_character_id` int(10) unsigned NOT NULL,
  `tvdb_people_id` int(10) unsigned DEFAULT NULL,
  `person_name` varchar(255) DEFAULT NULL,
  `character_name` varchar(255) DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  `sort_order` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_movie_cast`) USING BTREE,
  UNIQUE KEY `id_movie_character` (`id_movie`, `tvdb_character_id`),
  KEY `id_movie_sort` (`id_movie`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for movie_content_rating (age ratings are country-
-- specific, not language-specific - TheTVDB ties each one to a `country`,
-- not a language - so there's no *_lang table here either)
-- ----------------------------
DROP TABLE IF EXISTS `movie_content_rating`;
CREATE TABLE `movie_content_rating` (
  `id_movie_content_rating` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_movie` mediumint(8) unsigned NOT NULL,
  `tvdb_rating_id` int(10) unsigned NOT NULL,
  `country` char(3) DEFAULT NULL,
  `rating` varchar(20) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_movie_content_rating`) USING BTREE,
  UNIQUE KEY `id_movie_rating` (`id_movie`, `tvdb_rating_id`),
  KEY `id_movie_country` (`id_movie`, `country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for movie_trailer (TheTVDB returns a flat list of
-- separate trailer entries, each already tagged with its own `language` -
-- not one trailer translated N ways - so there's nothing to sync into a
-- *_lang table, bestForLanguage() just picks among the rows already here)
-- ----------------------------
DROP TABLE IF EXISTS `movie_trailer`;
CREATE TABLE `movie_trailer` (
  `id_movie_trailer` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_movie` mediumint(8) unsigned NOT NULL,
  `tvdb_trailer_id` int(10) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `language` char(3) DEFAULT NULL,
  PRIMARY KEY (`id_movie_trailer`) USING BTREE,
  UNIQUE KEY `id_movie_trailer_lookup` (`id_movie`, `tvdb_trailer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_movie_watchlist (counterpart of user_serie_watchlist -
-- no watch_later/stopped_watching columns here: those exist on the series
-- table purely because of TV Time import quirks around unfollowed/archived
-- shows, which don't apply the same way to a movie import - see
-- Api\Model\MovieWatchlist)
-- ----------------------------
DROP TABLE IF EXISTS `user_movie_watchlist`;
CREATE TABLE `user_movie_watchlist` (
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_movie` mediumint(8) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`, `id_movie`) USING BTREE,
  KEY `id_movie` (`id_movie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_movie_watched (one row per watch event, not per
-- movie - same "rewatch adds a row rather than updating" shape as
-- user_episode_watched, so watch count/history survives)
-- ----------------------------
DROP TABLE IF EXISTS `user_movie_watched`;
CREATE TABLE `user_movie_watched` (
  `id_user_movie_watched` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_movie` mediumint(8) unsigned NOT NULL,
  -- same import-provenance tag as user_episode_watched.id_tvtime_import -
  -- see its own comment. Here Api\Model\WatchedMovie::syncRewatchFromImport()
  -- dedupes by this plus the row's own exact watched_at (a movie rewatch
  -- carries a real distinct timestamp per event, unlike an episode
  -- rewatch's bare count - see Api\Model\TvTimeImport\Parser's own docblock)
  `id_tvtime_import` mediumint(8) unsigned DEFAULT NULL,
  `watched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_movie_watched`) USING BTREE,
  KEY `id_user_movie` (`id_user`, `id_movie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_serie_watchlist
-- ----------------------------
DROP TABLE IF EXISTS `user_serie_watchlist`;
CREATE TABLE `user_serie_watchlist` (
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_serie` mediumint(8) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- watch_later ("veure més tard") is set by hand (Api\Controller\Watchlist\
  -- {Archive,Unarchive}) or by the TV Time importer, for a show TV Time's
  -- own user_show_special_status.csv marks `status=for_later` (see
  -- Api\Model\TvTimeImport\Parser's own docblock). stopped_watching
  -- ("deixades de veure") is set both by hand (MarkRemoved/Restore) and by
  -- the importer, for a show that has watch history but isn't in TV Time's
  -- followed list at all anymore (unfollowed/deleted) or that TV Time
  -- itself marks archived there (see Parser). A show the user has already
  -- watched through to its last aired episode never comes out of import as
  -- either - see Watchlist::hasWatchedLastEpisode(), reused by the importer
  -- for exactly this. Regular Watchlist::add() always leaves both at 0.
  `watch_later` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `stopped_watching` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_user`, `id_serie`) USING BTREE,
  KEY `id_serie` (`id_serie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_episode_watched (one row per watch event, not
-- per episode - a rewatch adds another row rather than updating the
-- existing one, so watch history/count survives. WatchedEpisode::
-- markWatched() stays idempotent (checks first) for plain "mark watched";
-- markRewatched() always inserts. DELETE (markUnwatched) removes every row
-- for that episode - a full reset, not "undo one rewatch")
-- ----------------------------
DROP TABLE IF EXISTS `user_episode_watched`;
CREATE TABLE `user_episode_watched` (
  `id_user_episode_watched` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_episode` mediumint(8) unsigned NOT NULL,
  -- which TV Time import job (Api\Model\TvTimeImport) created this row, if
  -- any - NULL for a row from the user actually watching something in the
  -- app. Lets Api\Model\WatchedEpisode::syncRewatchesFromImport() tell "a
  -- previous import already recorded this rewatch" apart from "the user
  -- rewatched again since", so re-running an import doesn't double-count
  `id_tvtime_import` mediumint(8) unsigned DEFAULT NULL,
  `watched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_episode_watched`) USING BTREE,
  KEY `id_user_episode` (`id_user`, `id_episode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_password_reset (Webservice\Model\PasswordReset - this
-- package doesn't own the user/user_token schema either, see those tables'
-- own comments above)
-- ----------------------------
DROP TABLE IF EXISTS `user_password_reset`;
CREATE TABLE `user_password_reset` (
  `id_user_password_reset` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  -- SHA-256 hash of the 6-digit code, same reasoning as user_token.token
  `code` varchar(64) NOT NULL,
  -- locks the code out after PasswordReset::MAX_ATTEMPTS (5) wrong guesses -
  -- a 6-digit code alone (1M combinations) isn't brute-force-resistant
  -- without this
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id_user_password_reset`) USING BTREE,
  KEY `id_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for email_change (Webservice\Model\EmailChange - a
-- pending change-of-email request, confirmed via a code sent to the *new*
-- address, same 6-digit/15-min/5-attempts shape as user_password_reset - see
-- Api\Controller\Account\{UpdateEmail,ConfirmEmailChange})
-- ----------------------------
DROP TABLE IF EXISTS `email_change`;
CREATE TABLE `email_change` (
  `id_email_change` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  -- deliberately plain, unlike user.email (TwoWay-encrypted) - a short-
  -- lived (15 min), single-use, soon-deleted staging value, not the
  -- account's permanent record
  `new_email` varchar(255) NOT NULL,
  -- SHA-256 hash of the 6-digit code, same reasoning as user_token.token
  `code` varchar(64) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id_email_change`) USING BTREE,
  KEY `id_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for tvtime_import (background job tracking for the TV
-- Time GDPR-export importer - see Api\Controller\Import\*. Processed by a
-- separate cron-triggered endpoint rather than inline in the upload
-- request, since syncing a whole TV Time history from TheTVDB is many
-- minutes of HTTP calls - and confirmed empirically to exceed even
-- Apache's own 60s reverse-proxy Timeout well before finishing - so each
-- call to that endpoint only processes one time-boxed batch of shows,
-- resuming next call via processed_show_ids until nothing is left)
-- ----------------------------
DROP TABLE IF EXISTS `tvtime_import`;
CREATE TABLE `tvtime_import` (
  `id_tvtime_import` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  `status` enum('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `zip_path` varchar(500) NOT NULL,
  -- JSON array of TheTVDB series ids already synced+applied - the resume
  -- checkpoint between batches
  `processed_show_ids` text,
  -- JSON array of lists-prod-lists.csv's own s_key values already created -
  -- same resume purpose as processed_show_ids, but for the separate
  -- lists-import phase that only starts once every show is done
  `processed_list_keys` text,
  -- JSON array of movie names (tracking-prod-records.csv's own movie_name,
  -- the only stable key available - see Api\Model\TvTimeImport\Parser -
  -- there's no TheTVDB id for movies in the export) already resolved+
  -- applied - same resume purpose as processed_show_ids, for the movies
  -- phase that only starts once every show AND list is done
  `processed_movie_keys` text,
  -- JSON summary, updated incrementally after every batch (shows synced,
  -- episodes marked watched, shows not found on TheTVDB, movies synced/
  -- unmatched, ...)
  `summary` text,
  `error` text,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_tvtime_import`) USING BTREE,
  KEY `id_user` (`id_user`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_movie_pending (a movie title
-- Api\Model\TvTimeImport\MovieMatcher couldn't confidently resolve on its
-- own - either several TheTVDB movies share that exact title with no way to
-- tell them apart, or nothing matched at all. Rather than silently skipping
-- it (or worse, guessing), the candidates TheTVDB did return are stored here
-- for the user to resolve by hand later - see Api\Model\MovieImportPending
-- and the app's own pending-movies resolution screen)
-- ----------------------------
DROP TABLE IF EXISTS `user_movie_pending`;
CREATE TABLE `user_movie_pending` (
  `id_user_movie_pending` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_tvtime_import` mediumint(8) unsigned NOT NULL,
  `movie_name` varchar(255) NOT NULL,
  `expected_year` varchar(4) DEFAULT NULL,
  -- the same per-entry data Processor::processMovies() would otherwise
  -- apply immediately on a confident match (see Parser::parseMovies()) -
  -- snapshotted here since the import's own zip/extracted CSVs are deleted
  -- once the job finishes, so resolving this later can't re-parse them
  `watchlist_created_at` timestamp NULL DEFAULT NULL,
  `watched_at` timestamp NULL DEFAULT NULL,
  -- JSON array of watch timestamps, one per confirmed extra watch (see
  -- Parser's own docblock on why each 'rewatch' row is a real, separately
  -- timestamped event)
  `rewatch_at` text,
  -- JSON array of up to 5 {tvdb_id, name, year, image} - MovieMatcher's own
  -- candidates at import time, so the resolution screen doesn't need a
  -- fresh TheTVDB search (and stays stable even if a later search re-ranks)
  `candidates` text NOT NULL,
  -- set by Api\Model\MovieImportPending::resolve()/skip() instead of
  -- deleting the row - a later import hitting the same still-ambiguous
  -- title (the source data doesn't change) must not re-create a dialog the
  -- user already answered; see that class' own docblock
  `resolved` tinyint(1) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_movie_pending`) USING BTREE,
  -- re-running an import (or a second import later) that hits the same
  -- still-unresolved title updates this row in place instead of piling up
  -- duplicates
  UNIQUE KEY `id_user_movie_name` (`id_user`, `movie_name`),
  KEY `id_tvtime_import` (`id_tvtime_import`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_serie_pending (a show TV Time's own tv_show_id
-- no longer resolves on TheTVDB at all - TheTVDB renumbers/merges series ids
-- over time, so the export's old id is dead while the same show is findable
-- by name under a new one; Api\Model\TvTimeImport\SeriesMatcher's own
-- name search either found more than one same-titled candidate with no way
-- to tell them apart, or found nothing. Mirrors user_movie_pending above,
-- but the watched/rewatch snapshot is keyed by season+episode NUMBER rather
-- than TheTVDB episode id - the old episode ids are exactly as dead as the
-- show id itself once a new id is picked, but season/episode numbers still
-- line up against the new id's own freshly-synced episode list, letting
-- watch history be recovered instead of just the show identity)
-- ----------------------------
DROP TABLE IF EXISTS `user_serie_pending`;
CREATE TABLE `user_serie_pending` (
  `id_user_serie_pending` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  `id_tvtime_import` mediumint(8) unsigned NOT NULL,
  `show_name` varchar(255) NOT NULL,
  `watch_later` tinyint(1) NOT NULL DEFAULT 0,
  `stopped_watching` tinyint(1) NOT NULL DEFAULT 0,
  `watchlist_created_at` timestamp NULL DEFAULT NULL,
  -- JSON array of {season, episode, at} - every watched episode this show
  -- had under its old (dead) tvdb_id, snapshotted since the import's own
  -- zip/extracted CSVs are deleted once the job finishes
  `watched_episodes` text NOT NULL,
  -- JSON array of {season, episode, cpt, at} - see Parser's own docblock on
  -- rewatches only ever being a count+single timestamp per episode, never a
  -- discrete per-event log the way movie rewatches are
  `rewatch_episodes` text NOT NULL,
  -- JSON array of up to 5 {tvdb_id, name, year, image} - SeriesMatcher's own
  -- candidates at import time
  `candidates` text NOT NULL,
  -- see user_movie_pending.resolved's own comment, identical reasoning
  `resolved` tinyint(1) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_serie_pending`) USING BTREE,
  UNIQUE KEY `id_user_show_name` (`id_user`, `show_name`),
  KEY `id_tvtime_import` (`id_tvtime_import`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_list (custom user-curated series lists, e.g.
-- imported from TV Time's own "lists" feature - see Api\Model\UserList).
-- `ordering` uses large gaps (1000 per slot) rather than dense 0..n-1
-- positions specifically so reordering only ever needs the id of the
-- neighbor a client dropped an item next to (always visible on whatever
-- page it's currently looking at), never the full cross-page order -
-- Api\Model\UserList::moveAfter()/UserListSerie::moveAfter() insert a
-- new value at the midpoint between two neighbors, only renumbering the
-- whole collection on the rare occasion two neighbors are already
-- adjacent integers with no room left between them
-- ----------------------------
DROP TABLE IF EXISTS `user_list`;
CREATE TABLE `user_list` (
  `id_user_list` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` mediumint(8) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  -- lists-prod-lists.csv's own `s_key` for a list created by the TV Time
  -- importer (Api\Model\TvTimeImport\Processor) - NULL for a list the user
  -- created by hand. Lets a *later, separate* import job recognize this
  -- exact list again (Api\Model\UserList::findByTvtimeKey()) instead of
  -- creating a duplicate on every re-import - MySQL's own unique-key
  -- semantics treat each NULL as distinct, so hand-created lists never
  -- collide with each other here
  `tvtime_s_key` varchar(64) DEFAULT NULL,
  `ordering` int(10) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_list`) USING BTREE,
  UNIQUE KEY `id_user_tvtime_s_key` (`id_user`, `tvtime_s_key`),
  KEY `id_user_ordering` (`id_user`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_list_serie (a list's own series, own ordering
-- within that list - same gap-based scheme as user_list.ordering above)
-- ----------------------------
DROP TABLE IF EXISTS `user_list_serie`;
CREATE TABLE `user_list_serie` (
  `id_user_list_serie` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user_list` mediumint(8) unsigned NOT NULL,
  `id_serie` mediumint(8) unsigned NOT NULL,
  `ordering` int(10) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_list_serie`) USING BTREE,
  UNIQUE KEY `id_user_list_serie_lookup` (`id_user_list`, `id_serie`),
  KEY `id_user_list_ordering` (`id_user_list`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_list_movie (a list's own movies, own ordering
-- within that list - same gap-based scheme as user_list_serie above; a
-- list's series and movies are ordered independently of each other)
-- ----------------------------
DROP TABLE IF EXISTS `user_list_movie`;
CREATE TABLE `user_list_movie` (
  `id_user_list_movie` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user_list` mediumint(8) unsigned NOT NULL,
  `id_movie` mediumint(8) unsigned NOT NULL,
  `ordering` int(10) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_list_movie`) USING BTREE,
  UNIQUE KEY `id_user_list_movie_lookup` (`id_user_list`, `id_movie`),
  KEY `id_user_list_ordering` (`id_user_list`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_serie_list_pending (which user_list(s) wanted
-- a user_serie_pending row's show as a member - Processor::processLists()
-- used to just silently drop a list's own series/movie whenever it couldn't
-- resolve it (dead tvdb_id with no SeriesMatcher fallback at all, or an
-- ambiguous/unmatched movie); it now reuses the exact same
-- user_serie_pending/user_movie_pending rows the main show/movie
-- import already creates for this purpose, linking this list to that row
-- instead of duplicating its own separate pending concept. Once the user
-- resolves that pending row (Api\Model\SeriesImportPending::resolve()), it
-- adds the newly-synced series to every list linked here, not just the
-- user's overall watchlist - see resolve()'s own docblock)
-- ----------------------------
DROP TABLE IF EXISTS `user_serie_list_pending`;
CREATE TABLE `user_serie_list_pending` (
  `id_user_serie_list_pending` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user_serie_pending` mediumint(8) unsigned NOT NULL,
  `id_user_list` mediumint(8) unsigned NOT NULL,
  -- the list item's own added_at from the export (Parser::parseLists()) -
  -- applied to user_list_serie.created once this resolves, same as a
  -- normally-resolved list series would get
  `added_at` timestamp NULL DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_serie_list_pending`) USING BTREE,
  -- re-running an import (or a later batch of the same one) that hits the
  -- same still-unresolved show in the same list is a no-op, not a duplicate
  UNIQUE KEY `id_pending_id_user_list` (`id_user_serie_pending`, `id_user_list`),
  KEY `id_user_list` (`id_user_list`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for user_movie_list_pending - see
-- user_serie_list_pending's own docblock just above, identical shape
-- ----------------------------
DROP TABLE IF EXISTS `user_movie_list_pending`;
CREATE TABLE `user_movie_list_pending` (
  `id_user_movie_list_pending` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `id_user_movie_pending` mediumint(8) unsigned NOT NULL,
  `id_user_list` mediumint(8) unsigned NOT NULL,
  `added_at` timestamp NULL DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user_movie_list_pending`) USING BTREE,
  UNIQUE KEY `id_pending_id_user_list` (`id_user_movie_pending`, `id_user_list`),
  KEY `id_user_list` (`id_user_list`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
