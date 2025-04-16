<?php
 include_once('common.php');


 function detect_port($default, $num = 0) // this function return ordinal port only! 
 {      
    $result = $default;

    $list = glob('/dev/ttyACM*');  
    if (is_array($list) && isset($list[$num]))
       $result = $list[$num]; 
        
    $list = glob('/dev/ttyUSB*');
    if (is_array($list) && isset($list[$num]))
       $result = $list[$num];
        
    $list = glob('/dev/ttyS*');
    if (is_array($list) && isset($list[$num]))
       $result = $list[$num];
        
    return str_replace('/dev/', '', $result);   
 }

  class serial_port
  {
    protected $lfn = false;
    protected $tty = 0;
    protected $lock = 0; 
    protected $port = '?';
    protected $start_str = '.';
      
    public    $baudrate   = 2400;
    public    $config_str = 'stty -F /dev/%s %d -echo';
      
      function __construct($port, $start_str = 'controller')
      {
        $this->port = $port;
        $this->start_str = $start_str;      
      }
  
      function __destruct()
      {
        $this->close_port();
        
      }          
  
      function close_port()
      {       
        if (!$this->tty) return;
        
        printf(tss()." closing port [%s] \n", $this->port);
        
        flock($this->lock, LOCK_UN);
        flock($this->tty, LOCK_UN);
        fclose($this->tty);      
        fclose($this->lock);
        if ($this->lfn) 
            unlink ($this->lfn);
            
        $this->tty = false;
        $this->lock = false;   
      }
  
      function open_port($port = 'detect', $context = 'default')
      {
        global $argv;
        if (strpos($port, 'detect') !== false) 
        {
           $port = detect_port('ttyACM0');
           echo "open_port detected [$port]\n";
  	 // if (strpos($port, '/') !== false) die("#FATAL: cannot extract port name!\n");	
        }
        
        $this->port = $port;
        
        echo("trying open port-lock $port file\n");
        $fname = "/tmp/$port.lock";
        $this->lock = fopen($fname, 'x');
        if (!$this->lock) 
            throw new Exception("Cannot open $fname, may be locked by another script");
        
        if (!flock($this->lock, LOCK_EX + LOCK_NB))
            throw new Exception("Cannot lock $fname, may be locked by another script");
            
        $this->lfn = $fname;      
             
        $pid = getmypid();     
        fputs($this->lock, date('[d.m.y H:i:s]'). ". serial.php @ $pid / $context ");
        if ($argv)
        {
           $args = implode(' ', $argv);
           fputs($this->lock, $args);
        }
        fputs($this->lock, "\n");
              
        
        echo("trying open port $port\n");
        
        exec(sprintf($this->config_str, $port, $this->baudrate)); // required root rights?  
  
        $this->tty = fopen("/dev/$port", 'r+');
        if (!$this->tty)
            throw new Exception("#FATAL: cannot open port [$port]\n");
        sleep(1);   
  
        //  if (!flock($this->tty, LOCK_EX + LOCK_NB))
        //    throw new Exception("#FATAL: cannot lock port [$port]\n");
        
        $hello = '';    
        echo tss().". #INIT: $port opened, waiting for hello message...\n";
        usleep(10000);
        stream_set_timeout($this->tty, 5, 0);
             
        // ожидание ициализации ардуины
        for ($i = 0; $i < 15; $i++)
        {       
           set_time_limit(10);       
           // print_r( fstat($this->tty) );
           echo tss().". [$i] trying read from port.\n"; 
           $s = $this->gets(64);       
           if ($s === false) continue;
           $hello .= trim($s, "\n\r");              
           // echo (tss().". #ECHO: { $s } \n");
           if (strpos($s, $this->start_str) !== false)               
               break;         
        }
         
        $hello = str_replace('-', '', $hello);
        echo ( tss().". #HELLO: $hello \n");
        return $this->tty;    
      }
    
      function get_tty() 
      {
         return $this->tty;       
      }
      
      function gets($len = 256)
      { 
         $ch = fread($this->tty, 1);
         if ($ch == "\n") return '';
         
         return $ch . fgets($this->tty, $len);       
      }
          
      function puts($msg)
      {
        if (!stream_set_blocking($this->tty, 1))
            echo "#WARN: cannot set stream blocking mode\n";    
      
        fputs($this->tty, $msg);
      }
      
      function reopen()
      {
         $this->close_port();
         $this->open_port($this->port);
      
      }
    
    }
    
  ?>
