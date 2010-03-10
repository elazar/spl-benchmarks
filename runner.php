<?php

/**
 * This script executes the SPL test scripts located in the tests 
 * subdirectory, measure the average execution time and memory usage for a 
 * specified number of executions, and records it in a CSV file for later 
 * use.
 */

define('PHP_CGI_PATH', '/home/matt/Downloads/php-5.3.2/build/php_build/bin/php-cgi');
define('EXECUTIONS', 20);

$elements = array(10, 100, 500, 1000, 5000);
$files = glob('tests/*.php');

$log = fopen('results/raw.csv', 'w');
fputcsv($log, array('Elements', 'File', 'Time', 'RPS', 'Memory'));

$descriptor = array(
    0 => array('pipe', 'r'), // stdin
    1 => array('pipe', 'w'), // stdout
    2 => array('pipe', 'w')  // stderr
);
$pipes = array();

foreach ($elements as $count) {
    foreach ($files as $file) {
        echo $file, ' ', $count, PHP_EOL;

        $cmd = PHP_CGI_PATH . ' -d max_execution_time=0 -d auto_append_file="' . dirname(__FILE__) . '/memory.php" -q -T ' . EXECUTIONS . ' ' . $file . ' ' . $count;
        $process = proc_open($cmd, $descriptor, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        $lines = explode(PHP_EOL, $stdout);
        $total = array_sum($lines);
        $memory = $total / EXECUTIONS;

        $fields = explode(' ', trim($stderr));
        $total = $fields[2];
        $time = $total / EXECUTIONS;
        $rps = 1 / $time;

        fputcsv($log, array(
            $count,
            basename($file),
            $time,
            $rps,
            $memory
        ));
    }
}

fclose($log);
