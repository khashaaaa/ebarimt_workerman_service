<?php

define('START_FOLDER', 1);
define('END_FOLDER', 2);
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
        }
    }
}

function updateIniFile($folderName)
{
    $iniFilePath = $folderName . DIRECTORY_SEPARATOR . 'posapi.ini';

    if (!file_exists($iniFilePath)) {
        echo "INI file not found in $folderName\n";
        return;
    }

    $iniContent = file_get_contents($iniFilePath);

    $iniContent = preg_replace('/name=vatps_\d{5}\.db/', 'name=vatps_' . $folderName . '.db', $iniContent);

    $newPort = 9000 + (int)$folderName;

    $iniContent = preg_replace('/port=\d{4}/', 'port=' . $newPort, $iniContent);

    file_put_contents($iniFilePath, $iniContent);
}

for ($i = START_FOLDER; $i <= END_FOLDER; $i++) {
    $folderName = sprintf('%05d', $i);
    $destFolder = $folderName;

    copyFolder(SOURCE_FOLDER, $destFolder);

    updateIniFile($folderName);

    echo "$folderName: completed\n";
}

echo "All folders have been processed.\n";
