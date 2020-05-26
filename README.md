Utility to restrict MySQL user (read) access to tables and columns.
This script does not write anything to MySQL but generates SQL statements to output which can be executed after inspection.
This script inspects `information_schema` and `mysql` databases to generate GRANT statements and was tested on MySQL 5.7.

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


This script tries to solve these issues to make GRANTS works in a blacklist way where only protected tables and columns need to be specified, and it tries to be stateless in terms of revoking table permissions - you don't need to know beforehand if some GRANTS exists if you want to REVOKE it.


USAGE
=================
```
git clone https://github.com/restyler/mysql-grants-generator
cd mysql-grants-generator
cp config.dist.php config.php
nano config.php
php generate.php > fixaccess.sql
mysql < fixaccess.sql
```
generate.php launch and its output execution may be included in the deploy process after migrations running to add new tables to grants automatically.


BAD CODE WARNING
============
This is an alpha-quality quick & dirty script with bad PHP practices all over the place, which is designed to be self-contained zero-dependency and thinks that the input comes from a reliable source, so there is no proper SQL injection protection!
