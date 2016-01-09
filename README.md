Commands
========

[![Build Status on TravisCI](https://secure.travis-ci.org/xp-framework/command.svg)](http://travis-ci.org/xp-framework/command)
[![XP Framework Module](https://raw.githubusercontent.com/xp-framework/web/master/static/xp-framework-badge.png)](https://github.com/xp-framework/core)
[![BSD Licence](https://raw.githubusercontent.com/xp-framework/web/master/static/licence-bsd.png)](https://github.com/xp-framework/core/blob/master/LICENCE.md)
[![Required PHP 5.5+](https://raw.githubusercontent.com/xp-framework/web/master/static/php-5_5plus.png)](http://php.net/)
[![Supports PHP 7.0+](https://raw.githubusercontent.com/xp-framework/web/master/static/php-7_0plus.png)](http://php.net/)
[![Supports HHVM 3.4+](https://raw.githubusercontent.com/xp-framework/web/master/static/hhvm-3_4plus.png)](http://hhvm.com/)
[![Latest Stable Version](https://poser.pugx.org/xp-framework/command/version.png)](https://packagist.org/packages/xp-framework/command)

Also known as "xpcli": Command line argument parsing via annotations.

Example
-------

```php
use rdbms\DriverManager;
use rdbms\ResultSet;
use io\streams\Streams;

/**
 * Performs an SQL query
 */
class Query extends \util\cmd\Command {
  private $connection, $query;

  /**
   * Set dsn (e.g. mysql://user:pass@host[:port][/database])
   *
   * @param   string dsn
   */
  #[@arg(position= 0)]
  public function setConnection($dsn) {
    $this->connection= DriverManager::getConnection($dsn);
    $this->connection->connect();
    $this->out->writeLine('@ ', $this->connection);
  }

  /**
   * Set SQL query
   *
   * @param   string query
   */
  #[@arg(position= 1)]
  public function setQuery($query) {
    if ('-' === $query) {
      $this->query= Streams::readAll($this->in->getStream());
    } else {
      $this->query= $query;
    }
  }

  /** @return int */
  public function run() {
    $this->out->writeLine('>>> ', $this->query);
    $result= $this->connection->query($this->query);
    if ($result instanceof ResultSet) {
      $this->out->writeLine('<<< Results');
      foreach ($result as $record) {
        $this->out->writeLine($record);
      }
    } else {
      $this->out->writeLine('<<< ', $result);
    }
    return 0;
  }
}
```

To execute the class, use the `xpcli` runner:

```sh
$ xpcli -cp /path/to/rdbms/src/main/php Query 'mysql://localhost/test' 'select * from account'
@ rdbms.mysqlx.MySqlxConnection(->rdbms.DSN@(mysql://localhost/test), rdbms.mysqlx.MySqlxProtocol(...)
>>> select * from account
<<< Results
[
  account_id => 1
  username => "kiesel"
  email => "alex.dandrea@example.com"
]
[
  account_id => 2
  username => "thekid"
  email => "timm.friebe@example.com"
]
```

To show a usage, supply `-?` or `--help`. This will display a usage:

```sh
$ xpcli -cp ../rdbms/src/main/php Query -?
Performs an SQL query
========================================================================
Usage: $ xpcli Query <connection> <query>
Arguments:
* #1
  Set dsn (e.g. mysql://user:pass@host[:port][/database])

* #2
  Set SQL query
```

See also
--------

* [RFC #0133](https://github.com/xp-framework/rfc/issues/133) - Add support for filenames as argument for XPCLI
* [RFC #0102](https://github.com/xp-framework/rfc/issues/102) - XP Class Runners *(original RFC)*