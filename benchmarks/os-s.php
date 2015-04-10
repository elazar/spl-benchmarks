<?php
$a = new SplObjectStorage;
for ($i = 0; $i < $argv[1]; $i++) {
    $object = new stdClass;
    $a->attach($object, $object);
}
$a = new SplObjectStorage;
$b = new SplObjectStorage;
for ($i = 0; $i < $argv[1]; $i++) {
    $a->attach((object) rand(1, $argv[1]));
    $b->attach((object) rand(1, $argv[1]));
}
$c = clone $a;
$c->addAll($b);
$c = clone $a;
$c->removeAll($b); 
