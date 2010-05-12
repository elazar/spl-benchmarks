<?php
$a = new SplObjectStorage;
for ($i = 0; $i < $argv[1]; $i++) {
    $object = new stdClass;
    $a->attach($object, $object);
}
