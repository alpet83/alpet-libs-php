<?php
    //  date_default_timezone_set("Asia/Nicosia");
    $log_indent = '';  
    $script_name = 'default';

    if (php_sapi_name() == 'cli') {
      $script_name = basename(strtolower($argv[0]), '.php');          
      ini_set('display_errors', true);
    }
    elseif (isset($_SERVER['SCRIPT_NAME'])) {
      $script_name = basename(strtolower($_SERVER['SCRIPT_NAME']), '.php');
    }


    $err_log = "$script_name.errors.log";      
    if (file_exists('logs') && is_dir('logs'))
        $err_log = "logs/$err_log";

    $err_count = 0;
    $curl_last_error = '';
    $curl_resp_header = '';
    
    class CurlOptions {
        public $connect_timeout = 20;
        public $total_timeout =  40;    
        public $extra = array();
    }    
    
    $ws_recv      = false; 
    $session_logs = [];

    define ('SQL_TIMESTAMP_MS', 'Y-m-d H:i:s.q');

    $remote_addr = 'localhost';
    if (isset($_SERVER) && isset($_SERVER['REMOTE_ADDR']))
        $remote_addr = $_SERVER['REMOTE_ADDR'];

    error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

    function is_posval($val) {
        return ($val > 0);
    }
    function is_negval($val) {
        return ($val < 0);
    }

    /** Return value sign */
    function signval(float $val): int {
        if (0 == $val) return 0;
        return ($val > 0 ? 1 : -1);
    }
    function same_sign() {
        $args = func_get_args();

        if (0 == count($args)) return false;
        $ref = signval($args[0]);
        foreach ($args as $arg)
          if ($ref != signval($arg)) return false;
        return true;
    }

    
    /**
     * Determine if a string contains a given substring
     * Performs a case-insensitive check indicating if `needle` is contained in `haystack`. Alternative to `str_contains`
     *
     * @param string $haystack The string to search in.
     * @param string $needle The substring to search for in the `haystack`.
     * @return bool Returns `true` if `needle` is in `haystack`, `false` otherwise.
     */
    function str_in(string $haystack, string $needle, int $offset = 0): bool {      
      return false !== stripos($haystack, $needle,$offset);
    }
    
    /** Generate local timestamp string with milliseconds precision */
    function tss(): string
    {
        list($usec, $sec) = explode(" ", microtime()); 
        $usec = str_replace('0.', '.', $usec);
        $usec = str_replace('1.', '.', $usec);     
        $ts = date('y-m-d H:i:s').substr($usec, 0, 4);
        return "[$ts]";
    }
    /** Generate DateTime object with UTC timezone */  
    function utc_time($ts = 'now')
    {
        return new DateTime ($ts, new DateTimeZone('UTC'));
    }

    function str_ts($ts = 'now', $tz = null)
    {
        $date = new DateTime($ts, $tz); // ('now', 'Europe/Moscow');
        list($usec, $sec) = explode(" ", microtime());
        
        $usec = sprintf('%.3f', $usec);
        $usec = str_replace('0.', '', $usec); //
        $usec = str_replace('1.', '', $usec); //
        return $date->format('H:i:s.').$usec;
    }
    
    function str_ts_sq($ts = null, $tz = null)
    {
        return '['. str_ts().']';
    }    
    
    function pr_time()
    { 
        list($usec, $sec) = explode(" ", microtime());
        $usec = str_replace('0.', '.', $usec);            
        return doubleval( strval($sec).$usec );     
    }

    function dt_from_ts(int $timestamp): DateTime {
        $dt = new DateTime();
        $dt->setTimestamp($timestamp);
        return $dt;
    }

    function time_ms() {
        return round (microtime(true) * 1000);
    }
    
    function date_ms($format, $ms = false, $utc = false) {
        if (false === $ms) 
            $ms = time_ms();
        $sec = floor($ms / 1000);
        $result = '';
        if ($utc)
            $result = gmdate($format, $sec);
        else
            $result = date($format, $sec);
        $result = str_ireplace('q',sprintf('%03ld', $ms % 1000), $result);
        return $result;
    }
    
    function strtotime_ms($s) { // accepts yyyy-mm-dd H:i:s.zzz
        $m = []; 
        if (preg_match('/(.*)\.(\d\d\d)\d*(Z*)$/', trim($s), $m)) {
            $ts = $m[1];
            if (count($m) > 2) $ts .= $m[3];             
            $result = strtotime($ts) * 1000 + $m[2];    
        }   
        else  
            $result = strtotime($s) * 1000;  
        return $result;
    }

    function format_ts(int $seconds) {
        return date(SQL_TIMESTAMP, $seconds);
    }

    function format_tms(int $ms) {
        return date_ms(SQL_TIMESTAMP3, $ms);
    }

    function format_qty(float $qty) {
        if (0 == $qty) return '0';

        if (abs($qty) > 1e12)
            return sprintf("%.3fT", $qty / 1e12);
        elseif (abs($qty) > 1e9)
            return sprintf("%.3fB", $qty / 1e9);
        elseif (abs($qty) > 1e6)
            return sprintf("%.3fM", $qty / 1e6);
        elseif (abs($qty) > 1000)
            return sprintf("%.3fK", $qty / 1000);
        elseif (abs($qty) > 1)
            return sprintf("%.3f", $qty);        
        elseif (abs($qty) > 0.001)
            return sprintf("%.3fm", $qty * 1000);

        return sprintf("%.6fμ", $qty * 1e6);
    }

    function precise_time()
    {
        list($usec, $sec) = explode(" ", microtime());
        $usec *= 1000000;      
        $r = array($sec, $usec);
        return $r;
    }
    
    function diff_time_ms($start, $end)
    {  
        return 1000 * ($end[0] - $start[0]) + 0.001 * ($end[1] - $start[1]); 
    }
    
    function diff_minutes($ts, $last_ts, $tz = null) 
    {
        $dt_last = new DateTime($last_ts, $tz);
        $dt_now  = new DateTime($ts, $tz);
        $diff    = $dt_now->diff($dt_last);
        return ( $diff->days * 24 + $diff->h ) * 60 + $diff->i;
    }
    
    function log_msg()
    {
        global $log_file, $log_indent;    
        $args = func_get_args();
        $msg = array_shift($args);
        try {
            $line = str_ts_sq().sprintf(".%s $msg\n",$log_indent, ...$args); 
        }
        catch (ArgumentCountError $E) {
            $line = print_r($args, true);      
            $stack = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT , 3);
            $trace = '';
            foreach ($stack as $sl) {
                $al = $sl['args'];
                $trace .= sprintf("%s:%d in %s, args %d: %s\n", 
                            $sl['file'], $sl['line'], $sl['function'], count($al), json_encode($al));
            }  
            $trace = preg_replace('/[\x00-\x1F\x7F]/', '', $trace); 
            $line .= "\n #EXCEPTION({$E->getMessage()})\n from: $trace ";
        }         
        if (false !== strpos($line, '#ERROR'))
            log_error($line);

        if ($log_file)    
            fputs($log_file, $line);
        else
        {
            echo  $line;    
            flush ();
        }
    }

    function log_error($msg, $suffix = "\n")
    {
        global $err_log, $err_count;
        $trace = debug_backtrace();
        $t = $trace[1];
        $line = sprintf("[%s][%s:%d]. %s%s", str_ts_sq(), $t['file'], $t['line'], $msg, $suffix);
        $f = fopen($err_log, 'a+');
        if (!$f) {
            error_log( $line);
            return;
        }
        fputs($f, $line);
        fclose($f);
    }

    function print_traceback()
    {
        $trace = debug_backtrace();
        // var_dump();
        $sep = ' ';
        $i = 0;
        foreach ($trace as $line)
        {
            $s = sprintf("\t$sep %s:%03d in function [%s]", $line['file'], $line['line'], $line['function'] );
            $sep .= ' ';
            if ($i++ > 0) log_msg($s);          
        }
    }

    /**
     * Return $_REQUEST[name] or default value with optional filtering against SQL injection
     * @param string $name
     * @param mixed $default
     * @param string $inj_filt
     * @return mixed
     */
    function rqs_param(string $name, mixed $default, string $inj_filt = 'SQL:PHP'): mixed
    {
        global $remote_addr;
        if (isset($_REQUEST[$name]))
            $v = $_REQUEST[$name];
        else 
            return $default;
        if (false !== strpos($inj_filt, 'SQL')) {
            // $v = urldecode($v);
            $src = $v;
            $v = str_ireplace('--', '', $v);
            $v = str_ireplace("'", '«', $v);
            $v = str_ireplace('"', '¨', $v);
            $v = str_ireplace('password', 'Passw0rd', $v);
            $v = str_ireplace('union select', 'Soviet uni0n', $v);
            $v = str_ireplace('union', 'uni0n', $v);
            $v = preg_replace('/concat\s*\(/', 'c0n.cat(', $v);
            $v = preg_replace('/(\W)user/i', '\1uzer', $v);
            $v = preg_replace('/(\W)admin/i', '\1odmen', $v);
            if ($v != $src) {           
                $msg = tss().sprintf(" file [%s] param [$name] filtered [%s]=>[%s], request from [%s]\n", __FILE__, $src, $v, $remote_addr);
                file_add_contents('/tmp/inj_filter.log', $msg);
            }   
        }
        return $v;  
    }
    
    function round_step($step)
    {
        $ratio = 1;
        while ($step < 10) 
        {
            $step *= 10;
            $ratio *= 0.1;
        }
        while ($step > 100)
        {
            $step *= 0.1;
            $ratio *= 10;
        }                
        
        if ($step > 50) $step = 50;
        elseif ($step > 25) $step = 25;     
        elseif ($step > 20) $step = 20;
        else 
            $step = 10; 
        
        return $step * $ratio;
    }                                

    function check_mkdir($path, $attr = 0755)
    {
      if (!file_exists($path))
          if (!mkdir($path, $attr, true))
              log_error(" failed create dir '$path'");
    }
    
    function init_colors($im)
    {
        global $colors;
        $colors['black']    = imagecolorallocate($im, 0, 0, 0);         // background
        $colors['aqua']     = imagecolorallocate($im, 50, 255, 255);
        $colors['orange']   = imagecolorallocate($im, 220, 210, 60);
        $colors['lime']     = imagecolorallocate($im, 0, 255, 0);
        $colors['red']      = imagecolorallocate($im, 255, 0,  0);
        $colors['white']    = imagecolorallocate($im, 255, 255, 255);
        $colors['lt_blue']  = imagecolorallocate($im, 128, 128, 255);
        $colors['purple']   = imagecolorallocate($im, 255,   0, 255);
        $colors['yellow']   = imagecolorallocate($im, 255, 255, 0);     
        $colors['gray']     = imagecolorallocate($im, 100, 100, 100); 
        $colors['silver']   = imagecolorallocate($im, 185, 185, 185);      
    }

    function draw_horiz_axis ($im, $rect, $v_min, $v_max)
    {
        global $colors;
        $gray = $colors ['gray'];
        $white = $colors ['white'];
        $silver = $colors ['silver'];     
        $lcolor = $colors ['label'];
        
        
        $width   = $rect->width ();
        $v_range = $v_max - $v_min;          
        $x_aps = round ($width / axis_step_x);           // сколько точек на оси
        $v_step = round_step ($v_range / $x_aps);        // value step
        $x_step = $width / $v_range;                     // X step     
        $value = round( $v_min / $v_step + 1) * $v_step; // initial value
        
        
        while ($value <= $v_max)
        {
            $rx = round( $rect->left + ($value - $v_min) * $x_step );
                                        
            imagestring($im, 5, $rx - 10, $rect->bottom + 8, round($value, 5), $white);
            
            $ln_color = $gray;
            if ( fmod($value, $v_step * 2) < $v_step ) $ln_color = $silver;        
            
            imageline($im, $rx, $rect->top,    $rx, $rect->bottom - 1, $ln_color);
            imageline($im, $rx, $rect->bottom, $rx, $rect->bottom + 5, $white);
            $value += $v_step;                   
        }           
    }

    function draw_vert_axis ($im, $rect, $v_min, $v_max, $b_right)
    {
        global $colors;
        $gray = $colors ['gray'];
        $white = $colors ['white'];
        $silver = $colors ['silver'];     
        $lcolor = $colors ['label'];
        
        $height  = $rect->height();
        $v_range = $v_max - $v_min;          
        $y_aps = round ($height / axis_step_y);          // сколько точек на оси
        $v_step = round_step ($v_range / $y_aps);        // value step
        $y_step = $height / $v_range;                    // Y step     
        $value = round( $v_min / $v_step + 1) * $v_step; // initial value
        
        
        while ($value <= $v_max)
        {
            $ry = round( $rect->bottom - ($value - $v_min) * $y_step);            
            
            $ln_color = $gray;
                    
            if ( fmod($value, $v_step * 2) < $v_step ) $ln_color = $silver;      
                    
            imageline($im, $rect->left + 1,  $ry, $rect->right - 1, $ry, $ln_color); // cross chart h-line
                    
            if ($b_right)
            { 
                imageline($im, $rect->right, $ry, $rect->right + 5, $ry, $white);
                imagestring($im, 5, $rect->right + 20, $ry - 7, round($value, 5), $lcolor); // draw labels on right axis
            }           
            else
            { 
                $label = round($value, 5);     
                if ($v_min > 10000)    
                    $label = sprintf("%.3f M", $label * 1e-6);
                
                imageline($im, $rect->left - 5, $ry, $rect->left, $ry, $white);
                imagestring($im, 5, $rect->left - 70, $ry - 7, $label,  $lcolor); // draw labels on left axis          
            }
                                        
            $value += $v_step;           
        }      
    }                            


    class RECT 
    {
        public $left = 0;
        public $top = 0;
        public $right = 0;
        public $bottom = 0;

        public function __construct(float $l, float $t, float $r, float $b)
        {
            $this->left   = $l;
            $this->top    = $t;
            $this->right  = $r;
            $this->bottom = $b;
        }
        
        public function height()
        {
            return $this->bottom - $this->top;
        }
        
        public function width() 
        {
            return $this->right - $this->left;
        }      
    
    }

    function file_add_contents($fname, $s)
    {
        $mode = file_exists($fname) ? 'a+' : 'w'; 
        $fd = fopen($fname, $mode);
        if (!$fd) return false;
        fwrite($fd, $s);
        fclose($fd);
    }   
    
    function file_read_int($fname)
    {
        return intval(file_get_contents($fname));
    } 
    
    function file_load_json($filename, $ctx = null, $assoc = false) {
        $json = file_get_contents($filename, false,  $ctx);
        if (!$json)
          return null;
        return json_decode($json, $assoc); 
    }

    function file_save_json($filename, $obj) {
        $json = json_encode($obj);
        file_put_contents($filename, $json); 
    }

    function curl_http_request (string $source, $post_data = null, $curl_opts = null) {
        global $curl_last_error, $curl_resp_header;    

        if (!function_exists("curl_init")) {    
          die("#FATAL(curl_http_request): curl_init not supported!");
        }  
        $ch = curl_init();  
      
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);    

        if (is_null($curl_opts)) 
            $curl_opts = new CurlOptions();

        curl_setopt_array($ch, $curl_opts->extra);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $curl_opts->connect_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $curl_opts->total_timeout);      
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);

        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Easy PHP client; '.php_uname('s').'; PHP/'.phpversion().')');  
        
        if ($post_data) {
          curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
        
        $url = $source;    
        

        if (strpos($url, 'http') === false)
            $url = "http://$source";
        // echo("url = $url\n");	
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);
        if ($result === false) 
        {
          $curl_last_error = curl_error($ch); 
          $curl_resp_header = '';
          $result = sprintf("#ERROR: curl_exec failed for %s: [%d: %s]\n", $url, curl_errno($ch), $curl_last_error);
        } else {
          $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
          $curl_resp_header = substr($result, 0, $header_size);
          $result = substr($result, $header_size);
        }          
        curl_close($ch);
        return $result;
    }                                   

    function format_backtrace(int $options = 0, string $format = 'basic', int $limit = 10, int $shifts = 1, bool $colored = false ) {
        global $last_exception;
        $res =  debug_backtrace($options);    

        if ($options < 0) {
            if (isset($last_exception) && is_object($last_exception)) 
                $res = $last_exception->getTrace();
            else
                return  "ERROR: last_exception is not set\n";
        }
        
        $last_exception = null;
        $result = '';    
        $res = array_slice($res, $shifts, $limit);

        $fmt_func = $colored ? 'format_color' : 'sprintf';
        
        foreach ($res as $rec) {
            if ('json' == $format) 
                $result  .= json_encode($rec)."\n";
            if ('basic' == $format) {
                $args = '';
                if (isset($rec['args'])) 
                    $args = " with params ".substr(json_encode($rec['args'], 0, 1), 0, 100);                 

                $result .= $fmt_func("\t%s:%d %s in %s\n", $rec['file'] ?? '<no filename>', $rec['line'] ?? -1, $args, $rec['function']); 
            }
              
        } 
        if ('detail' == $format) 
            $result = print_r($res, true);        

        return $result;
    }

    function array_keys_dump(array $a) {
        return json_encode(array_keys($a));
    }

    function array_value(mixed $a, $key, $default = null) {
        return isset($a[$key]) ? $a[$key] : $default;
    }
  
    function setup_pid_file(string $pid_file, int $timeout = 300) {        
        set_time_limit($timeout);
        $t_start = time();
        $my_pid = getmypid();
        while (true) {
            $elps = time() - $t_start;
            if ($timeout - $elps < 60)  {
                $sig = $timeout - $elps < 30 ? SIGTERM : SIGQUIT;
                log_msg("#FORCE_KILL($my_pid): using signal $sig, result: %s", shell_exec("kill -$sig $(cat $pid_file) 2>&1"));
            }    
            if ($elps > $timeout) 
                die("#FATAL: can't lock PID file $pid_file in $timeout seconds...\n");
            $pid_lock = fopen($pid_file, 'w+');            
            if (!is_resource($pid_lock) || 
                !flock($pid_lock, LOCK_EX | LOCK_NB)) {
                fseek($pid_lock, 0);
                stream_set_blocking($pid_lock, false);
                $pid = fread($pid_lock, 10);
                log_msg("#ERROR($my_pid): %s is already locked, possible another instance alive with PID %s. Elapsed %d", $pid_file, $pid, $elps); // here can be hangout 
                sleep(10);
            }
            else
                break;
        }     
        fputs($pid_lock, $my_pid); 
        return $pid_lock;
    }

?>