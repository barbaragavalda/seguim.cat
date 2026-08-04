<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\TvTimeImport;
use Core\Routing\Attribute\Route;
use ZipArchive;

/**
 * Only stores the upload and queues a job - the actual sync against
 * TheTVDB (see Api\Controller\Import\TvTimeProcess) can take many minutes
 * for a full TV Time history, far longer than a single web request should
 * ever run.
 */
#[Route('/import/tvtime', methods: ['POST'], name: 'api.import.tvtime')]
class TvTime extends Controller
{

    // generous for a personal export - the real one this was built against
    // is ~15MB
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
        // a fresh import means starting clean - any previous job of this
        // user's (done, failed, or abandoned mid-way), its zip, and
        // whatever it left pending for manual resolution would otherwise
        // just keep piling up (see removeAllForUser()'s own docblock).
        // Doesn't touch the user's actual watch history from a previous
        // import - only this job-tracking bookkeeping.
        $importModel->removeAllForUser($this->user->getID());

        $id = $importModel->create($this->user->getID(), $path);
        $this->assign('id', $id);
        $this->assign('status', 'pending');
    }

}
