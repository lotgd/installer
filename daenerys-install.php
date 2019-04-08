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

/**
 * Tries to parse cli options
 * @param array $argv
 * @param int $start
 * @return array
 */
function get_cli_options(array $argv, int $start = 2)
{
    $pos = 0;
    $options = [];
    $last_option = null;
    foreach ($argv as $arg) {
        $pos++;

        if ($pos <= $start) {
            continue;
        }

        if (substr($arg, 0, 2) == "--") {
            $last_option = substr($arg, 2);
            $options[$last_option] = True;
            continue;
        }

        if ($last_option === null) {
            $last_option = ".";
            $options[$last_option] = [];
        }

        if (is_bool($options[$last_option])) {
            $options[$last_option] = $arg;
        } elseif (is_array($options[$last_option])) {
            $options[$last_option][] = $arg;
        } else {
            $v = $options[$last_option];
            $options[$last_option] = [$v, $arg];
        }
    }

    return $options;
}

$argOptions = get_cli_options($argv);

$index = <<<'PHP'
<?php

use LotGD\Crate\WWW\Kernel;
use Symfony\Component\Debug\Debug;
use Symfony\Component\HttpFoundation\Request;

require __DIR__.'/vendor/autoload.php';

$_SERVER['APP_ENV'] = "prod";
$_SERVER['APP_DEBUG'] = false;


if ($_SERVER['APP_DEBUG']) {
    umask(0000);

    Debug::enable();
}

if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? false) {
    Request::setTrustedProxies(explode(',', $trustedProxies), Request::HEADER_X_FORWARDED_ALL ^ Request::HEADER_X_FORWARDED_HOST);
}

if ($trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? $_ENV['TRUSTED_HOSTS'] ?? false) {
    Request::setTrustedHosts([$trustedHosts]);
}

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
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
    disableAutoSchemaUpdate: false
game:
    epoch: 2016-07-01 00:00:00.0 -8
    offsetSeconds: 0
    daysPerDay: 1
logs:
    path: ../logs
YML;

$composer = <<<'JSON'
{
    "name": "local/test",
    "require": {
        "lotgd/crate-html": "dev-master"
    },
    "license": "AGPL3",
    "authors": [
        {
            "name": "Daenerys installation script",
            "email": "localhost@example.com"
        }
    ],
    "repositories": [
        {
            "type": "composer",
            "url": "https://raw.githubusercontent.com/lotgd/packages/master/build/packages.json"
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON;


out("Daenerys installer, version 0.2");

if($argc < 2) {
    out(<<<MSG
To use daenerys, you must use one of the following modes:
    - check, checks the environment if daenerys is installable
    - install, tries to install daenerys. Use --nointeraction to supress interactions.
MSG
);
    exit(1);
}

if ($argv[1] == "check") {
    out("Checking the environment");

    $checks = [
        "PHP" => ["7.1 or later", function () {
            return version_compare("7.1", PHP_VERSION, "<=");
        }],
        "Directory" => ["writable", function () {
            return fileperms('.') & 0x0080;
        }],
        "Composer" => ["available", function () {
            return command_exists("composer --version");
        }],
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

    // @ToDo: Let the user set some options here.
    $minVersion = "0.5.0-alpha";

    file_put_contents("composer.json", $composer);

    $output = run_command("composer install");

    if ($output[0] > 0) {
        print("It looks like something went wrong with composer install.\n\n");
        exit(1);
    }

    mkdir("config");
    mkdir("logs");
    mkdir("css");
    mkdir("icons");

    file_put_contents("index.php", $index);
    file_put_contents("config/lotgd.yml", $config);

    # Initialise database
    `vendor/bin/daenerys database:init`;

    if (!isset($argOptions["nointeraction"])) {
        $name = readline("Admin account name [admin]: ") ?: "admin";
        $password = readline("Password [changeme]: ") ?: "changeme";
        $email = readline("Email address for login [admin@example.com]: ") ?: "admin@example.com";

        `vendor/bin/daenerys crate:user:add --username="$name" --password="$password" --email="$email"`;
    } else {
        `vendor/bin/daenerys crate:user:add --username="admin" --password="changeme" --email="admin@example.com"`;
    }

    # Install assets
    `cp vendor/lotgd/crate-html/public/css/* css`;
    `cp vendor/lotgd/crate-html/public/icons/* icons`;

    # Install modules if given
    if (isset($argOptions["installDefaultModules"])) {
        $moduleList = [
            "lotgd/module-village",
            "lotgd/module-scene-bundle",
            "lotgd/module-new-day",
            "lotgd/module-gender",
            "lotgd/module-res-charstats",
            "lotgd/module-forest",
            "lotgd/module-training",
        ];
        $moduleList = implode(" ", $moduleList);
        `composer require $moduleList`;
    }

    `vendor/bin/daenerys database:schemaUpdate`;
    `vendor/bin/daenerys module:register`;
    `vendor/bin/console cache:clear`;
} elseif ($argv[1] == "install-module") {
    if (isset($argv[2])) {
        `composer update`;
        `composer require {$argv[2]}`;

        `vendor/bin/daenerys database:schemaUpdate`;
        `vendor/bin/daenerys module:register`;
        `vendor/bin/console cache:clear`;
    } else {
        print("Second argument must be a module.");
    }
} elseif ($argv[1] == "clean") {
    @remove_complete_dir("vendor");
    @remove_complete_dir("config");
    @remove_complete_dir("logs");
    @remove_complete_dir("css");
    @remove_complete_dir("icons");

    @unlink("composer.json");
    @unlink("composer.lock");
    @unlink("index.html");
    @unlink("bundle.js");
    @unlink("style.css");
} elseif ($argv[1] == "run-test") {
    # Runs a test server (do not use for production!)
    `php -S localhost:8000 -t .`;
} else {
    out("Unknown mode.\n");
}


print("\n\n");