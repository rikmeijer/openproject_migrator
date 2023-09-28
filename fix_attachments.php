<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'openproject.php';

$api = rikmeijer\openproject\request($_ENV['OPENPROJECT_URL'], $_ENV['OPENPROJECT_TOKEN']);
$requester = rikmeijer\openproject\connect($api);


function compare_attachments(object $attachment1, object $attachment2) : int|bool {;
    if ($attachment1->fileName !== $attachment2->fileName) {
        return false;
    } elseif ($attachment1->fileSize !== $attachment2->fileSize) {
        return false;
    } else {
        return strcasecmp($attachment1->createdAt, $attachment2->createdAt);
    }
}

$parents = array_unique([
    'Agile' => 40,
    'Boeken/artikelen' => 463,
    'boshalte.nl' => 387,
    'Cadeau Silke 40' => 354,
    'Films' => 42,
    'Huishouden' => 41,
    'Ideeën' => 37,
    'Inrichting' => 464,
    'Inzichten' => 37,
    'Recepten' => 63,
    'Rondreis Zuid Amerika' => 466,
    'Tattoo' => 467,
    'Verbouwing' => 464
]);

$workingDirectory = __DIR__ . DIRECTORY_SEPARATOR . $_ENV['GOOGLE_KEEP_NOTES_DIR'];

foreach ($parents as $workpackage_id) {
    echo PHP_EOL . 'Retrieving workpackage ' . $workpackage_id . '...';
    $workpackage = rikmeijer\openproject\get_workpackage($requester, $workpackage_id);
    if (!is_object($workpackage)) {
        exit('FAIL');
    }
    echo ' ' . $workpackage->subject;

    echo PHP_EOL . 'Retrieving workpackage attachments...';
    $attachments = rikmeijer\openproject\list_workpackage_attachments($requester, $workpackage);
    echo ' ' . count($attachments) . ' attachments';

    if (count($attachments) > 0) {
        $deduplicated_attachments = [];
        foreach ($attachments as $attachment) {
            echo PHP_EOL . $attachment->fileName;
            
            $local = $workingDirectory . DIRECTORY_SEPARATOR . $attachment->fileName;
            
            $strategies = [
                fn($filename) => str_replace('_', ' ', $filename)
            ];
            
            while (file_exists($local) === false && count($strategies) > 0) {
                $strategy = array_pop($strategies);
                $local = $strategy($local);
            }
            if (file_exists($local) === false) {
                print ', no local copy';
                continue;
            }
            
            $filesize = filesize($local);
            if ($filesize <= $attachment->fileSize) {
                
            } elseif (!rikmeijer\openproject\create_attachment_under_workpackage($requester, basename($attachment->_links->container->href), $attachment->fileName, $attachment->contentType, fn(string &$filetype) => rikmeijer\openproject\keep_get_contents($filetype, $local))) {
                print ', failed replacing.';
            } else {
                rikmeijer\openproject\delete_attachment($api, $attachment);
                print ', replaced with local copy';
            }

        }

    }

}