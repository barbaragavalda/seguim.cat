<?php

namespace Web\Model;

/**
 * All translatable copy for the public marketing site (Home, Privacy).
 * Catalan is the source/msgid language (Bootstrap::router() already calls
 * Language::initGettext() - bindtextdomain/textdomain('messenges') -
 * before any controller runs, same as the translated-URL-slug mechanism
 * AttributeRouteLoader::translatePath() relies on), so every string here
 * is wrapped in _() and resolves to the current request's locale via
 * locale/{es_ES,en_GB}/LC_MESSAGES/messenges.mo. No catalog exists for
 * ca_ES itself - gettext returns an unmatched msgid unchanged, which for
 * the source language is already correct.
 */
class Content
{

    public static function home(): array
    {
        return array(
            'ctaLabel'   => _("Accedeix a l'app"),
            'heroTitle'  => _('No perdis mai el fil del que mires'),
            'heroBody'   => _("Seguim! t'ajuda a fer un seguiment de les sèries i pel·lícules que mires: marca capítols vistos, desa els teus favorits i no perdis mai el fil de què t'has de veure."),
            // required content, not just nice-to-have - Google's OAuth
            // verification specifically checks that the app's homepage
            // explains *both* what the app does *and* how it uses the
            // Google user data it requests (Sign in with Google), not just
            // the first one
            'googleNote' => _("Si t'hi apuntes amb Google, només fem servir el teu compte per identificar-te — mai accedim a res més."),
            'features'  => array(
                array('icon' => 'check', 'title' => _('Marca capítols vistos'), 'body' => _('Un toc i ja saps per on vas.')),
                array('icon' => 'favorite', 'title' => _('Desa favorits'), 'body' => _('Les teves sèries i pel·lícules preferides, sempre a mà.')),
                array('icon' => 'list_alt', 'title' => _('Crea llistes'), 'body' => _('Organitza el que vols veure com tu vulguis.')),
                array('icon' => 'folder_zip', 'title' => _('Importa de TV Time'), 'body' => _('Recupera tot el teu historial en un moment.')),
            ),
        );
    }

    public static function footer(): array
    {
        return array(
            'privacyLink'     => _('Política de privacitat'),
            'tvdbAttribution' => _('Metadades proporcionades per TheTVDB.'),
        );
    }

    /**
     * @return array{updated: string, intro: string, sections: array<int, array{title: string, body: string}>}
     */
    public static function privacy(): array
    {
        return array(
            'updated' => _("Última actualització: 6 d'agost de 2026"),
            'intro'   => _("Aquesta política explica quines dades recull l'aplicació <strong>Seguim!</strong>, per a què les fem servir i quins drets tens sobre elles."),
            'sections' => array(
                array(
                    'title' => _('Responsable del tractament'),
                    'body'  => _('Bàrbara Gavaldà<br>Correu de contacte: <a href="mailto:barbaragavalda@gmail.com">barbaragavalda@gmail.com</a>'),
                ),
                array(
                    'title' => _('Quines dades recollim'),
                    'body'  => _('<ul>'
                        . '<li><strong>Dades de compte:</strong> nom d\'usuari, adreça electrònica (emmagatzemada xifrada) i contrasenya (emmagatzemada com a hash, mai en text pla).</li>'
                        . '<li><strong>Si inicies sessió amb Google:</strong> el teu identificador de compte de Google i l\'adreça electrònica associada, únicament per autenticar-te.</li>'
                        . '<li><strong>Idioma preferit</strong> de l\'aplicació.</li>'
                        . '<li><strong>Activitat dins l\'app:</strong> les sèries i pel·lícules que segueixes, els capítols que has marcat com a vistos, els teus favorits i les llistes personalitzades que crees.</li>'
                        . '<li><strong>Si importes dades de TV Time:</strong> processem el fitxer que puges únicament per traspassar aquest historial al teu compte.</li>'
                        . '</ul>'),
                ),
                array(
                    'title' => _('Per què les recollim'),
                    'body'  => _('Únicament per oferir-te el servei: mantenir el teu compte, recordar què has vist i ajudar-te a seguir sèries i pel·lícules. No fem servir les teves dades amb finalitats publicitàries.'),
                ),
                array(
                    'title' => _('Amb qui les compartim'),
                    'body'  => _('<ul>'
                        . '<li><strong>Google:</strong> només si tries iniciar sessió amb Google, per verificar la teva identitat.</li>'
                        . '<li><strong>TheTVDB:</strong> fem servir aquest servei per obtenir informació de sèries i pel·lícules (títols, imatges, sinopsis) — no els enviem cap dada personal teva.</li>'
                        . '</ul>'
                        . '<p>No venem ni compartim les teves dades amb tercers per a publicitat o analítica.</p>'),
                ),
                array(
                    'title' => _('Quant de temps les guardem'),
                    'body'  => _('Mentre el teu compte estigui actiu. Pots eliminar el teu compte en qualsevol moment des de l\'app (Perfil → Elimina el compte), la qual cosa esborra totes les teves dades de manera permanent i immediata.'),
                ),
                array(
                    'title' => _('Els teus drets'),
                    'body'  => _('Tens dret a accedir, rectificar i eliminar les teves dades, així com a sol·licitar-ne una còpia. Pots exercir aquests drets directament des de l\'app (edició de perfil, eliminació de compte) o contactant-nos a <a href="mailto:barbaragavalda@gmail.com">barbaragavalda@gmail.com</a>. També tens dret a presentar una reclamació davant l\'Agència Espanyola de Protecció de Dades (aepd.es).'),
                ),
                array(
                    'title' => _('Seguretat'),
                    'body'  => _('La teva adreça electrònica es guarda xifrada i la contrasenya mai es guarda en text pla, només com a hash irreversible.'),
                ),
                array(
                    'title' => _('Cookies'),
                    'body'  => _('El nostre servidor fa servir una galeta tècnica de sessió, necessària pel seu funcionament. No fem servir galetes de seguiment ni de publicitat.'),
                ),
                array(
                    'title' => _('Canvis a aquesta política'),
                    'body'  => _("Si actualitzem aquesta política de forma significativa, t'ho notificarem dins l'app."),
                ),
                array(
                    'title' => _('Contacte'),
                    'body'  => _('Per qualsevol dubte sobre aquesta política, escriu-nos a <a href="mailto:barbaragavalda@gmail.com">barbaragavalda@gmail.com</a>.'),
                ),
            ),
        );
    }

}
