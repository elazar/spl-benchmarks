<?php
$a = new SplMinHeap; 
for($i = 0; $i < $argv[1]; $i++) {
    $a->insert(rand(1, $argv[1]));
}
for($i = 0; $i < $argv[1]; $i++) {
    $a->extract();
}
