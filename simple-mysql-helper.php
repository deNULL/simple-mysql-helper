<?php

class DB {
  public static $instance;
  public $conn;

  public function __construct($db_name = false, $db_host = false, $db_user = false, $db_pass = false) {
    if (!$db_name) {
      $db_name = DB_NAME;
    }
    if (!$db_host) {
      $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    }
    if (!$db_user) {
      $db_user = defined('DB_USER') ? DB_USER : 'root';
    }
    if (!$db_pass) {
      $db_pass = defined('DB_PASS') ? DB_PASS : '';
    }

    mb_internal_encoding("UTF-8");
    $this->conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $this->conn->query("SET CHARACTER SET 'utf8mb4'");
    $this->conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");
    $this->conn->query("SET NAMES 'utf8mb4_unicode_ci'");
    self::$instance = $this;
  }

  public static function connect($db_name = false, $db_host = false, $db_user = false, $db_pass = false) {
    return new DB($db_name, $db_host, $db_user, $db_pass);
  }

  public function select($table, $where = false, $limit = false, $order = '', $total = false, $assoc = false) {
    $conn = isset($this) ? $this->conn : self::$instance->conn;
    $cond = array();
    if ($where === false) {
      // nothing
    } else
    if (gettype($where) == 'string') {
      $cond[] = $where;
    } else {
      foreach ($where as $key => $value) {
        if (is_array($value)) {
          $opts = array();
          foreach ($value as $v) {
            $opts[] = "'" . $conn->escape_string($v) . "'";
          }

          $cond[] = $key . ' IN (' . implode(',', $opts) . ')';
          $assoc = ($limit === 0) ? $key : false;
        } else {
          $cond[] = $key . ' = ' . ((isset($value) && ($value !== null)) ?
            "'" . $conn->escape_string($value) . "'" : "NULL");
        }
      }
    }
    $result = $conn->query("SELECT * FROM {$table}" .
      (empty($cond) ? "" : (" WHERE " . implode(' AND ', $cond))) .
      (empty($order) ? "" : (" ORDER BY " . $order)) .
      (($limit === false) || $assoc ? "" : (" LIMIT " . (is_string($limit) ? $limit : max($limit, 1)))));

    if (!$result) {
      /*error_log($conn->error);
      error_log("SELECT * FROM {$table}" .
      (empty($cond) ? "" : (" WHERE " . implode(' AND ', $cond))) .
      (empty($order) ? "" : (" ORDER BY " . $order)) .
      ($limit === false ? "" : (" LIMIT " . max($limit, 1))));*/
      /*echo "SELECT * FROM {$table}" .
      (empty($cond) ? "" : (" WHERE " . implode(' AND ', $cond))) .
      (empty($order) ? "" : (" ORDER BY " . $order)) .
      ($limit === false ? "" : (" LIMIT " . max($limit, 1)));
      print_r($conn->error);*/
      return false;
    }

    $fields = $result->fetch_fields();

    $rows = array();
    while ($row = $result->fetch_assoc()) {
      foreach ($fields as $field) {
        if ($row[$field->name] !== null) {
          switch ($field->type) {
            case 3:
              settype($row[$field->name], 'int');
              break;
            case 4:
            case 5:
              settype($row[$field->name], 'float');
              break;
            default:
              settype($row[$field->name], 'string');
              break;
          }
        }
      }
      if ($assoc) {
        if (is_array($assoc)) {
          $container = &$rows;
          foreach ($assoc as $field) {
            $val = $row[$field];
            if (!isset($container[$val])) {
              $container[$val] = array();
            }
            $container = &$container[$val];
          }
          $container = $row;
        } else {
          $rows[$row[$assoc]] = $row;
        }
      } else {
        $rows[] = $row;
      }
    }
    $resp = (($limit === 0) && !$assoc ? (empty($rows) ? false : $rows[0]) : $rows);
    if ($total) {
      $result = $conn->query("SELECT COUNT(*) AS total FROM {$table}" .
        (empty($cond) ? "" : (" WHERE " . implode(' AND ', $cond))));
      $row = $result->fetch_assoc();
      $resp = array($resp, intval($row['total']));
    }

    return $resp;
  }

  public function assoc($table, $assoc, $where = false, $limit = false, $order = '', $total = false) {
    $instance = isset($this) ? $this : self::$instance;
    return $instance->select($table, $where, $limit, $order, $total, $assoc);
  }

  public function single($table, $where, $order = '') {
    $instance = isset($this) ? $this : self::$instance;
    return $instance->select($table, $where, 0, $order);
  }

  public function insert($table, $vals, $keys = false) {
    $conn = isset($this) ? $this->conn : self::$instance->conn;

    if (empty($vals)) {
      return false;
    }

    if (!isset($vals[0]) || !is_array($vals[0])) {
      $vals = array($vals);
    }

    $dups = array();
    $fields = array();
    foreach ($vals[0] as $key => $value) {
      $fields[] = $key;
    }
    if ($keys) {
      foreach ($keys as $key => $value) {
        if (!is_int($key)) {
          if ($value == 'UPD') {
            $dups[] = "`$key` = VALUES(`$key`)";
          } else
          if ($value == 'INC') {
            $dups[] = "`$key` = `$key` + 1";
          } else
          if ($value == 'ADD') {
            $dups[] = "`$key` = `$key` + VALUES(`$key`)";
          } else
          if ($value == 'MAX') {
            $dups[] = "`$key` = GREATEST(`$key`, VALUES(`$key`))";
          } else
          if ($value == 'MIN') {
            $dups[] = "`$key` = LEAST(`$key`, VALUES(`$key`))";
          } else
          if ($value) {
            $dups[] = "`$key` = $value";
          }
          $fields[] = "$key";
        } else {
          $fields[] = "$value";
        }
      }
    }

    $fields = array_unique($fields);

    $rows = array();
    foreach ($vals as $val) {
      $row = array();
      foreach ($fields as $key) {
        if (isset($val[$key]) && ($val[$key] !== null)) {
          $row[] = "'" . $conn->escape_string($val[$key]) . "'";
        } else {
          $row[] = "NULL";
        }
      }
      $rows[] = "(" . implode(",", $row) . ")";
    }

    foreach ($fields as $i => &$field) {
      $fields[$i] = "`$field`";
    }

    $conn->query(
      "INSERT INTO {$table} (" . implode(", ", $fields) . ")".
        " VALUES " . implode(", ", $rows) .
        (empty($dups) ? "" : (" ON DUPLICATE KEY UPDATE " . implode(", ", $dups)))
    );

    return $conn->insert_id;
  }

  public function esc($string) {
    $conn = isset($this) ? $this->conn : self::$instance->conn;
    return $conn->escape_string($string);
  }
}