Commands ChangeLog
==================

## ?.?.? / ????-??-??

## 7.2.0 / 2016-08-29

* Added forward compatibility with XP 8.0.0: Refrain from using deprecated
  `util.Properties::fromString()`
  (@thekid)

## 7.1.3 / 2016-07-11

* Fixed issue #8: Command inside "." - @thekid

## 7.1.2 / 2016-07-05

* Fixed I/O not being reassigned on Console changes - @thekid

## 7.1.1 / 2016-05-05

* Shortened command names in usage if a command package is registered
  (@thekid)

## 7.1.0 / 2016-05-01

* Merged PR #6: Add support for named commands - @thekid
* Merged PR #4: Pass configuration to command constructor - @thekid

## 7.0.0 / 2016-02-21

* **Adopted semantic versioning. See xp-framework/rfc#300** - @thekid 
* Added version compatibility with XP 7 - @thekid

## 6.10.0 / 2016-01-10

* **Heads up: Bumped minimum XP version required to XP 6.10.0** - @thekid
* Merged PR #2: Make command instantiation overrideable. Declare a static
  `newInstance()` method and return an instance of your command from it.
  (@thekid)
* Merged PR #1: Integrate into xp command infrastructure. See the XP
  RFC for this, xp-framework/rfc#303
  (@thekid)

## 6.9.2 / 2016-01-09

* Implemented xp-framework/rfc#307: Extract XPCLI from core - @thekid