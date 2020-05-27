<?php
$config = require 'config.php';

require_once 'DB.php'; 


function getTableColumns($dbname, $tablename, $excludeColumns = [])
{
    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=?s AND TABLE_NAME=?s ";
    if (!count($excludeColumns)) {
        return DB::q($sql, $dbname, $tablename)->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $sql .= ' AND COLUMN_NAME NOT IN ?a';
        return DB::q($sql, $dbname, $tablename, $excludeColumns)->fetchAll(PDO::FETCH_COLUMN);
    }
}

function getTablePrivileges($privileges, $dbname, $tablename, $username)
{
    $tablePrivileges = DB::q("SELECT PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA=?s AND TABLE_NAME=?s AND GRANTEE=?s AND PRIVILEGE_TYPE IN ?a", $dbname, $tablename, $username, $privileges)->fetchAll(PDO::FETCH_COLUMN);

    $columnPrivileges = DB::q("SELECT DISTINCT(PRIVILEGE_TYPE) FROM information_schema.COLUMN_PRIVILEGES WHERE TABLE_SCHEMA=?s AND TABLE_NAME=?s AND GRANTEE=?s AND PRIVILEGE_TYPE IN ?a", $dbname, $tablename, $username, $privileges)->fetchAll(PDO::FETCH_COLUMN);

    // since REVOKE is clever enough to remove all grants from TABLE_PRIVILEGES and COLUMN_PRIVILEGES
    // we just need one mention of proper grant so return something like ['SELECT', 'SHOW VIEW']
    return array_merge_recursive($tablePrivileges, $columnPrivileges);
}

function getGlobalPrivileges($privileges, $username)
{
    $globalPrivileges = DB::q("SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=?s AND  PRIVILEGE_TYPE IN ?a", $username, $privileges)->fetchAll(PDO::FETCH_COLUMN);
    return $globalPrivileges;
}

function getAllowedDbList($privileges, $username)
{
    $sql = "SELECT TABLE_SCHEMA FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=?s AND PRIVILEGE_TYPE IN ?a";
    return DB::q($sql, $username, $privileges)->fetchAll(PDO::FETCH_COLUMN);
}

function getDbPrivileges($privileges, $username, $dbname)
{
    $sql = "SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=?s AND PRIVILEGE_TYPE IN ?a AND TABLE_SCHEMA=?s";
    return DB::q($sql, $username, $privileges, $dbname)->fetchAll(PDO::FETCH_COLUMN);
}


$generatedSql = [];
$username = $config['user_to_restrict'];

$dbUserSplit = explode("'@'", trim($username, " '"));

$userExists = DB::q("SELECT COUNT(*) FROM mysql.user WHERE User=?s AND Host=?s", $dbUserSplit[0], $dbUserSplit[1])->fetchColumn();

if (!$userExists) {
    printf('no such user in db: %s', $username) . "\n";
    exit(0);
}

// revoke global access
if ($globalPrivileges = getGlobalPrivileges(['SELECT', 'SHOW VIEW'], $username)) {
    $generatedSql[]= "REVOKE " . implode(',', $globalPrivileges) . " ON *.* FROM " . $username;
}

$allowedDbs = getAllowedDbList(['SELECT', 'SHOW VIEW'], $username);

// revoke access to databases not listed in config 
foreach ($allowedDbs as $dbname) {
    if (!in_array($dbname, $config['databases_to_allow'])) {
        $existingPrivileges = getDbPrivileges(['SELECT', 'SHOW VIEW'], $username, $dbname);
        $generatedSql[]= "REVOKE " . implode(',', $existingPrivileges) . " ON `$dbname`.* FROM " . $username;
    }
}

// revoke access to tables in databases not listed in config
$redundantTablePrivileges = DB::q("SELECT TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA NOT IN ?a ", $config['databases_to_allow'])->fetchAll(PDO::FETCH_ASSOC);

foreach ($redundantTablePrivileges as $p) {
    $generatedSql[] = "REVOKE " . $p['PRIVILEGE_TYPE'] . ' ON `' .  $p['TABLE_SCHEMA'] . '`.' . $p['TABLE_NAME'] . ' FROM ' . $username;
}

// @TODO: revoke access to columns in tables in databases not listed in config


foreach ($config['databases_to_allow'] as $dbname) {
    $protectionConfig = $config['tables_to_protect'][$dbname] ?? [];

    if (empty($protectionConfig)) {
        // give full access to this database instead of looping through each table
        $generatedSql[] = "GRANT SELECT, SHOW VIEW ON `$dbname`.* TO " . $username;
        continue;
    }

    $tables = DB::q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?s ", $dbname)->fetchAll(PDO::FETCH_COLUMN);

    if (count($tables)) {
        // @TODO: remove global perms on db in case they exist
        //$generatedSql[]= "REVOKE SELECT,SHOW VIEW ON `$dbname`.* FROM $DB_USER_TO_RESTRICT";

        foreach ($tables as $tablename) {
            if (!isset($protectionConfig[$tablename])) {
                $generatedSql[] = "GRANT SELECT, SHOW VIEW ON `$dbname`.$tablename TO " . $username;
            } else {
                if (is_array($protectionConfig[$tablename])) {
                    $unprotectedCols = getTableColumns($dbname, $tablename, $protectionConfig[$tablename]);

                    $generatedSql[] = "GRANT SELECT ( `" . implode('`,`', $unprotectedCols) . "`) ON `$dbname`.$tablename TO " . $username;
                } else {
                    // true means just avoid giving access to this table
                    // but we can call REVOKE only if there exists privilege for this table or its columns
                    // otherwise mysql throws 'no such privilege' error
                    if ($tablePrivileges = getTablePrivileges(['SELECT', 'SHOW VIEW'], $dbname, $tablename, $username)) {
                        $generatedSql[] = "REVOKE " . implode(',', $tablePrivileges) . " ON `$dbname`.$tablename FROM " . $username;
                    }
                }
            }
        }
    }
}


echo implode(";\n", $generatedSql) . ";\n\n";
