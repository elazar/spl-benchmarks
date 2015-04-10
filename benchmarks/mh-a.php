<?php
$a = array();
for($i = 0; $i < $argv[1]; $i++) {
    $a[] = rand(1, $argv[1]);
    sort($a);
}
for($i = 0; $i < $argv[1]; $i++) {
    array_shift($a);
}
