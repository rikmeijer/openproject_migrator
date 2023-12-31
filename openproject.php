<?php

namespace rikmeijer\openproject;

require __DIR__ . '/vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('OPENPROJECT_TYPE_TASK', 1);

function request(string $openproject_url, string $openproject_token) : callable {
    return function(string $path, callable $loadRequest) use ($openproject_url, $openproject_token) : object|bool {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $openproject_url . $path);
        curl_setopt($ch, CURLOPT_USERPWD, "apikey:" . $openproject_token);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $expectedResponse = $loadRequest(function (string $method, string $contentType, ?string $data = null) use ($ch) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            
            $headers = [];
            $headers[] = 'Content-Type: ' . $contentType;
            if (is_null($data) === false) {
                $headers[] = 'Content-Length: ' . strlen($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            return $method === 'DELETE' ? [204, null] : [201, 'application/json'];
        });

        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        
        $return = curl_exec($ch);
        if ($return === false) {
            fwrite(STDERR, PHP_EOL . '[' . $path . '] http error: ' . curl_error($ch));
            return false;
        } elseif (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== $expectedResponse[0]) {
            fwrite(STDERR, PHP_EOL . '[' . $path . '] unexpected ('.$expectedResponse[0].') response: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
            fwrite(STDERR, PHP_EOL .  $return);
            return false;
        }
        
        switch ($expectedResponse[1]) {
            case 'application/json':
                $json = json_decode($return);
                if ($json === null) {
                    fwrite(STDERR, PHP_EOL . '[' . $path . '] invalid json: ' . $return);
                    return false;
                } elseif ($json->_type === "Error") {
                    fwrite(STDERR, PHP_EOL . '[' . $path . '] openproject error' . $json->errorIdentifier . ': ' . $json->message);
                    return false;
                }
                return $json;
               
                
            default:
                return $return === '' ?  true : $return;
        }
    };
}

function build_multipart(string $fileType, string $filename, callable $download, callable $post) {
    $delimiter = '-------------' . uniqid();

    $data = [];
    $data[] = "--" . $delimiter;
    $data[] = 'Content-Disposition: form-data; name="metadata"';
    $data[] = 'Content-Type: application/json';
    $data[] = "";
    $data[] = json_encode(['fileName' => $filename, 'contentType' => $fileType]);

    $contents = $download($fileType);
    $data[] =  "--" . $delimiter;
    $data[] =  'Content-Disposition: form-data; name="file"; filename="' . $filename . '"';
    $data[] =  'Content-Type: ' . $fileType;
    $data[] =  'Content-Transfer-Encoding: binary';
    $data[] =  "";
    $data[] =  $contents;
    
    $data[] =  "--" . $delimiter . "--";
    return $post('POST', 'multipart/form-data; boundary=' . $delimiter, join("\r\n", $data));
}

function connect(callable $requester): callable {
    return fn(string $path, array $query = []) => function (?string $contentType = null, ?array $postdata = null, ?callable $download = null) use ($requester, $path, $query) {
        return $requester($path . '?' . http_build_query($query), match ($contentType) {
            'application/json' => fn(callable $post) => $post('POST', $contentType, json_encode($postdata)),
            'multipart/form-data' => fn(callable $post) => build_multipart($postdata['type'], $postdata['name'], $download, $post),
            default => fn (callable $post) => [200, 'application/json']
        });
    };
}

function delete_workpackage(callable $request, object $workpackage) {
    return $request($workpackage->_links->delete->href, fn(callable $post) => $post(strtoupper($workpackage->_links->delete->method), 'application/json'));
}

function delete_attachment(callable $request, object $attachment) {
    return $request($attachment->_links->delete->href, fn(callable $post) => $post(strtoupper($attachment->_links->delete->method), 'application/json'));
}

function list_workpackages(callable $requester, int $pageSize, int $offset) : array {
    $workpackages = $requester('/api/v3/projects/' . $_ENV['OPENPROJECT_PROJECT_ID'] . '/work_packages', [
        'pageSize' => $pageSize,
        'offset' => $offset,
        'filters' => json_encode([['subject'=>["operator" => "~", "values" => ['20']]]])
    ])();
    return $workpackages->_embedded->elements;
}

function keep_find_attachment(string &$filetype, string $filepath) {
    if (file_exists($filepath)) {
        return $filepath;
    } elseif (in_array($filetype, ['image/png', 'application/octet-stream']) === false) {
        exit('File `' . $filepath . '` ('.$filetype.') not found');
    }
    
    $basename = basename($filepath, '.png');
    $jpeg_path = dirname($filepath) . DIRECTORY_SEPARATOR . $basename . '.jpg';
    if (file_exists($jpeg_path)) {
        $filetype = 'image/jpeg';
        return $jpeg_path;
    }
    exit('File `' . $filepath . '` or `'. $jpeg_path .'` not found');
}
function keep_get_contents(string &$filetype, string $filepath) {
    return file_get_contents(keep_find_attachment($filetype, $filepath));
}

function item_already_migrated(callable $requester, string $item_id) {
    $workpackages = $requester('/api/v3/projects/' . $_ENV['OPENPROJECT_PROJECT_ID'] . '/work_packages', ['filters' => json_encode([(object)[$_ENV['OPENPROJECT_MIGRATION_FIELD']=>(object)["operator" => "=", "values" => [$item_id]]]])])();
    return $workpackages->count > 0 ? $workpackages->_embedded->elements[0] : null;
}

function get_workpackage(callable $requester, string $workpackage_id) {
    return $work_package_tasks = $requester('/api/v3/work_packages/' . $workpackage_id)();
}

function list_workpackage_attachments(callable $requester, object $workpackage) {
    return $requester($workpackage->_links->attachments->href)()->_embedded->elements;
}
function list_workpackage_tasks(callable $requester, object $workpackage) {
    return $requester('/api/v3/projects/' . $_ENV['OPENPROJECT_PROJECT_ID'] . '/work_packages', ['filters' => json_encode([
        (object)[
            'parent' => (object)["operator" => "=", "values" => [$workpackage->id]],
            'type_id' => (object)["operator" => "=", "values" => [OPENPROJECT_TYPE_TASK]]
        ]
    ])])()->_embedded->elements;
}

function filter_existing_attachments(callable $requester, object $work_package, array $attachments, callable $filter) : array {
    $workpackage_attachments = list_workpackage_attachments($requester, $work_package);
    return array_filter($attachments, function($attachment) use ($workpackage_attachments, $filter) {
        foreach ($workpackage_attachments as $workpackage_attachment) {
            if ($filter($workpackage_attachment, $attachment)) {
                return false;
            }
        }
        return true;
    });
}
function filter_existing_tasks(callable $requester, object $work_package, array &$tasks, callable $filter) : array {
    $work_package_tasks = list_workpackage_tasks($requester, $work_package);
    return array_filter($tasks, function($task) use ($work_package_tasks, $filter) {
        foreach ($work_package_tasks as $work_package_task) {
            if ($filter($work_package_task, $task)) {
                return false;
            }
        }
        return true;
    });
}

function create_workpackage(callable $requester, string $id, string $name, string $description, bool $closed, ?int $parent_id, ?\DateTimeImmutable $start, ?\DateTimeImmutable $due) {
    // types: 1 = task, 4 = feature, 2,3 = N/A, 5 = epic, 6 = us
    $data = [
                'subject' => $name,
                'startDate' => ($start ? $start->format('Y-m-d') : null),
                'dueDate' => ($due ? $due->format('Y-m-d') : null),
                'description' => [
                    'format' => 'markdown',
                    'raw' => $description,
                ],
                'customField1' => $id,
                '_links' => [
                    'type' => [
                        'href' => '/api/v3/types/6'
                    ],
                    'status' => [
                        'href' => '/api/v3/statuses/' . ($closed ? 12 : 1)
                    ]
                ]
    ];
    if (isset($parent_id)) {
        $data['parent'] = [
                'href' => '/api/v3/work_packages/' . $parent_id
            ];
    }
    $requester('/api/v3/projects/' . $_ENV['OPENPROJECT_PROJECT_ID'] . '/work_packages/form')('application/json', $data);
    return $requester('/api/v3/projects/' . $_ENV['OPENPROJECT_PROJECT_ID'] . '/work_packages')('application/json', $data);
}

function create_attachment_under_workpackage(callable $requester, int $workpackage_id, string $name, string $type, callable $download) : object|bool {
    return $requester('/api/v3/work_packages/' . $workpackage_id . '/attachments')('multipart/form-data', [
                'name' => $name,
                'type' => $type
            ], $download);
}

function create_comment_under_workpackage(callable $requester, int $workpackage_id, string $content) : object|bool {
    return $requester('/api/v3/work_packages/' . $workpackage_id . '/activities')('application/json', [
                'comment' => [
                    'raw' => $content
                ],
                'type' => 'markdown'
    ]);
}

function create_task_under_workpackage(callable $requester, int $workpackage_id, string $subject, bool $closed, ?DateTimeImmutable $dueDate) {
    // add checklists as tasks (type: 1, parent: created workpackage)
    $data = [
                'subject' => $subject,
                'dueDate' => ($dueDate ? $dueDate->format('Y-m-d') : null),
                '_links' => [
                    'type' => [
                        'href' => '/api/v3/types/' . OPENPROJECT_TYPE_TASK
                    ],
                    'parent' => [
                        'href' => '/api/v3/work_packages/' . $workpackage_id
                    ],
                    'status' => [
                        'href' => '/api/v3/statuses/' . ($closed ? '12' : '1')
                    ]
                ]
    ];

    $requester('/api/v3/projects/' . $_ENV['OPENPROJECT_PROJECT_ID'] . '/work_packages/form')('application/json', $data);
    return $requester('/api/v3/projects/' . $_ENV['OPENPROJECT_PROJECT_ID']. '/work_packages')('application/json', $data);
}
