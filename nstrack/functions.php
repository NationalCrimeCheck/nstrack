<?php
/*
* NSTrack, Copyright (C) 2015 Karmabunny Web Design
* Written by Benno Lang
* Released under the GPL v3 with NO WARRANTY
*/


/**
 * Converts a token (from token_get_all) to a readable, one line string
 * @param string|array $tok The token
 * @return string
 */
function token_str($tok) {
    if (!is_array($tok)) return "'" . addslashes($tok) . "'";
    $ret = token_name($tok[0]) . ':';
    if (is_array($tok[1])) {
        $ret .= implode(' ', $tok[1]);
    } else {
        $ret .= $tok[1];
    }
    return $ret;
}


/**
 * Removes the namespace from a class/interface name
 * e.g. rm_class_ns(\Awesome\Brilliant\Thing) -> Thing
 * @param string $class
 * @return string
 */
function rm_class_ns($class) {
    $pos = strrpos($class, '\\');
    if ($pos === false) return $class;
    return substr($class, $pos + 1);
}


/**
 * Finds the shortest element of an array
 */
function array_shortest($arr) {
    $len = 10000;
    $short = false;
    foreach ($arr as $el) {
        if (strlen($el) >= $len) continue;
        $short = $el;
        $len = strlen($el);
    }
    return $short;
}


/**
 * Adds a use block to a file
 * @param string $block A block of 'use ...;' statements
 * @param string $file Path to the file to read and then overwrite
 * @return void writes to the specified file
 */
function write_use_block($block, $file) {
    $lines = file($file);
    $in_php = false;
    $in_comment = false;
    $code_start = -1;
    $ns = -1;
    $use_start = -1;
    $use_end = -1;
    foreach ($lines as $num => $line) {
        if (preg_match('/^\s*<\?php.*?>\s*$/', $line)) {
            $code_start = $num ? $num - 1 : 0;
            $in_php = false;
            break;
        }

        if (preg_match('/^\s*<\?php/', $line)) {
            $in_php = true;
            continue;
        }
        if (preg_match('/^\s*$/', $line)) continue;

        if ($in_php) {

            // Skim to the end of a comment
            if ($in_comment) {
                if (preg_match('!\*/!', $line)) {
                    $in_comment = false;
                }
                continue;
            }

            // Ignore one-line comments
            if (preg_match('!^\s*(/\*.*\*/|//.*)$!', $line)) {
                continue;
            }

            // Start of comment
            if (preg_match('!^\s*/\*!', $line)) {
                if ($use_start > 0) {
                    $use_end = $num;
                    break;
                }
                $in_comment = true;
                continue;
            }

            // Position of namespace declaration
            if (preg_match('/^\s*namespace\s/', $line)) {
                $ns = $num;
                continue;
            }

            // Position of first use statement
            if (preg_match('/^\s*use\s/', $line)) {
                if ($use_start < 0) $use_start = $num;
                continue;
            }

            // Position after all use statements
            $use_end = $num;
        }

        $code_start = $num;
        break;
    }

    if ($use_start > 0) {
        $start = rtrim(implode('', array_slice($lines, 0, $use_start)));
        if ($ns > 0) {
            $start .= "\n\n";
        } else {
            $start .= "\n";
        }
        $end = "\n\n" . ltrim(implode('', array_slice($lines, $use_end)));
    } else if ($ns > 0) {
        $start = rtrim(implode('', array_slice($lines, 0, $ns + 1))) . "\n\n";
        $end = "\n\n" . ltrim(implode('', array_slice($lines, $ns + 1)));
    } else if ($code_start >= 0) {
        $end = ltrim(implode('', array_slice($lines, $code_start)));
        if ($in_php) {
            $start = rtrim(implode('', array_slice($lines, 0, $code_start)));
            if ($start != '<?php') $start .= "\n";
            $start .= "\n";
            if (substr($end, 0, 2) != '?>') $end = "\n\n" . $end;
        } else {
            $start = "<?php\n";
            $end = "?>\n" . $end;
        }
    } else {
        $err = "Unable to determine use block position\n";
        $err .= "in_php: $in_php\n";
        $err .= "code_start: $code_start\n";
        $err .= "ns: $ns\n";
        $err .= "use_start: $use_start\n";
        $err .= "use_end: $use_end\n";
        die($err);
        throw new Exception($err);
    }
    $new_content = $start . $block . $end;

    file_put_contents($file, $new_content);
}


/**
 * Generates a ParsedFile from cached data
 * @param array $cache_data An entry from the cache file
 * @return ParsedFile File info
 */
function load_cached_file(array $cache_data)
{
    $parsed_file = new ParsedFile($cache_data['file']);
    $parsed_file->mtime = $cache_data['mtime'];
    $parsed_file->namespaces = $cache_data['namespaces'];
    $parsed_file->classes = $cache_data['classes'];

    // Convert arrays to ClassRef objects
    $parsed_file->refs = [];
    foreach ($cache_data['refs'] as $ref) {
        $class_ref = new ClassRef($ref['class'], $ref['line'], $ref['key']);
        $parsed_file->refs[] = $class_ref;
    }

    // Convert arrays to UseStatement objects
    $parsed_file->uses = [];
    foreach ($cache_data['uses'] as $use) {
        $use_st = new UseStatement($use['entity'], $use['alias']);
        $parsed_file->uses[] = $use_st;
    }

    return $parsed_file;
}


/**
 * Checks that a call to json_encode was successful
 * @param mixed $json Result of json_encode
 * @return void
 * @throws Exception Containing error message
 */
function json_fail($json)
{
    if ($json !== false) return;

    switch (json_last_error()) {
        case JSON_ERROR_NONE:
            return;
        case JSON_ERROR_DEPTH:
            $err = 'Maximum stack depth exceeded';
        break;
        case JSON_ERROR_STATE_MISMATCH:
            $err = 'Underflow or the modes mismatch';
        break;
        case JSON_ERROR_CTRL_CHAR:
            $err = 'Unexpected control character found';
        break;
        case JSON_ERROR_SYNTAX:
            $err = 'Syntax error, malformed JSON';
        break;
        case JSON_ERROR_UTF8:
            $err = 'Malformed UTF-8 characters, possibly incorrectly encoded';
        break;
        default:
            $err = 'Unknown error';
        break;
    }
    throw new Exception($err);
}
