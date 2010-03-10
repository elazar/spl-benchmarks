<?php
$a = array();
for ($i = 0; $i < $argv[1]; $i++) {
    $object = new stdClass;
    $a[spl_object_hash($object)] = $object;
}
