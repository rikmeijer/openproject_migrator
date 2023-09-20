<?php

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

function build_data_files($boundary, $fields, $files) {
    $data = '';
    $eol = "\r\n";

    $delimiter = '-------------' . $boundary;

    foreach ($fields as $name => $content) {
        $data .= "--" . $delimiter . $eol
                . 'Content-Disposition: form-data; name="' . $name . "\"" . $eol . $eol
                . $content . $eol;
    }


    foreach ($files as $name => $file) {
        $data .= "--" . $delimiter . $eol
                . 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['name'] . '"' . $eol
                . 'Content-Type: ' . $file['type'] . $eol
                . 'Content-Transfer-Encoding: binary' . $eol
        ;

        $data .= $eol;
        $data .= $file['data'] . $eol;
    }
    $data .= "--" . $delimiter . "--" . $eol;

    return $data;
}

/**
  The current openproject api is missing some exmaples. This is what i got working in PHP.
 */
function openproject(string $url, string $token): callable {
    return function (string $scope) use ($url, $token) {
        return function ($req, $postdata = false, $contentType = 'application/json') use ($scope, $url, $token) {
            $url .= '/api/v3/' . $scope . $req;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERPWD, "apikey:" . $token);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

            if ($postdata) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

                $headers = [];
                if ($contentType === 'multipart/form-data') {
                    $boundary = uniqid();
                    $delimiter = '-------------' . $boundary;

                    $post_data = build_data_files($boundary, ['metadata' => json_encode($postdata->metadata)], [
                        'file' => [
                            'name' => $postdata->metadata['fileName'],
                            'type' => $postdata->metadata['contentType'],
                            'data' => $postdata->file
                        ]
                    ]);

                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                    $headers[] = 'Content-Length: ' . strlen($post_data);
                    $headers[] = "Content-Type: multipart/form-data; boundary=" . $delimiter;
                } else {
                    $data = json_encode($postdata);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                    $headers[] = 'Content-Type: ' . $contentType;
                    $headers[] = 'Content-Length: ' . strlen($data);
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $return = curl_exec($ch);
            if ($return === false) {
                exit(curl_error($ch));
            }
            curl_close($ch);
            return json_decode($return);
        };
    };
}

function trello(Stevenmaguire\Services\Trello\Client $client) {
    return function (string $boardId, string $listName, callable $requester) use ($client) {
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
    };
}

function openproject_create_workpackage_from_card(callable $requester, object $card) {
    // types: 1 = task, 4 = feature, 2,3 = N/A, 5 = epic, 6 = us
    $data = (object) [
        'subject' => $card->name,
        'startDate' => ($card->start?DateTimeImmutable::createFromFormat('X-m-d\\TH:i:s.vP', $card->start)->format('Y-m-d'):null),
        'dueDate' => ($card->due?DateTimeImmutable::createFromFormat('X-m-d\\TH:i:s.vP', $card->due)->format('Y-m-d'):null),
        'description' => [
            'format' => 'markdown',
            'raw' => $card->desc,
        ],
        'customField1' => $card->id,
        '_links' => [
            'project' => [
                'href' => '/api/v3/projects/3'
            ],
            'type' => [
                'href' => '/api/v3/types/6'
            ],
            'status' => [
                'href' => '/api/v3/statuses/' . ($card->closed ? 12 : 1)
            ],
//            'parent' => [
//                'href' => '/api/v3/work_packages/<ID>'
//            ]
        ]
    ];
    $form = $requester('work_packages')('/form', $data);
    return $requester('work_packages')('', $data);
}

function openproject_create_attachments_under_workpackage(callable $requester, int $workpackage_id, array $attachments, callable $download) {
    foreach ($attachments as $attachment) {
        if (str_starts_with($attachment->url, \Stevenmaguire\Services\Trello\Configuration::get('domain'))) {
        $requester('work_packages')('/' . $workpackage_id . '/attachments', (object) [
                    'metadata' => [
                        'fileName' => $attachment->fileName,
                        'contentType' => $attachment->mimeType
                    ],
                    'file' => $download($attachment->url)
                ], 'multipart/form-data');
        } else {
        $requester('work_packages')('/' . $workpackage_id . '/activities', (object) [
                    'comment' => [
                        'raw' => $attachment->url
                    ],
                    'type' => 'markdown'
        ]);
        }
    }
}

function openproject_create_comments_under_workpackage(callable $requester, int $workpackage_id, array $actions) {
    foreach ($actions as $action) {
        if (!isset($action->data->text)) {
            continue;
        }

        $requester('work_packages')('/' . $workpackage_id . '/activities', (object) [
                    'comment' => [
                        'raw' => $action->data->text
                    ],
                    'type' => 'markdown'
        ]);
    }
}

function openproject_create_tasks_under_workpackage(callable $requester, int $workpackage_id, array $checklists) {
    // add checklists as tasks (type: 1, parent: created workpackage)
    foreach ($checklists as $checklist) {
        foreach ($checklist->checkItems as $checkItem) {
            echo PHP_EOL . 'Task, ' . $checkItem->name;
            $data = (object) [
                        'subject' => $checkItem->name,
                        'dueDate' => ($checkItem->due?DateTimeImmutable::createFromFormat('X-m-d\\TH:i:s.vP', $checkItem->due)->format('Y-m-d'):null),
                        '_links' => [
                            'project' => [
                                'href' => '/api/v3/projects/3'
                            ],
                            'type' => [
                                'href' => '/api/v3/types/1' // Task
                            ],
                            'parent' => [
                                'href' => '/api/v3/work_packages/' . $workpackage_id
                            ],
                            'status' => [
                                'href' => '/api/v3/statuses/' . ($checkItem->state === 'incomplete' ? '1' : '12')
                            ]
                        ]
            ];

            $requester('work_packages')('/form', $data);
            $requester('work_packages')('', $data);
        }
    }
}

$trello = trello(new Stevenmaguire\Services\Trello\Client(array(
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

$trello($_ENV['TRELLO_BOARD_ID'], $_ENV['TRELLO_LIST_NAME'], openproject($_ENV['OPENPROJECT_URL'], $_ENV['OPENPROJECT_TOKEN']));
