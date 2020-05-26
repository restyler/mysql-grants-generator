<?php
$config = require 'config.php';

require_once 'db.php';



function getTableColumns($dbname, $tablename, $excludeColumns = [])
{
    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ";
    $params = [$dbname, $tablename];

    if (count($excludeColumns)) {
        $sql .= 'AND COLUMN_NAME NOT IN (\'' . implode('\',\'', $excludeColumns) . '\')';
    }

    return DB::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function getTablePrivileges($privileges, $dbname, $tablename, $username)
{
    $tablePrivileges = DB::run("SELECT PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND GRANTEE=? AND PRIVILEGE_TYPE IN('" . implode("','", $privileges) . "')", [$dbname, $tablename, $username])->fetchAll(PDO::FETCH_COLUMN);

    $columnPrivileges = DB::run("SELECT DISTINCT(PRIVILEGE_TYPE) FROM information_schema.COLUMN_PRIVILEGES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND GRANTEE=? AND PRIVILEGE_TYPE IN('" . implode("','", $privileges) . "')", [$dbname, $tablename, $username])->fetchAll(PDO::FETCH_COLUMN);

    // since REVOKE is clever enough to remove all grants from TABLE_PRIVILEGES and COLUMN_PRIVILEGES
    // we just need one mention of proper grant so return something like ['SELECT', 'SHOW VIEW']
    return array_merge_recursive($tablePrivileges, $columnPrivileges);
}


$generatedSql = [];

$dbUserSplit = explode("'@'", trim($config['user_to_restrict'], " '"));

$userExists = DB::run("SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host=?", $dbUserSplit)->fetchColumn();

if (!$userExists) {
    printf('no such user in db: %s',  $config['user_to_restrict']) . "\n";
    exit(0);
}


foreach ($config['databases_to_allow'] as $dbname) {
    $protectionConfig = $config['tables_to_protect'][$dbname] ?? [];

    $tables = DB::run("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ", [$dbname])->fetchAll(PDO::FETCH_COLUMN);

    if (count($tables)) {
        // @TODO: remove global perms on db in case they exist
        //$generatedSql[]= "REVOKE SELECT,SHOW VIEW ON `$dbname`.* FROM $DB_USER_TO_RESTRICT";

        foreach ($tables as $tablename) {
            if (!isset($protectionConfig[$tablename])) {
                $generatedSql[] = "GRANT SELECT, SHOW VIEW ON `$dbname`.$tablename TO " . $config['user_to_restrict'];
            } else {
                if (is_array($protectionConfig[$tablename])) {
                    $unprotectedCols = getTableColumns($dbname, $tablename, $protectionConfig[$tablename]);

                    $generatedSql[] = "GRANT SELECT ( `" . implode('`,`', $unprotectedCols) . "`) ON `$dbname`.$tablename TO " . $config['user_to_restrict'];
                } else {
                    // true means just avoid giving access to this table
                    // but we can call REVOKE only if there exists privilege for this table or its columns
                    // otherwise mysql throws 'no such privilege' error
                    if ($tablePrivileges = getTablePrivileges(['SELECT', 'SHOW VIEW'], $dbname, $tablename, $config['user_to_restrict'])) {
                        $generatedSql[] = "REVOKE " . implode(',', $tablePrivileges) . " ON `$dbname`.$tablename FROM " . $config['user_to_restrict'];
                    }
                }
            }
        }
    }
}


echo implode(";\n", $generatedSql) . ";\n\n";
