<?php
class Deployer {
    const INI_FILE = "deploy.ini";
    const INI_SEPARATOR = ":";
    static public $content;
    static public $json;
    static public $file;
    static public $token = false;
    static public $algo = false;
    //OPTIONS
    static public $full_name;
    static public $update_modules = true;
    static public $git;

    static public function retrieveCheckoutSha() {
        // retrieve the checkout_sha
        if (isset(self::$json["checkout_sha"])) return self::$json["checkout_sha"];
        if (isset($_SERVER["checkout_sha"])) return $_SERVER["checkout_sha"];
        if (isset($_GET["sha"])) return $_GET["sha"];
        return false;
    }
    static public function output($text, $code = 0, $echo = true) {
        fputs(self::$file, $text . PHP_EOL);
        if ($code) {
            http_response_code($code);
        }
        if ($echo) {
            echo $text . PHP_EOL;
        }
    }
    // function to forbid access
    static public function forbid($reason) {
        // write the error to the log and the body
        self::output("=== ERROR: " . $reason . " ===" . PHP_EOL . "*** ACCESS DENIED ***", 403);
    
        // close the log
        fclose(self::$file);
    
        // stop executing
        exit;
    }
    
    static public function checkToken($secret) {
        if (empty($secret)) return true;

        // Check for a GitHub signature
        if (isset($_SERVER["HTTP_X_HUB_SIGNATURE"])) {
            list($algo, $token) = explode("=", $_SERVER["HTTP_X_HUB_SIGNATURE"], 2) + array("", "");
            if ($token !== hash_hmac($algo, self::$content, $secret)) {
                return self::forbid("X-Hub-Signature does not match TOKEN");
            }
            return true;
        }

        // Check for a GitLab token
        if (isset($_SERVER["HTTP_X_GITLAB_TOKEN"])) {
            $token = $_SERVER["HTTP_X_GITLAB_TOKEN"];
            if ($token !== $secret) {
                return self::forbid("X-GitLab-Token does not match TOKEN");
            }
            return true;
        }

        // Check for a $_GET token
        if (isset($_GET["token"])) {
            $token = $_GET["token"];
            if ($token !== $secret) {
                return self::forbid('$_GET["token"] does not match TOKEN');
            }
            return true;
        }

        // if none of the above match, but a token exists, exit
        return self::forbid("No token detected");
    }
    static public function reparse($array) {
        if (!is_array($array)) return $array;
        $result = [];
        foreach($array as $key => $value) {
            $keys = preg_split("#\s*" . preg_quote(self::INI_SEPARATOR) . "\s*#", $key);
            $ptr = &$result;
            while (count($keys) > 1) {
                $k = array_shift($keys);
                if (!isset($ptr[$k])) {
                    $ptr[$k] = [];
                }
                $ptr = &$ptr[$k];
            }
            $ptr[$keys[0]] = self::reparse($value);
        }
        return $result;
    }
    static public function shell_exec($command, $label = "") {
        // write to the log
        self::output("*** {$label} ***");

        $output = shell_exec("{$command} 2>&1; echo $?");
        preg_match('#(?:\r\n|\n\r|\r|\n)([0-9]+)$#', $output, $res);
        $exit = $res[1];
        // var_export($res);
        $output = substr($output, 0, -strlen($res[0]));
        self::output(trim($output));

        // if an error occurred, return 500 and log the error
        if ($exit !== "0") {
            self::output("=== ERROR: '{$label}' failed using GIT `{$command}` ===", 500);
        }
    }

    static public function process($git, $repository) {
        $dir = $repository['dir'];
        if (substr($dir, -1) !== "/") {
            $dir .= "/";
        }
        if (!file_exists($dir)) {
            return self::output("=== ERROR: DIR `" . $dir . "` does not exist ===", 404);
        }
        if (!is_dir($dir)) {
            return self::output("=== ERROR: DIR `" . $dir . "` is not a directory ===", 404);
        }
        if (!file_exists($dir . ".git")) {
            return self::output("=== ERROR: DIR `" . $dir . "` is not a repository ===", 404);
        }
        // change directory to the repository
        chdir($dir);

        // write to the log
        self::output("*** AUTO PULL INITIATED ***");

        self::shell_exec($git . " status", "GIT STATUS");

        /**
         * Attempt to reset specific hash if specified
         */
        if (!empty($_GET["reset"]) && $_GET["reset"] === "true") {
            self::shell_exec($git . " reset --hard HEAD --force", "RESET TO HEAD");
        }

        /**
         * Attempt to execute BEFORE_PULL if specified
         */
        if (!empty($repository['before_pull'])) {
            self::shell_exec($repository['before_pull'], "BEFORE_PULL");
        }
        
        /**
         * Attempt to PULL
         */
        self::shell_exec($git . " pull", "PULL");

        /**
         * Attempt to checkout specific hash if specified
         */
        $sha = self::retrieveCheckoutSha();
        if (!empty($sha)) {
            self::shell_exec($git . " reset --hard {$sha}", "RESET TO HASH");
        }

        /**
         * Attempt to execute AFTER_PULL if specified
         */
        if (!empty($repository['after_pull'])) {
            self::shell_exec($repository['after_pull'], "AFTER_PULL");
        }

        if (self::$update_modules && file_exists($repository['dir'] . ".gitmodules")) {
            self::shell_exec($git . " submodule status", "GIT SUBMODULE STATUS");
            self::shell_exec($git . " submodule update --force", "GIT SUBMODULE UPDATE");
        }

        // write to the log
        self::output("*** AUTO PULL COMPLETE ***");
    }
    static public function exec($options = null) {
        if (is_null($options)) {
            $options = self::reparse(parse_ini_file(self::INI_FILE, true));
        }
        // specify that the response does not contain HTML
        $secret = $options['secret'];
        $repositories = (isset($options['repository'])) ? $options['repository'] : $options['repositories'];
        $logfile = $options['logfile'];
        self::$update_modules = (isset($options['update_modules']) && $options['update_modules'] !== "");
        self::$content = file_get_contents("php://input");
        self::$json = json_decode(self::$content, true);
        self::$file = fopen($logfile, "a");

        header("Content-Type: text/plain");
        
        // write the time to the log
        date_default_timezone_set("UTC");
        $time = time();
        self::output(date("d-m-Y (H:i:s)", $time));
        
        
        // use user-defined max_execution_time
        if (!empty($options['max_execution_time'])) {
            ini_set("max_execution_time", $options['max_execution_time']);
        }
        
        self::checkToken($secret);
        
        $full_name = self::$json["repository"]["full_name"];
        if (empty($repositories[$full_name])) {
            return self::output("=== ERROR: REPOSITORY `" . $full_name . "` not found ===", 404);
        }
        $repository = $repositories[$full_name];
        $branch = "refs/heads/master";
        if (!isset($repository['branch']) || $repository['branch'] === "") {
            $branch = $repository['branch'];
        }
        
        // check if pushed branch matches branch specified in config
        if (self::$json["ref"] !== $branch) {
            return self::output("=== ERROR: Pushed branch `" . self::$json["ref"] . "` does not match BRANCH `" . $branch . "` ===", 400);
        }

        self::process($options['git'], $repository);
        
        // close the log
        self::output(PHP_EOL);
        fclose(self::$file);
    }
}