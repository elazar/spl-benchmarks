<?php
$a = array();
for ($i = 0; $i < $argv[1]; $i++) {
    $object = new stdClass;
    $a[spl_object_hash($object)] = $object;
}
$a = array();
$b = array();
for ($i = 0; $i < $argv[1]; $i++) {
    $a[] = rand(1, $argv[1]);
    $b[] = rand(1, $argv[1]);
}
$c = array_merge($a, $b);
$c = array_diff($a, $b);
