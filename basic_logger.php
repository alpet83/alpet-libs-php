<?php
/* класс BasicLogger реализует сохранение отладочной информации в журнал, и вывод в консоль
     вывод всех журналов микшируется фактически в std_out или в std_err
   поддерживается автокомпрессия журналов
  */

    function make_symlink(string $path, string $syml): bool {        
        if (is_link($path)) {
            log_cmsg("~C91#WARN:~C00 trying to create link for link %s, targeted to %s", $path, readlink($path));
            unlink($path);
            return false;
        }

        if (file_exists($syml)) {
            $exist = readlink($syml);
            if (is_file($path) || $path !== $exist) {                 
                log_cmsg("~C31#UNLINK:~C00 %s => %s", $syml, $exist);
                unlink($syml);
            }    
            else {
                // log_cmsg("~C33#EXISTS:~C00 %s already linked to %s", $syml, $exist);
                goto SKIP_DIR_LINK;
            }     
        }      

        log_cmsg("#DBG: creating symbol link %s for %s", $syml, $path);

        $attempts = 0;
        while (symlink($path, $syml) && $attempts < 5) {
            $attempts ++;
            $check = readlink($syml);
            if ($check == $path)              
                log_cmsg("~C93#LINKED:~C00 %s", $syml);
            else {         
                $type = 'file';            
                unlink($syml);
                if (is_link($check)) {
                    $type = 'link';
                    unlink($check);
                }
                log_cmsg("~C91#FAILED($attempts):~C00 create symbol link %s, real redirect %s:%s, removing", $syml, $check, $type);                
                
                continue;
            }    
            break;
        }
        
SKIP_DIR_LINK:        
        return file_exists($syml);
    }


  class Incident {

    public $fmt = '';
    public $backtrace = '';

    public $count = 1;

    public function __construct(string $fmt, string $backtrace) {
        $this->fmt = $fmt;
        $this->backtrace = $backtrace;      
    }

    public function key() {
        return md5($this->fmt.$this->backtrace);
    }

  }


  class BasicLogger {
    protected $log_prefix = 'test_';
    
    public    $log_dir = '../log/';
    public    $sub_dir;
    public    $std_out = null;
    public    $lines = 0;
    public    $indent = '';

    public    $last_msg = '';
    public    $last_msg_t = 0.0;

    public    $size_limit = 300 * 1048576;
    
    protected $file_name = '';  // used last
    protected $real_name = '';

    protected $log_fd   = null;
    protected $err_fd   = null;

    protected $last_create = 0;


    public function  __construct(string $sub, string $prefix, $stdout = STDOUT) {
        $this->sub_dir = $sub;
        $this->std_out = $stdout;
        $this->log_prefix = $prefix;
    }
    public function __destruct() {
        $prev_date = date('Y-m-d', time() - 86400);
        $base = realpath($this->log_dir.$this->sub_dir);
        $path = "$base/$prev_date";
        $dir = getcwd();
        // полная запаковка каталога
        if (file_exists($path) && is_dir($path)) {
            chdir($base);
            shell_exec("tar --bzip --remove-files -cf $prev_date.tar.bz2 $prev_date");
            chdir($dir);
        }    
        $this->close('logger destruct');
    }
    
    public function archive(int $size_above = 1048576) {
        if ($this->file_size() >= $size_above) {          
            if (is_resource($this->log_fd))
                fclose($this->log_fd);
            $this->log_fd = null;
            $res = shell_exec("/bin/bzip2 -9 -k -f {$this->real_name}");
            $archive = "{$this->real_name}.bz2";
            $msg = sprintf(" #COMPRESS_LOG(%s): %s", $this->real_name, $res);
            if (file_exists($archive)) {
                $msg .= " archive size = ".filesize($archive);
                unlink($this->file_name);
                if ($this->real_name !== $this->file_name)
                    unlink($this->real_name);
                $msg .= ", removed original log and link";
            }      
            log_msg($msg);
            file_add_contents("{$this->log_dir}/compressed.log", tss()." $msg\n");
            $this->file_name = '';            
        }    
        elseif (is_link($this->file_name))
                unlink($this->file_name);

    }

    public function close(string $reason) {
        if (is_resource($this->log_fd))
            fclose($this->log_fd);
        $this->log_fd = null;  
        $this->archive();
        log_cmsg("~C93#CLOSED_LOG:~C00 real name %s called due %s from  %s", $this->real_name, $reason, format_backtrace());
        $this->real_name = '';
        $this->file_name = '';
        
    }

    public function log_filename(bool $create_link = true) {
        if (strlen($this->file_name) > 4) 
            return $this->file_name;         
        else
            log_cmsg("~C31#WARN:~C00 not file_name defined for logger %s: %s", $this->log_prefix, $this->file_name);

        $elps = time() - $this->last_create;
        if ($elps < 600)
            log_cmsg("~C91#WARN:~C00 log previoulsy was created % seconds ago. Renaming requested from %s", $elps, format_backtrace());

        $base = realpath($this->log_dir.$this->sub_dir);
        $path = "$base/".date('Y-m-d/');      
        $cwd = getcwd();         
        if (!file_exists($path)) 
            mkdir($path, 0770, true);          

        $idx = '';
        if (preg_match('/(\d+)/', $this->sub_dir, $m)) 
            $idx = $m[1];

        $syml = "$cwd/logs$idx.td";             

        if (make_symlink($path, $syml))
            $path = "$cwd/logs$idx.td";        
        
        $result = "$path/{$this->log_prefix}_".date('H-i').'.log';
        try {
            file_put_contents($result, '☺');            
        } catch (Exception $e) {
            log_cmsg("#EXCEPTION %s from %s", $e->getMessage(), $e->getTraceAsString());
        } 

        $symf = "$path/{$this->log_prefix}.log"; 
        $this->real_name = $result;
        $this->file_name = $result;

        if ($create_link) {
            make_symlink($result, $symf);        
            $this->file_name = $symf;
        }
        return $result;
    }

    public function file_size(): int  {
      if ($this->log_fd)
         return ftell($this->log_fd); 
      if (file_exists($this->real_name))
         return filesize($this->real_name);
      return 0;  
    }

    public function  log($msg) {
      if (!is_resource($this->log_fd)) {
        $this->file_name = $this->log_filename();                
        $this->log_fd = fopen($this->file_name, 'wb');                        
        $this->last_create = time();
      }

      if (strlen($msg) > 20480) 
         $msg = "#TRUNCATED: ".substr($msg, 0, 20480);                
         

      $ts = tss();      
      $elps = pr_time() - $this->last_msg_t;
      if (false !== strpos($msg, '#PERF'))  
          $ts .= sprintf(" +%5.2f ", $elps);
        
      $this->last_msg = $msg;
      $this->last_msg_t = pr_time();
      if ($this->std_out)
          fputs($this->std_out, "$ts {$this->indent} $msg\033[0m\n");
      if ($this->log_fd)
         fputs($this->log_fd, "$ts {$this->indent} $msg\033[0m\n");
      $this->lines += substr_count($msg, "\n") + 1;
      

      $size = $this->file_size();
      $minute = date('i');
      $huge_size = $size > $this->size_limit;
      if ($huge_size || 0 == $minute && $this->lines >= 5000) {
        $this->close("log size $size, lines {$this->lines}");        
        $this->file_name = $this->log_filename();
        $this->log_fd = fopen($this->file_name, 'wb');
        $msg = format_color("#LOG_ROTATE: $this->file_name reaches size %.1f MiB, check for flood", $size / 1048576);
        if ($huge_size)
            throw new Exception($msg);
        else
            log_cmsg($msg);
      }  
    }

  };
