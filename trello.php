<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'openproject.php';

function trello_date(string $datetime) : DateTimeImmutable {
    return DateTimeImmutable::createFromFormat('X-m-d\\TH:i:s.vP', $datetime);
}

function trello(string $boardId, string $listName, callable $requester, Stevenmaguire\Services\Trello\Client $client) {
    $board = $client->getBoard($boardId);

    echo 'Dumping lists and cards on "' . $board->name . '"' . PHP_EOL;
    foreach ($client->getBoardLists($board->id) as $list) {
        if ($listName !== $list->name) {
            continue;
        }
        echo PHP_EOL . 'List: ' . $list->name . ' (' . $list->id . ')';

        foreach ($client->getListCards($list->id) as $card) {
            echo PHP_EOL . 'Card: ' . $card->name . ' (' . ($card->closed ? 'archived' : '') . ')';
            
            if ($work_package = rikmeijer\openproject\item_already_migrated($requester, $card->id)) {
                print PHP_EOL . 'Already migrated';
                continue;
            }

            $work_package = rikmeijer\openproject\create_workpackage($requester, $card->id, $card->name, $card->desc, $card->closed, trello_date($card->start), trello_date($card->due));
            if (!isset($work_package->id)) {
                var_dump($work_package);
                exit;
            }

            // Add attachments
            //https://www.openproject.org/docs/api/endpoints/attachments/#create-work-package-attachment
            echo PHP_EOL . 'Attachments: ' . $card->badges->attachments;
            if ($card->badges->attachments > 0) {
                trello_create_attachments_under_workpackage($requester, $work_package->id, $client->getCardAttachments($card->id), fn(string &$filetype, string $url) => $client->downloadAttachment($url));
            }

            echo PHP_EOL . 'Comments: ' . $card->badges->comments;
            if ($card->badges->comments > 0) {
                trello_create_comments_under_workpackage($requester, $work_package->id, $client->getCardAction($card->id));
            }

            trello_create_tasks_under_workpackage($requester, $work_package->id, $client->getCardChecklists($card->id));
        }
    }
}

function trello_create_attachments_under_workpackage(callable $requester, int $workpackage_id, array $attachments, callable $download) {
    foreach ($attachments as $attachment) {
        if (str_starts_with($attachment->url, \Stevenmaguire\Services\Trello\Configuration::get('domain'))) {
            rikmeijer\openproject\create_attachment_under_workpackage($requester, $workpackage_id, $attachment->fileName, $attachment->mimeType, fn(string &$filetype) => $download($filetype, $attachment->url));
        } else {
            rikmeijer\openproject\create_comment_under_workpackage($requester, $workpackage_id, $attachment->url);
        }
    }
}

function trello_create_comments_under_workpackage(callable $requester, int $workpackage_id, array $actions) {
    foreach ($actions as $action) {
        if (!isset($action->data->text)) {
            continue;
        }
        rikmeijer\openproject\create_comment_under_workpackage($requester, $workpackage_id, $action->data->text);
    }
}

function trello_create_tasks_under_workpackage(callable $requester, int $workpackage_id, array $checklists) {
    // add checklists as tasks (type: 1, parent: created workpackage)
    foreach ($checklists as $checklist) {
        foreach ($checklist->checkItems as $checkItem) {
            echo PHP_EOL . 'Task, ' . $checkItem->name;
            rikmeijer\openproject\create_task_under_workpackage($requester, $workpackage_id, $checkItem->name, $checkItem->state !== 'incomplete', trello_date($checkItem->due));
        }
    }
}

trello($_ENV['TRELLO_BOARD_ID'], $_ENV['TRELLO_LIST_NAME'], rikmeijer\openproject\connect(rikmeijer\openproject\request($_ENV['OPENPROJECT_URL'], $_ENV['OPENPROJECT_PROJECT_ID'], $_ENV['OPENPROJECT_TOKEN'])), new Stevenmaguire\Services\Trello\Client(array(
    //'callbackUrl' => 'http://your.domain/oauth-callback-url',
    //'expiration' => '3days',
    'key' => $_ENV['TRELLO_KEY'],
    //'name' => 'My sweet trello enabled app',
    //'scope' => 'read,write',
    'secret' => $_ENV['TRELLO_SECRET'],
    'token' => $_ENV['TRELLO_TOKEN'],
        //'version' => '1',
        //'proxy' => 'tcp://localhost:8125',
)));
