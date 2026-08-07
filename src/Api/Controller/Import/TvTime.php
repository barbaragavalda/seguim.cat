<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\TvTimeImport;
use Core\Routing\Attribute\Route;
use ZipArchive;

/**
 * Only stores the upload and queues a job - the actual TheTVDB sync (see
 * TvTimeProcess) can take many minutes, far longer than a single web
 * request should run.
 */
#[Route('/import/tvtime', methods: ['POST'], name: 'api.import.tvtime')]
class TvTime extends Controller
{

    // generous for a personal export - real-world exports run ~15MB
    private const int MAX_SIZE = 200 * 1024 * 1024;

    protected function run(): void
    {
        $file = $_FILES['file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $this->error = 'A zip file upload is required.';
            return;
        }
        if ($file['size'] > self::MAX_SIZE) {
            $this->error = 'File is too large.';
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            $this->error = 'Not a valid zip file.';
            return;
        }
        $zip->close();

        $dir = DIR_ROOT . 'src/cache/' . (IS_DEV ? 'dev' : 'prod') . '/imports/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $path = $dir . $this->user->getID() . '-' . bin2hex(random_bytes(8)) . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            $this->error = 'Could not store the uploaded file.';
            return;
        }

        $importModel = new TvTimeImport();
        // clears any previous job of this user's (and its files) so they
        // don't pile up on disk - deliberately leaves watch history and
        // pending-resolution rows alone, see removeAllForUser()'s own docblock
        $importModel->removeAllForUser($this->user->getID());

        $id = $importModel->create($this->user->getID(), $path);
        $this->assign('id', $id);
        $this->assign('status', 'pending');
    }

}
