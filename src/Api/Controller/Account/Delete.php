<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Api\Model\MovieFavorite;
use Api\Model\MovieImportPending;
use Api\Model\MovieWatchlist;
use Api\Model\SerieFavorite;
use Api\Model\SeriesImportPending;
use Api\Model\TvTimeImport;
use Api\Model\UserGoogle;
use Api\Model\UserList;
use Api\Model\WatchedEpisode;
use Api\Model\WatchedMovie;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;
use Webservice\Model\EmailChange;
use Webservice\Model\User;
use Webservice\Model\UserToken;

/**
 * Overrides Webservice\Controller\DeleteAccount (same path/method - a
 * project's own routes are loaded before a vendor package's and win on
 * collision, see Core\Bootstrap::loadRoutes()) because that vendor
 * controller only knows about the user/user_token schema it owns - it has
 * no idea this project also keeps its own per-user rows in user_serie_watchlist/
 * user_episode_watched/user_import/user_list/user_movie_watchlist/
 * user_movie_watched, which were otherwise left orphaned after a delete
 */
#[Route('/account', methods: ['DELETE'], name: 'api.account.delete')]
class Delete extends Controller
{

    protected function run(): void
    {
        $userID = $this->user->getID();

        (new UserToken())->revokeAllForUser($userID);
        (new Watchlist())->removeAllForUser($userID);
        (new WatchedEpisode())->removeAllForUser($userID);
        (new MovieWatchlist())->removeAllForUser($userID);
        (new WatchedMovie())->removeAllForUser($userID);
        (new MovieImportPending())->removeAllForUser($userID);
        (new SeriesImportPending())->removeAllForUser($userID);
        (new TvTimeImport())->removeAllForUser($userID);
        (new UserList())->removeAllForUser($userID);
        (new SerieFavorite())->removeAllForUser($userID);
        (new MovieFavorite())->removeAllForUser($userID);
        (new UserGoogle())->removeAllForUser($userID);
        (new EmailChange())->deleteForUser($userID);
        (new User())->delete($userID);
    }

}
