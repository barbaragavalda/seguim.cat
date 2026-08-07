<?php

namespace Web\Model;

use Core\Model\Model;
use PDO;

/**
 * A handful of random poster images (mixing series and movies) for the
 * landing page's decorative hero strip. `image` on both `serie`/`movie`
 * already stores TheTVDB's own absolute artwork URL (confirmed against
 * real dev data - https://artworks.thetvdb.com/...), so no local
 * proxying/caching is needed, just embed it directly.
 */
class Posters extends Model
{

    public function random(int $limit): array
    {
        $half   = (int) ceil($limit / 2);
        $sql    = '
            (SELECT image FROM serie WHERE image IS NOT NULL ORDER BY RAND() LIMIT :half_series)
            UNION ALL
            (SELECT image FROM movie WHERE image IS NOT NULL ORDER BY RAND() LIMIT :half_movies)
        ';
        $params = array(
            'half_series' => array('value' => $half, 'type' => PDO::PARAM_INT),
            'half_movies' => array('value' => $half, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        $images = array_column($rows, 'image');
        shuffle($images);

        return array_slice($images, 0, $limit);
    }

}
