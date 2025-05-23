<?php
    // basic functions for easing ClickHouse DB access

    require_once 'vendor/autoload.php';
    require_once 'lib/db_config.php'; // CLICKHOUSE_HOST, CLICKHOUSE_USER, CLICKHOUSE_PASS must defined in this file

    function ClickHouseConnect($user = CLICKHOUSE_USER, $passwd = CLICKHOUSE_PASS, $dbname = 'trading'): mixed {
        $config = [
            'host' => CLICKHOUSE_HOST,
            'port' => '8123',
            'username' => $user,
            'password' => $passwd,
            'readonly' => false
        ];
        try {
            $db = new ClickHouseDB\Client($config);
            $db->database($dbname);
            $db->settings()->readonly(false); 
            $db->setTimeout(1.5);      // 1500 ms
            $db->setTimeout(300);       // 10 seconds
            $db->setConnectTimeOut(5); // 5 seconds
            $db->setReadOnlyUser(false);
            return $db;
        } catch (Exception $E) {
            log_cmsg("~C91#ERROR:~C00 cannot initialze ClickHouse DB interface. Error: %s ", $E->getMessage());      
        } 
        return null;
    }

    function ClickHouseConnectMySQL(string $host = null, string $user = null, string $pass = null, string $db_name = 'datafeed'): ?mysqli_ex {    
        $mysqli = new mysqli_ex();
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);           
        if (is_null($host))
            $host = CLICKHOUSE_HOST.':9004';      
        if (!$mysqli->real_connect($host, $user ?? CLICKHOUSE_USER, $pass ?? CLICKHOUSE_PASS,$db_name)) {        
            log_cmsg("~C91 #ERROR:~C00 cannot initialze ClickHouse DB interface with host %s. Error: %s ", $host, $mysqli->connect_error);
            $mysqli = null;
        }   
        return $mysqli;
    }

    function LoadQueryStats (ClickHouseDB\Client $db, string $table_qfn, int $limit) {        
        $query = "SELECT event_time_microseconds, written_rows, result_rows, tables FROM system.query_log\n";       
        $query .= "WHERE  (query_kind = 'Insert') AND (type = 'QueryFinish') AND (tables[1]  = '$table_qfn')  ORDER BY event_time_microseconds DESC LIMIT $limit";        
        try {
            $db->write('SYSTEM FLUSH LOGS'); // this need privs!
            $stmt = $db->select($query);
            $res = [];
            if (is_object($stmt) && !$stmt->isError()) 
                for ($i = 0; $i < $stmt->count(); $i ++) {
                    $row = $stmt->fetchRow();
                    $res []= $row;
                }
            elseif (is_object($stmt))
                $res = "FAILED: SELECT FROM system.query.log";
        } catch (Exception $E) {
            return sprintf("#EXCEPTION: %s in %s", $E->getMessage(), $E->getTraceAsString());
        }
        return $res;  
    }

?>