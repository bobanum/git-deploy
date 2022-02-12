<?php
require_once("Deployer.class.php");
Deployer::exec(/* [
    "secret" => "c'est mon secret",                                 // The secret token to add as a GitHub or GitLab secret, or otherwise as https://www.example.com/?token=secret-token
    "remote_repository" => "git@github.com:bobanum/gvalue-vue.git", // The SSH URL to your repository
    "logfile" => "deploy.log",                                      // The name of the file you want to log to.
    "git" => "git",                                                 // The path to the git executable
    "max_execution_time" => 180,                                    // Override for PHP's max_execution_time (may need set in php.ini)
    "repositories" => [
        "bobanum/gvalue-vue" => [
            "dir" => "/home/mboudrea/public_html/gvalue-vue",               // The path to your repostiroy; this must begin with a forward slash (/)
            "branch" => "refs/heads/master",                                // The branch route
            "before_pull" => "git reset --hard @{u}",                       // A command to execute before pulling
            "after_pull" => "",                                             // A command to execute after successfully pulling
        ],
    ],
] */);
