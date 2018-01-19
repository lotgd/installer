#!/usr/bin/php
<?php
/**
 * Installer script for daenerys
 */

// @ToDo: Add check to abort this script of not cli.

/**
 * @param string $text
 */
function out(string $text, int $tab = 0)
{
    print(str_repeat("\t", $tab));
    print($text . "\n");
}

/**
 * Returns true if a command successfully runs (= should exist)
 * @param $command
 * @return bool
 */
function command_exists($command)
{
    $return_val = 0;
    $output = "";
    $return = exec($command . " 2> /dev/null", $output, $return_val);
    return $return_val === 0;
}

/**
 * Runs a command and returns the return value and the captured output
 * @param $command
 * @return array
 */
function run_command($command)
{
    $return_val = 0;
    $output = "";
    $return = exec($command . "", $output, $return_val);
    return [$return_val, $output];
}

/**
 * Removes a complete directory
 * @param $directory
 * @return bool
 */
function remove_complete_dir($directory)
{
    $files = scandir($directory);
    foreach ($files as $file) {
        if ($file === "." or $file === "..") {
            continue;
        }

        $file = "$directory/$file";

        if (is_dir($file)) {
            remove_complete_dir($file);
            continue;
        }

        unlink($file);
    }

    return rmdir($directory);
}

$index = <<<'PHP'
<?php

use Symfony\Component\HttpFoundation\Request;

/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require __DIR__.'/vendor/lotgd/crate-graphql/app/autoload.php';

$kernel = new AppKernel('prod', false);
//$kernel->loadClassCache();
//$kernel = new AppCache($kernel);

// When using the HttpCache, you need to call the method in your front controller instead of relying on the configuration parameter
//Request::enableHttpMethodParameterOverride();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
PHP;

$config = <<<'YML'
database:
    dsn: "sqlite:%cwd%config/daenerys-run.db3"
    name: daenerys
    user: root
    password:
game:
    epoch: 2016-07-01 00:00:00.0 -8
    offsetSeconds: 0
    daysPerDay: 1
logs:
    path: ../logs
YML;



out("Daenerys installer, version 0.1");

if($argc < 2) {
    out(<<<MSG
To use daenerys, you must use one of the following modes:
    - check, checks the environment if daenerys is installable
    - install, tries to install daenerys
MSG
);
    exit(1);
}

if ($argv[1] == "check") {
    out("Checking the environment");

    $checks = [
        "PHP" => ["7.1 or later", function(){return version_compare("7.1", PHP_VERSION, "<=");}],
        "Directory" => ["writable", function(){return fileperms('.') & 0x0080;}],
        "Composer" => ["available", function(){return command_exists("composer --version");}],
    ];

    $faults = 0;

    foreach ($checks as $what => [$adjective, $callback]) {
        if ($callback()) {
            out(sprintf("[+] %s is %s.", $what, $adjective), 1);
        } else {
            out(sprintf("[-] %s is not %s.", $what, $adjective), 1);
            $faults++;
        }
    }

    if ($faults > 0) {
        print("Not all checks passed\n\n");
        exit(1);
    }
} elseif ($argv[1] == "install") {
    out("Installation of daenerys");

    $output = run_command("composer init --no-interaction "
        . "--repository https://code.lot.gd "
        . "--require \"lotgd/crate-graphql:dev-version/0.4\" \"lotgd/core:dev-version/0.4\" "
        . "--name \"local/test\" "
        . "--author \"Daenerys installation script <localhost@example.com>\" "
        . "--license \"AGPL3\" "
        . "--stability dev"
    );

    $output = run_command("composer install");

    mkdir("config");
    mkdir("logs");

    file_put_contents("index.php", $index);
    file_put_contents("config/lotgd.yml", $config);
} elseif ($argv[1] == "clean") {
    @remove_complete_dir("vendor");
    @remove_complete_dir("config");
    @remove_complete_dir("logs");

    @unlink("composer.json");
    @unlink("composer.lock");
    @unlink("index.php");
} elseif ($argv[1] == "run-test") {
    # Runs a test server (do not use for production!)
    `php -S localhost:8000 -t .`;
} else {
    out("Unknown mode.\n");
}


print("\n\n");