<?php namespace util\cmd;

use lang\Runnable;
use xp\command\CmdRunner;

/**
 * Base class for all commands
 */
abstract class Command implements Runnable {

  /** @var io.streams.StringReader */
  public $in  = null;

  /** @var io.streams.StringWriter */
  public $out = null;

  /** @var io.streams.StringWriter */
  public $err = null;

  /**
   * Make Commands runnable via `xp`.
   *
   * @param  string[] $args
   * @return int
   */
  public static function main($args) {
    array_unshift($args, strtr(static::class, '\\', '.'));
    return CmdRunner::main($args);
  }
}
