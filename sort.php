<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 26.2.16
 * Time: 12.44
 */

/* bubble method */

$a = array(5,4,3,1,2);

for($j=0; $j < count($a)-1; $j++) {
    $i=0;
    while ($i < count($a)-1)  {
        if( $a[$i] > $a[$i+1]) {
            $c=$a[$i];
            $a[$i]=$a[$i+1];
            $a[$i+1]=$c;
        }
        ++$i;
    }
}

//print_r($a);

/* bubble method */

/**
 * @param array $ar
 * @return array|null
 */
function func(array $ar)
{
    $size = count($ar);
    for ($i = 0; $i < $size-1; ++$i) {
        for ($j = $i + 1; $j < $size; ++$j) {
            if ($ar[$i] === $ar[$j]) {
                return array(
                    array('index' => $i, 'value' => $ar[$i]),
                    array('index' => $j, 'value' => $ar[$j]),
                );
            }
        }
    }

    return null;
}

$ar = range(1, 1000);
$ar[] = rand(1, 1000);
shuffle($ar);

$val = func($ar);

//var_dump($ar);
var_dump($val);