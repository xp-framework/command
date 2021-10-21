Commands
========

[![Build status on GitHub](https://github.com/xp-framework/command/workflows/Tests/badge.svg)](https://github.com/xp-framework/command/actions)
[![XP Framework Module](https://raw.githubusercontent.com/xp-framework/web/master/static/xp-framework-badge.png)](https://github.com/xp-framework/core)
[![BSD Licence](https://raw.githubusercontent.com/xp-framework/web/master/static/licence-bsd.png)](https://github.com/xp-framework/core/blob/master/LICENCE.md)
[![Requires PHP 7.0+](https://raw.githubusercontent.com/xp-framework/web/master/static/php-7_0plus.svg)](http://php.net/)
[![Supports PHP 8.0+](https://raw.githubusercontent.com/xp-framework/web/master/static/php-8_0plus.svg)](http://php.net/)
[![Latest Stable Version](https://poser.pugx.org/xp-framework/command/version.png)](https://packagist.org/packages/xp-framework/command)

Also known as "xpcli": Command line argument parsing via annotations.

Example
-------

```php
use util\cmd\{Command, Arg};
use rdbms\DriverManager;
use io\streams\Streams;

/**
 * Performs an SQL query
 */
class Query extends Command {
  private $connection, $query;
  private $verbose= false;

  /**
   * Connection DSN, e.g. `mysql://user:pass@host[:port][/database]`
   *
   * @param  string $dsn
   */
  #[Arg(position: 0)]
  public function setConnection($dsn) {
    $this->connection= DriverManager::getConnection($dsn);
    $this->connection->connect();
  }

  /**
   * SQL query. Use `-` to read from standard input.
   *
   * @param  string $query
   */
  #[Arg(position: 1)]
  public function setQuery($query) {
    if ('-' === $query) {
      $this->query= Streams::readAll($this->in->stream());
    } else {
      $this->query= $query;
    }
  }

  /**
   * Verbose output
   */
  #[Arg]
  public function setVerbose() {
    $this->verbose= true;
  }

  /** @return int */
  public function run() {
    $this->verbose && $this->out->writeLine('@ ', $this->connection);
    $this->verbose && $this->out->writeLine('>>> ', $this->query);

    $result= $this->connection->open($this->query);
    if ($result->isSuccess()) {
      $this->verbose && $this->out->writeLine('<<< ', $result->affected());
      return $result->affected() ? 0 : 1;
    } else {
      $this->verbose && $this->out->writeLine('<<< Results');
      foreach ($result as $found => $record) {
        $this->out->writeLine($record);
      }
      return isset($found) ? 0 : 2;
    }
  }
}
```

To execute the class, use the `cmd` command:

```sh
$ xp -m /path/to/rdbms cmd Query 'mysql://localhost/test' 'select * from account' -v
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

To show the command's usage, supply `-?` or `--help`:

![query-class-usage](https://cloud.githubusercontent.com/assets/696742/12219325/375b43ba-b73f-11e5-9588-d7a122668e3d.png)

See also
--------

* [RFC #0133](https://github.com/xp-framework/rfc/issues/133) - Add support for filenames as argument for XPCLI
* [RFC #0102](https://github.com/xp-framework/rfc/issues/102) - XP Class Runners *(original RFC)*