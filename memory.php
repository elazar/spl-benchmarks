<?php

/**
 * This file is used by runner.php via auto_append_file to output memory 
 * usage for each test script.
 */

echo memory_get_peak_usage(), PHP_EOL;
