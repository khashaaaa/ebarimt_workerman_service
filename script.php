<?php

define('START_FOLDER', 2);
define('END_FOLDER', 450);
define('BASE_PORT', 9000);
define('SOURCE_FOLDER', '00001');
define('INI_FILENAME', 'posapi.ini');
define('WORKERMAN_SERVICE', 'ebarimt_workerman_service');

function updatePosapiIni($folderPath, $folderName, $portNumber)
{
    $iniPath = $folderPath . DIRECTORY_SEPARATOR . INI_FILENAME;

    if (!file_exists($iniPath)) {
        echo "INI file not found in $folderPath\n";
        return;
    }

    $content = file_get_contents($iniPath);
    $content = str_replace('name=vatps_00001.db', "name=vatps_$folderName.db", $content);
    $content = str_replace('port=9001', "port=$portNumber", $content);

    file_put_contents($iniPath, $content);
}

function copyFolder($source, $destination)
{
    if (!is_dir($source)) {
        echo "Source folder does not exist: $source\n";
        return;
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }

    foreach (scandir($source) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcFile = $source . DIRECTORY_SEPARATOR . $file;
        $destFile = $destination . DIRECTORY_SEPARATOR . $file;

        if (is_dir($srcFile)) {
            copyFolder($srcFile, $destFile);
        } else {
            copy($srcFile, $destFile);
            chmod($destFile, 0777);
        }
    }
}

for ($i = START_FOLDER; $i <= END_FOLDER; $i++) {
    $folderName = sprintf('%05d', $i);
    $destFolder = $folderName;
    $portNumber = BASE_PORT + $i;

    copyFolder(SOURCE_FOLDER, $destFolder);
    updatePosapiIni($destFolder, $folderName, $portNumber);

    echo "$folderName: completed\n";
}

$user = getenv('USER') ?: 'www-data';
$projectDir = __DIR__;
$parentDir = dirname($projectDir);

exec("cd $parentDir && sudo chown -R $user:$user " . WORKERMAN_SERVICE);

echo "Ownership updated for " . WORKERMAN_SERVICE . " from outside the project directory.\n";
echo "All folders have been processed.\n";
