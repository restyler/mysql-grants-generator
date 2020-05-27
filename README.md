Utility to restrict MySQL user (read) access to tables and columns.
This script does not write anything to MySQL but generates SQL statements to output which can be inspected and executed.
This script inspects `information_schema` and `mysql` databases to generate GRANT and REVOKE statements and was tested on MySQL 5.7 and PHP 7.3.

THE PROBLEM
=================
Imagine you have database with 200 tables and table `user` has 50 columns.
You need to restrict read access to columns  `user.email`, `user.phone` and `user.password` for MySQL read-only user which is used by analytics software, e.g. Metabase. You also want to completely restrict access to table `secret_table`.
You also want new tables and new columns, which appear in the database during application lifetime (by running migrations) to work seamlessly and be available for analysis right away. 

Basically, you need to write `GRANT SELECT, SHOW VIEW ON db.table1 TO metabaseuser;` for every whitelisted table.
For column granular access you need something like `GRANT SELECT ( id,userName,alias,firstName,lastName,status,createdAt, ....) ON db.table1 TO metabaseuser;` for user table, mentioning each column EXCEPT the private ones which are email and phone, and password.

MySQL GRANTS system has only whitelist approach implemented, so you need to explicitly specify each column in a table, and specify each table which you want to give GRANTS to, which is a lot of manual work.
Here is an article on how it can be done semi-manually: https://chartio.com/learn/databases/grant-permissions-for-mysql/

If you've earlier granted some table access and then decided to forbid access to it, you need to write REVOKE operation, which has an important caveat in MySQL - REVOKE throws errors if such GRANT did not exist, so before REVOKING something you need to grok through SHOW GRANTS for the restricted user and make sure such grants exist - this makes it difficult to write idempotent queries where you REVOKE "just in case" - you need to fiddle around ignoring MySQL exceptions: https://stackoverflow.com/questions/43867038/mysql-revoke-privilege-if-exists
Another non-obvious "feature" of REVOKE is that if you earlier gave some user GRANT on specific table, then `REVOKE SELECT ON dbname.*` (which you might think should remove all GRANTS on all tables inside database) won't remove this specific table permission, and will also throw "There is no such grant defined for user" as mentioned earlier. You need to be specific and write `REVOKE SELECT ON dbname.tablename FOR username`.


This script tries to solve these issues and works in a more predictable way where only allowed databases, with protected tables and columns, need to be specified, and it tries to be stateless in terms of revoking db and table permissions - you don't need to know beforehand if some GRANTS exists if you want to REVOKE it.

INSTALLATION
=================
```
git clone https://github.com/restyler/mysql-grants-generator
cd mysql-grants-generator
cp config.dist.php config.php
nano config.php
```
USAGE
=================
```
php generate.php > fixaccess.sql
mysql < fixaccess.sql
```
This may be included in the deploy process after migrations execution to update grants automatically.


BAD CODE WARNING
============
This is an alpha-quality quick & dirty script with bad PHP practices all over the place, which is designed to be self-contained zero-dependency and thinks that the input comes from a reliable source, so there is no proper SQL injection protection!
