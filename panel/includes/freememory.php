<?php
/*
 * Deallocate the space consumed by the user defined variables
 * Making the variables value to NULL instead of using unset,
 * since Making the value to NULL will free the memory that time itself
 * Require this file at the end of your php file so that space consumed by-
 * all user defined variables will be freed
 * @link http://sanjaykumarns.blogspot.in/p/freeing-memory-with-php-var-null.html
 * @author  Sanjay Kumar N S <sanjaykumarns@gmail.com>
 * @date  17-JULY-2014
 */


// diff the ignore list as keys after merging any missing ones with the defined list
$definedVariablesArr = array_diff_key(get_defined_vars() + array_flip($ignore), array_flip($ignore)); //user defined var(s)
// should be left with the user defined var(s)

//In order to display the amount of memory allocated to PHP before unsetting, uncomment the below line
//echo "\nBefore:" . (memory_get_usage() / 1e+6) . " Bytes\n";

$definedVariablesArr = array_keys($definedVariablesArr); // take keys of the array ie the variable names

//loop through the variables and set the value to NULL
foreach($definedVariablesArr AS $var) {
    ${$var} = NULL;
}


// manually unsetting the localscope variables used in theis file
$definedVariablesArr = NULL; 
$var                 = NULL;

//In order to display the amount of memory allocated to PHP after unsetting, uncomment the below line
//echo "After:" . (memory_get_usage() / 1e+6) . " Bytes\n";

?>