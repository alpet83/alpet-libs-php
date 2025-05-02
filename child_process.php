<?php
    class ChildProcess {


        public   $cmd = '';

        public   $cwd = null;

        public   $env_vars = []; 

        public   $index = 0;

        protected $instance = null;

        protected $stdin;

        protected $stdout;    
        protected $stderr;

        protected $was_runned = false;
        
        public function __construct() {
            $this->env_vars = getenv();
        }
         
        
        public function GetStatus(): array {
            $st =  ['bad_instance' => 'yes', 'running' => false, 'stopped' => true, 'exitcode' => -254];
            $st_real = false;      
            if (is_resource($this->instance))
                $st_real = proc_get_status($this->instance);

            $st = is_array($st_real) ? $st_real: $st;            

            $st['index'] = $this->index;
            $running = $st['running'] || true === $st['stopped'];
            $this->was_runned |= $running;
            if (!$running && $this->was_runned) {
                $st['exited'] = true; // just now
                $this->OnExit(); // close process if was runned, free resources   
            }
                
            return $st;
        }

        public function GetStderr(): string {
            return is_resource($this->stderr) ?  stream_get_contents($this->stderr) : '';                
        }

        public function GetStdout(): string {
            return is_resource($this->stdout) ?  stream_get_contents($this->stdout) : '';                
        }

        public function IsActive(): bool {
            $st = $this->GetStatus();            
            return $st['running'] && !$st['stopped'];      
        }        

        public function Open(): bool {
            $ds = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
            $this->stderr = $this->stdin = $this->stdout = null;

            $this->instance = proc_open($this->cmd, $ds, $pipes, $this->cwd, $this->env_vars);
            if (is_resource($this->instance)) {
                $this->stdin = $pipes[0];
                $this->stdout = $pipes[1];
                $this->stderr = $pipes[2];
                stream_set_blocking($this->stderr, false);
                stream_set_blocking($this->stdout, false);
                $this->was_runned = true;
                return true;
            }
            $this->was_runned = false;
            return false;
        }

        protected function OnExit() {
            proc_close($this->instance);
            $this->instance = null;
        }


        public function Stop(array $delays = [30, 10, 10, 10]) {
            if (!$this->IsActive()) return;            
            $signals = [SIGQUIT, SIGTERM, SIGKILL, SIGHUP];
            foreach ($signals as $n => $sig) {
                proc_terminate($this->instance, SIGQUIT);
                if ($this->IsActive())
                    sleep($delays[$n]);                                  
                else
                    break;
            }               
            proc_close($this->instance);
            $this->instance = null;
        }

    } // class ChildProcess

    class ChildProcessManager {

        protected $processes = [];
        public $max_processes = 4;        

        public function Allocate(string $cmd, $class = 'ChildProcess'): ?ChildProcess {
            foreach ($this->processes as $proc) 
                if (!$proc->IsActive()) {
                    $proc->cmd = $cmd;
                    return $proc;
                }

            if (count($this->processes) >= $this->max_processes) 
                return null; 
            $proc = new $class();                    
            $proc->cmd = $cmd;
            $proc->index = count($this->processes);
            $this->processes []= $proc;
            return $proc;
        }

        public function BusyCount(): int {
            $count = 0;
            foreach ($this->processes as $proc) 
                if ($proc->IsActive()) 
                    $count++;
            return $count;
        }
    } // class ChildProcessManager
        