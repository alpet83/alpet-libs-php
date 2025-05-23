<?php
    $table_params = "";
    $color_errors = true;
    $ustr = '_';

    define('SQL_TIMESTAMP', 'Y-m-d H:i:s');
    define('SQL_TIMESTAMP3', 'Y-m-d H:i:s.q');

    define('MYSQLI_RAW',    0x000); // return mysqli_result 
    define('MYSQLI_OBJECT', MYSQLI_ASSOC + 0x100); // return mysqli_row object

    if (!defined('MYSQL_NUM'))
        define('MYSQL_NUM', MYSQLI_NUM); 

    if (!function_exists('tss')) 
        include_once(__DIR__.'/common.php');
       
  
    if (!function_exists('log_cmsg'))     
        include_once(__DIR__.'/esctext.php');

    if (PHP_VERSION_ID > 70000) 
       define("OLD_MYSQL_SUPPORT", false);
    else
       define("OLD_MYSQL_SUPPORT", true); 
    // ob_start(); ob_get_clean();

    $failed_sql = '/dev/stderr';
    if (file_exists('logs') && is_dir('logs')) { 
        $failed_sql = 'cli' == php_sapi_name() ? basename($argv[0]) : $_SERVER['SCRIPT_NAME'];
        if ('' == $failed_sql) 
            $failed_sql = 'failed.sql';
        $failed_sql = 'logs/'.str_replace('.php', '.sql', $failed_sql);
    }

    // ", KEY `TIMESTAMP` (`ts`)"
    $link   = null;
    $mysqli = null;
    $db_error = '';  
    $double_column = "double NOT NULL DEFAULT '0'";
    $float_column  = "float NOT NULL DEFAULT '0'";
    $id_column     = 'int(11) unsigned NOT NULL AUTO_INCREMENT'; 
    
    function sqli(): ?mysqli_ex {
        global $mysqli;
        return $mysqli;
    }

    function crop_query($query, $limit = 70)
    {
        $lines = explode("\n", $query);
        if (count($lines) <= $limit) return $query;    
        $rmv = count($lines) - $limit - 10;
        if ($rmv <= 0) return $query;
        
        $ins = ['... [$rmv lines] ...'];    
        array_splice($lines, 10, $rmv, $ins); // remove internal lines   
        
        return implode("\n", $lines);
    }
    
    function param_type($param) {
        if (is_string($param)) return 's';
        if (is_int($param))    return 'i';
        if (is_float($param))  return 'd';
        return 'b';
    }
    

    class mysqli_ex extends mysqli 
    {     
        public $extended = true;  
        public $last_query = '';
        public $last_query_time = 0.0;

        protected $last_read_mode = MYSQLI_STORE_RESULT;

        public $ins_count = 0;
        public $ins_rows = 0;

        public $timings = [];

        public $min_profile = 0.25; // minimal query time to log

        public $error_logger = null; // logger instance, may be class BasicLogger

        public $log_func = 'log';

        public $replica = null;  // relication connection, for post request to alternate/remote DB

        public $raw_rows = null;


        public function active_db(): string {
            return $this->query('SELECT DATABASE();')->fetch_column();
        }

        public function delete_from(string $table, string $params): bool {
            if (!$this->table_exists($table)) 
                return false;
            $query = "DELETE FROM `$table`\n$params";
            return $this->try_query($query);
        }
        
        public function flush_stats()
        {
            $count = $this->ins_count;
            $rows = $this->ins_rows;
            echo "#FLUSH_STATS: ins_count $count, ins_rows $rows\n";
            $query = "UPDATE `db_stats`\n";
            $query .= "SET ts = CURRENT_TIMESTAMP,\n";
            $query .= "  ins_count = db_stats.ins_count + $count,\n";
            $query .= "  ins_rows = db_stats.ins_rows + $rows\n";
            $query .= "WHERE id = 1;";
            echo $query;
            $this->try_query($query);          
            $this->ins_count = 0;
            $this->ins_rows = 0;
        }     
        

        public static function format_columns(array $cl) {
            foreach ($cl as $i => $cn) 
                $cl [$i] = "`$cn`"; 
            return implode(',', $cl);  
        }
        public function format_timings(float $min = 1, string $indent = ''): string {
            $result = '';
            $time = [];
            foreach ($this->timings as $rec) 
            $time []= $rec[0];
            array_multisort($this->timings, SORT_DESC, $time);
            // rsort($this->timings);
            foreach ($this->timings as $query => $rec) {
            if ($rec[0] > $min)
                $result .= format_color("$indent%s: %.1f / %d = %.2f\n", $query, $rec[0], $rec[1], $rec[0] / $rec[1]);
            } 
            return $result;
        }

        public function format_value(mixed $value,  string $quote_char = '"'): string {           
            if (is_null($value))     
                return 'null';
            elseif (is_numeric($value) || is_float($value))
                return $value;
            elseif (is_bool($value))
                return $value ? 1 : 0;  

            if (is_array($value) || is_object($value))
                $value = json_encode($value); 

            return $quote_char.$this->real_escape_string($value).$quote_char;                        
        }

        public function is_clickhouse(): bool {
            return str_in($this->server_info, 'ClickHouse');
        }

        public function insert_into(string $table, string $columns, string $values, string $ignore = 'IGNORE')
        {
            if (false === strpos($table, '.')) $table = "`$table`";
            $query = "INSERT $ignore INTO $table ($columns)\n VALUES $values";
            $res = $this->try_query($query);
            if ($res) 
                $this->insert_stat($this->affected_rows);
            return $res;
        }   
        
        public function insert_rows(string $table, mixed $columns, array $rows): int {
            if (is_array($columns))
                $columns = implode(',', $columns);
            
            $values = implode(",\n\t", $rows);              
            if ($this->insert_into($table, $columns, $values))
                return $this->affected_rows;

            return -1;            
        } 

        protected function insert_stat($rows)
        {
            $this->ins_count ++;
            $this->ins_rows += $rows;
        }

        protected function on_query_error(string $query, bool $echo, bool $replica = false) {
            global $color_errors, $html_formatting, $failed_sql;
            $ct_open = '';
            $ct_close = '';
            if ($color_errors)
            {
                $ct_open = '~C91';
                $ct_close = '~C00';                
            }
            $conn = $replica ? $this->replica : $this;
            $err = $conn->error;          
            $cr = crop_query($query);        
            $rmode = $conn->last_read_mode;
            file_add_contents($failed_sql, "$query\n");
            if (str_in($err, 'MySQL server has gone away')) {
                $conn->close();
                if ($replica)
                    $this->replica = null;
            }

            $msg = format_color("$ct_open#FAILED$ct_close [$cr]\n result_mode = $rmode\n with error:\n\t$err\n");            
            if (is_object($this->error_logger) && method_exists($this->error_logger, $this->log_func)) {
                $this->error_logger->{$this->log_func}($msg);
            }    
            else
                log_msg($msg);

            if ($echo) {  
                echo "$ct_open#FAILED$ct_close [$cr]\n result_mode = $rmode\n with error:\n\t$err\n";
                print_traceback();
            }       
        }
    

        
        public function safe_query(string $query, array $params): bool|mysqli_result {
            $stmt = $this->prepare($query);
            if (!is_object($stmt)) {
                $this->on_query_error($query, false);
                return false;
            }
            $plist = array();
            $tlist = '';
            // formatting parameters
            foreach ($params as $param) {
                if (is_array($param) && count($param) > 1) {
                    $tlist .= $param[0];
                    $plist []= $param[1];
                }
                else {
                    $tlist .= param_type($param);
                    $plist []= $param;
                }                
            }
            // bind 
            $stmt->bind_param($tlist, ...$plist);
            if (!$stmt->execute()) {
                $this->on_query_error($query, false);
                return false;
            }   
            if (str_in($query, 'INSERT ') && 0 == $stmt->num_rows)
                return true;
            return $stmt->get_result();
        }

        public function safe_select(string $columns, string $table, string $restrict, array $params): bool|mysqli_result {
            return $this->safe_query("SELECT $columns FROM $table WHERE $restrict", $params);
        }

        public function select_from(string $columns, string $table, string $params = '', $rmode = MYSQLI_STORE_RESULT): bool|mysqli_result  {       
            if (false !== strpos($table, '#ERROR')) 
                throw new Exception("mysqli_ex->select_from: invalid table name $table");

            if (!str_in($table, '.') && 
                !str_in($table, ' AS ') &&
                !str_in($table, '`') ) $table = "`$table`";
            return $this->try_query("SELECT $columns FROM $table\n$params", $rmode);        
        }
        
        public function select_map(string $columns, string $table, string $params = '', int $mode = MYSQLI_BOTH): array|null // return mapped data [key] => value
        {
            $result = [];            
            $clist = explode(',', $columns);
            $col_cnt = count($clist);
            if ($col_cnt < 2) return [];            
            $id_key = trim($clist[0], '` ');
            $id_val = trim($clist[1],'` ');

            $not_obj = MYSQLI_OBJECT != $mode;
            if (MYSQLI_NUM == $mode)
                $id_key = 0;

            $r = $this->select_from($columns, $table, $params);     
            if (!$r) return null;
            $row = [];                   
            if (is_array($this->raw_rows))    
                $this->raw_rows = [ ]; // reset            
            while ($row = $r->fetch_array($mode & 0xff)) {           
                if (is_array($this->raw_rows))               
                    $this->raw_rows []= $row; // for debug                                
                
                $key = array_shift($row); // for MYSQLI_NUM keys - remove 0 with shift                                                   
                if (null === $key) 
                        continue;    
                if (2 == $col_cnt && $id_val != '*') 
                    $result [$key]= $row[array_key_last($row)]; // key first = key last after array_shift
                else  {                                                         
                    if (MYSQLI_NUM == $mode)                         
                        $row = array_values($row);                    
                    else
                        unset($row[$id_key]);                    
                    if (count($row) > 0)
                        $result [$key]= $not_obj ? $row : new mysqli_row($row);  // only data columns returns
                }    
            }  
            return $result;  
        }
                            
        public function select_col(string $column, string $table, string $params = '', int $mode = MYSQLI_NUM): mixed { // return result as array of arrays        
            $result = array();
            $r = $this->select_from($column, $table, $params);     
            if (!$r) return null;
            while ($row = $r->fetch_array($mode))
                $result []= $row[0];
            if (MYSQLI_OBJECT == $mode) 
                return new mysqli_row($result); // strange, but OK             
            return $result;  
        }     
        public function select_count(string $table, string $params = '') {
            return $this->select_value('COUNT(*)', $table, $params);
        }

        public function select_row(string $columns, string $table, string $params = '', int $mode = MYSQLI_NUM): mixed  {  // return array of single result        
            if (stripos($params, 'limit') === false) {
                $params = trim($params);
                $params = rtrim($params, ';'); // breaking LIMIT clause        
                $params .= "\n LIMIT 1";
            }    
                
            $r = $this->select_from($columns, $table, $params);     
            if (MYSQLI_RAW == $mode) return $r;
            if (is_null($r) || false === $r) return null;                       
            $row = $r->fetch_array($mode & 0xff);  
            if (MYSQLI_OBJECT == $mode && is_array($row)) 
                return new mysqli_row($row);
            return $row;
        }
        
        public function select_rows(string $columns, string $table, string $params = '', int $mode = MYSQLI_NUM): mixed { // return result as array of arrays        
            $result = array();
            $r = $this->select_from($columns, $table, $params);     
            if (!$r) return null;
            if (MYSQLI_RAW == $mode) return $r;

            while ($row = $r->fetch_array($mode & 0xff)) {
                if (MYSQLI_OBJECT == $mode && is_array($row)) 
                    $result []= new mysqli_row($row);
                else
                    $result []= $row;                
            }    
            return $result;  
        }                                        
    
        public function select_value(string $column, string $table, string $params = ''): mixed
        {
            $row = $this->select_row($column, $table, $params);
            if ($row)
                return $row[0];
            else
                return null;
        }
        
        public function show_create_table(string $table_name): string|bool {
            global $db_error;
            if (!$this->table_exists($table_name)) {
                $db_error = "ERROR: table not exists $table_name";
                return false;
            }
            $info = $this->try_query("SHOW CREATE TABLE $table_name");                
            if (is_object($info) && is_array($row = $info->fetch_row()))                   
                return array_pop($row);                                           
            else
                $db_error .= ' query returned '.var_export($info, true);            
            return false;
        }
        
        public function show_tables(string $db_name = null, string $like = null) {
            $query = "SHOW TABLES";
            if ($db_name) 
                $query .= " FROM `$db_name`";
            if ($like)
                $query .= " LIKE '$like'";
            $res = $this->try_query($query);            
            if (!$res) return [];
            $result = [];
            $rows = $res->fetch_all(MYSQLI_NUM);
            foreach ($rows as $row)
                $result []= $row[0]; 
            return $result; 
        }

        public function table_exists(string $table)
        {        
            if (strpos($table, '.') !== false)
                list($db_name, $tb_name) = explode('.', $table);
            else {
                $db_name = $this->query('SELECT database();')->fetch_row()[0];
                $tb_name = $table;
            }  

            if ($this->is_clickhouse()) {                
                $exists = $this->select_value('count(*)', 'system.tables', "WHERE database = '$db_name' AND name = '$tb_name'");
                return $exists;
            }

            $query = "SELECT TABLE_NAME\n FROM INFORMATION_SCHEMA.TABLES\n";
            $query.=  "WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = '$tb_name';";
            $r = $this->query($query);
            return $r && $r->num_rows == 1;
        }
        

        public function try_query($query, $rmode = MYSQLI_STORE_RESULT, $echo = false): bool|mysqli_result  {
            $this->last_query = substr($query, 0, 10000);
            $this->last_read_mode = $rmode;
            $start = pr_time();        
            $result = $this->query($query);
            $rep_res = 'Ignored';
            $replica = $this->replica;                
            if (is_object($replica) && $result) {
                $cmd = strtok(trim($query), ' ');
                $cmd = strtoupper($cmd);
                $mod_cmds = 'ALTER,CREATE,INSERT,UPDATE,DELETE,TRUNCATE,DROP,LOCK,RENAME,UNLOCK';
                if (str_in($mod_cmds, $cmd)) 
                    $rep_res = $replica->try_query($query); // possible chain continued...
                else    
                    $rep_res .= ":$cmd";
            }

            $elps = round(pr_time() - $start, 3);
            $this->last_query_time = $elps;

            if ($elps > $this->min_profile) {
                $kq = str_replace("\n", '\n', $query);           
                $kq = str_replace(' ', ' ', $kq); // non-breaking space
                if (isset($this->timings[$kq])) {
                    $this->timings[$kq][0] += $elps;
                    $this->timings[$kq][1] ++;
                }   
                else
                    $this->timings[$kq] = [$elps, 1];                         
            }                        

            if (!$result)
                $this->on_query_error($query, $echo);             
            if (!$rep_res) 
                $this->on_query_error("REPLICATION: $query", $echo, true);                
            
            return $result;
        } // try_query  


        public function pack_values(mixed $columns, mixed $src, string $quote_char = '"') {      
            $vals = array();        
            if (is_string($columns)) {
                $columns = str_replace(' ', '', $columns);
                $columns = explode(',', $columns);
            }   
            // TODO: this code may be heavy, required optimization
            // add every value         
            foreach ($columns as $column) {
                $value = null;
                $column = trim($column);
                if (is_array($src))
                    $value = $src[$column];
                elseif (is_object($src) && isset($src->$column))    
                    $value = $src->$column;
                else {
                    $dump = var_export($src, true);
                    $dump = substr($dump, 0, 1024);
                    throw new Exception("pack_values: absent source:$column ".gettype($src).": $dump");    
                }  
                $vals []= $this->format_value($value, $quote_char);
            }    
         
            return implode(',', $vals);                         
        }
        
      
    }; // class mysqli_ex

    class mysqli_buffer {
        protected $mysqli = null;
        public $block_mode = true;
        public $processed = 0;  // succesfully stored
        protected $buffer = array();

        public function __construct(mysqli_ex $conn) {
            $this->mysqli = $conn;
        }

        public function  push_query(string $query, $success_out = false, $failed_out = false, bool $exec_now = true): bool {
            $this->buffer []= array($query, $success_out, $failed_out);
            if ($exec_now)
                return $this->try_commit();
            return true; 
        }

        public function try_commit(): bool {
            $mysqli = $this->mysqli;                            
            if ( 0 == count($this->buffer) ) return true;

            if (!$mysqli->ping()) return false; // connection lost

            while (count($this->buffer) > 0) {
                $query = $this->buffer[0];
                if ($mysqli->try_query($query[0]))  {
                    array_shift($this->buffer); // remove processed
                    $this->processed ++;
                    if ($query[1]) 
                        log_cmsg($query[1]); // display success message
                } 
                else {        
                    if ($query[2]) 
                        log_cmsg($query[2]." {$mysqli->error}"); // display error message
                    return false;
                }   
            }  
            return 0 == count($this->buffer); // full commit
        } // function try_commit
    } 



    class mysqli_row extends stdClass
        implements ArrayAccess, Countable {
        
        public    $row = [];
        public    $is_row_updated = false;

        public function __construct(array $src) {            
            $this->row = $src;
        }
        public function count(): int {
            return count($this->row);
        }
        
        public function __toString() {
            return json_encode($this->row);
        }
        public function __get(string $name) {
            return $this->row[$name] ?? null;
        }        
        public function __set($name, $value) {
            $this->row[$name] = $value;
            $this->is_row_updated = true;
        }
        public function __isset($name): bool {
            return isset($this->row[$name]);
        }
        public function __unset($name) {
            unset($this->row[$name]);
            $this->is_row_updated = true;
        }        

        public function offsetExists(mixed $offset): bool {
            return isset($this->row[$offset]);
        }
        public function offsetGet(mixed $offset): mixed {
            return $this->row[$offset] ?? null;
        }
        public function offsetSet(mixed $offset, mixed $value): void {
            $this->__set($offset, $value);            
        }
        public function offsetUnset(mixed $offset): void {
            $this->__unset($offset);
        }
    } // class mysqli_row

    // class for small table "direct" access
    class mysqli_table implements ArrayAccess, Countable {
        protected $rows = [];
        protected $conn = null;

        protected $columns = [];

        protected $id_key = 'id';

        protected $last_load = 0;

        public  $cache_time = 10; // seconds
        public  $table_name = '';

        public function __construct(string $table_name, mysqli_ex $mysqli, string $columns = '*') {  // always first column must be unique (such as id)
            $this->conn  = $mysqli;
            $this->table_name = $table_name;            
            if ('*' == $columns) {
                $this->columns = $mysqli->select_col('COLUMN_NAME', 'INFORMATION_SCHEMA.COLUMNS', "WHERE TABLE_NAME = '$table_name'");
                if (is_null($this->columns)) 
                    throw new Exception("mysqli_table: cannot get columns for $table_name");
            } 
            else
                $this->columns = explode(',', $columns); 
            $this->id_key = $this->columns[0];
            $this->sync(true);
        }

        public function count(): int {
            $this->sync();
            return count($this->rows);
        }
        public function offsetExists(mixed $offset): bool {
            $this->sync();
            return isset($this->rows[$offset]);
        }
        public function offsetGet(mixed $offset): mixed {
            $this->sync();
            return $this->rows[$offset] ?? null;
        }
        public function offsetSet(mixed $offset, mixed $value): void {
            $this->rows[$offset] = $value;
            $query = '';
            $mysqli = $this->conn;  
            $rec = $value;
            if (is_object($rec)) {
                if ($rec instanceof mysqli_row) {
                    $rec->is_row_updated = false;  // now value will be upgraded
                    $rec = $rec->row;
                }    
                else
                    $rec = json_decode(json_encode($rec), true);
            }    

            if (is_array($rec)) {                
                $rec[$this->id_key] = strval($offset);
                // unset($rec['id']);                                
                $values = $mysqli->pack_values($this->columns, $rec);
                $columns = mysqli_ex::format_columns($this->columns);
                $query = "INSERT INTO $this->table_name ($columns) VALUES ($values)\n";
                $query .= "ON DUPLICATE KEY UPDATE\n";
                $updl = [];
                foreach ($this->columns as $column) {
                    $updl []= "`$column` = ".$mysqli->format_value($rec[$column]);
                }
                $query .= implode(",\n", $updl)." -- row set";                                  
            }            
            else {
                $cl = $this->columns;
                if (count($cl) <= 1) 
                    throw new Exception("mysqli_table: bad columns list {$this->columns}");
                $offset = intval($offset);
                $value = $mysqli->format_value($value);                
                $query = "INSERT INTO $this->table_name VALUES ($offset, $value)\n";
                $query .= "ON DUPLICATE KEY UPDATE {$cl[1]} = $value; -- value set ";
            }
            $mysqli->try_query($query);            
        }
        public function offsetUnset(mixed $offset): void {
            $offset = strval($offset);
            unset($this->rows[$offset]);            
            $this->conn->try_query("DELETE FROM $this->table_name WHERE {$this->id_key} = '$offset';");
        }

        public function sync(bool $force = false){
            $elps = pr_time() - $this->last_load;
            foreach ($this->rows as $key => $row) {
                if (is_object($row) && $row instanceof mysqli_row && $row->is_row_updated) 
                    $this->offsetSet($key, $row);
            }
            if ($elps < $this->cache_time && !$force) return;
            $cols = mysqli_ex::format_columns($this->columns);
            $rows = $this->conn->select_map($cols, $this->table_name, '', MYSQLI_ASSOC); // first key must be unique/primary
            $this->rows = [];
            foreach ($rows as $key => $row) {
                $this->rows[$key] = is_array($row) ? new mysqli_row($row) : $row;
            }
            $this->last_load = pr_time();
        }
    }

    function init_db($db_name = false, $new = true)
    { 
        global $link, $mysqli, $db_user, $db_pass, $db_name_active;
        
        if ($new)
        {
        $link = new mysqli_ex('localhost', $db_user, $db_pass); 
        $mysqli = $link;
        
        if ($link->connect_error)
        { 
            log_msg ('#ERROR: cannot connect to DB server: '.$link->connect_error);
            $link = null;
            $mysqli = null;
        }  
        else    
            if ($db_name) { 
                if ($mysqli->select_db($db_name))
                    $db_name_active = $db_name; // or die('cannot select DB depth_history');
                else  {
                    log_msg("#FATAL: Cannot select DB $db_name\n");
                    $mysqli = null;                  
                }  
            }    
        }
        elseif (OLD_MYSQL_SUPPORT)
        {
            $link = mysql_connect('localhost', $db_user, $db_pass) or die('cannot connect to DB server: '.mysql_error());
            if ($link && $db_name)
                mysql_select_db($db_name, $link);
            $mysqli = false;
        }
    }

    function init_remote_db(string $db_name = null, string $db_user = null, string $db_pass = null, mixed $db_sock = null, array $servers = null)
    {
        global $db_configs, $db_servers, $db_alt_server, $db_error, $db_profile;
        $remote = false;
        $db_error = '';      
        if (null !== $db_name && isset($db_configs) && isset($db_configs[$db_name])  && null === $db_user) {
            $db_profile = $db_configs[$db_name];  
            $db_user = $db_profile[0];
            $db_pass = $db_profile[1];
            if (isset($db_profile[2]))
                $db_sock = $db_profile[2];
        }

        $driver = new mysqli_driver();
        $rep = $driver->report_mode;
        $driver->report_mode = MYSQLI_REPORT_OFF;        
        if (null === $servers)
            $servers = $db_servers;

        foreach ($servers as $alt_server)
        { 
            $db_alt_server = $alt_server;        
            if (null === $alt_server && null === $db_sock) continue;
            $remote = new mysqli_ex(); // no auth data = no connect
            $remote->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);           
            $res = $remote->real_connect($alt_server, $db_user, $db_pass, $db_name, null, $db_sock);        
            if ($res)            
                break;           
            else    
                $db_error = "#FAILED: connect to remote server [$alt_server] with $db_user, err = {$remote->error}:{$remote->connect_errno}";       
            $remote = false;   
        } 
        $driver->report_mode = $rep;
        return $remote; 
    }


    function table_exists($table): bool
    {
        global $mysqli;
        if ($mysqli) {
        $res = $mysqli->try_query("SHOW TABLES LIKE '$table'");
        return  ($res && $res->num_rows > 0);       
        }  
    
        if (OLD_MYSQL_SUPPORT)
            return (mysql_num_rows(mysql_query("SHOW TABLES LIKE '$table'")) == 1);
            
        return false;    
    }


    function mysql_err()
    {
        global $mysqli;
        if ($mysqli)
            return $mysqli->error;
        elseif (OLD_MYSQL_SUPPORT) 
            return mysql_error();
        return 0;    
    }

    function try_query($query, $link = null) // obsolete procedural variant(!)
    {
        global $mysqli;
        $result = false;
        
        if ($mysqli) {
            $result = $mysqli->query($query);        
        }  
        elseif(OLD_MYSQL_SUPPORT)
        {
        if ($link)
            $result = mysql_query($query, $link);
        else
            $result = mysql_query($query);
        
        }
        
        if (!$result)
        {            
            $err = mysql_err(); 
            $cr = crop_query($query);
            log_msg("#FAILED [$cr] with error:\n\t$err\n");
            print_traceback();        
        }
        return $result;
    }
  

    function make_index($table, $name, $col)
    {
        $query = "CREATE INDEX $name ON $table($col)";
        try_query($query);
    }

    function make_table_ex ($table, $columns, $pk, $params = '', $engine = 'InnoDB')
    {
        $query = "CREATE TABLE IF NOT EXISTS $table (\n";
        $keys = array_keys($columns);
        foreach ($keys as $k)
        {
            $t = $columns[$k];
            $query .= "`$k` $t,\n";
        }
        $query .= "PRIMARY KEY(`$pk`)";
        if ($params) $query .= $params;

        $query .= ")\n ENGINE = $engine\n";
        $query .= "DEFAULT CHARACTER SET = utf8\n";
        $query .= "COLLATE = utf8_unicode_ci\n";
        return try_query($query); 
        // throw new Exception ("#MYSQL: make_table failed [\n".$query.'] with errors:\n '.mysql_err());
    }

    function make_table ($table, $columns, $params, $engine = 'InnoDB')
    {
        return make_table_ex($table, $columns, 'id', $params, $engine);
    }

    function select_from($columns, $table, $params)
    {    
        $query = "SELECT $columns FROM $table\n$params";     
        $r = try_query($query);
        return $r;
    }

    function select_row($columns, $table, $params, $type = MYSQL_NUM)
    {
        $r = select_from($columns, $table, "$params\n LIMIT 1");     
        if (is_object($r) && method_exists ($r, 'fetch_array'))
            return  $r->fetch_array($type);
        if (is_resource($r) && OLD_MYSQL_SUPPORT) 
            return mysql_fetch_array($r, $type);  
        return null;         
    }
    
    function select_value($column, $table, $params)
    {
        $row = select_row($column, $table, $params);
        if ($row)
            return $row[0];
        else
            return null;
    }

  

    function pair_id($pair)
    {
        global $mysqli;
        
        $row = $mysqli->select_row('id', 'pair_map', "WHERE pair = '$pair'");

        if ( !$row || count($row) == 0 )
        {
        $add = "INSERT INTO pair_map (pair) VALUES('$pair');";
        log_msg("add_rqs: $add ");
        $mysqli->try_query($add);              
        $row = $mysqli->select_row('id', 'pair_map', "WHERE pair = '$pair'");       
        }

        $id = -1;

        if ( count($row) > 0 ) $id = $row [0];    
        return $id;
    }

    function on_data_update($dtype, $ts)
    {
        global $mysqli;           
        
        $query = "INSERT INTO datafeed.last_sync (data_type, ts)\n";
        $query .= " VALUES ('$dtype', '$ts')\n";
        $query .= " ON DUPLICATE KEY UPDATE\n ts=VALUES(ts)";
        if (!$mysqli)    
            log_msg("#WARN: mysqli object == null");
        
        try_query($query);
    }
  
    function batch_query($qstart, $qend, $data, $limit = 10000) // obsolete big-insert optimizer
    {  
        $cnt = count ($data);
        if ($cnt > $limit)
            log_msg("batch query processing [$qstart ... $qend] for $cnt lines "); 
        
        while ($cnt > 0)
        { 
        $query = $qstart;
        
        $slice = array();
        $cnt = count($data);                             
                                
        if ($cnt <= $limit)
        {
            $slice = $data;           
            $cnt = 0;
        }    
        else   
        {
            $slice = array_splice($data, 0, $limit);
            log_msg("batch_query processing $limit, rest $cnt ");
        }                                            
        
        $query .= implode(",\n",$slice);
        $query .= $qend;
        
        // log_msg("$query");
        try_query($query);
        }  
    
    } // batch_query  

    function do_multi_query(mysqli_ex $link, string $query, mixed $fmt = false) {
    if ($link->multi_query($query)) {        
            $affected = $link->affected_rows;
            $results = 0;
            while ($link->more_results() && $link->next_result())  {
            $sr = $link->use_result();
            $results ++;
            $affected += $link->affected_rows; 
            if($sr instanceof mysqli_result)  {
                if (isset($sr->num_rows))          
                    $affected += $sr->num_rows;
                $sr->free();
            }   

        }       
        if (is_string($fmt)) log_cmsg($fmt, $affected, $results);
        return true;
    }
    return false;
    }


?>