# Memgraph Bolt wrapper

This library contains wrapper class to cover basic functionality with [Bolt library](https://github.com/neo4j-php/Bolt).

![DB Tests PHP8](https://github.com/stefanak-michal/memgraph-bolt-wrapper/actions/workflows/tests.2204.yml/badge.svg?branch=main)

<a href='https://ko-fi.com/Z8Z5ABMLW' target='_blank'><img height='36' style='border:0px;height:36px;' src='https://cdn.ko-fi.com/cdn/kofi1.png?v=3' border='0' alt='Buy Me a Coffee at ko-fi.com' /></a>

## Usage

```php
Memgraph::$auth = ['scheme' => 'none'];
$rows = Memgraph::query('RETURN $n as num', ['n' => 123]);
```

You can also use methods like `queryFirstField` and `queryFirstColumn`.

_If you want to learn more about available query parameters check tests._

### Database server

Default connection is executed on 127.0.0.1:7687. You can change target server with static properties:

```php
Memgraph::$host = '127.0.0.1';
Memgraph::$port = 7687;
```

### Transactions

Transaction methods are:

```php
Memgraph::begin();
Memgraph::commit();
Memgraph::rollback();
```

### Log handler

You can set callable function into `Memgraph::$logHandler` which is called everytime query is executed. Method will receive executed query with additional statistics.

_Check class property annotation for more information._

### Error handler

Standard behaviour on error is trigger_error with E_USER_ERROR. If you want to handle Exception by yourself you can set callable function into `Memgraph::$errorHandler`.

### Statistics

Wrapper offers special method `Memgraph::statistic()`. This method returns specific information from last executed query.

_Check method annotation for more information._
