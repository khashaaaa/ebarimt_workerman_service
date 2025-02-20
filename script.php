<?php

use Workerman\Worker;
use Workerman\Timer;

require_once 'vendor/autoload.php';

define('START_FOLDER', 1);
define('END_FOLDER', 450);
define('SOURCE_FOLDER', '00001');

function copyFolder($source, $destination)
{
    if (!is_dir($source)) {
        echo "Source folder does not exist: $source\n";
        return;
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }

    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcFile = $source . DIRECTORY_SEPARATOR . $file;
        $destFile = $destination . DIRECTORY_SEPARATOR . $file;

        if (is_dir($srcFile)) {
            copyFolder($srcFile, $destFile);
        } else {
            copy($srcFile, $destFile);
        }
    }
    closedir($dir);
}

$worker = new Worker();
$worker->onWorkerStart = function () {
    for ($i = START_FOLDER; $i <= END_FOLDER; $i++) {
        $folderName = sprintf('%05d', $i);
        $destFolder = $folderName;

        copyFolder(SOURCE_FOLDER, $destFolder);
        echo "$folderName: completed\n";
    }

    echo "All folders have been processed.\n";
    Worker::stopAll();
};

Worker::runAll();
