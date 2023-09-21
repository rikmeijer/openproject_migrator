<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'openproject.php';

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

            //get work packages
            $pagesize = 20;
            $offset = 0;
            $workpackages = $requester('projects')('/3/work_packages?filters=' . urlencode('[{"customField1":{"operator" : "=", "values": ["' . $card->id . '"]}}]'));
            if ($workpackages->count > 0) {
                print PHP_EOL . 'Already migrated';
                continue;
            }



            $work_package = openproject_create_workpackage_from_card($requester, $card);
            if (!isset($work_package->id)) {
                var_dump($work_package);
                exit;
            }

            // Add attachments
            //https://www.openproject.org/docs/api/endpoints/attachments/#create-work-package-attachment
            echo PHP_EOL . 'Attachments: ' . $card->badges->attachments;
            if ($card->badges->attachments > 0) {
                openproject_create_attachments_under_workpackage($requester, $work_package->id, $client->getCardAttachments($card->id), fn(string $url) => $client->downloadAttachment($url));
            }

            echo PHP_EOL . 'Comments: ' . $card->badges->comments;
            if ($card->badges->comments > 0) {
                openproject_create_comments_under_workpackage($requester, $work_package->id, $client->getCardAction($card->id));
            }



            openproject_create_tasks_under_workpackage($requester, $work_package->id, $client->getCardChecklists($card->id));
        }
    }
}

trello($_ENV['TRELLO_BOARD_ID'], $_ENV['TRELLO_LIST_NAME'], openproject($_ENV['OPENPROJECT_URL'], $_ENV['OPENPROJECT_TOKEN']), new Stevenmaguire\Services\Trello\Client(array(
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
