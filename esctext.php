<?php  
  
    $color_scheme = 'html';

    if (isset($argv))
        $color_scheme = 'cli';
    function colorize_msg($msg) {
        global $color_scheme;
        if ('cli' == $color_scheme) {
            $msg = preg_replace_callback("|~C([34]8)#([0-9A-Fa-f]{6})|",
                        function($m) {
                        list($r, $g, $b) = sscanf($m[2], '%2x%2x%2x');
                        return "\033[{$m[1]};2;$r;$g;{$b}m";
                        }, $msg);

            $cmsg = preg_replace('/~C(1*\d\d)/', "\033".'[$1m', $msg);
            if ($cmsg !== $msg) $cmsg .= "\033[0m"; // reset all at end
        } 
        elseif ('html' == $color_scheme) {
        $cmsg = str_replace('~C00', "</font>", $msg); 
        $cmsg = preg_replace('/~C(1*\d\d)/', "<font class=cl$1>", $cmsg);      
        if (false !== strpos($cmsg, '<font') && false === strpos($cmsg, '</font>'))  $cmsg .= '</font>';
        }


        return $cmsg;
    
    }
    
    function format_color() {
        global $color_scheme;
        $args = func_get_args();
        $fmt = array_shift($args);
        // $fmt = str_ireplace('%d', '~C95%d~C00', $fmt);     
        $fmt = preg_replace('/(%[-\d]*s)/', '~C92$1~C00', $fmt); 
        $fmt = preg_replace('/(%[-\dl]*[du])/','~C95$1~C00', $fmt);  // 
        $fmt = preg_replace('/(%[-\.\d]*[fF])/','~C95$1~C00', $fmt);
        $fmt = preg_replace('/(%[-\.\d]*[gG])/','~C95$1~C00', $fmt);
        if (count($args) > 0)
        $msg = sprintf($fmt, ...$args);
        else
        $msg = $fmt;
        
        if ('none' == $color_scheme)
            return $msg;
        else
            return colorize_msg($msg);    
    }
    
    function format_uncolor() {
        $args = func_get_args();
        $msg = sprintf(...$args);
        return preg_replace('/~C(\d\d)/', '', $msg);
    }      
    
    function log_cmsg() {    
        $args = func_get_args();
        $fmt = array_shift($args);
        $msg = format_color($fmt, ...$args);
        log_msg('%s', $msg);
    } 

    function esc_color_styles($tags) {
        $result = '';
        if($tags) $result .= "\t<style type='text/css'>\n";
        $result .= " .cl00 { color: gray; }\n ";
        $result = " .cl91 { color: #FF8080; }\n ";
        $result = " .cl92 { color: lime; }\n ";
        $result = " .cl93 { color: yellow; }\n ";
        $result = " .cl94 { color: #8080FF; }\n ";
        $result = " .cl95 { color: #FF80FF; }\n ";
        $result = " .cl96 { color: #00FFFF; }\n ";
        $result = " .cl97 { color: white; }\n ";
        if($tags) $result = "\t</style>\n";
        return $result;
    } 

    function error_exit() {
        global $color_scheme;        
        log_cmsg(...func_get_args());        
        $color_scheme = 'cli'; 
        $msg = format_uncolor(...func_get_args());
        $trace = format_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 'basic', 1);        
        error_log("$msg ".trim($trace));
        die(-1);  
    }
?>