<?php
$threshold = $argv[1] * 0.1;
$a = new SplPriorityQueue;
$i = 0;
do {
    if ($i <= $argv[1]) {
        $a->insert($i, rand(1, 10));
    }
    if ($i > $threshold) {
        $a->extract();
    }   
    $i++;
} while (count($a));
