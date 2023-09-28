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
            if (array_key_exists($attachment->fileName, $deduplicated_attachments) === false) {
                $deduplicated_attachments[$attachment->fileName] = $attachment;
            } else {
                switch (compare_attachments($deduplicated_attachments[$attachment->fileName], $attachment)) {
                    case false: // incomparable, keep both
                        break;

                    case -1: // 1 older than 2, remove 2
                    case 0: // equal, remove 2
                        if (($response = \rikmeijer\openproject\delete_attachment($api, $attachment)) !== false) {
                            echo PHP_EOL  . $attachment->fileName . ': ' . $attachment->createdAt . ' deleted';
                        } else {
                            echo 'failure: "'.$response.'"';
                        }
                        break;

                    case 1: // 1 younger than 2, replace 1
                        if (($response = \rikmeijer\openproject\delete_attachment($api, $deduplicated_attachments[$attachment->fileName])) !== false) {
                            echo PHP_EOL . $attachment->fileName . ': ' . $deduplicated_attachments[$attachment->fileName]->createdAt . ' deleted';
                            $deduplicated_attachments[$attachment->fileName] = $attachment;
                        } else {
                            echo 'failure: "'.$response.'"';
                        }
                        break;

                    default:
                        break;
                }
            }

        }

    }

}