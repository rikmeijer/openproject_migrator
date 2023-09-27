<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'openproject.php';

$api = rikmeijer\openproject\request($_ENV['OPENPROJECT_URL'], $_ENV['OPENPROJECT_TOKEN']);
$requester = rikmeijer\openproject\connect($api);

$move_attachments = function(object $workpackage, int $parent_id) use ($requester, $api) {
    $attachments = rikmeijer\openproject\list_workpackage_attachments($requester, $workpackage);

    if (count($attachments) > 0) {
        $fail = false;
        foreach ($attachments as $attachment) {
            echo PHP_EOL . $attachment->fileName . '...';
            if (rikmeijer\openproject\create_attachment_under_workpackage($requester, $parent_id, $attachment->fileName, $attachment->contentType, fn(string $filetype) => $api($attachment->_links->downloadLocation->href, fn(callable $post) => $filetype)) === false) {
                $fail = true;
                echo 'FAIL';
                return false;
            }
            echo 'OK';
        }
    }
    return true;
};

$offset = 1;
while (count($work_packages = rikmeijer\openproject\list_workpackages($requester, 20, $offset)) > 0) {
    echo PHP_EOL . PHP_EOL . 'page ' . $offset;
    foreach ($work_packages as $workpackage) {
        echo PHP_EOL . $workpackage->subject;
        if (!isset($workpackage->_links->parent->href)) {
            echo PHP_EOL . 'no parent';
            continue;
        }
        $parent_id = basename($workpackage->_links->parent->href);

        if (preg_match('/\d{2}-\d{2}-\d{2}T\d{2}_\d{2}_\d{2}.\d{3}\+\d{2}_\d{2}/', $workpackage->subject) === 0) {
            echo PHP_EOL . "Not a date-only subject";
            
        } elseif(filter_var($workpackage->description->raw, FILTER_VALIDATE_URL)) {
            echo PHP_EOL . "Copy url to parents ('.$parent_id.') comments, ";
            if (rikmeijer\openproject\create_comment_under_workpackage($requester, $parent_id, $workpackage->description->raw) === false) {
                echo 'failed';
                continue;
            }
            echo 'done';
            
            
            if ($move_attachments($workpackage, $parent_id) === false) {
                echo PHP_EOL . 'failed copying attachments to parent';
                continue;
            }
            echo PHP_EOL . 'copied attachments to parent';
            
            
            echo PHP_EOL . 'delete workpackage';
            rikmeijer\openproject\delete_workpackage($api, $workpackage);
        } elseif (empty($workpackage->description->raw)) {
            echo PHP_EOL . "empty description, ";
            if ($move_attachments($workpackage, $parent_id) === false) {
                echo PHP_EOL . 'failed copying attachments to parent';
                continue;
            }
            echo PHP_EOL . 'copied attachments to parent';
            
            $work_package_tasks = rikmeijer\openproject\list_workpackage_tasks($requester, $workpackage);
            if (count($work_package_tasks) > 0) {
                echo 'TODO tasks';
                continue;
            } 
            echo PHP_EOL . 'delete workpackage';
            rikmeijer\openproject\delete_workpackage($api, $workpackage);
        } else {
            echo PHP_EOL . "No it is not url: " . substr($workpackage->description->raw, 0, 100);
           // my else codes goes
        }
    }
    $offset++;
}