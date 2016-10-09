<?php

/**
 * Establish a PDO connection to the database
 */
class DB
{
    static function setup($host, $username, $password, $dbname)
    {
        try {
            $dsn = "mysql:host=$host;dbname=$dbname";
            $options = [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"];
            $connection = new PDO($dsn, $username, $password, $options);
            // $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            die("Failed to connect to MySQL database with error: " . str_ireplace($password, '[REDACTED]', $e->getMessage()));
        }

        return new PDOWrapper($connection, $dsn, $username, $password);
    }
}

class PDOWrapper
{
    function __construct($connection, $dsn, $username, $password)
    {
        $this->_old_reconnect_count = null;
        $this->connection = $connection;
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->reconnects = 0;
    }

    function __call($name, $parameters)
    {
        return call_user_func_array([$this->connection, $name], $parameters);
    }

    function disableAutomaticReconnect()
    {
        $this->reconnects = 999;
    }

    function reconnect()
    {
        $this->connection = new PDO($this->dsn, $this->username, $this->password, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"]);
        $this->reconnects++;
    }

    function resetErrors()
    {
        $this->sqlstate = null;
        $this->errormessage = null;
        $this->errornumber = null;
    }

    function fetchOneCachedRow($query, $parameters = [], $expire = 600)
    {
        $key = Cache::callToKey($query, array_merge($parameters ?: [], [$this->dsn, 'fetchOneCachedRow']));
        if (!($results = Cache::get($key))) {
            $results = $this->fetchOneRow($query, $parameters);
            Cache::set($key, $results, $expire);
        }

        return $results;
    }

    function selectCachedMap($key, $query, $parameters = [], $expire = 600)
    {
        if (($results = $this->cachedExecute($query, $parameters, $expire)) === false) {
            return false;
        }

        $map = [];
        foreach ($results as $result) {
            $map[$result[$key]] = $result;
        }

        return $map;
    }

    function cachedRowCount($query, $parameters = [], $expire = 600)
    {
        $key = Cache::callToKey($query, array_merge($parameters ?: [], [$this->dsn, 'cachedRowCount']));
        if (!($results = Cache::get($key))) {
            $results = $this->rowCount($query, $parameters);
            Cache::set($key, $results, $expire);
        }

        return $results;
    }

    function cachedExecute($query, $parameters = [], $expire = 600)
    {
        $key = Cache::callToKey($query, array_merge($parameters ?: [], [$this->dsn, 'cachedExecute']));
        if (!($results = Cache::get($key))) {
            $results = $this->execute($query, $parameters);
            Cache::set($key, $results, $expire);
        }

        return $results;
    }

    function execute($query, $parameters = [], $fetch_all = true, $logfailure = true)
    {
        $this->resetErrors();
        $this->statement = $statement = $this->connection->prepare($query);
        $this->lastinsertid = $this->updated = $this->inserted = null;

        if (!($r = $statement->execute($parameters))) {
            list($this->sqlstate, $this->errornumber, $this->errormessage) = $statement->errorInfo();
            if (!$this->errormessage) {
                $this->errormessage = "SQLSTATE " . $this->sqlstate;
            } else if ($this->errormessage == "MySQL server has gone away" and !$this->reconnects) {
                $this->reconnect();

                return $this->execute($query, $parameters, $fetch_all, $logfailure);
            }

            if ($logfailure) {
                $tmp = $this->errormessage;
                $this->errormessage = $tmp;
            }

            return false;
        }

        $this->reconnects = 0;
        $this->lastinsertid = $this->connection->lastInsertID();
        $this->affectedrows = $statement->rowCount();
        $this->updated = $this->affectedrows == 2;
        $this->inserted = $this->affectedrows == 1;

        if ($fetch_all) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } else {
            return $this->statement->rowCount();
        }
    }

    function startTransaction()
    {
        if ($this->_old_reconnect_count) {
            throw new Exception('Transaction already in progress.');
        }

        $success = $this->execute('START TRANSACTION') !== false;
        if ($success) {
            $this->_old_reconnect_count = $this->reconnects;
            $this->disableAutomaticReconnect();
        }

        return $success;
    }

    function commit()
    {
        if ($this->_old_reconnect_count === null) {
            throw new Exception('Transaction not in progress.');
        }

        $success = $this->execute('COMMIT') !== false;
        if ($success) {
            $this->reconnects = $this->_old_reconnect_count;
            $this->_old_reconnect_count = null;
        }

        return $success;
    }

    function rollBack()
    {
        if ($this->_old_reconnect_count === null) {
            throw new Exception('Transaction not in progress.');
        }

        $success = $this->execute('ROLLBACK') !== false;
        if ($success) {
            $this->reconnects = $this->_old_reconnect_count;
            $this->_old_reconnect_count = null;
        }

        return $success;
    }

    function selectMap($key, $query, $parameters = [])
    {
        if (($results = $this->execute($query, $parameters)) === false) {
            return false;
        }

        $map = [];
        foreach ($results as $result) {
            $map[$result[$key]] = $result;
        }

        return $map;
    }

    function nextRow()
    {
        return $this->statement->fetch(PDO::FETCH_ASSOC);
    }

    // TODO: replace this where used
    function next_row()
    {
        return $this->statement->fetch(PDO::FETCH_ASSOC);
    }

    function fetchOneRow($query, $parameters = [])
    {
        if (($result = $this->execute($query, $parameters)) === false) {
            return false;
        }

        if (count($result) < 2) {
            return array_pop($result);
        } else {
            $this->errormessage = "fetchOneRow called, but more than one row was returned. Parameters: " . implode(" ", $parameters);

            return false;
        }
    }

    function rowCount($query, $parameters = [])
    {
        $query = preg_replace('/;+\s*$/', '', $query);
        $results = $this->fetchOneRow("SELECT COUNT(*) as count FROM ($query) as t", $parameters);

        return $results['count'];
    }

    function lastInsertID()
    {
        return $this->connection->lastInsertID();
    }

    function fetchCachedColumn($query, $parameters = [], $expire = 600)
    {
        // TODO: Figure out what's passing in null here.
        $key = Cache::callToKey($query, array_merge($parameters ?: [], ['fetchCachedColumn', $this->dsn]));
        if (!($results = Cache::get($key))) {
            $results = $this->fetchColumn($query, $parameters);
            Cache::set($key, $results, $expire);
        }

        return $results;
    }

    function fetchColumn($query, $parameters = [])
    {
        if (($result = $this->execute($query, $parameters)) === false) {
            return false;
        }

        $column = [];
        foreach ($result as $row) {
            $column[] = array_pop($row);
        }

        return $column;
    }

    function insertArray($arr, $table, $updateondupe = true)
    {
        $parameters = [];
        $query = "INSERT INTO $table (";
        foreach ($arr as $key => $value) {
            $parameters[] = $value;
        }

        $query .= '`' . implode('`,`', array_keys($arr)) . '`) VALUES (' . nbinds($parameters) . ')';
        if ($updateondupe) {
            $query .= ' ON DUPLICATE KEY UPDATE ';
            $binds = [];
            foreach ($arr as $key => $value) {
                if ($updateondupe === true or in_array($key, $updateondupe) or isset($updateondupe[$key])) {
                    $binds[] = "`$key` = ?";
                    if ($updateondupe !== true) {
                        if (isset($updateondupe[$key])) {
                            $parameters[] = $updateondupe[$key];
                        } else {
                            $parameters[] = $value;
                        }
                    }
                }
            }
            $query .= implode(', ', $binds);

            if ($updateondupe === true) {
                $parameters = array_merge($parameters, $parameters);
            }
        }

        return $this->execute($query, $parameters);
    }
}

function nbinds($parameters, $wrap_in_parens = false)
{
    if (is_array($parameters)) {
        $n = count($parameters);
    } else {
        $n = $parameters;
    }

    $binds = substr(str_repeat(', ? ', $n), 1);

    if ($wrap_in_parens) {
        // FIXME
        return substr(str_replace('?', ', (?)', $binds), 1);
    }

    return $binds;
}