<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'openproject.php';

// 1. we can not use the Google Keep API directly, so reorting to the Takeout
// 2. 


function keep(string $workingDirectory, callable $requester) {
    $keep_metadata = fn(string $metaDataFile) : ?object => file_exists($metaDataFile) ? json_decode(file_get_contents($metaDataFile)) : null;
    $keep_done = function(string $notepath, object $metadata) {
        $workingDirectory = dirname($notepath);
        $targetDirectory = $workingDirectory . DIRECTORY_SEPARATOR . 'done';
        if (isset($metadata->attachments)) {
            foreach ($metadata->attachments as $attachment) {
                $attachment_path = rikmeijer\openproject\find_attachment($attachment->mimetype, $workingDirectory . DIRECTORY_SEPARATOR . $attachment->filePath);
                rename($attachment_path, $targetDirectory . DIRECTORY_SEPARATOR . basename($attachment_path));
            }
        }
        $jsonPath = $workingDirectory . DIRECTORY_SEPARATOR . basename($notepath, '.html') . '.json';
        if (file_exists($jsonPath)) {
            rename($jsonPath, $targetDirectory . DIRECTORY_SEPARATOR . basename($notepath, '.html') . '.json');
        }
        if (file_exists($notepath)) {
            rename($notepath, $targetDirectory . DIRECTORY_SEPARATOR . basename($notepath));
        }
    };
    return function(array $mapping) use ($workingDirectory, $requester, $keep_metadata, $keep_done) {
        $notes = glob($workingDirectory . DIRECTORY_SEPARATOR . '*.html');
        echo PHP_EOL . 'Found '.count($notes).' notes (*.html) in ' . $workingDirectory;
        echo PHP_EOL;
        $migrated = 0;
        foreach ($notes as $note) {
            $noteName = basename($note, '.html');
            $metadata = $keep_metadata($workingDirectory . DIRECTORY_SEPARATOR . $noteName . '.json');
            if ($metadata === null) {
                echo 'missing metadata for ' . $noteName . '.';
                continue;
            }
            
            $done = fn() => $keep_done($note, $metadata);
            
            print PHP_EOL . $noteName . '...';
            
            
            
            $work_package = rikmeijer\openproject\item_already_migrated($requester, $noteName);
            if ($work_package !== null) {
                print 'already migrated.';
                // merge existing item
                
                if (isset($metadata->attachments)) {
                    $metadata->attachments = rikmeijer\openproject\filter_existing_attachments($requester, $work_package, $metadata->attachments, fn(object $workpackage_attachment, object $metadata_attachment) => $workpackage_attachment->fileName === $metadata_attachment->filePath);
                }
                
                if (isset($metadata->listContent)) {
                    $metadata->listContent = rikmeijer\openproject\filter_existing_tasks($requester, $work_package, $metadata->listContent, fn(object $workpackage_task, object $metadata_task) => $workpackage_task->subject === $metadata_task->text);
                }
            } elseif ($metadata->textContent === '' && !is_null($parent_id = keep_map_label_to_parent($mapping, $metadata->labels??[]))) {
                // only attachments, use parent as target
                if ($parent_id === false) {
                    echo ', unknown target.';
                    continue;
                }
                $work_package = rikmeijer\openproject\get_workpackage($requester, $parent_id);
                
                $metadata->attachments[] = (object)[
                    'filePath' => basename($note),
                    'mimetype' => 'text/html'
                ];
                
                if (isset($metadata->attachments)) {
                    $metadata->attachments = rikmeijer\openproject\filter_existing_attachments($requester, $work_package, $metadata->attachments, fn(object $workpackage_attachment, object $metadata_attachment) => $workpackage_attachment->fileName === $metadata_attachment->filePath);
                }
                
                if (isset($metadata->listContent)) {
                    $metadata->listContent = rikmeijer\openproject\filter_existing_tasks($requester, $work_package, $metadata->listContent, fn(object $workpackage_task, object $metadata_task) => $workpackage_task->subject === $metadata_task->text);
                }
            } else {
                $parent_id = keep_map_label_to_parent($mapping, $metadata->labels??[]);
                if ($parent_id === false) {
                    echo ', unknown target.';
                    continue;
                }

                $work_package = rikmeijer\openproject\create_workpackage(
                        $requester, 
                        $noteName, 
                        !empty($metadata->title) ? $metadata->title : $noteName, 
                        $metadata->textContent??'', 
                        $metadata->isArchived, 
                        $parent_id,
                        null,
                        null
                );
                
                $metadata->attachments[] = (object)[
                    'filePath' => basename($note),
                    'mimetype' => 'text/html'
                ];
            }
            
            if (!isset($work_package->id)) {
                var_dump($work_package);
                exit;
            }

            // Add attachments
            //https://www.openproject.org/docs/api/endpoints/attachments/#create-work-package-attachment

            $attachment_success = true;
            if (isset($metadata->attachments)) {
                $attachment_success = count($metadata->attachments) === 0;
                echo PHP_EOL . 'Attachments: ' . count($metadata->attachments);
                if (count($metadata->attachments) > 0) {
                    $attachment_success = keep_create_attachments_under_workpackage($requester, $work_package->id, $metadata->attachments, fn(string &$filetype, string $name) => keep_get_contents($filetype, $workingDirectory . DIRECTORY_SEPARATOR . $name));
                }
            }
                
            $task_success = true;
            if (isset($metadata->listContent)) {
                $task_success = count($metadata->listContent) === 0;
                echo PHP_EOL . 'Tasks: ' . count($metadata->listContent);
                if (count($metadata->listContent) > 0) {
                    $task_success = keep_create_tasks_under_workpackage($requester, $work_package->id, $metadata->listContent);
                }
            }
            if ($attachment_success && $task_success) {
                $done();
                $migrated++;
            }
        }
        return $migrated;
    };
}

function keep_map_label_to_parent(array $mapping, ?array $labels) {
    foreach ($labels as $note_label) {
        echo $note_label->name;
        if (array_key_exists($note_label->name, $mapping)) {
            return $mapping[$note_label->name];
        }
    }
    return array_key_exists(0, $mapping)?$mapping[0]:false;
}

function keep_create_attachments_under_workpackage(callable $requester, int $workpackage_id, array $attachments, callable $download) {
    foreach ($attachments as $attachment) {
        echo PHP_EOL . 'Attachment, ' . $attachment->filePath;
        if (!rikmeijer\openproject\create_attachment_under_workpackage($requester, $workpackage_id, $attachment->filePath, $attachment->mimetype, fn(string &$filetype) => $download($filetype, $attachment->filePath))) {
            return false;
        }
    }
    return true;
}
function keep_create_tasks_under_workpackage(callable $requester, int $workpackage_id, array $checklist) {
    // add checklists as tasks (type: 1, parent: created workpackage)
    foreach ($checklist as $checkItem) {
        echo PHP_EOL . 'Task, ' . $checkItem->text;
        if (!rikmeijer\openproject\create_task_under_workpackage($requester, $workpackage_id, $checkItem->text, $checkItem->isChecked, null)) {
            return false;
        }
    }
    return true;
}

$keep = keep(__DIR__ . DIRECTORY_SEPARATOR . $_ENV['GOOGLE_KEEP_NOTES_DIR'], rikmeijer\openproject\connect(rikmeijer\openproject\request($_ENV['OPENPROJECT_URL'], $_ENV['OPENPROJECT_TOKEN'])));

$migrated = $keep([
    0 => null,
    'Agile' => 40,
    'Boeken/artikelen' => 463,
    'boshalte.nl' => 387,
    'Cadeau Silke 40' => 354,
    'Crypto' => null,
    'Films' => 42,
    'Huishouden' => 41,
    'Ideeën' => 37,
    'Imagery' => null,
    'Inrichting' => 464,
    'Inzichten' => 37,
    'Recepten' => 63,
    'Rondreis Zuid Amerika' => 466,
    'Tattoo' => 467,
    'Verbouwing' => 464
]);
print PHP_EOL . $migrated . ' items migrated.';