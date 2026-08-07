<?php

namespace Web\Model;

use Core\Model\Model;
use PDO;

/** Random poster images for the landing page's hero strip. `image` on serie/movie already stores TheTVDB's absolute artwork URL, so no local proxying/caching is needed */
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
