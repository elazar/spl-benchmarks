<?php
$threshold = $argv[1] * 0.1;
$a = array();
$i = 0;
do {
    if ($i <= $argv[1]) {
        $a[] = array($i, rand(1, 10));
        usort($a, 'priority_sort');
    }
    if ($i > $threshold) {
        array_shift($a);
    }   
    $i++;
} while (count($a));
function priority_sort($a, $b) {
    return $a[1] - $b[1];
}
